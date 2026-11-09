<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Services\ExplorationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentExplorationController
{
    /**
     * Run an on-demand cognitive inquiry cycle driven by curiosity C.
     */
    public function inquire(
        Request $request,
        Agent $agent,
        ExplorationService $explorationService
    ): JsonResponse {
        $validated = $request->validate([
            'topic' => ['nullable', 'string', 'max:255'],
        ]);

        $report = $explorationService->runInquiry($agent, $validated['topic'] ?? null);

        return response()->json([
            'status' => 'success',
            'inquiry_report' => $report,
        ]);
    }

    /**
     * Persist an approved inquiry finding as a hypothesis memory.
     */
    public function persist(
        Request $request,
        Agent $agent,
        ExplorationService $explorationService
    ): JsonResponse {
        $validated = $request->validate([
            'topic' => ['required', 'string', 'max:255'],
            'hypothesis_content' => ['required', 'string', 'max:2000'],
            'confidence' => ['required', 'numeric', 'between:0.05,0.95'],
            'taxonomic_type' => ['required', 'string', 'in:episodic,semantic,social,procedural'],
            'next_exploratory_question' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $result = $explorationService->persistFinding($agent, $validated);

            return response()->json([
                'status' => 'success',
                'message' => 'Hipótesis de indagación persistida en la memoria autobiográfica de EXILIO-001.',
                'memory' => $result['memory'],
                'agent' => $result['agent'],
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
