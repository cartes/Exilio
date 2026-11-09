<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Services\DreamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentDreamController
{
    /**
     * Propone un sueño a partir de la experiencia vigente. No persiste nada.
     */
    public function dream(Agent $agent, DreamService $dreamService): JsonResponse
    {
        try {
            $report = $dreamService->dream($agent);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'status' => 'success',
            'dream_report' => $report,
        ]);
    }

    /**
     * Persiste un sueño aprobado explícitamente por el auditor.
     */
    public function persist(Request $request, Agent $agent, DreamService $dreamService): JsonResponse
    {
        $validated = $request->validate([
            'narrative' => ['required', 'string', 'max:2000'],
            'exploratory_topic' => ['required', 'string', 'max:120'],
            'exploratory_query' => ['nullable', 'string', 'max:255'],
            'source_memory_ids' => ['required', 'array', 'min:1', 'max:' . DreamService::MAX_SOURCES],
            'source_memory_ids.*' => ['required', 'string', 'uuid'],
        ]);

        try {
            $result = $dreamService->persistDream($agent, $validated);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Sueño registrado como simulación (dream). No consolida hechos ni rasgos.',
            'memory' => $result['memory'],
            'agent' => $result['agent'],
        ], 201);
    }
}
