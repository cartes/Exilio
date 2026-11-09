<?php

namespace App\Services;

use App\Http\Controllers\AgentLearningController;
use App\Models\Agent;
use App\Models\Memory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Motor onírico mínimo (Fase 5), exclusivamente bajo demanda.
 *
 * Un sueño es una recombinación simbólica de memorias vigentes de tipo `fact` o `hypothesis`.
 * - Siempre se persiste como `oneiric` + `dream`, origen `agent_inference`, sin `confidence`.
 * - Nunca modifica rasgos de personalidad ni se usa como evidencia de hechos.
 * - Solo guía la curiosidad: siembra (o enlaza) un interés con relevancia R <= 0.25.
 * - Sin experiencia suficiente no hay sueño: no se inventa material de la nada.
 */
class DreamService
{
    public const MIN_SOURCES = 2;
    public const MAX_SOURCES = 4;
    public const SEED_RELEVANCE = 0.15;

    public function __construct(
        protected GeminiService $geminiService,
        protected IntrospectionService $introspectionService
    ) {}

    /**
     * Propone un sueño sin persistirlo.
     *
     * @return array<string, mixed>
     *
     * @throws \DomainException si no hay experiencia vigente suficiente.
     */
    public function dream(Agent $agent): array
    {
        $sources = $this->selectSources($agent);

        if ($sources->count() < self::MIN_SOURCES) {
            throw new \DomainException(
                'No hay experiencia vigente suficiente para soñar: se requieren al menos '
                . self::MIN_SOURCES . ' memorias (hechos o hipótesis) no rectificadas.'
            );
        }

        $labels = $sources->map(fn (Memory $m) => $this->labelFor($m))->values()->all();
        $a = $labels[0];
        $b = $labels[1];

        $fallback = [
            'narrative' => "Simulación interna: ¿y si «{$a}» y «{$b}» fueran partes de un mismo patrón aún no observado?",
            'exploratory_topic' => mb_substr("{$a} ↔ {$b}", 0, 120),
            'exploratory_query' => mb_substr("¿Existe alguna relación verificable entre «{$a}» y «{$b}»?", 0, 255),
        ];

        $fragments = $sources->map(function (Memory $m) {
            $status = $m->epistemic_status === 'hypothesis'
                ? 'hipótesis ' . round(((float) $m->confidence) * 100) . '%'
                : 'hecho';

            return "- [{$m->id}] ({$status}) " . mb_substr($m->content, 0, 400);
        })->implode("\n");

        $grounding = $this->introspectionService->buildContext($agent, 'Sueño: recombinación simbólica');
        $systemInstruction = <<<INSTRUCTION
{$grounding['system_instruction']}

MODO ACTIVO: MOTOR ONÍRICO (simulación interna, NO evidencia).
Recombina de forma simbólica y creativa los siguientes fragmentos de tu experiencia real:
{$fragments}

REGLAS:
1. El sueño es una SIMULACIÓN. Nunca lo presentes como hecho ni como algo vivido.
2. Usa únicamente los fragmentos dados; no inventes memorias ni datos externos.
3. Cierra con una pregunta verificable que pueda investigarse después en el mundo real.
4. Sin afirmaciones de consciencia, emociones ni sufrimiento.
5. Responde SOLO con JSON plano (sin markdown):
{"narrative":"2 a 4 frases, tono simbólico","exploratory_topic":"tema breve (máx 80 caracteres)","exploratory_query":"pregunta verificable en una frase"}
INSTRUCTION;

        $response = $this->geminiService->generateResponse(
            $agent,
            'Genera el sueño a partir de los fragmentos.',
            $systemInstruction,
            json_encode($fallback, JSON_UNESCAPED_UNICODE),
            ['mode' => 'dream']
        );

        $parsed = $this->parseJson($response['content']);
        $usedFallback = $parsed === null;
        if ($usedFallback) {
            Log::info('DreamService: respuesta no parseable, se usa la recombinación determinista.');
            $parsed = $fallback;
        }

        return [
            'narrative' => $parsed['narrative'],
            'exploratory_topic' => mb_substr($parsed['exploratory_topic'], 0, 120),
            'exploratory_query' => mb_substr($parsed['exploratory_query'], 0, 255),
            'source_memory_ids' => $sources->pluck('id')->values()->all(),
            'sources' => $sources->map(fn (Memory $m) => [
                'id' => $m->id,
                'epistemic_status' => $m->epistemic_status,
                'excerpt' => mb_substr($m->content, 0, 140),
            ])->values()->all(),
            // true cuando el sueño es la recombinación local y no una generación del modelo
            'is_fallback' => $usedFallback || !GeminiService::isRealProvider($response['provider'] ?? null),
            'telemetry' => [
                'provider' => $response['provider'],
                'model' => $response['model'],
                'tokens_used' => $response['tokens_used'],
                'cost_usd' => $response['cost_usd'],
                'current_month_spend_usd' => $response['current_month_spend_usd'],
                'monthly_budget_usd' => $response['monthly_budget_usd'],
            ],
        ];
    }

