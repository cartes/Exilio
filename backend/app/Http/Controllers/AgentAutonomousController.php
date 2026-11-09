<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Services\AutonomousLearningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentAutonomousController
{
    /**
     * Run a single autonomous cycle of inquiry and versioned learning.
     * Returns the checkpoint with cost analysis and prompt for continuation.
     */
    public function step(
        Request $request,
        Agent $agent,
        AutonomousLearningService $autonomousService
    ): JsonResponse {
        $validated = $request->validate([
            'topic' => ['nullable', 'string', 'max:255'],
            'exploratory_query' => ['nullable', 'string', 'max:1000'],
            'mission_budget_usd' => ['nullable', 'numeric', 'min:0.0001', 'max:5.00'],
            // El gasto de la misión lo calcula el servidor por mission_id; mission_spent_usd ya no se acepta.
            'mission_id' => ['nullable', 'uuid', 'required_with:mission_budget_usd'],
        ]);

        try {
            $checkpoint = $autonomousService->runStep($agent, $validated);

            return response()->json([
                'status' => 'success',
                'checkpoint' => $checkpoint,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'status' => 'budget_limit_exceeded',
                'message' => $e->getMessage(),
            ], 429);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al procesar ciclo autónomo: ' . $e->getMessage(),
            ], 500);
        }
    }
}

