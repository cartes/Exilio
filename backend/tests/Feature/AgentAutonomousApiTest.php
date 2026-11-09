<?php

namespace Tests\Feature;

use App\Models\Agent;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_can_run_autonomous_step_and_receive_cost_checkpoint(): void
    {
        $response = $this->postJson("/api/agents/{$this->agent->id}/autonomous-step");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'checkpoint' => [
                    'status',
                    'completed_step' => [
                        'topic',
                        'curiosity_score',
                        'deliberation',
                        'hypothesis_content',
                        'confidence',
                        'memory_id',
                        'memory_type',
                        'version_before',
                        'version_after',
                        'summary',
                        'cost_usd',
                        'tokens_used',
                        'provider',
                    ],
                    'next_step_proposal' => [
                        'topic',
                        'proposed_query',
                        'estimated_tokens',
                        'estimated_cost_usd',
                    ],
                    'budget_telemetry' => [
                        'monthly_budget_usd',
                        'current_month_spend_usd',
                        'remaining_budget_usd',
                    ],
                    'checkpoint_message',
                ],
            ]);

        // Verifies version increment
        $this->assertEquals(1, $response->json('checkpoint.completed_step.version_before'));
        $this->assertEquals(2, $response->json('checkpoint.completed_step.version_after'));

        // Verifies that database updated personality_version
        $this->assertEquals(2, $this->agent->fresh()->personality_version);

        // Verifies that a hypothesis memory was persisted
        $this->assertDatabaseHas('memories', [
            'agent_id' => $this->agent->id,
            'epistemic_status' => 'hypothesis',
            'origin' => 'agent_inference',
        ]);

        // Verifies that agent_learning_versions recorded snapshot
        $this->assertDatabaseHas('agent_learning_versions', [
            'agent_id' => $this->agent->id,
            'version' => 2,
            'operation_type' => 'learning',
        ]);
    }
}

