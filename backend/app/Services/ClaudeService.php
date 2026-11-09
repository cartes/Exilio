<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AiUsageLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ClaudeService
{
    public const DEFAULT_MODEL = 'claude-haiku-5-5';

    // Pricing per token for Haiku 5.5 ($0.10 / 1M prompt, $0.50 / 1M output)
    public const PROMPT_TOKEN_COST_USD = 0.00000010;
    public const COMPLETION_TOKEN_COST_USD = 0.00000050;

    /**
     * Generate grounded conversational response enforcing budget policy and telemetry via Anthropic Claude API.
     *
     * @param  array<string, mixed>  $groundingData
     * @return array{
     *   content: string,
     *   provider: string,
     *   model: string,
     *   tokens_used: int,
     *   cost_usd: float,
     *   current_month_spend_usd: float,
     *   monthly_budget_usd: float,
     *   budget_exceeded: bool
     * }
     */
    public function generateResponse(
        Agent $agent,
        string $userMessage,
        string $systemInstruction,
        string $fallbackResponse,
        array $groundingData = []
    ): array {
        $monthlyBudget = (float) env('AI_MONTHLY_BUDGET_USD', 5.00);
        $providerConfig = env('AI_PROVIDER', 'mock');
        $apiKey = env('ANTHROPIC_API_KEY');

        $currentMonthSpend = (float) AiUsageLog::where('agent_id', $agent->id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('estimated_cost_usd');

        // 1. Budget Gate Check
        if ($currentMonthSpend >= $monthlyBudget) {
            $budgetMsg = "[LÍMITE PRESUPUESTARIO ALCANZADO: US\${$monthlyBudget}/mes] " . $fallbackResponse;
            return [
                'content' => $budgetMsg,
                'provider' => 'mock_budget_gate',
                'model' => 'none',
                'tokens_used' => 0,
                'cost_usd' => 0.0,
                'current_month_spend_usd' => $currentMonthSpend,
                'monthly_budget_usd' => $monthlyBudget,
                'budget_exceeded' => true,
            ];
        }

        // 2. If no Anthropic API key is set or mock mode, return deterministic fallback
        if (empty($apiKey) || $providerConfig === 'mock') {
            AiUsageLog::create([
                'agent_id' => $agent->id,
                'provider' => 'mock',
                'model' => 'deterministic-introspection',
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
                'estimated_cost_usd' => 0.0,
                'mission_id' => $groundingData['mission_id'] ?? null,
                'created_at' => now(),
            ]);

            return [
                'content' => $fallbackResponse,
                'provider' => 'mock',
                'model' => 'deterministic-introspection',
                'tokens_used' => 0,
                'cost_usd' => 0.0,
                'current_month_spend_usd' => $currentMonthSpend,
                'monthly_budget_usd' => $monthlyBudget,
                'budget_exceeded' => false,
            ];
        }

        // 3. Call Anthropic Messages API
        $model = env('ANTHROPIC_MODEL', self::DEFAULT_MODEL);
        $url = 'https://api.anthropic.com/v1/messages';

        $payload = [
            'model' => $model,
            'max_tokens' => 800,
            'system' => $systemInstruction,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $userMessage,
                ],
            ],
        ];

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout(20)->post($url, $payload);

            // If rate-limited or overloaded, retry once
            if (in_array($response->status(), [429, 529, 503], true) && app()->environment() !== 'testing') {
                sleep(2);
                $response = Http::withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])->timeout(20)->post($url, $payload);
            }

            if (!$response->successful()) {
                $errorData = $response->json('error') ?? [];
                $errorMsg = $errorData['message'] ?? "HTTP {$response->status()}";
                Log::warning('Anthropic API call returned non-200', [
                    'status' => $response->status(),
                    'error' => $errorData,
                    'body' => $response->body(),
                ]);

                return [
                    'content' => "[Aviso Anthropic Claude API ({$response->status()}): {$errorMsg}] " . $fallbackResponse,
                    'provider' => 'claude_fallback',
                    'model' => $model,
                    'tokens_used' => 0,
                    'cost_usd' => 0.0,
                    'current_month_spend_usd' => $currentMonthSpend,
                    'monthly_budget_usd' => $monthlyBudget,
                    'budget_exceeded' => false,
                ];
            }

            $data = $response->json();
            $text = $data['content'][0]['text'] ?? $fallbackResponse;

            $usage = $data['usage'] ?? [];
            $promptTokens = (int) ($usage['input_tokens'] ?? 0);
            $completionTokens = (int) ($usage['output_tokens'] ?? 0);
            $totalTokens = $promptTokens + $completionTokens;

            $cost = round(
                ($promptTokens * self::PROMPT_TOKEN_COST_USD) + ($completionTokens * self::COMPLETION_TOKEN_COST_USD),
                6
            );

            AiUsageLog::create([
                'agent_id' => $agent->id,
                'provider' => 'claude',
                'model' => $model,
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $totalTokens,
                'estimated_cost_usd' => $cost,
                'mission_id' => $groundingData['mission_id'] ?? null,
                'created_at' => now(),
            ]);

            return [
                'content' => trim($text),
                'provider' => 'claude',
                'model' => $model,
                'tokens_used' => $totalTokens,
                'cost_usd' => $cost,
                'current_month_spend_usd' => $currentMonthSpend + $cost,
                'monthly_budget_usd' => $monthlyBudget,
                'budget_exceeded' => false,
                'refusal_reason' => ($data['stop_reason'] ?? null) === 'refusal' ? 'refusal' : null,
            ];
        } catch (\Throwable $e) {
            Log::error('Exception connecting to Anthropic Claude API', ['error' => $e->getMessage()]);

            return [
                'content' => "[Conexión a Anthropic Claude no disponible] " . $fallbackResponse,
                'provider' => 'claude_offline_fallback',
                'model' => $model,
                'tokens_used' => 0,
                'cost_usd' => 0.0,
                'current_month_spend_usd' => $currentMonthSpend,
                'monthly_budget_usd' => $monthlyBudget,
                'budget_exceeded' => false,
            ];
        }
    }
}

