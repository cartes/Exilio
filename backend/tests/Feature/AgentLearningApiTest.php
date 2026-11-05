<?php

namespace Tests\Feature;

use App\Models\Agent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentLearningApiTest extends TestCase
{
    use RefreshDatabase;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = Agent::where('name', 'EXILIO-001')->firstOrFail();
    }

    public function test_initial_baseline_version_v1_exists_and_is_empty(): void
    {
        $response = $this->getJson("/api/agents/{$this->agent->id}/versions");

        $response->assertStatus(200);
        $versions = $response->json();

        $this->assertCount(1, $versions);
        $this->assertSame(1, $versions[0]['version']);
        $this->assertSame('initial', $versions[0]['operation_type']);
        $this->assertSame([], $versions[0]['attributes_snapshot']['personality']);
        $this->assertSame([], $versions[0]['attributes_snapshot']['interests']);
    }

    public function test_fact_memory_consolidates_personality_and_acquired_relevance(): void
    {
        $fact = $this->postJson("/api/agents/{$this->agent->id}/memories", [
            'type' => 'episodic',
            'epistemic_status' => 'fact',
            'origin' => 'user_input',
            'content' => 'Sesión verificada analizando estructuras de datos en grafos.',
            'evidence_ref' => 'obs-101',
        ])->json();

        $learnResponse = $this->postJson("/api/agents/{$this->agent->id}/learn", [
            'evidence_memory_ids' => [$fact['id']],
            'summary' => 'Consolidación por hecho observable',
            'trait_updates' => [
                'analytical_rigor' => 0.80,
            ],
            'interest_topics' => [
                ['topic' => 'teoría de grafos'],
            ],
        ]);

        $learnResponse->assertStatus(201);
        $data = $learnResponse->json();

        $this->assertSame(2, $data['agent']['personality_version']);
        $personality = $data['agent']['attributes']['personality'];
        $interests = $data['agent']['attributes']['interests'];

        $this->assertSame('consolidated', $personality['analytical_rigor']['consolidation_status']);
        $this->assertEquals(0.80, $personality['analytical_rigor']['value']);
        $this->assertSame([$fact['id']], $personality['analytical_rigor']['evidence_memory_ids']);

        $this->assertSame('consolidated', $interests['teoría de grafos']['consolidation_status']);
        $this->assertSame('fact', $interests['teoría de grafos']['source_epistemic_status']);
        $this->assertEquals(0.25, $interests['teoría de grafos']['acquired_relevance']);
    }

    public function test_hypothesis_memory_applies_tentative_confidence_weighted_learning(): void
    {
        $hypothesis = $this->postJson("/api/agents/{$this->agent->id}/memories", [
            'type' => 'semantic',
            'epistemic_status' => 'hypothesis',
            'origin' => 'agent_inference',
            'content' => 'Hipótesis: el interlocutor prefiere síntesis estructuradas.',
            'confidence' => 0.50,
        ])->json();

        $learnResponse = $this->postJson("/api/agents/{$this->agent->id}/learn", [
            'evidence_memory_ids' => [$hypothesis['id']],
            'trait_updates' => [
                'structured_synthesis' => 0.80,
            ],
            'interest_topics' => [
                ['topic' => 'comunicación técnica'],
            ],
        ]);

        $learnResponse->assertStatus(201);
        $attrs = $learnResponse->json('agent.attributes');

        $this->assertSame('tentative', $attrs['personality']['structured_synthesis']['consolidation_status']);
        $this->assertEquals(0.40, $attrs['personality']['structured_synthesis']['value']);
        $this->assertSame('tentative', $attrs['interests']['comunicación técnica']['consolidation_status']);
        $this->assertEquals(0.10, $attrs['interests']['comunicación técnica']['acquired_relevance']);
    }

    public function test_dream_memory_guides_curiosity_and_exploratory_query_but_rejects_trait_consolidation(): void
    {
        $dream = $this->postJson("/api/agents/{$this->agent->id}/memories", [
            'type' => 'oneiric',
            'epistemic_status' => 'dream',
            'origin' => 'system_event',
            'content' => 'Soñé que veía un perro correr libremente en la playa.',
        ])->json();

        // 1. Attempting to alter personality traits from a dream must fail with 422
        $invalidTraitResponse = $this->postJson("/api/agents/{$this->agent->id}/learn", [
            'evidence_memory_ids' => [$dream['id']],
            'trait_updates' => [
                'coastal_affinity' => 0.50,
            ],
        ]);
        $invalidTraitResponse->assertStatus(422);
        $invalidTraitResponse->assertJsonValidationErrors(['trait_updates']);

        // 2. Guiding curiosity with an exploratory query from the dream succeeds and caps R at 0.25
        $validDreamLearn = $this->postJson("/api/agents/{$this->agent->id}/learn", [
            'evidence_memory_ids' => [$dream['id']],
            'summary' => 'Semilla exploratoria originada en sueño',
            'interest_topics' => [
                [
                    'topic' => 'playas aptas para perros',
                    'exploratory_query' => 'Averiguar cuál es la mejor playa para llevar un perro',
                ],
            ],
        ]);

        $validDreamLearn->assertStatus(201);
        $interest = $validDreamLearn->json('agent.attributes.interests')['playas aptas para perros'];

        $this->assertSame('oneiric_seed', $interest['consolidation_status']);
        $this->assertSame('dream', $interest['source_epistemic_status']);
        $this->assertSame('Averiguar cuál es la mejor playa para llevar un perro', $interest['exploratory_query']);
        $this->assertEquals(0.15, $interest['acquired_relevance']);
        $this->assertSame([$dream['id']], $interest['evidence_memory_ids']);

        // 3. Second dream stimulus on the same topic caps R at MAX_RELEVANCE_DREAM (0.25)
        $secondDreamLearn = $this->postJson("/api/agents/{$this->agent->id}/learn", [
            'evidence_memory_ids' => [$dream['id']],
            'interest_topics' => [
                ['topic' => 'playas aptas para perros'],
            ],
        ]);

        $secondDreamLearn->assertStatus(201);
        $updatedInterest = $secondDreamLearn->json('agent.attributes.interests')['playas aptas para perros'];
        $this->assertEquals(0.25, $updatedInterest['acquired_relevance']);
    }

    public function test_rejects_superseded_memory_as_learning_evidence(): void
    {
        $v1Mem = $this->postJson("/api/agents/{$this->agent->id}/memories", [
            'type' => 'episodic',
            'epistemic_status' => 'fact',
            'origin' => 'user_input',
            'content' => 'Observación inicial luego corregida.',
        ])->json();

        $this->postJson("/api/memories/{$v1Mem['id']}/rectify", [
            'type' => 'episodic',
            'epistemic_status' => 'fact',
            'origin' => 'user_input',
            'content' => 'Observación rectificada vigente.',
        ])->assertStatus(201);

        $response = $this->postJson("/api/agents/{$this->agent->id}/learn", [
            'evidence_memory_ids' => [$v1Mem['id']],
            'interest_topics' => [
                ['topic' => 'auditoría de datos'],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['evidence_memory_ids']);
    }

    public function test_can_revert_to_previous_version_auditably(): void
    {
        $fact = $this->postJson("/api/agents/{$this->agent->id}/memories", [
            'type' => 'episodic',
            'epistemic_status' => 'fact',
            'origin' => 'user_input',
            'content' => 'Evidencia para versión 2.',
        ])->json();

        // Advance to v2
        $this->postJson("/api/agents/{$this->agent->id}/learn", [
            'evidence_memory_ids' => [$fact['id']],
            'trait_updates' => ['methodical' => 0.70],
        ])->assertStatus(201);

        $this->assertSame(2, $this->agent->fresh()->personality_version);

        // Revert back to v1 (initial empty state)
        $revertResponse = $this->postJson("/api/agents/{$this->agent->id}/versions/1/revert", [
            'reason' => 'Reversión de prueba de auditoría a estado inicial',
        ]);

        $revertResponse->assertStatus(201);
        $this->assertSame(3, $revertResponse->json('agent.personality_version'));
        $this->assertSame([], $revertResponse->json('agent.attributes.personality'));
        $this->assertSame('revert', $revertResponse->json('version_record.operation_type'));
        $this->assertSame(1, $revertResponse->json('version_record.reverted_from_version'));

        // All 3 versions remain in audit history
        $history = $this->getJson("/api/agents/{$this->agent->id}/versions")->json();
        $this->assertCount(3, $history);
    }

    public function test_evidence_audit_flags_rectified_and_purged_memories_used_in_active_version(): void
    {
        $mem1 = $this->postJson("/api/agents/{$this->agent->id}/memories", [
            'type' => 'episodic',
            'epistemic_status' => 'fact',
            'origin' => 'user_input',
            'content' => 'Evidencia que luego será rectificada.',
        ])->json();

        $mem2 = $this->postJson("/api/agents/{$this->agent->id}/memories", [
            'type' => 'semantic',
            'epistemic_status' => 'fact',
            'origin' => 'manual_entry',
            'content' => 'Evidencia que luego será purgada.',
        ])->json();

        // Learn v2 from mem1 and mem2 while both are active
        $learn = $this->postJson("/api/agents/{$this->agent->id}/learn", [
            'evidence_memory_ids' => [$mem1['id'], $mem2['id']],
            'trait_updates' => ['observant' => 0.65],
            'interest_topics' => [['topic' => 'trazabilidad epistémica']],
        ])->json();

        $this->assertFalse($learn['agent']['evidence_audit']['has_stale_evidence']);

        // Rectify mem1 and purge mem2
        $this->postJson("/api/memories/{$mem1['id']}/rectify", [
            'type' => 'episodic',
            'epistemic_status' => 'fact',
            'origin' => 'user_input',
            'content' => 'Evidencia rectificada posteriormente.',
        ])->assertStatus(201);

        $this->deleteJson("/api/memories/{$mem2['id']}", [
            'reason' => 'Purga posterior al aprendizaje',
        ])->assertStatus(200);

        $agentData = $this->getJson("/api/agents/{$this->agent->id}")->json();
        $this->assertTrue($agentData['evidence_audit']['has_stale_evidence']);
        $this->assertSame([$mem1['id']], $agentData['evidence_audit']['superseded_memory_ids']);
        $this->assertSame([$mem2['id']], $agentData['evidence_audit']['purged_memory_ids']);
    }
}