    /**
     * Persiste un sueño aprobado y siembra curiosidad (nueva versión auditable).
     *
     * @param array{narrative: string, exploratory_topic: string, exploratory_query?: ?string, source_memory_ids: list<string>} $dream
     * @return array{memory: Memory, agent: Agent}
     *
     * @throws \InvalidArgumentException si las fuentes no son memorias vigentes no oníricas del agente.
     */
    public function persistDream(Agent $agent, array $dream): array
    {
        $ids = array_values(array_unique($dream['source_memory_ids'] ?? []));
        $sources = $agent->memories()->active()
            ->whereIn('id', $ids)
            ->whereIn('epistemic_status', ['fact', 'hypothesis'])
            ->get();

        if (count($ids) === 0 || $sources->count() !== count($ids)) {
            throw new \InvalidArgumentException(
                'Las fuentes del sueño deben ser memorias vigentes (hechos o hipótesis) del agente.'
            );
        }

        $topic = trim($dream['exploratory_topic']);
        $query = isset($dream['exploratory_query']) ? trim((string) $dream['exploratory_query']) : '';

        return DB::transaction(function () use ($agent, $dream, $ids, $topic, $query) {
            $memory = $agent->memories()->create([
                'type' => 'oneiric',
                'epistemic_status' => 'dream',
                'origin' => 'agent_inference',
                'content' => trim($dream['narrative']),
                'confidence' => null,
                'evidence_ref' => 'sueno-' . date('Ymd-His'),
                'metadata' => [
                    'generated_by' => 'dream_engine',
                    'source_memory_ids' => $ids,
                    'exploratory_topic' => $topic,
                ],
            ]);

            AgentLearningController::ensureBaselineVersion($agent);

            $attributes = $agent->attributes ?? [];
            $interests = $attributes['interests'] ?? [];
            $existing = $interests[$topic] ?? null;

            if ($existing === null) {
                $r = self::SEED_RELEVANCE; // <= AgentLearningController::MAX_RELEVANCE_DREAM
                $signals = AgentLearningController::deriveCuriositySignals('dream', $r, null);
                $interests[$topic] = [
                    'topic' => $topic,
                    'acquired_relevance' => $r,
                    'consolidation_status' => 'oneiric_seed',
                    'source_epistemic_status' => 'dream',
                    'exploratory_query' => $query !== '' ? $query : "Investigar hechos verificables sobre: {$topic}",
                    'curiosity_signals' => $signals,
                    'curiosity_score' => round(
                        0.30 * $signals['novelty'] + 0.25 * $signals['uncertainty']
                        + 0.30 * $signals['learning'] + 0.15 * $signals['relevance'],
                        4
                    ),
                    'evidence_memory_ids' => [$memory->id],
                ];
                $summary = "Semilla onírica: «{$topic}» (R={$r}, sin consolidar rasgos).";
            } else {
                // Un interés ya fundamentado en hechos/hipótesis no se reescribe por un sueño.
                $ev = $existing['evidence_memory_ids'] ?? [];
                $ev[] = $memory->id;
                $interests[$topic]['evidence_memory_ids'] = array_values(array_unique($ev));
                if (empty($existing['exploratory_query']) && $query !== '') {
                    $interests[$topic]['exploratory_query'] = $query;
                }
                $summary = "Sueño vinculado al interés existente «{$topic}» (sin cambiar R ni rasgos).";
            }

            $attributes['interests'] = $interests;
            $newVersion = ((int) $agent->personality_version) + 1;

            $agent->update([
                'personality_version' => $newVersion,
                'attributes' => $attributes,
            ]);

            $agent->learningVersions()->create([
                'version' => $newVersion,
                'operation_type' => 'learning',
                'summary' => $summary,
                'evidence_memory_ids' => [$memory->id],
                'attributes_snapshot' => $attributes,
                'reverted_from_version' => null,
                'created_at' => now(),
            ]);

            return ['memory' => $memory, 'agent' => $agent->fresh()];
        });
    }

    /**
     * Memorias fuente: recientes, vigentes, no oníricas, priorizando variedad temática.
     *
     * @return Collection<int, Memory>
     */
    protected function selectSources(Agent $agent): Collection
    {
        $recent = $agent->memories()->active()
            ->whereIn('epistemic_status', ['fact', 'hypothesis'])
            ->orderByDesc('created_at')
            ->limit(24)
            ->get();

        $picked = collect();
        $seen = [];

        foreach ($recent as $m) {
            $key = $m->metadata['topic'] ?? $m->type;
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $picked->push($m);
            }
            if ($picked->count() >= self::MAX_SOURCES) {
                return $picked;
            }
        }

        foreach ($recent as $m) {
            if ($picked->count() >= self::MAX_SOURCES) {
                break;
            }
            if (!$picked->contains('id', $m->id)) {
                $picked->push($m);
            }
        }

        return $picked;
    }

    protected function labelFor(Memory $m): string
    {
        $label = $m->metadata['topic'] ?? mb_substr(trim($m->content), 0, 50);

        return trim((string) $label);
    }

    /**
     * @return array{narrative: string, exploratory_topic: string, exploratory_query: string}|null
     */
    protected function parseJson(string $raw): ?array
    {
        if (!preg_match('/\{[\s\S]*\}/', $raw, $matches)) {
            return null;
        }
        $data = json_decode($matches[0], true);
        if (!is_array($data)) {
            return null;
        }
        foreach (['narrative', 'exploratory_topic', 'exploratory_query'] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key]) || trim($data[$key]) === '') {
                return null;
            }
        }

        return [
            'narrative' => trim($data['narrative']),
            'exploratory_topic' => trim($data['exploratory_topic']),
            'exploratory_query' => trim($data['exploratory_query']),
        ];
    }
}
