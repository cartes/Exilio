<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\ResearchFinding;
use App\Models\ResearchScout;
use App\Services\Research\ResearchScoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentResearchController
{
    public function index(Agent $agent): JsonResponse
    {
        $scouts = $agent->researchScouts()
            ->withCount([
                'documents',
                'findings',
                'findings as pending_findings_count' => fn ($q) => $q->where('status', 'pending'),
            ])
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        return response()->json($scouts);
    }

    public function show(ResearchScout $scout): JsonResponse
    {
        return response()->json([
            'scout' => $scout,
            'documents' => $scout->documents()->orderBy('fetched_at')->get(['id', 'url', 'title', 'http_status', 'fetched_at']),
            'findings' => $scout->findings()->with('document:id,url,title')->orderByDesc('relevance')->get(),
        ]);
    }

    /** EXILIO-001 propone una misión (sin IA, a partir de su mayor curiosidad). */
    public function propose(Agent $agent, ResearchScoutService $service): JsonResponse
    {
        return $this->guarded(fn () => response()->json(['scout' => $service->propose($agent)], 201));
    }

    /** El auditor crea una misión propia (queda aprobada, pero no se ejecuta hasta lanzarla). */
    public function store(Request $request, Agent $agent, ResearchScoutService $service): JsonResponse
    {
        $v = $request->validate([
            'topic' => ['required', 'string', 'max:120'],
            'query' => ['nullable', 'string', 'max:500'],
            'max_pages' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $scout = $service->createForAuditor($agent, $v['topic'], $v['query'] ?? '', (int) ($v['max_pages'] ?? 5));

        return response()->json(['scout' => $scout], 201);
    }

    public function approve(Request $request, ResearchScout $scout, ResearchScoutService $service): JsonResponse
    {
        $v = $request->validate(['max_pages' => ['nullable', 'integer', 'min:1', 'max:20']]);

        return $this->guarded(fn () => response()->json(['scout' => $service->approve($scout, $v['max_pages'] ?? null)]));
    }

    public function reject(ResearchScout $scout, ResearchScoutService $service): JsonResponse
    {
        return $this->guarded(fn () => response()->json(['scout' => $service->reject($scout)]));
    }

    public function cancel(ResearchScout $scout, ResearchScoutService $service): JsonResponse
    {
        return $this->guarded(fn () => response()->json(['scout' => $service->cancel($scout)]));
    }

    /**
     * Lanza una misión aprobada. Responde de inmediato (202) y ejecuta tras enviar la respuesta;
     * el estado se consulta con GET. Una sola ejecución por misión (reclamo atómico).
     */
    public function run(ResearchScout $scout, ResearchScoutService $service): JsonResponse
    {
        if (!$service->claim($scout)) {
            return response()->json([
                'message' => "La misión no está lista para lanzarse (estado: {$scout->fresh()->status}).",
            ], 409);
        }

        $id = $scout->id;
        dispatch(function () use ($id): void {
            $scout = ResearchScout::find($id);
            // Idempotente: si ya terminó o el auditor la canceló, no se vuelve a ejecutar.
            if ($scout && $scout->status === 'running') {
                app(ResearchScoutService::class)->execute($scout);
            }
        })->afterResponse();

        return response()->json(['scout' => $scout->fresh()], 202);
    }

    public function approveFinding(ResearchFinding $finding, ResearchScoutService $service): JsonResponse
    {
        return $this->guarded(function () use ($finding, $service) {
            $memory = $service->approveFinding($finding);

            return response()->json([
                'status' => 'success',
                'message' => 'Hallazgo registrado como hecho observable: la fuente lo afirma (no implica que sea cierto).',
                'memory' => $memory,
                'finding' => $finding->fresh(),
            ], 201);
        });
    }

    public function rejectFinding(ResearchFinding $finding, ResearchScoutService $service): JsonResponse
    {
        return $this->guarded(fn () => response()->json(['finding' => $service->rejectFinding($finding)]));
    }

    private function guarded(\Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
