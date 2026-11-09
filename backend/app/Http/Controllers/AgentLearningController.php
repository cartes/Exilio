<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\AgentLearningVersion;
use App\Models\Memory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AgentLearningController
{
    public const MAX_RELEVANCE_FACT = 1.0;
    public const MAX_RELEVANCE_HYPOTHESIS = 0.60;
    public const MAX_RELEVANCE_DREAM = 0.25;

    public function index(Agent $agent): JsonResponse
    {
        $this->ensureBaselineVersion($agent);

        $versions = $agent->learningVersions()
            ->orderBy('version', 'desc')
            ->get();

        return response()->json($versions);
    }

    public function learn(Request $request, Agent $agent): JsonResponse
    {
        $validated = $request->validate([
            'evidence_memory_ids' => ['required', 'array', 'min:1'],
            'evidence_memory_ids.*' => ['required', 'string', 'uuid'],
            'summary' => ['nullable', 'string', 'max:255'],
            'trait_updates' => ['nullable', 'array'],
            'interest_topics' => ['nullable', 'array'],
            'interest_topics.*.topic' => ['required_with:interest_topics', 'string', 'max:120'],
            'interest_topics.*.exploratory_query' => ['nullable', 'string', 'max:255'],
        ]);

        $traitUpdates = $validated['trait_updates'] ?? [];
        $interestTopics = $validated['interest_topics'] ?? [];

        if (empty($traitUpdates) && empty($interestTopics)) {
            throw ValidationException::withMessages([
                'learning' => ['A learning operation must specify at least one trait update or interest topic.'],
            ]);
        }

        foreach ($traitUpdates as $traitKey => $targetVal) {
            if (!is_string($traitKey) || trim($traitKey) === '') {
                throw ValidationException::withMessages([
                    'trait_updates' => ['Trait keys must be non-empty strings.'],
                ]);
            }
            if (!is_numeric($targetVal) || (float) $targetVal < 0.0 || (float) $targetVal > 1.0) {
                throw ValidationException::withMessages([
                    "trait_updates.{$traitKey}" => ['Trait values must be normalized in [0, 1].'],
                ]);
            }
        }

        $requestedIds = array_values(array_unique($validated['evidence_memory_ids']));
        $memories = $agent->memories()->whereIn('id', $requestedIds)->get();

        if ($memories->count() !== count($requestedIds)) {
            throw ValidationException::withMessages([
                'evidence_memory_ids' => ['All evidence memories must exist and belong to the target agent.'],
            ]);
        }

        foreach ($memories as $memory) {
            if ($memory->superseded_at !== null) {
                throw ValidationException::withMessages([
                    'evidence_memory_ids' => [
                        "Superseded memory '{$memory->id}' cannot be used as learning evidence.",
                    ],
                ]);
            }
        }

        $hasFact = $memories->contains(fn (Memory $m) => $m->epistemic_status === 'fact');
        $hasHypothesis = $memories->contains(fn (Memory $m) => $m->epistemic_status === 'hypothesis');
        $onlyDreams = !$hasFact && !$hasHypothesis;

        if ($onlyDreams && !empty($traitUpdates)) {
            throw ValidationException::withMessages([
                'trait_updates' => [
                    'Dreams cannot consolidate or modify personality traits as lived reality; they can only guide curiosity and exploratory interests.',
                ],
            ]);
        }

        $hypotheses = $memories->filter(fn (Memory $m) => $m->epistemic_status === 'hypothesis');
        $avgConfidence = $hypotheses->isNotEmpty()
            ? (float) $hypotheses->avg('confidence')
            : null;

        $nonDreamIds = $memories
            ->filter(fn (Memory $m) => $m->epistemic_status !== 'dream')
            ->pluck('id')
            ->values()
            ->all();

        $result = DB::transaction(function () use (
            $agent,
            $memories,
            $requestedIds,
            $nonDreamIds,
            $traitUpdates,
            $interestTopics,
            $hasFact,
            $hasHypothesis,
            $avgConfidence,
            $validated
        ) {
            $this->ensureBaselineVersion($agent);

            $currentAttributes = $agent->attributes ?? [];
            $nextPersonality = $currentAttributes['personality'] ?? [];
            $nextInterests = $currentAttributes['interests'] ?? [];

            foreach ($traitUpdates as $traitKey => $targetVal) {
                $key = trim((string) $traitKey);
                $numericTarget = (float) $targetVal;

                if ($hasFact) {
                    $effectiveVal = round($numericTarget, 4);
                    $status = 'consolidated';
                } else {
                    $confFactor = $avgConfidence ?? 0.5;
                    $effectiveVal = round(min(self::MAX_RELEVANCE_HYPOTHESIS, $numericTarget * $confFactor), 4);
                    $status = 'tentative';
                }

                $existingEvidence = $nextPersonality[$key]['evidence_memory_ids'] ?? [];
                $mergedEvidence = array_values(array_unique(array_merge($existingEvidence, $nonDreamIds)));

                $nextPersonality[$key] = [
                    'key' => $key,
                    'value' => $effectiveVal,
                    'consolidation_status' => $status,
                    'evidence_memory_ids' => $mergedEvidence,
                ];
            }

            foreach ($interestTopics as $item) {
                $topic = trim((string) $item['topic']);
                $existing = $nextInterests[$topic] ?? [];
                $baseR = (float) ($existing['acquired_relevance'] ?? 0.0);

                $delta = 0.0;
                foreach ($memories as $mem) {
                    if ($mem->epistemic_status === 'fact') {
                        $delta += 0.25;
                    } elseif ($mem->epistemic_status === 'hypothesis') {
                        $delta += round(0.20 * (float) $mem->confidence, 4);
                    } elseif ($mem->epistemic_status === 'dream') {
                        $delta += 0.15;
                    }
                }

                if ($hasFact) {
                    $cap = self::MAX_RELEVANCE_FACT;
                    $consolidation = 'consolidated';
                    $dominantStatus = 'fact';
                } elseif ($hasHypothesis) {
                    $cap = self::MAX_RELEVANCE_HYPOTHESIS;
                    $consolidation = 'tentative';
                    $dominantStatus = 'hypothesis';
                } else {
                    $cap = self::MAX_RELEVANCE_DREAM;
                    $consolidation = 'oneiric_seed';
                    $dominantStatus = 'dream';
                }

                $rVal = min($cap, round($baseR + $delta, 4));
                $signals = $this->deriveCuriositySignals($dominantStatus, $rVal, $avgConfidence);
                $cScore = round(
                    0.30 * $signals['novelty']
                    + 0.25 * $signals['uncertainty']
                    + 0.30 * $signals['learning']
                    + 0.15 * $signals['relevance'],
                    4
                );

                $customQuery = isset($item['exploratory_query']) ? trim((string) $item['exploratory_query']) : '';
                if ($customQuery === '' && $dominantStatus === 'dream') {
                    $customQuery = "Explorar e investigar hechos verificables sobre: {$topic}";
                }

                $existingEvidence = $existing['evidence_memory_ids'] ?? [];
                $mergedEvidence = array_values(array_unique(array_merge($existingEvidence, $requestedIds)));

                $nextInterests[$topic] = [
                    'topic' => $topic,
                    'acquired_relevance' => $rVal,
                    'consolidation_status' => $consolidation,
                    'source_epistemic_status' => $dominantStatus,
                    'exploratory_query' => $customQuery !== '' ? $customQuery : ($existing['exploratory_query'] ?? null),
                    'curiosity_signals' => $signals,
                    'curiosity_score' => $cScore,
                    'evidence_memory_ids' => $mergedEvidence,
                ];
            }

            $newVersionNumber = ((int) $agent->personality_version) + 1;
            $newAttributes = array_merge($currentAttributes, [
                'personality' => $nextPersonality,
                'interests' => $nextInterests,
            ]);

            $agent->update([
                'personality_version' => $newVersionNumber,
                'attributes' => $newAttributes,
            ]);

            $versionRecord = $agent->learningVersions()->create([
                'version' => $newVersionNumber,
                'operation_type' => 'learning',
                'summary' => $validated['summary'] ?? 'Operación explícita de aprendizaje controlado',
                'evidence_memory_ids' => $requestedIds,
                'attributes_snapshot' => $newAttributes,
                'reverted_from_version' => null,
                'created_at' => now(),
            ]);

            return [
                'agent' => $agent->fresh(),
                'version_record' => $versionRecord,
            ];
        });

        return response()->json($result, 201);
    }

    public function revert(Request $request, Agent $agent, int $version): JsonResponse
    {
        $this->ensureBaselineVersion($agent);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        if ($version >= (int) $agent->personality_version) {
            throw ValidationException::withMessages([
                'version' => ['Can only revert to a strictly previous version.'],
            ]);
        }

        $targetSnapshot = $agent->learningVersions()
            ->where('version', $version)
            ->first();

        if (!$targetSnapshot) {
            throw ValidationException::withMessages([
                'version' => ["Target version v{$version} not found for this agent."],
            ]);
        }

        $result = DB::transaction(function () use ($agent, $targetSnapshot, $version, $validated) {
            $newVersionNumber = ((int) $agent->personality_version) + 1;
            $restoredAttributes = $targetSnapshot->attributes_snapshot;

            $agent->update([
                'personality_version' => $newVersionNumber,
                'attributes' => $restoredAttributes,
            ]);

            $reason = $validated['reason'] ?? 'Reversión explícita por auditoría';
            $versionRecord = $agent->learningVersions()->create([
                'version' => $newVersionNumber,
                'operation_type' => 'revert',
                'summary' => "{$reason} (restaurado desde v{$version})",
                'evidence_memory_ids' => $targetSnapshot->evidence_memory_ids ?? [],
                'attributes_snapshot' => $restoredAttributes,
                'reverted_from_version' => $version,
                'created_at' => now(),
            ]);

            return [
                'agent' => $agent->fresh(),
                'version_record' => $versionRecord,
            ];
        });

        return response()->json($result, 201);
    }

    public static function ensureBaselineVersion(Agent $agent): void
    {
        $hasInitial = $agent->learningVersions()->where('version', 1)->exists();
        if (!$hasInitial) {
            $attrs = $agent->attributes ?? [];
            if (!isset($attrs['personality'])) {
                $attrs['personality'] = [];
            }
            if (!isset($attrs['interests'])) {
                $attrs['interests'] = [];
            }
            if (!isset($attrs['autobiographical_memory'])) {
                $attrs['autobiographical_memory'] = [];
            }

            $agent->learningVersions()->create([
                'version' => 1,
                'operation_type' => 'initial',
                'summary' => 'Estado inicial fundador sin sesgos ni relevancia precargada.',
                'evidence_memory_ids' => [],
                'attributes_snapshot' => $attrs,
                'reverted_from_version' => null,
                'created_at' => now(),
            ]);
        }
    }

    /**
     * @return array{novelty: float, uncertainty: float, learning: float, relevance: float}
     */
    public static function deriveCuriositySignals(string $dominantStatus, float $acquiredRelevance, ?float $avgConfidence): array
    {
        if ($dominantStatus === 'dream') {
            return [
                'novelty' => 0.85,
                'uncertainty' => 0.80,
                'learning' => 0.75,
                'relevance' => $acquiredRelevance,
            ];
        }

        if ($dominantStatus === 'hypothesis') {
            $conf = $avgConfidence ?? 0.5;
            return [
                'novelty' => 0.65,
                'uncertainty' => round(max(0.15, 1.0 - $conf), 4),
                'learning' => 0.70,
                'relevance' => $acquiredRelevance,
            ];
        }

        return [
            'novelty' => 0.50,
            'uncertainty' => 0.25,
            'learning' => 0.60,
            'relevance' => $acquiredRelevance,
        ];
    }
}

