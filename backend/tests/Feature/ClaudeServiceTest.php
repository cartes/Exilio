<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AiUsageLog;
use App\Services\ClaudeService;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClaudeServiceTest extends TestCase
{
    use RefreshDatabase;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = Agent::where('name', 'EXILIO-001')->firstOrFail();
    }

    public function test_claude_service_returns_fallback_when_api_key_empty(): void
    {
        putenv('ANTHROPIC_API_KEY=');
        putenv('AI_PROVIDER=claude');

        $service = app(ClaudeService::class);
        $res = $service->generateResponse(
            $this->agent,
            'Hola',
            'Eres un asistente',
            'Fallback local'
        );

        $this->assertSame('Fallback local', $res['content']);
        $this->assertSame('mock', $res['provider']);
        $this->assertSame(0, $res['tokens_used']);
    }

    public function test_claude_service_enforces_budget_gate(): void
    {
        putenv('AI_MONTHLY_BUDGET_USD=5.00');

        AiUsageLog::create([
            'agent_id' => $this->agent->id,
            'provider' => 'claude',
            'model' => 'claude-haiku-5-5',
            'prompt_tokens' => 50000000,
            'completion_tokens' => 10000000,
            'total_tokens' => 60000000,
            'estimated_cost_usd' => 5.20,
            'created_at' => now(),
        ]);

        $service = app(ClaudeService::class);
        $res = $service->generateResponse(
            $this->agent,
            'Hola',
            'System instructions',
            'Fallback local'
        );

        $this->assertTrue($res['budget_exceeded']);
        $this->assertStringContainsString('LÍMITE PRESUPUESTARIO ALCANZADO', $res['content']);
    }

    public function test_claude_service_calls_anthropic_api_and_logs_usage(): void
    {
        $_ENV['AI_PROVIDER'] = 'claude';
        $_SERVER['AI_PROVIDER'] = 'claude';
        $_ENV['ANTHROPIC_API_KEY'] = 'sk-ant-test-key-12345';
        $_SERVER['ANTHROPIC_API_KEY'] = 'sk-ant-test-key-12345';
        $_ENV['ANTHROPIC_MODEL'] = 'claude-haiku-5-5';
        $_SERVER['ANTHROPIC_MODEL'] = 'claude-haiku-5-5';

        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'id' => 'msg_123',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Hola Cristian, he analizado nuevas opciones de ingresos prácticos.',
                    ],
                ],
                'model' => 'claude-haiku-5-5',
                'usage' => [
                    'input_tokens' => 150,
                    'output_tokens' => 50,
                ],
            ], 200),
        ]);

        $service = app(ClaudeService::class);
        $res = $service->generateResponse(
            $this->agent,
            '¿Qué opciones de ingresos prácticos existen?',
            'Eres EXILIO-001.',
            'Fallback local'
        );

        $this->assertSame('claude', $res['provider']);
        $this->assertSame('claude-haiku-5-5', $res['model']);
        $this->assertSame(200, $res['tokens_used']);
        $this->assertGreaterThan(0.0, $res['cost_usd']);
        $this->assertStringContainsString('he analizado nuevas opciones', $res['content']);

        $this->assertDatabaseHas('ai_usage_logs', [
            'agent_id' => $this->agent->id,
            'provider' => 'claude',
            'model' => 'claude-haiku-5-5',
            'prompt_tokens' => 150,
            'completion_tokens' => 50,
        ]);
    }

    public function test_gemini_service_delegates_to_claude_when_ai_provider_is_claude(): void
    {
        $_ENV['AI_PROVIDER'] = 'claude';
        $_SERVER['AI_PROVIDER'] = 'claude';
        $_ENV['ANTHROPIC_API_KEY'] = 'sk-ant-test-key-12345';
        $_SERVER['ANTHROPIC_API_KEY'] = 'sk-ant-test-key-12345';
        $_ENV['ANTHROPIC_MODEL'] = 'claude-haiku-5-5';
        $_SERVER['ANTHROPIC_MODEL'] = 'claude-haiku-5-5';

        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'id' => 'msg_delegated',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Respuesta delegada desde ClaudeService.',
                    ],
                ],
                'model' => 'claude-haiku-5-5',
                'usage' => [
                    'input_tokens' => 100,
                    'output_tokens' => 40,
                ],
            ], 200),
        ]);

        $geminiService = app(GeminiService::class);
        $res = $geminiService->generateResponse(
            $this->agent,
            'Mensaje de prueba',
            'System instructions',
            'Fallback local'
        );

        $this->assertSame('claude', $res['provider']);
        $this->assertSame('claude-haiku-5-5', $res['model']);
        $this->assertSame('Respuesta delegada desde ClaudeService.', $res['content']);
    }
}
