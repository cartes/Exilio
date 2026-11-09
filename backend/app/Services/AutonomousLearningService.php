<?php

namespace App\Services;

use App\Models\Agent;
use App\Http\Controllers\AgentLearningController;
use App\Models\AgentLearningVersion;
use App\Models\AiUsageLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AutonomousLearningService
{
    public function __construct(
        protected ExplorationService $explorationService,
        protected GeminiService $geminiService,
        protected IntrospectionService $introspectionService
    ) {}

    /**
     * Run a single autonomous cycle of inquiry, persistence, and versioned learning evolution.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function runStep(Agent $agent, array $options = []): array
    {
        $monthlyBudget = (float) env('AI_MONTHLY_BUDGET_USD', 5.00);
        $currentMonthSpend = (float) AiUsageLog::where('agent_id', $agent->id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('estimated_cost_usd');

        if ($currentMonthSpend >= $monthlyBudget) {
            throw new \RuntimeException("Límite presupuestario mensual alcanzado (US\${$monthlyBudget}/mes). Operación detenida por seguridad.");
        }

        // Tope de misión verificable: el gasto se calcula en el servidor a partir de ai_usage_logs
        // (por mission_id); el valor que envíe el cliente no se usa.
        $missionBudget = isset($options['mission_budget_usd']) ? (float) $options['mission_budget_usd'] : null;
        $missionId = $options['mission_id'] ?? null;
        $missionSpent = fn (): float => $missionId
            ? (float) AiUsageLog::where('agent_id', $agent->id)->where('mission_id', $missionId)->sum('estimated_cost_usd')
            : 0.0;

        if ($missionBudget !== null && $missionSpent() >= $missionBudget) {
            throw new \RuntimeException("Tope de presupuesto de la misión alcanzado (US\$" . number_format($missionBudget, 2) . "). Investigación completada dentro de tu límite.");
        }

        // 1. Run Cognitive Exploration / Inquiry using the configured real provider
        $inquiry = $this->explorationService->runInquiry(
            $agent,
            $options['topic'] ?? null,
            $options['exploratory_query'] ?? null,
            $missionId
        );

        // 1b. Una hipótesis enlatada (mock, error, presupuesto) NO es investigación:
        // no se persiste ni hace evolucionar al agente.
        if ($inquiry['is_fallback']) {
            return $this->skippedFallbackResult($agent, $inquiry, $monthlyBudget, $missionBudget, $missionSpent());
        }

        // 2. Persist the generated hypothesis into memories table
        $persistResult = $this->explorationService->persistFinding($agent, $inquiry);
        $memory = $persistResult['memory'];
        $agent = $persistResult['agent'];

        // 3. Evolución versionada. La personalidad NO se toca aquí: los rasgos solo cambian por
        // aprendizaje explícito (/learn) con evidencia; este paso solo ajusta intereses.
        $versionResult = DB::transaction(function () use ($agent, $memory, $inquiry) {
            $nextVersion = ((int) $agent->personality_version) + 1;

            $attributes = $agent->attributes ?? [];
            $interests = $attributes['interests'] ?? [];
            $confidence = (float) $inquiry['confidence'];
            $topic = $inquiry['topic'];

            if (isset($interests[$topic])) {
                $curRel = (float) ($interests[$topic]['acquired_relevance'] ?? 0.20);
                // Tope de relevancia por hipótesis (Fase 3): 0.60
                $newRel = min(AgentLearningController::MAX_RELEVANCE_HYPOTHESIS, round($curRel + 0.05, 2));
                $signals = $interests[$topic]['curiosity_signals'] ?? [
                    'novelty' => 0.5,
                    'uncertainty' => 0.5,
                    'learning' => 0.6,
                    'relevance' => $newRel,
                ];
                $signals['relevance'] = $newRel;
                // La incertidumbre baja ligeramente a medida que avanza la indagación
                $signals['uncertainty'] = max(0.2, round(($signals['uncertainty'] ?? 0.5) * 0.95, 2));
            } else {
                // Tema nuevo: relevancia derivada solo de la evidencia (regla de hipótesis de Fase 3)
                $newRel = min(AgentLearningController::MAX_RELEVANCE_HYPOTHESIS, round(0.20 * $confidence, 4));
                $signals = AgentLearningController::deriveCuriositySignals('hypothesis', $newRel, $confidence);
                $interests[$topic] = [
                    'topic' => $topic,
                    'consolidation_status' => 'tentative',
                    'source_epistemic_status' => 'hypothesis',
                    'exploratory_query' => $inquiry['next_exploratory_question'] ?? null,
                    'evidence_memory_ids' => [$memory->id],
                ];
            }

            $interests[$topic]['acquired_relevance'] = $newRel;
            $interests[$topic]['curiosity_signals'] = $signals;
            $interests[$topic]['curiosity_score'] = round(
                (0.30 * ($signals['novelty'] ?? 0.5)) +
                (0.25 * ($signals['uncertainty'] ?? 0.5)) +
                (0.30 * ($signals['learning'] ?? 0.6)) +
                (0.15 * $newRel),
                2
            );

            $attributes['interests'] = $interests;

            AgentLearningController::ensureBaselineVersion($agent);

            $summary = "Aprendizaje autónomo supervisado sobre '{$topic}' (Hipótesis con " . round($confidence * 100) . "% certeza).";

            $learningVersion = AgentLearningVersion::create([
                'id' => (string) Str::uuid(),
                'agent_id' => $agent->id,
                'version' => $nextVersion,
                'operation_type' => 'learning',
                'summary' => $summary,
                'evidence_memory_ids' => [$memory->id],
                'attributes_snapshot' => $attributes,
                'reverted_from_version' => null,
                'created_at' => now(),
            ]);

            $agent->personality_version = $nextVersion;
            $agent->attributes = $attributes;
            $agent->save();

            return [
                'next_version' => $nextVersion,
                'learning_version' => $learningVersion,
                'summary' => $summary,
            ];
        });

        // 4. Calculate fresh monthly telemetry & next step cost estimation
        $freshSpend = (float) AiUsageLog::where('agent_id', $agent->id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('estimated_cost_usd');

        $remainingBudget = max(0.0, $monthlyBudget - $freshSpend);

        // Next step cost estimate: ~1,500 tokens of gemini-2.5-flash-lite
        $nextStepEstimatedCost = 0.00018;

        $checkpointMessage = sprintf(
            "He completado este ciclo de indagación sobre '%s'. Formulé la hipótesis: \"%s\" (certeza: %d%%) y evolucioné a la versión v%d.0.\n\n• Costo de este paso: US$%s (Tokens: %d)\n• Gasto acumulado del mes: US$%s / US$%.2f (Restante: US$%s)\n• Próximo paso propuesto: Indagar \"%s\" (Costo est.: ~US$%.5f).\n\n¿Autorizas que continúe con el siguiente ciclo o paso a reposo (Idle)?",
            $inquiry['topic'],
            $inquiry['hypothesis_content'],
            round($inquiry['confidence'] * 100),
            $versionResult['next_version'],
            number_format($inquiry['telemetry']['cost_usd'], 6),
            $inquiry['telemetry']['tokens_used'],
            number_format($freshSpend, 6),
            $monthlyBudget,
            number_format($remainingBudget, 6),
            $inquiry['next_exploratory_question'],
            $nextStepEstimatedCost
        );

        return [
            'status' => 'checkpoint_ready',
            'agent' => $agent->fresh(),
            'completed_step' => [
                'topic' => $inquiry['topic'],
                'curiosity_score' => $inquiry['curiosity_score'],
                'deliberation' => $inquiry['deliberation'],
                'hypothesis_content' => $inquiry['hypothesis_content'],
                'confidence' => $inquiry['confidence'],
                'memory_id' => $memory->id,
                'memory_type' => $memory->type,
                'version_before' => (int) $versionResult['next_version'] - 1,
                'version_after' => (int) $versionResult['next_version'],
                'summary' => $versionResult['summary'],
                'cost_usd' => $inquiry['telemetry']['cost_usd'],
                'tokens_used' => $inquiry['telemetry']['tokens_used'],
                'provider' => $inquiry['telemetry']['provider'],
            ],
            'next_step_proposal' => [
                'topic' => $inquiry['topic'],
                'proposed_query' => $inquiry['next_exploratory_question'],
                'estimated_tokens' => 1500,
                'estimated_cost_usd' => $nextStepEstimatedCost,
            ],
            'budget_telemetry' => [
                'monthly_budget_usd' => $monthlyBudget,
                'current_month_spend_usd' => round($freshSpend, 6),
                'remaining_budget_usd' => round($remainingBudget, 6),
                'mission_budget_usd' => $missionBudget,
                'mission_spent_usd' => round($missionSpent(), 6),
            ],
            'checkpoint_message' => $checkpointMessage,
        ];
    }

    /**
     * @param array<string, mixed> $inquiry
     * @return array<string, mixed>
     */
    protected function skippedFallbackResult(Agent $agent, array $inquiry, float $monthlyBudget, ?float $missionBudget, float $missionSpent): array
    {
        $provider = $inquiry['telemetry']['provider'] ?? 'desconocido';
        $spend = (float) AiUsageLog::where('agent_id', $agent->id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('estimated_cost_usd');

        return [
            'status' => 'fallback_skipped',
            'agent' => $agent->fresh(),
            'reason' => $provider,
            'topic' => $inquiry['topic'],
            'budget_telemetry' => [
                'monthly_budget_usd' => $monthlyBudget,
                'current_month_spend_usd' => round($spend, 6),
                'remaining_budget_usd' => round(max(0.0, $monthlyBudget - $spend), 6),
                'mission_budget_usd' => $missionBudget,
                'mission_spent_usd' => round($missionSpent, 6),
            ],
            'checkpoint_message' => "No hubo una respuesta real del modelo (proveedor: {$provider}). "
                . 'No se guardó ninguna hipótesis ni cambió la versión: un texto local de respaldo no cuenta como aprendizaje.',
        ];
    }
}

