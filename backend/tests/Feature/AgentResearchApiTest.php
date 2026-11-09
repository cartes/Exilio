<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\ResearchFinding;
use App\Models\ResearchScout;
use App\Services\Research\SearchProvider;
use App\Services\Research\SearchUnavailableException;
use App\Services\Research\WebGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgentResearchApiTest extends TestCase
{
    use RefreshDatabase;

    protected Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = Agent::where('name', 'EXILIO-001')->firstOrFail();
        $this->agent->update(['attributes' => [
            'personality' => [],
            'interests' => [
                'Resiliencia de redes' => [
                    'topic' => 'Resiliencia de redes',
                    'curiosity_score' => 0.80,
                    'exploratory_query' => 'resiliencia de redes modulares frente a fallos',
                    'consolidation_status' => 'tentative',
                    'evidence_memory_ids' => [],
                ],
                'Ecología' => [
                    'topic' => 'Ecología',
                    'curiosity_score' => 0.50,
                    'exploratory_query' => 'modularidad en ecosistemas',
                    'consolidation_status' => 'oneiric_seed',
                    'evidence_memory_ids' => [],
                ],
            ],
        ]]);

        // La puerta resuelve siempre a una IP pública (sin red real en los tests).
        $this->app->bind(WebGate::class, fn () => new WebGate(fn () => ['93.184.216.34']));
    }

    /** @param list<array{url: string, title?: string, snippet?: string}> $results */
    private function fakeSearch(array $results): void
    {
        $this->app->instance(SearchProvider::class, new class($results) implements SearchProvider {
            public function __construct(private array $results) {}

            public function search(string $query, int $limit): array
            {
                return array_map(fn ($r) => ['title' => '', 'snippet' => ''] + $r, array_slice($this->results, 0, $limit));
            }
        });
    }

    private function page(string $extra = ''): string
    {
        return '<html><head><title>Fuente de prueba</title></head><body><article>'
            . '<p>Las redes modulares presentan una mayor resiliencia frente a fallos aleatorios de sus nodos principales. ' . $extra . '</p>'
            . '<p>La estructura de la red modular limita la propagación de un fallo hacia el resto del sistema completo.</p>'
            . '<p>Texto de relleno sin relación alguna con el asunto investigado, para alcanzar la longitud mínima exigida por el extractor.</p>'
            . '</article></body></html>';
    }

    private function html(string $body): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response($body, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    private function scout(string $status = 'approved'): ResearchScout
    {
        return $this->agent->researchScouts()->create([
            'topic' => 'Resiliencia de redes',
            'query' => 'resiliencia de redes modulares frente a fallos',
            'status' => $status,
            'origin' => 'auditor',
            'max_pages' => 5,
        ]);
    }

    public function test_agent_proposes_mission_from_highest_curiosity_without_ai(): void
    {
        $this->postJson("/api/agents/{$this->agent->id}/research/propose")
            ->assertStatus(201)
            ->assertJsonPath('scout.topic', 'Resiliencia de redes')
            ->assertJsonPath('scout.status', 'proposed')
            ->assertJsonPath('scout.origin', 'agent_proposal');

        // Segunda propuesta: pasa al siguiente interés; la tercera ya no tiene temas.
        $this->postJson("/api/agents/{$this->agent->id}/research/propose")
            ->assertStatus(201)->assertJsonPath('scout.topic', 'Ecología');
        $this->postJson("/api/agents/{$this->agent->id}/research/propose")->assertStatus(422);

        $this->assertDatabaseCount('ai_usage_logs', 0);
    }

    public function test_cannot_propose_without_interests(): void
    {
        $this->agent->update(['attributes' => ['personality' => [], 'interests' => []]]);
        $this->postJson("/api/agents/{$this->agent->id}/research/propose")->assertStatus(422);
    }

    public function test_proposed_mission_cannot_run_until_approved(): void
    {
        $scout = $this->scout('proposed');

        $this->postJson("/api/research/scouts/{$scout->id}/run")->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_full_flow_search_fetch_findings_and_approval_into_memory(): void
    {
        $this->fakeSearch([['url' => 'https://a.example/redes']]);
        Http::fake([
            'https://a.example/robots.txt' => Http::response('', 404),
            'https://a.example/redes' => $this->html($this->page()),
        ]);

        $scout = $this->scout('proposed');
        $this->postJson("/api/research/scouts/{$scout->id}/approve")->assertStatus(200)->assertJsonPath('scout.status', 'approved');
        $this->postJson("/api/research/scouts/{$scout->id}/run")->assertStatus(202);

        $fresh = $scout->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertSame(1, $fresh->pages_fetched);
        $this->assertDatabaseHas('source_documents', ['scout_id' => $scout->id, 'title' => 'Fuente de prueba']);

        $detail = $this->getJson("/api/research/scouts/{$scout->id}")->assertStatus(200);
        $findings = $detail->json('findings');
        $this->assertNotEmpty($findings);
        $this->assertSame('pending', $findings[0]['status']);
        $this->assertStringContainsString('resiliencia', $findings[0]['excerpt']);

        // Aprobar: queda como hecho observable «la fuente afirma», con procedencia.
        $res = $this->postJson("/api/research/findings/{$findings[0]['id']}/approve")
            ->assertStatus(201)
            ->assertJsonPath('memory.epistemic_status', 'fact')
            ->assertJsonPath('memory.origin', 'system_event')
            ->assertJsonPath('memory.type', 'episodic')
            ->assertJsonPath('memory.evidence_ref', 'https://a.example/redes');
        $this->assertStringContainsString('afirma', $res->json('memory.content'));
        $this->assertSame('research_scout', $res->json('memory.metadata.generated_by'));

        // No se puede revisar dos veces.
        $this->postJson("/api/research/findings/{$findings[0]['id']}/approve")->assertStatus(422);
        $this->assertSame('approved', ResearchFinding::find($findings[0]['id'])->status);

        // Sin IA ni gasto en 6a, y el agente no cambió de versión (no hay aprendizaje implícito).
        $this->assertDatabaseCount('ai_usage_logs', 0);
        $this->assertEquals(1, $this->agent->fresh()->personality_version);
    }

    public function test_rejecting_a_finding_creates_no_memory(): void
    {
        $this->fakeSearch([['url' => 'https://a.example/redes']]);
        Http::fake([
            'https://a.example/robots.txt' => Http::response('', 404),
            'https://a.example/redes' => $this->html($this->page()),
        ]);
        $scout = $this->scout();
        app(\App\Services\Research\ResearchScoutService::class)->run($scout);

        $finding = ResearchFinding::firstOrFail();
        $this->postJson("/api/research/findings/{$finding->id}/reject")->assertStatus(200)->assertJsonPath('finding.status', 'rejected');
        $this->assertDatabaseCount('memories', 0);
    }

    public function test_internal_urls_from_search_are_blocked_and_never_requested(): void
    {
        $this->fakeSearch([
            ['url' => 'http://127.0.0.1/admin'],
            ['url' => 'http://169.254.169.254/latest/meta-data'],
            ['url' => 'https://a.example/redes'],
        ]);
        Http::fake([
            'https://a.example/robots.txt' => Http::response('', 404),
            'https://a.example/redes' => $this->html($this->page()),
        ]);

        $scout = app(\App\Services\Research\ResearchScoutService::class)->run($this->scout());

        $this->assertSame(1, $scout->pages_fetched);
        $blocked = collect($scout->run_log)->where('result', 'blocked');
        $this->assertCount(2, $blocked);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '127.0.0.1') || str_contains($r->url(), '169.254'));
    }

    public function test_per_host_cap_and_duplicate_content_are_enforced(): void
    {
        config(['research.max_per_host' => 2]);
        $this->fakeSearch([
            ['url' => 'https://a.example/1'],
            ['url' => 'https://a.example/2'],
            ['url' => 'https://a.example/3'],
            ['url' => 'https://b.example/espejo'],
        ]);
        Http::fake([
            'https://a.example/robots.txt' => Http::response('', 404),
            'https://b.example/robots.txt' => Http::response('', 404),
            'https://a.example/1' => $this->html($this->page('uno')),
            'https://a.example/2' => $this->html($this->page('dos')),
            'https://a.example/3' => $this->html($this->page('tres')),
            'https://b.example/espejo' => $this->html($this->page('uno')), // idéntico a /1
        ]);

        $scout = app(\App\Services\Research\ResearchScoutService::class)->run($this->scout());

        $this->assertSame(2, $scout->pages_fetched);
        $this->assertTrue(collect($scout->run_log)->contains(fn ($l) => str_contains($l['detail'], 'Tope por dominio')));
        $this->assertTrue(collect($scout->run_log)->contains(fn ($l) => str_contains($l['detail'], 'ya conocido')));
    }

    public function test_page_budget_cannot_exceed_hard_cap(): void
    {
        config(['research.max_pages_hard_cap' => 3]);

        $this->postJson("/api/agents/{$this->agent->id}/research/scouts", [
            'topic' => 'Redes', 'query' => 'redes', 'max_pages' => 20,
        ])->assertStatus(201)->assertJsonPath('scout.max_pages', 3);
    }

    public function test_robots_txt_blocks_a_page_during_a_run(): void
    {
        $this->fakeSearch([['url' => 'https://a.example/privado/x']]);
        Http::fake([
            'https://a.example/robots.txt' => Http::response("User-agent: *\nDisallow: /privado", 200),
            'https://a.example/privado/x' => $this->html($this->page()),
        ]);

        $scout = app(\App\Services\Research\ResearchScoutService::class)->run($this->scout());

        $this->assertSame(0, $scout->pages_fetched);
        $this->assertSame('blocked', $scout->run_log[0]['result']);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/privado/x'));
    }

    public function test_search_engine_down_fails_the_mission_cleanly(): void
    {
        $this->app->instance(SearchProvider::class, new class implements SearchProvider {
            public function search(string $query, int $limit): array
            {
                throw new SearchUnavailableException('SearXNG no responde.');
            }
        });

        $scout = app(\App\Services\Research\ResearchScoutService::class)->run($this->scout());

        $this->assertSame('failed', $scout->status);
        $this->assertStringContainsString('SearXNG', $scout->error);
    }

    public function test_cancelled_mission_cannot_run_and_kill_switch_works(): void
    {
        $scout = $this->scout();
        $this->postJson("/api/research/scouts/{$scout->id}/cancel")->assertStatus(200)->assertJsonPath('scout.status', 'cancelled');
        $this->postJson("/api/research/scouts/{$scout->id}/run")->assertStatus(409);
    }

    public function test_global_switch_disables_research(): void
    {
        config(['research.enabled' => false]);
        $this->fakeSearch([['url' => 'https://a.example/redes']]);
        Http::fake();

        $scout = app(\App\Services\Research\ResearchScoutService::class)->run($this->scout());

        $this->assertSame('failed', $scout->status);
        Http::assertNothingSent();
    }
}
