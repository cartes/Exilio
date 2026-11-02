<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Memory;
use App\Models\MemoryTombstone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MemoryController
{
    public function index(Request $request, Agent $agent): JsonResponse
    {
        $query = $agent->memories();

        if (!$request->boolean('include_superseded', false)) {
            $query->active();
        }

        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }

        if ($request->filled('epistemic_status')) {
            $query->where('epistemic_status', $request->query('epistemic_status'));
        }

        $memories = $query->orderBy('created_at', 'desc')->get();

        return response()->json($memories);
    }

    public function tombstones(Agent $agent): JsonResponse
    {
        $tombstones = $agent->tombstones()->orderBy('created_at', 'desc')->get();

        return response()->json($tombstones);
    }

    public function show(Memory $memory): JsonResponse
    {
        return response()->json($memory);
    }

    public function store(Request $request, Agent $agent): JsonResponse
    {
        $validated = $this->validateMemoryPayload($request);

        $memory = $agent->memories()->create($validated);

        return response()->json($memory->fresh(), 201);
    }

    public function rectify(Request $request, Memory $memory): JsonResponse
    {
        if ($memory->superseded_at !== null) {
            throw ValidationException::withMessages([
                'memory' => ['Cannot rectify an already superseded memory.'],
            ]);
        }

        $validated = $this->validateMemoryPayload($request);
        $validated['supersedes_id'] = $memory->id;

        $newMemory = DB::transaction(function () use ($memory, $validated) {
            $memory->update(['superseded_at' => now()]);

            return $memory->agent->memories()->create($validated);
        });

        return response()->json($newMemory->fresh(), 201);
    }

    public function destroy(Request $request, Memory $memory): JsonResponse
    {
        $agentId = $memory->agent_id;
        $memoryId = $memory->id;
        $reason = $request->input('reason', 'Explicit purge');

        DB::transaction(function () use ($memory, $agentId, $memoryId, $reason) {
            MemoryTombstone::create([
                'memory_id' => $memoryId,
                'agent_id' => $agentId,
                'reason' => $reason,
                'created_at' => now(),
            ]);

            $memory->delete();
        });

        return response()->json([
            'message' => 'Memory permanently purged.',
            'memory_id' => $memoryId,
            'agent_id' => $agentId,
        ]);
    }

    private function validateMemoryPayload(Request $request): array
    {
        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(Memory::TYPES)],
            'epistemic_status' => ['required', 'string', Rule::in(Memory::EPISTEMIC_STATUSES)],
            'origin' => ['required', 'string', Rule::in(Memory::ORIGINS)],
            'content' => ['required', 'string'],
            'confidence' => ['nullable', 'numeric', 'min:0.0', 'max:1.0'],
            'evidence_ref' => ['nullable', 'string', 'max:255'],
            'occurred_at' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
        ]);

        $type = $validated['type'];
        $status = $validated['epistemic_status'];
        $origin = $validated['origin'];
        $confidence = $validated['confidence'] ?? null;

        $errors = [];

        // 1. Oneiric rules
        if ($type === 'oneiric' && $status !== 'dream') {
            $errors['epistemic_status'][] = 'Oneiric memories must have epistemic_status = dream.';
        }

        if ($type !== 'oneiric' && $status === 'dream') {
            $errors['type'][] = 'Only oneiric memories can have epistemic_status = dream.';
        }

        // 2. Fact origin rule
        if ($status === 'fact' && $origin === 'agent_inference') {
            $errors['origin'][] = 'Facts require an observable origin; agent inferences must be recorded as hypotheses.';
        }

        // 3. Confidence rule
        if ($status === 'hypothesis' && $confidence === null) {
            $errors['confidence'][] = 'Hypotheses must specify a confidence value in [0, 1].';
        }

        if ($status !== 'hypothesis' && $confidence !== null) {
            $errors['confidence'][] = 'Confidence can only be assigned to hypotheses.';
        }

        if (!empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        $validated['metadata'] = $validated['metadata'] ?? [];

        return $validated;
    }
}