<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AiUsageLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    public const DEFAULT_MODEL = 'gemini-2.5-flash-lite';

    // Pricing per token (approx Gemini 2.5 Flash: $0.075 / 1M prompt, $0.30 / 1M output)
    public const PROMPT_TOKEN_COST_USD = 0.000000075;
    public const COMPLETION_TOKEN_COST_USD = 0.00000030;

    /**
     * Generate grounded conversational response enforcing budget policy and telemetry.
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
        $apiKey = env('GEMINI_API_KEY');

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

        // 2. If provider is mock or no API key is set, use deterministic introspective generator
        if ($providerConfig !== 'gemini' || empty($apiKey)) {
            $log = AiUsageLog::create([
                'agent_id' => $agent->id,
                'provider' => 'mock',
                'model' => 'deterministic-introspection',
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
                'estimated_cost_usd' => 0.0,
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

        // 3. Call Gemini API
        $model = env('GEMINI_MODEL', self::DEFAULT_MODEL);
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        try {
            $payload = [
                'system_instruction' => [
                    'parts' => [
                        ['text' => $systemInstruction],
                    ],
                ],
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            ['text' => $userMessage],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.5,
                    'maxOutputTokens' => 800,
                ],
            ];

            $response = Http::timeout(20)->post($url, $payload);

            // If rate-limited (429) or temporarily overloaded (503), wait 2s and retry once
            if (in_array($response->status(), [429, 503], true) && app()->environment() !== 'testing') {
                sleep(2);
                $response = Http::timeout(20)->post($url, $payload);
            }

            if (!$response->successful()) {
                $errorData = $response->json('error') ?? [];
                $errorMsg = $errorData['message'] ?? "HTTP {$response->status()}";
                Log::warning('Gemini API call returned non-200', [
                    'status' => $response->status(),
                    'error' => $errorData,
                    'body' => $response->body(),
                ]);

                return [
                    'content' => "[Aviso Gemini API ({$response->status()}): {$errorMsg}] " . $fallbackResponse,
                    'provider' => 'gemini_fallback',
                    'model' => $model,
                    'tokens_used' => 0,
                    'cost_usd' => 0.0,
                    'current_month_spend_usd' => $currentMonthSpend,
                    'monthly_budget_usd' => $monthlyBudget,
                    'budget_exceeded' => false,
                ];
            }

            $data = $response->json();
            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? $fallbackResponse;

            $usageMetadata = $data['usageMetadata'] ?? [];
            $promptTokens = (int) ($usageMetadata['promptTokenCount'] ?? 0);
            $completionTokens = (int) ($usageMetadata['candidatesTokenCount'] ?? 0);
            $totalTokens = (int) ($usageMetadata['totalTokenCount'] ?? ($promptTokens + $completionTokens));

            $cost = round(
                ($promptTokens * self::PROMPT_TOKEN_COST_USD) + ($completionTokens * self::COMPLETION_TOKEN_COST_USD),
                6
            );

            AiUsageLog::create([
                'agent_id' => $agent->id,
                'provider' => 'gemini',
                'model' => $model,
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $totalTokens,
                'estimated_cost_usd' => $cost,
                'created_at' => now(),
            ]);

            return [
                'content' => trim($text),
                'provider' => 'gemini',
                'model' => $model,
                'tokens_used' => $totalTokens,
                'cost_usd' => $cost,
                'current_month_spend_usd' => $currentMonthSpend + $cost,
                'monthly_budget_usd' => $monthlyBudget,
                'budget_exceeded' => false,
            ];
        } catch (\Throwable $e) {
            Log::error('Exception connecting to Gemini API', ['error' => $e->getMessage()]);

            return [
                'content' => "[Conexión local a Gemini no disponible] " . $fallbackResponse,
                'provider' => 'gemini_offline_fallback',
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

