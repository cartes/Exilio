<?php

namespace App\Services\Research;

use App\Models\Agent;
use App\Models\Memory;
use App\Models\ResearchFinding;
use App\Models\ResearchScout;
use App\Models\SourceDocument;

/**
 * Scouts de investigación web (Fase 6a). Sin IA: la búsqueda, descarga y extracción son deterministas.
 *
 * Flujo: EXILIO propone (proposed) -> el auditor aprueba (approved) -> se lanza (running) ->
 * completed, con hallazgos pendientes que el auditor aprueba o rechaza una a una.
 */
class ResearchScoutService
{
    public function __construct(
        protected SearchProvider $search,
        protected WebGate $gate,
        protected ContentExtractor $extractor
    ) {}

    /**
     * EXILIO-001 propone una misión a partir de su mayor curiosidad disponible.
     *
     * @throws \DomainException si no hay intereses sobre los que proponer.
     */
    public function propose(Agent $agent): ResearchScout
    {
        $interests = collect($agent->attributes['interests'] ?? [])
            ->sortByDesc(fn ($i) => (float) ($i['curiosity_score'] ?? 0))
            ->values();

        if ($interests->isEmpty()) {
            throw new \DomainException('EXILIO-001 aún no tiene intereses sobre los que proponer una investigación.');
        }

        $blocked = $agent->researchScouts()
            ->whereIn('status', ['proposed', 'approved', 'running', 'rejected'])
            ->pluck('topic')
            ->all();

        foreach ($interests as $interest) {
            $topic = trim((string) ($interest['topic'] ?? ''));
            if ($topic === '' || in_array($topic, $blocked, true)) {
                continue;
            }

            $query = trim((string) ($interest['exploratory_query'] ?? '')) ?: $topic;
            $alreadyDone = $agent->researchScouts()
                ->where('topic', $topic)->where('query', mb_substr($query, 0, 500))->exists();
            if ($alreadyDone) {
                continue;
            }

            $c = number_format((float) ($interest['curiosity_score'] ?? 0), 2);
            $origin = ($interest['consolidation_status'] ?? '') === 'oneiric_seed'
                ? 'Surgió de una semilla onírica (simulación, aún sin verificar).'
                : 'Surgió de su experiencia registrada.';

            return $agent->researchScouts()->create([
                'topic' => mb_substr($topic, 0, 120),
                'query' => mb_substr($query, 0, 500),
                'status' => 'proposed',
                'origin' => 'agent_proposal',
                'proposal_reason' => "Mayor curiosidad disponible: «{$topic}» (C={$c}). {$origin} Pregunta guía: {$query}",
                'max_pages' => 5,
            ])->fresh();
        }

        throw new \DomainException('No hay temas nuevos que proponer: todos tienen una misión pendiente, rechazada o ya realizada.');
    }

    public function createForAuditor(Agent $agent, string $topic, string $query, int $maxPages): ResearchScout
    {
        return $agent->researchScouts()->create([
            'topic' => mb_substr(trim($topic), 0, 120),
            'query' => mb_substr(trim($query) ?: trim($topic), 0, 500),
            'status' => 'approved', // la pide el propio auditor
            'origin' => 'auditor',
            'max_pages' => $this->clampPages($maxPages),
        ])->fresh();
    }

    public function approve(ResearchScout $scout, ?int $maxPages = null): ResearchScout
    {
        $this->assertStatus($scout, ['proposed']);
        $scout->update([
            'status' => 'approved',
            'max_pages' => $maxPages !== null ? $this->clampPages($maxPages) : $this->clampPages($scout->max_pages),
        ]);

        return $scout->fresh();
    }

    public function reject(ResearchScout $scout): ResearchScout
    {
        $this->assertStatus($scout, ['proposed', 'approved']);
        $scout->update(['status' => 'rejected', 'finished_at' => now()]);

        return $scout->fresh();
    }

    /** Interruptor de parada: el scout lo comprueba antes de cada página. */
    public function cancel(ResearchScout $scout): ResearchScout
    {
        $this->assertStatus($scout, ['proposed', 'approved', 'running']);
        $scout->update(['status' => 'cancelled', 'finished_at' => now()]);

        return $scout->fresh();
    }

    /**
     * Reclama de forma atómica una misión aprobada (approved -> running). Devuelve false si otro
     * proceso ya la reclamó o cambió de estado, evitando ejecuciones dobles.
     */
    public function claim(ResearchScout $scout): bool
    {
        return ResearchScout::where('id', $scout->id)->where('status', 'approved')->update([
            'status' => 'running',
            'started_at' => now(),
            'run_log' => json_encode([]),
            'error' => null,
        ]) === 1;
    }

    /** Reclama y ejecuta (uso síncrono: pruebas y consola). */
    public function run(ResearchScout $scout): ResearchScout
    {
        if (!$this->claim($scout)) {
            $this->assertStatus($scout->fresh(), ['approved']);
        }

        return $this->execute($scout->fresh());
    }

