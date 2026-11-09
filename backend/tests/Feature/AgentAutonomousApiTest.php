<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AiUsageLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgentAutonomousApiTest extends TestCase
{
    use RefreshDatabase;

    protected Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = Agent::where('name', 'EXILIO-001')->firstOrFail();
        $this->agent->update([
            'personality_version' => 1,
            'attributes' => [
                'personality' => [],
                'interests' => [
                    'Teoría de Redes' => [
                        'topic' => 'Teoría de Redes',
                        'curiosity_score' => 0.72,
                        'exploratory_query' => '¿Cómo emergen los nodos centrales en una red social?',
                        'curiosity_signals' => [
                            'novelty' => 0.8,
                            'uncertainty' => 0.7,
                            'learning' => 0.8,
                            'relevance' => 0.4,
                        ],
                        'acquired_relevance' => 0.4,
                        'evidence_memory_ids' => [],
                        'consolidation_status' => 'tentative',
                    ],
                ],
                'autobiographical_memory' => [],
            ],
        ]);
    }

    private function useFakeGemini(): void
    {
        foreach (['AI_PROVIDER' => 'gemini', 'GEMINI_API_KEY' => 'test-key'] as $k => $v) {
            $_ENV[$k] = $v;
            $_SERVER[$k] = $v;
        }

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode([
                    'topic' => 'Teoría de Redes',
                    'curiosity_score' => 0.72,
                    'deliberation' => 'Analizo la estructura de la red.',
                    'hypothesis_content' => 'Los nodos centrales reducen la incertidumbre del grafo.',
                    'confidence' => 0.7,
                    'taxonomic_type' => 'semantic',
                    'next_exploratory_question' => '¿Qué pasa si falla un nodo central?',
                ], JSON_UNESCAPED_UNICODE)]]]]],
                'usageMetadata' => ['promptTokenCount' => 1000, 'candidatesTokenCount' => 200, 'totalTokenCount' => 1200],
            ]),
        ]);
    }

    public function test_mock_provider_step_is_skipped_and_does_not_learn(): void
    {
        $response = $this->postJson("/api/agents/{$this->agent->id}/autonomous-step");

        $response->assertStatus(200)
            ->assertJsonPath('checkpoint.status', 'fallback_skipped')
            ->assertJsonStructure(['checkpoint' => ['reason', 'checkpoint_message', 'budget_telemetry']]);

        $this->assertDatabaseMissing('memories', ['epistemic_status' => 'hypothesis']);
        $this->assertEquals(1, $this->agent->fresh()->personality_version);
    }

    public function test_real_provider_step_persists_hypothesis_and_evolves_interests_only(): void
    {
        $this->useFakeGemini();

        $response = $this->postJson("/api/agents/{$this->agent->id}/autonomous-step");

        $response->assertStatus(200)
            ->assertJsonPath('checkpoint.status', 'checkpoint_ready')
            ->assertJsonPath('checkpoint.completed_step.version_before', 1)
            ->assertJsonPath('checkpoint.completed_step.version_after', 2)
            ->assertJsonPath('checkpoint.completed_step.provider', 'gemini');

        $fresh = $this->agent->fresh();
        $this->assertEquals(2, $fresh->personality_version);
        $this->assertDatabaseHas('memories', [
            'agent_id' => $this->agent->id,
            'epistemic_status' => 'hypothesis',
            'origin' => 'agent_inference',
        ]);
        $this->assertDatabaseHas('agent_learning_versions', [
            'agent_id' => $this->agent->id,
            'version' => 2,
            'operation_type' => 'learning',
        ]);

        // Sin rasgos precargados: la personalidad solo cambia por aprendizaje explícito.
        $this->assertEmpty($fresh->attributes['personality']);
        $this->assertLessThanOrEqual(0.60, $fresh->attributes['interests']['Teoría de Redes']['acquired_relevance']);
    }

    public function test_new_topic_gets_relevance_only_from_hypothesis_evidence(): void
    {
        $this->useFakeGemini();

        $this->postJson("/api/agents/{$this->agent->id}/autonomous-step", ['topic' => 'Teoría de Redes Nueva'])
            ->assertStatus(200);

        $interest = $this->agent->fresh()->attributes['interests']['Teoría de Redes Nueva'] ?? null;
        $this->assertNotNull($interest);
        $this->assertSame('tentative', $interest['consolidation_status']);
        $this->assertNotEmpty($interest['evidence_memory_ids']);
    }

    public function test_mission_budget_is_enforced_with_server_side_spend(): void
    {
        $missionId = '6f1f3b0e-6c2a-4c53-8d36-0a3a5b0d9a11';

        AiUsageLog::create([
            'agent_id' => $this->agent->id,
            'provider' => 'gemini',
            'model' => 'x',
            'estimated_cost_usd' => 2.00,
            'mission_id' => $missionId,
            'created_at' => now(),
        ]);

        // El cliente miente diciendo que no gastó nada: el servidor manda.
        $this->postJson("/api/agents/{$this->agent->id}/autonomous-step", [
            'topic' => 'Teoría de Redes',
            'mission_budget_usd' => 2.00,
            'mission_id' => $missionId,
            'mission_spent_usd' => 0,
        ])->assertStatus(429)->assertJsonPath('status', 'budget_limit_exceeded');
    }

    public function test_mission_budget_requires_mission_id(): void
    {
        $this->postJson("/api/agents/{$this->agent->id}/autonomous-step", [
            'mission_budget_usd' => 2.00,
        ])->assertStatus(422)->assertJsonValidationErrors(['mission_id']);
    }

    public function test_mission_step_tags_usage_with_mission_id(): void
    {
        $this->useFakeGemini();
        $missionId = '7a2c4d10-1b3e-4f55-9c47-1b4b6c1e0b22';

        $this->postJson("/api/agents/{$this->agent->id}/autonomous-step", [
            'topic' => 'Teoría de Redes',
            'mission_budget_usd' => 2.00,
            'mission_id' => $missionId,
        ])->assertStatus(200)->assertJsonPath('checkpoint.budget_telemetry.mission_budget_usd', 2);

        $this->assertDatabaseHas('ai_usage_logs', ['mission_id' => $missionId, 'provider' => 'gemini']);
    }
}
