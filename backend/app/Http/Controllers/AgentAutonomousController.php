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

