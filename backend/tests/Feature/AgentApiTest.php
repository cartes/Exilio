<?php

namespace Tests\Feature;

use App\Models\Agent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_list_agents_including_founder(): void
    {
        $response = $this->getJson('/api/agents');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            '*' => [
                'id',
                'name',
                'status',
                'personality_version',
                'attributes',
                'created_at',
                'updated_at',
            ],
        ]);

        $agents = $response->json();
        $this->assertNotEmpty($agents);

        $founder = collect($agents)->firstWhere('name', 'EXILIO-001');
        $this->assertNotNull($founder, 'Founder agent EXILIO-001 must exist');
        $this->assertSame('active', $founder['status']);
        $this->assertSame(1, $founder['personality_version']);

        // Phase 0 invariant: personality and autobiographical memory must be empty
        $this->assertIsArray($founder['attributes']);
        $this->assertSame([], $founder['attributes']['personality']);
        $this->assertSame([], $founder['attributes']['autobiographical_memory']);
    }

    public function test_can_get_founder_agent_by_id(): void
    {
        $founder = Agent::where('name', 'EXILIO-001')->firstOrFail();

        $response = $this->getJson('/api/agents/' . $founder->id);

        $response->assertStatus(200);
        $response->assertJson([
            'id' => $founder->id,
            'name' => 'EXILIO-001',
            'status' => 'active',
            'personality_version' => 1,
            'attributes' => [
                'personality' => [],
                'autobiographical_memory' => [],
            ],
        ]);
    }

    public function test_returns_404_for_unknown_agent(): void
    {
        $response = $this->getJson('/api/agents/00000000-0000-0000-0000-000000000000');

        $response->assertStatus(404);
    }
}

