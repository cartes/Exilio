<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AiUsageLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgentChatApiTest extends TestCase
{
    use RefreshDatabase;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = Agent::where('name', 'EXILIO-001')->firstOrFail();
    }

    public function test_can_list_empty_chat_messages_and_telemetry(): void
    {
        $response = $this->getJson("/api/agents/{$this->agent->id}/chat");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'messages',
            'telemetry' => [
                'current_month_spend_usd',
                'monthly_budget_usd',
                'remaining_budget_usd',
                'active_provider',
            ],
        ]);
        $this->assertSame(0, count($response->json('messages')));
        $this->assertEquals(5.00, $response->json('telemetry.monthly_budget_usd'));
    }

    public function test_chat_creates_episodic_memory_and_introspective_response(): void
    {
        $response = $this->postJson("/api/agents/{$this->agent->id}/chat", [
            'message' => '¿Cuál es tu estado actual y qué rasgos tienes?',
        ]);

        $response->assertStatus(201);
        $data = $response->json();

        // 1. Checks user message saved in chat history
        $this->assertSame('user', $data['user_message']['role']);
        $this->assertSame('¿Cuál es tu estado actual y qué rasgos tienes?', $data['user_message']['content']);

        // 2. Checks agent message generated
        $this->assertSame('agent', $data['agent_message']['role']);
        $this->assertNotEmpty($data['agent_message']['content']);

        // 3. Verifies user message was persisted as an episodic fact memory
        $this->assertDatabaseHas('memories', [
            'agent_id' => $this->agent->id,
            'type' => 'episodic',
            'epistemic_status' => 'fact',
            'origin' => 'user_input',
            'evidence_ref' => 'chat-console',
        ]);

        // 4. Verifies grounding data in response
        $this->assertArrayHasKey('personality_version', $data['grounding']);
        $this->assertSame(1, $data['grounding']['personality_version']);
    }

    public function test_budget_gate_enforces_monthly_limit_fallback(): void
    {
        // Simulate existing usage exceeding the $5.00 USD monthly budget
        AiUsageLog::create([
            'agent_id' => $this->agent->id,
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'prompt_tokens' => 50000000,
            'completion_tokens' => 10000000,
            'total_tokens' => 60000000,
            'estimated_cost_usd' => 5.10,
            'created_at' => now(),
        ]);

        $response = $this->postJson("/api/agents/{$this->agent->id}/chat", [
            'message' => 'Test message over budget',
        ]);

        $response->assertStatus(201);
        $this->assertTrue($response->json('telemetry.budget_exceeded'));
        $this->assertStringContainsString('LÍMITE PRESUPUESTARIO ALCANZADO', $response->json('agent_message.content'));
    }

    public function test_can_query_ai_usage_telemetry(): void
    {
        AiUsageLog::create([
            'agent_id' => $this->agent->id,
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'prompt_tokens' => 1000,
            'completion_tokens' => 200,
            'total_tokens' => 1200,
            'estimated_cost_usd' => 0.000135,
            'created_at' => now(),
        ]);

        $response = $this->getJson("/api/agents/{$this->agent->id}/ai-usage");

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('total_calls'));
        $this->assertSame(1200, $response->json('total_tokens'));
        $this->assertGreaterThan(0.0, $response->json('current_month_spend_usd'));
    }

    public function test_chat_syncs_exploratory_interest_from_user_question(): void
    {
        $this->agent->attributes = [
            'personality' => [],
            'interests' => [
                'Obtener dinero' => [
                    'topic' => 'Obtener dinero',
                    'acquired_relevance' => 0.25,
                    'curiosity_score' => 0.43,
                    'consolidation_status' => 'consolidated',
                    'source_epistemic_status' => 'fact',
                    'exploratory_query' => '¿Qué tipos de hardware de minería ofrecen mejor hashrate?',
                    'evidence_memory_ids' => [],
                ],
            ],
        ];
        $this->agent->save();

        $response = $this->postJson("/api/agents/{$this->agent->id}/chat", [
            'message' => 'Puedes indagar de otras formas de obtener dinero??',
        ]);

        $response->assertStatus(201);

        $freshAgent = $this->agent->fresh();
        $updatedInterest = $freshAgent->attributes['interests']['Obtener dinero'];
        $this->assertSame('Puedes indagar de otras formas de obtener dinero??', $updatedInterest['exploratory_query']);
        $this->assertGreaterThan(0.65, $updatedInterest['curiosity_score']);
    }
}

