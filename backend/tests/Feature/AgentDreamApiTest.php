<?php

namespace Tests\Feature;

use App\Models\Agent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentDreamApiTest extends TestCase
{
    use RefreshDatabase;

    protected Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = Agent::where('name', 'EXILIO-001')->firstOrFail();
    }

    private function hypothesis(string $topic, string $content): string
    {
        return $this->agent->memories()->create([
            'type' => 'semantic',
            'epistemic_status' => 'hypothesis',
            'origin' => 'agent_inference',
            'content' => $content,
            'confidence' => 0.7,
            'metadata' => ['topic' => $topic],
        ])->id;
    }

    public function test_cannot_dream_without_enough_experience(): void
    {
        $this->hypothesis('Redes', 'Los nodos centrales aceleran la difusión.');

        $this->postJson("/api/agents/{$this->agent->id}/dream")
            ->assertStatus(422);

        $this->assertDatabaseCount('memories', 1);
    }

    public function test_dream_proposal_does_not_persist_and_flags_fallback(): void
    {
        $a = $this->hypothesis('Redes', 'Los nodos centrales aceleran la difusión.');
        $b = $this->hypothesis('Ecología', 'Los ecosistemas modulares resisten mejor los fallos.');

        $response = $this->postJson("/api/agents/{$this->agent->id}/dream");

        $response->assertStatus(200)
            ->assertJsonPath('dream_report.is_fallback', true)
            ->assertJsonStructure(['dream_report' => [
                'narrative', 'exploratory_topic', 'exploratory_query',
                'source_memory_ids', 'sources', 'telemetry' => ['provider', 'cost_usd'],
            ]]);

        $this->assertEqualsCanonicalizing([$a, $b], $response->json('dream_report.source_memory_ids'));
        $this->assertDatabaseMissing('memories', ['type' => 'oneiric']);
        $this->assertSame(1, (int) $this->agent->fresh()->personality_version);
    }

    public function test_persisting_dream_seeds_curiosity_without_touching_traits(): void
    {
        $a = $this->hypothesis('Redes', 'Los nodos centrales aceleran la difusión.');
        $b = $this->hypothesis('Ecología', 'Los ecosistemas modulares resisten mejor los fallos.');

        $response = $this->postJson("/api/agents/{$this->agent->id}/dream/persist", [
            'narrative' => 'Simulación: redes y ecosistemas como un mismo bosque.',
            'exploratory_topic' => 'Redes ↔ Ecología',
            'exploratory_query' => '¿Comparten redes y ecosistemas la misma modularidad?',
            'source_memory_ids' => [$a, $b],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('memory.type', 'oneiric')
            ->assertJsonPath('memory.epistemic_status', 'dream')
            ->assertJsonPath('memory.origin', 'agent_inference')
            ->assertJsonPath('memory.confidence', null);

        $agent = $this->agent->fresh();
        $interest = $agent->attributes['interests']['Redes ↔ Ecología'];

        $this->assertSame('oneiric_seed', $interest['consolidation_status']);
        $this->assertLessThanOrEqual(0.25, $interest['acquired_relevance']);
        $this->assertEmpty($agent->attributes['personality'] ?? []);
        $this->assertSame(2, (int) $agent->personality_version);
        $this->assertDatabaseHas('agent_learning_versions', [
            'agent_id' => $agent->id,
            'version' => 2,
            'operation_type' => 'learning',
        ]);
    }

    public function test_dream_cannot_use_dreams_or_foreign_memories_as_sources(): void
    {
        $a = $this->hypothesis('Redes', 'Los nodos centrales aceleran la difusión.');
        $dreamId = $this->agent->memories()->create([
            'type' => 'oneiric',
            'epistemic_status' => 'dream',
            'origin' => 'agent_inference',
            'content' => 'Un sueño previo.',
        ])->id;

        $this->postJson("/api/agents/{$this->agent->id}/dream/persist", [
            'narrative' => 'Sueño encadenado.',
            'exploratory_topic' => 'Cadena',
            'source_memory_ids' => [$a, $dreamId],
        ])->assertStatus(422);

        $this->postJson("/api/agents/{$this->agent->id}/dream/persist", [
            'narrative' => 'Sueño sin fuente real.',
            'exploratory_topic' => 'Nada',
            'source_memory_ids' => ['6f1f3b0e-6c2a-4c53-8d36-0a3a5b0d9a11'],
        ])->assertStatus(422);
    }
}