    /**
     * Ejecuta una misión ya reclamada (running). Acotada por max_pages y los topes de config/research.php.
     */
    public function execute(ResearchScout $scout): ResearchScout
    {
        $this->assertStatus($scout, ['running']);
        @set_time_limit(240);

        $log = [];
        $stored = 0;

        try {
            if (!config('research.enabled')) {
                throw new \RuntimeException('La investigación web está desactivada (RESEARCH_ENABLED=false).');
            }

            $limit = $this->clampPages($scout->max_pages);
            $results = $this->search->search(
                $scout->query,
                (int) config('research.max_search_results')
            );

            $perHost = [];
            $seenUrls = [];

            foreach ($results as $result) {
                if ($stored >= $limit) {
                    break;
                }

                // Kill switch: el auditor pudo cancelar mientras corría.
                if ($scout->fresh()->status !== 'running') {
                    $log[] = ['url' => null, 'result' => 'cancelled', 'detail' => 'Detenido por el auditor.'];
                    break;
                }

                $url = $result['url'];
                $host = strtolower((string) parse_url($url, PHP_URL_HOST));
                if ($host === '' || isset($seenUrls[$url])) {
                    continue;
                }
                $seenUrls[$url] = true;
                if (($perHost[$host] ?? 0) >= (int) config('research.max_per_host')) {
                    $log[] = ['url' => $url, 'result' => 'skipped', 'detail' => 'Tope por dominio.'];
                    continue;
                }

                try {
                    $page = $this->gate->fetch($url);
                    $perHost[$host] = ($perHost[$host] ?? 0) + 1;

                    if ($page['status'] >= 400) {
                        $log[] = ['url' => $url, 'result' => 'error', 'detail' => "HTTP {$page['status']}"];
                        continue;
                    }

                    $extracted = $this->extractor->extract($page['body'], $page['content_type']);
                    if (mb_strlen($extracted['text']) < 200) {
                        $log[] = ['url' => $url, 'result' => 'skipped', 'detail' => 'Texto insuficiente.'];
                        continue;
                    }

                    $hash = hash('sha256', $extracted['text']);
                    if (SourceDocument::where('agent_id', $scout->agent_id)->where('content_hash', $hash)->exists()) {
                        $log[] = ['url' => $url, 'result' => 'skipped', 'detail' => 'Contenido ya conocido.'];
                        continue;
                    }

                    $document = SourceDocument::create([
                        'scout_id' => $scout->id,
                        'agent_id' => $scout->agent_id,
                        'url' => mb_substr($page['url'], 0, 2048),
                        'title' => $extracted['title'] ?: null,
                        'http_status' => $page['status'],
                        'content_hash' => $hash,
                        'text' => $extracted['text'],
                        'fetched_at' => now(),
                    ]);
                    $stored++;

                    $created = $this->createFindings($scout, $document);
                    $log[] = ['url' => $url, 'result' => 'ok', 'detail' => "{$created} hallazgo(s)"];
                } catch (WebGateException $e) {
                    $log[] = ['url' => $url, 'result' => 'blocked', 'detail' => $e->getMessage()];
                }
            }

            $final = $scout->fresh();
            $scout->update([
                'status' => $final->status === 'cancelled' ? 'cancelled' : 'completed',
                'pages_fetched' => $stored,
                'run_log' => $log,
                'finished_at' => now(),
            ]);
        } catch (SearchUnavailableException | \RuntimeException $e) {
            $scout->update([
                'status' => 'failed',
                'pages_fetched' => $stored,
                'run_log' => $log,
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ]);
        }

        return $scout->fresh();
    }

    /**
     * Aprueba un hallazgo: queda como hecho observable «la fuente afirma…», no como verdad verificada.
     */
    public function approveFinding(ResearchFinding $finding): Memory
    {
        if ($finding->status !== 'pending') {
            throw new \DomainException('El hallazgo ya fue revisado.');
        }

        $document = $finding->document;
        $host = parse_url($document->url, PHP_URL_HOST) ?: $document->url;
        $title = $document->title ?: $host;

        $memory = Memory::create([
            'agent_id' => $finding->agent_id,
            'type' => 'episodic',
            'epistemic_status' => 'fact',
            'origin' => 'system_event',
            'content' => "La fuente «{$title}» ({$host}) afirma: «{$finding->excerpt}»",
            'confidence' => null,
            'evidence_ref' => mb_substr($document->url, 0, 255),
            'occurred_at' => $document->fetched_at,
            'metadata' => [
                'generated_by' => 'research_scout',
                'scout_id' => $finding->scout_id,
                'source_document_id' => $document->id,
                'url' => $document->url,
                'content_hash' => $document->content_hash,
                'fetched_at' => $document->fetched_at?->toIso8601String(),
                'topic' => $finding->scout->topic,
                'note' => 'Observación de lo que afirma una fuente web; no implica que lo afirmado sea cierto.',
            ],
        ]);

        $finding->update(['status' => 'approved', 'memory_id' => $memory->id]);

        return $memory;
    }

    public function rejectFinding(ResearchFinding $finding): ResearchFinding
    {
        if ($finding->status !== 'pending') {
            throw new \DomainException('El hallazgo ya fue revisado.');
        }
        $finding->update(['status' => 'rejected']);

        return $finding->fresh();
    }

    protected function createFindings(ResearchScout $scout, SourceDocument $document): int
    {
        $sentences = $this->extractor->relevantSentences($document->text, "{$scout->topic} {$scout->query}", 3);
        $created = 0;

        foreach ($sentences as $s) {
            $hash = hash('sha256', mb_strtolower($s['text']));
            $exists = ResearchFinding::where('agent_id', $scout->agent_id)->where('excerpt_hash', $hash)->exists();
            if ($exists) {
                continue;
            }

            ResearchFinding::create([
                'scout_id' => $scout->id,
                'agent_id' => $scout->agent_id,
                'source_document_id' => $document->id,
                'excerpt' => $s['text'],
                'excerpt_hash' => $hash,
                'relevance' => $s['score'],
                'status' => 'pending',
            ]);
            $created++;
        }

        return $created;
    }

    protected function clampPages(int $requested): int
    {
        return max(1, min($requested, (int) config('research.max_pages_hard_cap')));
    }

    /** @param list<string> $allowed */
    protected function assertStatus(ResearchScout $scout, array $allowed): void
    {
        if (!in_array($scout->status, $allowed, true)) {
            throw new \DomainException(
                "La misión está en estado «{$scout->status}»; se esperaba: " . implode(' o ', $allowed) . '.'
            );
        }
    }
}
