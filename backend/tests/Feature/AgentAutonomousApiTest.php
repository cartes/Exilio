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

    /**
     * @param array<string, mixed>|null $json    Cuerpo JSON que devuelve el "modelo" (null = investigación normal).
     * @param array<string, mixed>      $response Campos extra de la respuesta HTTP de Gemini (p. ej. promptFeedback).
     */
    private function useFakeGemini(?array $json = null, array $response = []): void
    {
        foreach (['AI_PROVIDER' => 'gemini', 'GEMINI_API_KEY' => 'test-key'] as $k => $v) {
            $_ENV[$k] = $v;
            $_SERVER[$k] = $v;
        }

        $json ??= [
            'topic' => 'Teoría de Redes',
            'curiosity_score' => 0.72,
            'deliberation' => 'Analizo la estructura de la red.',
            'hypothesis_content' => 'Los nodos centrales reducen la incertidumbre del grafo.',
            'confidence' => 0.7,
            'taxonomic_type' => 'semantic',
            'next_exploratory_question' => '¿Qué pasa si falla un nodo central?',
        ];

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($response + [
                'candidates' => [['content' => ['parts' => [['text' => json_encode($json, JSON_UNESCAPED_UNICODE)]]]]],
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

    public function test_model_decline_is_reported_without_learning(): void
    {
        $this->useFakeGemini([
            'decision' => 'decline',
            'decline_reason' => 'No es verificable con la información disponible.',
            'suggested_topic' => 'Métricas de modularidad',
            'recommendations' => ['Define primero qué se entiende por «nodo central».', 'Busca un caso con datos públicos.'],
        ]);

        $response = $this->postJson("/api/agents/{$this->agent->id}/autonomous-step");

        $response->assertStatus(200)
            ->assertJsonPath('checkpoint.status', 'provider_declined')
            ->assertJsonPath('checkpoint.suggested_topic', 'Métricas de modularidad')
            ->assertJsonCount(2, 'checkpoint.recommendations');

        $this->assertDatabaseMissing('memories', ['epistemic_status' => 'hypothesis']);
        $this->assertEquals(1, $this->agent->fresh()->personality_version);
        // El tema NO cambia solo: los intereses siguen intactos.
        $this->assertArrayNotHasKey('Métricas de modularidad', $this->agent->fresh()->attributes['interests']);
    }

    public function test_model_redirect_is_reported_with_suggested_topic(): void
    {
        $this->useFakeGemini([
            'decision' => 'redirect',
            'decline_reason' => 'Hay un ángulo más fértil.',
            'suggested_topic' => 'Resiliencia de redes',
        ]);

        $this->postJson("/api/agents/{$this->agent->id}/autonomous-step")
            ->assertStatus(200)
            ->assertJsonPath('checkpoint.status', 'provider_redirect')
            ->assertJsonPath('checkpoint.suggested_topic', 'Resiliencia de redes');

        $this->assertEquals(1, $this->agent->fresh()->personality_version);
    }

    public function test_provider_safety_block_is_a_decline_not_a_canned_hypothesis(): void
    {
        // Gemini bloquea: no hay candidatos con texto. Antes se guardaba el JSON enlatado como real.
        $this->useFakeGemini([], [
            'promptFeedback' => ['blockReason' => 'SAFETY'],
            'candidates' => [],
        ]);

        $this->postJson("/api/agents/{$this->agent->id}/autonomous-step")
            ->assertStatus(200)
            ->assertJsonPath('checkpoint.status', 'provider_declined');

        $this->assertDatabaseMissing('memories', ['epistemic_status' => 'hypothesis']);
        $this->assertEquals(1, $this->agent->fresh()->personality_version);
    }

    public function test_recommendations_are_returned_with_a_normal_investigation(): void
    {
        $this->useFakeGemini([
            'hypothesis_content' => 'Los nodos centrales reducen la incertidumbre del grafo.',
            'confidence' => 0.7,
            'taxonomic_type' => 'semantic',
            'next_exploratory_question' => '¿Qué pasa si falla un nodo central?',
            'recommendations' => ['Contrasta con un grafo real.', '', 'Ojo con el sesgo de muestreo.', 'Tercera', 'Cuarta (se recorta)'],
        ]);

        $this->postJson("/api/agents/{$this->agent->id}/autonomous-step")
            ->assertStatus(200)
            ->assertJsonPath('checkpoint.status', 'checkpoint_ready')
            ->assertJsonCount(3, 'checkpoint.completed_step.recommendations');
    }

    public function test_manual_explore_reports_decline_without_hypothesis(): void
    {
        $this->useFakeGemini(['decision' => 'decline', 'decline_reason' => 'Fuera de mis capacidades.']);

        $this->postJson("/api/agents/{$this->agent->id}/explore")
            ->assertStatus(200)
            ->assertJsonPath('inquiry_report.decision', 'decline')
            ->assertJsonPath('inquiry_report.hypothesis_content', '');
    }
}
