<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\AgentChatMessage;
use App\Models\AiUsageLog;
use App\Services\ExplorationService;
use App\Services\GeminiService;
use App\Services\IntrospectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AgentChatController
{
    public function index(Agent $agent): JsonResponse
    {
        $messages = $agent->chatMessages()
            ->orderBy('created_at', 'asc')
            ->limit(50)
            ->get();

        $monthlyBudget = (float) env('AI_MONTHLY_BUDGET_USD', 5.00);
        $currentMonthSpend = (float) $agent->aiUsageLogs()
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('estimated_cost_usd');

        return response()->json([
            'messages' => $messages,
            'telemetry' => [
                'current_month_spend_usd' => round($currentMonthSpend, 6),
                'monthly_budget_usd' => $monthlyBudget,
                'remaining_budget_usd' => round(max(0.0, $monthlyBudget - $currentMonthSpend), 6),
                'active_provider' => env('AI_PROVIDER', 'mock'),
            ],
        ]);
    }

    public function chat(
        Request $request,
        Agent $agent,
        IntrospectionService $introspectionService,
        GeminiService $geminiService,
        ExplorationService $explorationService
    ): JsonResponse {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
        ]);

        $userContent = trim($validated['message']);

        return DB::transaction(function () use ($agent, $userContent, $introspectionService, $geminiService, $explorationService) {
            // 1. Record user message as an episodic fact memory
            $userMemory = $agent->memories()->create([
                'type' => 'episodic',
                'epistemic_status' => 'fact',
                'origin' => 'user_input',
                'content' => "El interlocutor dijo en el diálogo: \"{$userContent}\"",
                'evidence_ref' => 'chat-console',
                'metadata' => ['channel' => 'interactive_dialogue'],
            ]);

            // 1b. If user asks to explore/investigate or asks about an interest topic, sync it to curiosity radar
            $explorationService->syncInterestFromUserPrompt($agent, $userContent, $userMemory->id);

            // 2. Record in agent_chat_messages
            $userChatMessage = $agent->chatMessages()->create([
                'role' => 'user',
                'content' => $userContent,
                'provider' => null,
                'grounding_snapshot' => [],
                'tokens_used' => 0,
                'cost_usd' => 0.0,
                'created_at' => now(),
            ]);

            // 3. Build grounded introspection context from verified agent state
            $context = $introspectionService->buildContext($agent, $userContent);

            // 4. Generate response through Gemini (with spending gate and fallback)
            $response = $geminiService->generateResponse(
                $agent,
                $userContent,
                $context['system_instruction'],
                $context['local_fallback_response'],
                $context['grounding_data']
            );

            // 5. Record agent's response in chat messages
            $agentChatMessage = $agent->chatMessages()->create([
                'role' => 'agent',
                'content' => $response['content'],
                'provider' => $response['provider'],
                'grounding_snapshot' => $context['grounding_data'],
                'tokens_used' => $response['tokens_used'],
                'cost_usd' => $response['cost_usd'],
                'created_at' => now(),
            ]);

            return response()->json([
                'user_message' => $userChatMessage,
                'agent_message' => $agentChatMessage,
                'grounding' => $context['grounding_data'],
                'telemetry' => [
                    'provider' => $response['provider'],
                    'model' => $response['model'],
                    'tokens_used' => $response['tokens_used'],
                    'cost_usd' => $response['cost_usd'],
                    'current_month_spend_usd' => $response['current_month_spend_usd'],
                    'monthly_budget_usd' => $response['monthly_budget_usd'],
                    'budget_exceeded' => $response['budget_exceeded'],
                ],
            ], 201);
        });
    }

    public function usage(Agent $agent): JsonResponse
    {
        $monthlyBudget = (float) env('AI_MONTHLY_BUDGET_USD', 5.00);

        $currentMonthLogs = $agent->aiUsageLogs()
            ->where('created_at', '>=', now()->startOfMonth())
            ->orderBy('created_at', 'desc')
            ->get();

        $totalSpend = (float) $currentMonthLogs->sum('estimated_cost_usd');
        $totalTokens = (int) $currentMonthLogs->sum('total_tokens');
        $totalCalls = $currentMonthLogs->count();

        return response()->json([
            'monthly_budget_usd' => $monthlyBudget,
            'current_month_spend_usd' => round($totalSpend, 6),
            'remaining_budget_usd' => round(max(0.0, $monthlyBudget - $totalSpend), 6),
            'total_calls' => $totalCalls,
            'total_tokens' => $totalTokens,
            'recent_logs' => $currentMonthLogs->take(10),
        ]);
    }
}

