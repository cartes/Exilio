<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Memory;
use App\Models\MemoryTombstone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MemoryApiTest extends TestCase
{
    use RefreshDatabase;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = Agent::where('name', 'EXILIO-001')->firstOrFail();
    }

    public function test_can_create_valid_fact_memory(): void
    {
        $payload = [
            'type' => 'episodic',
            'epistemic_status' => 'fact',
            'origin' => 'user_input',
            'content' => 'The user initiated session with greeting.',
            'occurred_at' => now()->toIso8601String(),
            'evidence_ref' => 'msg-001',
            'metadata' => ['channel' => 'console'],
        ];

        $response = $this->postJson("/api/agents/{$this->agent->id}/memories", $payload);

        $response->assertStatus(201);
        $response->assertJson([
            'agent_id' => $this->agent->id,
            'type' => 'episodic',
            'epistemic_status' => 'fact',
            'origin' => 'user_input',
            'content' => 'The user initiated session with greeting.',
            'confidence' => null,
            'evidence_ref' => 'msg-001',
        ]);

        $this->assertDatabaseHas('memories', [
            'agent_id' => $this->agent->id,
            'type' => 'episodic',
            'epistemic_status' => 'fact',
            'confidence' => null,
        ]);
    }

    public function test_can_create_valid_hypothesis_with_confidence(): void
    {
        $payload = [
            'type' => 'semantic',
            'epistemic_status' => 'hypothesis',
            'origin' => 'agent_inference',
            'content' => 'The user prefers concise analytical answers.',
            'confidence' => 0.85,
        ];

        $response = $this->postJson("/api/agents/{$this->agent->id}/memories", $payload);

        $response->assertStatus(201);
        $response->assertJson([
            'type' => 'semantic',
            'epistemic_status' => 'hypothesis',
            'confidence' => 0.85,
        ]);
    }

    public function test_can_create_valid_dream_memory_only_with_oneiric_type(): void
    {
        $payload = [
            'type' => 'oneiric',
            'epistemic_status' => 'dream',
            'origin' => 'system_event',
            'content' => 'Internal rehearsal simulation of interaction graphs.',
        ];

        $response = $this->postJson("/api/agents/{$this->agent->id}/memories", $payload);

        $response->assertStatus(201);
        $response->assertJson([
            'type' => 'oneiric',
            'epistemic_status' => 'dream',
            'confidence' => null,
        ]);
    }

    public function test_rejects_oneiric_type_with_non_dream_status(): void
    {
        $payload = [
            'type' => 'oneiric',
            'epistemic_status' => 'fact',
            'origin' => 'user_input',
            'content' => 'Invalid oneiric fact.',
        ];

        $response = $this->postJson("/api/agents/{$this->agent->id}/memories", $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['epistemic_status']);
    }

    public function test_rejects_dream_status_with_non_oneiric_type(): void
    {
        $payload = [
            'type' => 'episodic',
            'epistemic_status' => 'dream',
            'origin' => 'system_event',
            'content' => 'Invalid dream under episodic.',
        ];

        $response = $this->postJson("/api/agents/{$this->agent->id}/memories", $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['type']);
    }

    public function test_rejects_fact_derived_from_agent_inference(): void
    {
        $payload = [
            'type' => 'semantic',
            'epistemic_status' => 'fact',
            'origin' => 'agent_inference',
            'content' => 'An inference masquerading as a fact.',
        ];

        $response = $this->postJson("/api/agents/{$this->agent->id}/memories", $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['origin']);
    }

    public function test_rejects_hypothesis_without_confidence(): void
    {
        $payload = [
            'type' => 'social',
            'epistemic_status' => 'hypothesis',
            'origin' => 'agent_inference',
            'content' => 'Hypothesis without uncertainty specification.',
        ];

        $response = $this->postJson("/api/agents/{$this->agent->id}/memories", $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['confidence']);
    }

    public function test_rejects_confidence_assigned_to_facts_or_dreams(): void
    {
        $payload = [
            'type' => 'episodic',
            'epistemic_status' => 'fact',
            'origin' => 'user_input',
            'content' => 'Fact with arbitrary confidence number.',
            'confidence' => 0.95,
        ];

        $response = $this->postJson("/api/agents/{$this->agent->id}/memories", $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['confidence']);
    }

    public function test_rejects_confidence_outside_normalized_range(): void
    {
        foreach ([-0.1, 1.05] as $invalidConfidence) {
            $payload = [
                'type' => 'semantic',
                'epistemic_status' => 'hypothesis',
                'origin' => 'agent_inference',
                'content' => 'Hypothesis with invalid confidence.',
                'confidence' => $invalidConfidence,
            ];

            $response = $this->postJson("/api/agents/{$this->agent->id}/memories", $payload);
            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['confidence']);
        }
    }

    public function test_rectification_versions_memory_without_in_place_mutation(): void
    {
        $initial = $this->postJson("/api/agents/{$this->agent->id}/memories", [
            'type' => 'semantic',
            'epistemic_status' => 'hypothesis',
            'origin' => 'agent_inference',
            'content' => 'Initial preliminary hypothesis.',
            'confidence' => 0.40,
        ])->json();

        $initialId = $initial['id'];

        $rectified = $this->postJson("/api/memories/{$initialId}/rectify", [
            'type' => 'semantic',
            'epistemic_status' => 'hypothesis',
            'origin' => 'agent_inference',
            'content' => 'Refined hypothesis following contradictory evidence.',
            'confidence' => 0.70,
            'evidence_ref' => 'ref-contra-1',
        ]);

        $rectified->assertStatus(201);
        $rectifiedData = $rectified->json();

        $this->assertSame($initialId, $rectifiedData['supersedes_id']);
        $this->assertNull($rectifiedData['superseded_at']);

        // Check original memory is now marked superseded
        $originalInDb = Memory::findOrFail($initialId);
        $this->assertNotNull($originalInDb->superseded_at);

        // Active index returns only the rectified version
        $listResponse = $this->getJson("/api/agents/{$this->agent->id}/memories");
        $listResponse->assertStatus(200);
        $this->assertCount(1, $listResponse->json());
        $this->assertSame($rectifiedData['id'], $listResponse->json()[0]['id']);

        // Audit index with include_superseded=true returns both
        $auditResponse = $this->getJson("/api/agents/{$this->agent->id}/memories?include_superseded=true");
        $auditResponse->assertStatus(200);
        $this->assertCount(2, $auditResponse->json());
    }

    public function test_cannot_rectify_already_superseded_memory(): void
    {
        $initial = $this->postJson("/api/agents/{$this->agent->id}/memories", [
            'type' => 'semantic',
            'epistemic_status' => 'hypothesis',
            'origin' => 'agent_inference',
            'content' => 'Version 1.',
            'confidence' => 0.5,
        ])->json();

        $this->postJson("/api/memories/{$initial['id']}/rectify", [
            'type' => 'semantic',
            'epistemic_status' => 'hypothesis',
            'origin' => 'agent_inference',
            'content' => 'Version 2.',
            'confidence' => 0.6,
        ])->assertStatus(201);

        // Second attempt to rectify Version 1 must fail
        $secondAttempt = $this->postJson("/api/memories/{$initial['id']}/rectify", [
            'type' => 'semantic',
            'epistemic_status' => 'hypothesis',
            'origin' => 'agent_inference',
            'content' => 'Version 2-conflict.',
            'confidence' => 0.7,
        ]);

        $secondAttempt->assertStatus(422);
    }

    public function test_destroy_purges_memory_content_and_leaves_tombstone(): void
    {
        $created = $this->postJson("/api/agents/{$this->agent->id}/memories", [
            'type' => 'episodic',
            'epistemic_status' => 'fact',
            'origin' => 'user_input',
            'content' => 'Private observation to be permanently purged.',
        ])->json();

        $memoryId = $created['id'];

        $deleteResponse = $this->deleteJson("/api/memories/{$memoryId}", [
            'reason' => 'User requested right to be forgotten purge',
        ]);

        $deleteResponse->assertStatus(200);

        // Memory row must be physically gone
        $this->assertDatabaseMissing('memories', ['id' => $memoryId]);

        // Tombstone must be registered with audit reference and NO text content
        $tombstone = MemoryTombstone::where('memory_id', $memoryId)->first();
        $this->assertNotNull($tombstone);
        $this->assertSame($this->agent->id, $tombstone->agent_id);
        $this->assertSame('User requested right to be forgotten purge', $tombstone->reason);
    }

    public function test_can_list_agent_tombstones(): void
    {
        $memory = $this->agent->memories()->create([
            'type' => 'episodic',
            'epistemic_status' => 'fact',
            'origin' => 'user_input',
            'content' => 'Temporary audit memory',
        ]);

        $this->deleteJson('/api/memories/' . $memory->id, [
            'reason' => 'Testing audit list',
        ]);

        $response = $this->getJson('/api/agents/' . $this->agent->id . '/tombstones');

        $response->assertStatus(200);
        $response->assertJsonCount(1);
        $response->assertJsonFragment([
            'memory_id' => $memory->id,
            'reason' => 'Testing audit list',
        ]);
    }

    public function test_database_check_constraints_prevent_direct_invalid_inserts(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        // Directly attempt to bypass API and insert fact with agent_inference in DB
        DB::table('memories')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'agent_id' => $this->agent->id,
            'type' => 'semantic',
            'epistemic_status' => 'fact',
            'origin' => 'agent_inference',
            'content' => 'Bypassing API validation directly into DB.',
            'confidence' => null,
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
