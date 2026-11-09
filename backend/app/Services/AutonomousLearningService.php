<?php

namespace App\Services;

use App\Models\Agent;
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

        // Enforce mission budget cap if specified by the user (e.g., $2.00 USD in "Sal a investigar")
        $missionBudget = isset($options['mission_budget_usd']) ? (float) $options['mission_budget_usd'] : null;
        $missionSpentBefore = isset($options['mission_spent_usd']) ? (float) $options['mission_spent_usd'] : 0.0;

        if ($missionBudget !== null && $missionSpentBefore >= $missionBudget) {
            throw new \RuntimeException("Tope de presupuesto de la misión alcanzado (US\$" . number_format($missionBudget, 2) . "). Investigación completada dentro de tu límite.");
        }

        // 1. Run Cognitive Exploration / Inquiry using Gemini
        $inquiry = $this->explorationService->runInquiry(
            $agent,
            $options['topic'] ?? null,
            $options['exploratory_query'] ?? null
        );

        // 2. Persist the generated hypothesis into memories table
        $persistResult = $this->explorationService->persistFinding($agent, $inquiry);
        $memory = $persistResult['memory'];
        $agent = $persistResult['agent'];

        // 3. Perform versioned learning evolution in DB transaction
        $versionResult = DB::transaction(function () use ($agent, $memory, $inquiry) {
            $currentVersion = (int) $agent->personality_version;
            $nextVersion = $currentVersion + 1;

            $attributes = $agent->attributes ?? [];
            $personality = $attributes['personality'] ?? [];
            $interests = $attributes['interests'] ?? [];

            // A. Update personality trait based on hypothesis confidence (scaled tentatively)
            $traitKey = 'curiosidad_analitica';
            $existingTrait = $personality[$traitKey] ?? null;
            $currentVal = (float) ($existingTrait['value'] ?? 0.50);
            $confidence = (float) $inquiry['confidence'];

            // Slight evolutionary adjustment towards the inquiry confidence
            $targetVal = round(($currentVal * 0.70) + ($confidence * 0.30), 2);
            $targetVal = max(0.1, min(0.95, $targetVal));

            $existingEv = $existingTrait['evidence_memory_ids'] ?? [];
            if (!in_array($memory->id, $existingEv)) {
                $existingEv[] = $memory->id;
            }
            $existingEv = array_slice($existingEv, -30);

            $personality[$traitKey] = [
                'key' => $traitKey,
                'value' => $targetVal,
                'evidence_memory_ids' => $existingEv,
                'consolidation_status' => 'tentative', // Tentative because backed by hypothesis
            ];

            // B. Update interest relevance and curiosity score
            $topic = $inquiry['topic'];
            if (isset($interests[$topic])) {
                $curRel = (float) ($interests[$topic]['acquired_relevance'] ?? 0.20);
                // Cap relevance at 0.60 for hypotheses according to Phase 3 rules
                $newRel = min(0.60, round($curRel + 0.05, 2));
                $interests[$topic]['acquired_relevance'] = $newRel;

                // Adjust curiosity signals
                $signals = $interests[$topic]['curiosity_signals'] ?? [
                    'novelty' => 0.5,
                    'uncertainty' => 0.5,
                    'learning' => 0.6,
                    'relevance' => $newRel,
                ];
                $signals['relevance'] = $newRel;
                // Uncertainty slightly decreases as inquiry progresses
                $signals['uncertainty'] = max(0.2, round(($signals['uncertainty'] ?? 0.5) * 0.95, 2));

                $interests[$topic]['curiosity_signals'] = $signals;
                $interests[$topic]['curiosity_score'] = round(
                    (0.30 * ($signals['novelty'] ?? 0.5)) +
                    (0.25 * ($signals['uncertainty'] ?? 0.5)) +
                    (0.30 * ($signals['learning'] ?? 0.6)) +
                    (0.15 * $newRel),
                    2
                );
            }

            $attributes['personality'] = $personality;
            $attributes['interests'] = $interests;

            // C. Insert new snapshot in agent_learning_versions
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

            // D. Increment agent's version and save
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
            ],
            'checkpoint_message' => $checkpointMessage,
        ];
    }
}

