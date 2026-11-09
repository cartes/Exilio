<?php

namespace Tests\Feature;

use App\Models\Agent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentExplorationApiTest extends TestCase
{
    use RefreshDatabase;

    protected Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = Agent::where('name', 'EXILIO-001')->firstOrFail();
        $this->agent->update([
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

    public function test_can_run_cognitive_inquiry_cycle(): void
    {
        $response = $this->postJson("/api/agents/{$this->agent->id}/explore");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'inquiry_report' => [
                    'topic',
                    'curiosity_score',
                    'exploratory_query',
                    'deliberation',
                    'hypothesis_content',
                    'confidence',
                    'taxonomic_type',
                    'next_exploratory_question',
                    'telemetry' => [
                        'provider',
                        'model',
                        'tokens_used',
                        'cost_usd',
                        'current_month_spend_usd',
                        'monthly_budget_usd',
                    ],
                ],
            ]);

        $this->assertEquals('Teoría de Redes', $response->json('inquiry_report.topic'));
        $this->assertEquals(0.72, $response->json('inquiry_report.curiosity_score'));
    }

    public function test_can_persist_approved_inquiry_hypothesis(): void
    {
        $payload = [
            'topic' => 'Teoría de Redes',
            'hypothesis_content' => 'Los nodos centrales absorben información con mayor velocidad reduciendo la incertidumbre del grafo.',
            'confidence' => 0.75,
            'taxonomic_type' => 'semantic',
            'next_exploratory_question' => '¿Qué resistencia al fallo presenta esta estructura modular?',
        ];

        $response = $this->postJson("/api/agents/{$this->agent->id}/explore/persist", $payload);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'memory' => [
                    'agent_id' => $this->agent->id,
                    'type' => 'semantic',
                    'epistemic_status' => 'hypothesis',
                    'origin' => 'agent_inference',
                    'confidence' => 0.75,
                ],
            ]);

        $this->assertDatabaseHas('memories', [
            'agent_id' => $this->agent->id,
            'type' => 'semantic',
            'epistemic_status' => 'hypothesis',
            'origin' => 'agent_inference',
        ]);

        // Check that interest updated its next exploratory query
        $updatedAgent = $this->agent->fresh();
        $this->assertEquals(
            '¿Qué resistencia al fallo presenta esta estructura modular?',
            $updatedAgent->attributes['interests']['Teoría de Redes']['exploratory_query']
        );
    }

    public function test_persist_inquiry_validates_required_fields(): void
    {
        $response = $this->postJson("/api/agents/{$this->agent->id}/explore/persist", [
            'topic' => '',
            'hypothesis_content' => '',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['topic', 'hypothesis_content', 'confidence', 'taxonomic_type']);
    }
}
