<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use Illuminate\Http\JsonResponse;

class AgentController
{
    public function index(): JsonResponse
    {
        return response()->json(Agent::query()->orderBy('name')->get());
    }

    public function show(Agent $agent): JsonResponse
    {
        return response()->json($agent);
    }
}
