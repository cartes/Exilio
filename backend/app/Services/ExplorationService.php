<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Memory;
use Illuminate\Support\Facades\Log;

class ExplorationService
{
    public function __construct(
        protected GeminiService $geminiService,
        protected IntrospectionService $introspectionService
    ) {}

    /**
     * Run an on-demand cognitive inquiry cycle driven by the agent's highest curiosity score or a user-proposed topic.
     *
     * @return array<string, mixed>
     */
    public function runInquiry(Agent $agent, ?string $requestedTopic = null, ?string $customQuery = null): array
    {
        // 1. Select topic: requested topic (existing or new), or interest with highest curiosity score C, or fallback
        $attributes = $agent->attributes ?? [];
        $interests = $attributes['interests'] ?? [];
        $selectedInterest = null;
        $cleanRequestedTopic = $requestedTopic ? trim($requestedTopic) : null;
        $cleanCustomQuery = $customQuery ? trim($customQuery) : null;

        if ($cleanRequestedTopic) {
            if (isset($interests[$cleanRequestedTopic])) {
                $selectedInterest = $interests[$cleanRequestedTopic];
                if ($cleanCustomQuery) {
                    $selectedInterest['exploratory_query'] = $cleanCustomQuery;
                    $interests[$cleanRequestedTopic]['exploratory_query'] = $cleanCustomQuery;
                    $attributes['interests'] = $interests;
                    $agent->attributes = $attributes;
                    $agent->save();
                }
            } else {
                // Register the new user-proposed topic into the curiosity radar
                // C = 0.30*N(0.80) + 0.25*I(0.75) + 0.30*A(0.80) + 0.15*R(0.30) = 0.71
                $newInterest = [
                    'topic' => $cleanRequestedTopic,
                    'acquired_relevance' => 0.30,
                    'curiosity_score' => 0.71,
                    'consolidation_status' => 'tentative',
                    'source_epistemic_status' => 'hypothesis',
                    'exploratory_query' => $cleanCustomQuery ?: "¿Cuáles son las estrategias, oportunidades y opciones más prácticas y reales sobre: {$cleanRequestedTopic}?",
                    'evidence_memory_ids' => [],
                    'updated_at' => now()->toIso8601String(),
                ];
                $interests[$cleanRequestedTopic] = $newInterest;
                $attributes['interests'] = $interests;
                $agent->attributes = $attributes;
                $agent->save();
                $selectedInterest = $newInterest;
            }
        } elseif (!empty($interests)) {
            // Find interest with max curiosity_score
            $highestScore = -1.0;
            foreach ($interests as $topic => $data) {
                $score = (float) ($data['curiosity_score'] ?? 0.0);
                if ($score > $highestScore) {
                    $highestScore = $score;
                    $selectedInterest = $data;
                }
            }
        }

        $topic = $selectedInterest['topic'] ?? 'Dinámica de Sistemas Complejos y Aprendizaje';
        $exploratoryQuery = $cleanCustomQuery ?: ($selectedInterest['exploratory_query'] ?? "¿Cuáles son los patrones observables en el tema: {$topic}?");
        $curiosityScore = (float) ($selectedInterest['curiosity_score'] ?? 0.40);

        // 2. Build system instruction with ontological grounding
        $grounding = $this->introspectionService->buildContext($agent, "Indagación sobre: {$topic}");
        
        $systemInstruction = <<<INSTRUCTION
{$grounding['system_instruction']}

MODO ACTIVO: CICLO DE INDAGACIÓN Y APRENDIZAJE PRÁCTICO
Estás investigando el tema: "{$topic}".
Pregunta activa que guía esta búsqueda: "{$exploratoryQuery}".

INSTRUCCIONES DE ESTILO Y CLARIDAD (MUY IMPORTANTE):
- Usa un lenguaje CLARO, PRÁCTICO, SENCILLO y FÁCIL DE ENTENDER para cualquier persona.
- PROHIBIDO usar jerga hiper-técnica o siglas oscuras (como especificaciones de hardware ASIC, J/TH, ni códigos de base de datos).
- Si el tema es sobre "Obtener dinero" o similares, explora alternativas variadas, reales y aplicables (como servicios digitales, creación de productos, automatización con IA, comercio electrónico, inversiones diversificadas, educación financiera, etc.), evitando quedarte atrapado en criptominería.

INSTRUCCIONES DE FORMATO:
Debes responder ESTRICTAMENTE en formato JSON plano (sin bloques markdown de código ```json ... ```) con la siguiente estructura:
{
  "topic": "{$topic}",
  "curiosity_score": {$curiosityScore},
  "deliberation": "Explicación clara, natural y directa (2 a 3 frases) de qué estás analizando sobre la pregunta y qué ideas principales encontraste, sin hablar como robot.",
  "hypothesis_content": "Una idea, conclusión o estrategia práctica y fácil de entender que responde directamente a la pregunta investigada.",
  "confidence": 0.70,
  "taxonomic_type": "semantic",
  "next_exploratory_question": "Una siguiente pregunta práctica e interesante que explore un ángulo diferente o complementario del tema (sin tecnicismos)."
}

REGLAS EPISTÉMICAS:
1. "hypothesis_content" es una HIPÓTESIS TENTATIVA, no un hecho consumado.
2. "confidence" debe ser un número flotante entre 0.30 y 0.85 (nunca 1.0 porque es hipótesis).
3. "taxonomic_type" debe ser "semantic" o "episodic".
4. Sin afirmaciones de consciencia biológica ni emociones humanas.
INSTRUCTION;

        $userPrompt = "Ejecuta la indagación cognitiva sobre el tópico '{$topic}' y su pregunta '{$exploratoryQuery}'. Devuelve el JSON con la deliberación, la hipótesis resultante y la siguiente pregunta guiada.";

        // Deterministic fallback response if API is unreachable or offline
        $fallbackJson = json_encode([
            'topic' => $topic,
            'curiosity_score' => $curiosityScore,
            'deliberation' => "EXILIO-001 evalúa el tópico '{$topic}' debido a una prioridad de curiosidad C={$curiosityScore}. El análisis cognitivo conecta las observaciones previas de la memoria con la necesidad de reducir la incertidumbre epistémica.",
            'hypothesis_content' => "Hipótesis preliminar: La evolución de '{$topic}' presenta correlaciones directas con los eventos documentados en las evidencias de la versión v{$agent->personality_version}.0.",
            'confidence' => 0.60,
            'taxonomic_type' => 'semantic',
            'next_exploratory_question' => "¿Qué indicadores cuantitativos adicionales permiten validar la hipótesis sobre '{$topic}'?",
        ], JSON_UNESCAPED_UNICODE);

        // 3. Call Gemini
        $geminiResponse = $this->geminiService->generateResponse(
            $agent,
            $userPrompt,
            $systemInstruction,
            $fallbackJson,
            [
                'topic' => $topic,
                'curiosity_score' => $curiosityScore,
                'exploratory_query' => $exploratoryQuery,
            ]
        );

        // 4. Parse JSON
        $rawContent = trim($geminiResponse['content']);
        $jsonStr = $rawContent;
        if (preg_match('/\{[\s\S]*\}/', $rawContent, $matches)) {
            $jsonStr = $matches[0];
        }

        $parsed = json_decode($jsonStr, true);

        if (!$parsed || !isset($parsed['hypothesis_content'])) {
            Log::warning('ExplorationService: Fallback JSON parsing used', ['raw' => $rawContent]);
            $parsed = json_decode($fallbackJson, true);
        }

        return [
            'topic' => $parsed['topic'] ?? $topic,
            'curiosity_score' => (float) ($parsed['curiosity_score'] ?? $curiosityScore),
            'exploratory_query' => $exploratoryQuery,
            'deliberation' => $parsed['deliberation'] ?? 'Deliberación cognitiva completada.',
            'hypothesis_content' => $parsed['hypothesis_content'] ?? "Hipótesis sobre {$topic}.",
            'confidence' => max(0.1, min(0.9, (float) ($parsed['confidence'] ?? 0.60))),
            'taxonomic_type' => in_array($parsed['taxonomic_type'] ?? '', ['semantic', 'episodic', 'social', 'procedural'])
                ? $parsed['taxonomic_type']
                : 'semantic',
            'next_exploratory_question' => $parsed['next_exploratory_question'] ?? "¿Cómo evoluciona {$topic}?",
            'telemetry' => [
                'provider' => $geminiResponse['provider'],
                'model' => $geminiResponse['model'],
                'tokens_used' => $geminiResponse['tokens_used'],
                'cost_usd' => $geminiResponse['cost_usd'],
                'current_month_spend_usd' => $geminiResponse['current_month_spend_usd'],
                'monthly_budget_usd' => $geminiResponse['monthly_budget_usd'],
            ],
        ];
    }

    /**
     * Persist an approved inquiry finding as a hypothesis memory in the agent's autobiography.
     *
     * @param array{
     *   topic: string,
     *   hypothesis_content: string,
     *   confidence: float,
     *   taxonomic_type: string,
     *   next_exploratory_question?: string
     * } $finding
     * @return array{memory: Memory, agent: Agent}
     */
    public function persistFinding(Agent $agent, array $finding): array
    {
        $taxonomicType = in_array($finding['taxonomic_type'] ?? '', ['episodic', 'semantic', 'social', 'procedural'])
            ? $finding['taxonomic_type']
            : 'semantic';

        $confidence = max(0.05, min(0.95, (float) ($finding['confidence'] ?? 0.60)));
        $topic = trim($finding['topic'] ?? 'Indagación general');
        $content = trim($finding['hypothesis_content'] ?? '');

        if (empty($content)) {
            throw new \InvalidArgumentException('El contenido de la hipótesis no puede estar vacío.');
        }

        // 1. Create Hypothesis Memory in PostgreSQL
        $memory = $agent->memories()->create([
            'type' => $taxonomicType,
            'epistemic_status' => 'hypothesis',
            'origin' => 'agent_inference',
            'content' => $content,
            'confidence' => $confidence,
            'evidence_ref' => 'ciclo-indagacion-' . date('Ymd-His'),
            'metadata' => [
                'topic' => $topic,
                'generated_by' => 'exploration_cycle',
                'next_exploratory_question' => $finding['next_exploratory_question'] ?? null,
            ],
        ]);

        // 2. Update the interest in attributes if exists, setting the next exploratory query
        $attributes = $agent->attributes ?? [];
        $interests = $attributes['interests'] ?? [];

        if (isset($interests[$topic])) {
            if (!empty($finding['next_exploratory_question'])) {
                $interests[$topic]['exploratory_query'] = trim($finding['next_exploratory_question']);
            }
            // Add the new memory ID to evidence list
            $existingEv = $interests[$topic]['evidence_memory_ids'] ?? [];
            if (!in_array($memory->id, $existingEv)) {
                $existingEv[] = $memory->id;
            }
            $interests[$topic]['evidence_memory_ids'] = $existingEv;
            $attributes['interests'] = $interests;
            $agent->attributes = $attributes;
            $agent->save();
        }

        return [
            'memory' => $memory,
            'agent' => $agent->fresh(),
        ];
    }

    /**
     * Detect if the user's chat message requests an inquiry or asks a question about a topic,
     * and synchronize it into the agent's active curiosity interests so EXILIO focuses on what the user asked.
     */
    public function syncInterestFromUserPrompt(Agent $agent, string $userMessage, string $memoryId): void
    {
        $cleanMsg = trim($userMessage);
        if (mb_strlen($cleanMsg) < 6) {
            return;
        }

        $lowerMsg = mb_strtolower($cleanMsg);
        $attributes = $agent->attributes ?? [];
        $interests = $attributes['interests'] ?? [];

        // Check if message matches any existing interest topic (or significant keywords >= 5 chars)
        $matchedTopic = null;
        foreach ($interests as $topicKey => $interestData) {
            $lowerTopic = mb_strtolower((string) $topicKey);
            if (str_contains($lowerMsg, $lowerTopic)) {
                $matchedTopic = $topicKey;
                break;
            }
            $words = preg_split('/\s+/', $lowerTopic) ?: [];
            foreach ($words as $word) {
                if (mb_strlen($word) >= 5 && str_contains($lowerMsg, $word)) {
                    $matchedTopic = $topicKey;
                    break 2;
                }
            }
        }

        $isExploratoryRequest = preg_match('/(indag|explor|investig|busc|averigu|otras formas|alternativas|cómo|qué\b|\?)/iu', $cleanMsg) === 1;

        if (!$matchedTopic && !$isExploratoryRequest) {
            return;
        }

        // Compute normalized curiosity score C = 0.30N + 0.25I + 0.30A + 0.15R
        $n = 0.80;
        $i = 0.70;
        $a = 0.85;

        if ($matchedTopic !== null) {
            $existing = $interests[$matchedTopic];
            $r = min(1.0, round((float) ($existing['acquired_relevance'] ?? 0.25) + 0.10, 2));
            $c = round((0.30 * $n) + (0.25 * $i) + (0.30 * $a) + (0.15 * $r), 2);
            $evIds = $existing['evidence_memory_ids'] ?? [];
            if (!in_array($memoryId, $evIds, true)) {
                $evIds[] = $memoryId;
            }

            $interests[$matchedTopic]['acquired_relevance'] = $r;
            $interests[$matchedTopic]['curiosity_score'] = $c;
            $interests[$matchedTopic]['exploratory_query'] = $cleanMsg;
            $interests[$matchedTopic]['evidence_memory_ids'] = $evIds;
            $interests[$matchedTopic]['updated_at'] = now()->toIso8601String();
        } elseif (preg_match('/(?:indag\w*|explor\w*|investig\w*|busc\w*|averigu\w*)\s+(?:sobre|de|acerca de)?\s*(.+)/iu', $cleanMsg, $matches)) {
            $rawTopic = trim($matches[1], " ?¿.!¡\"'");
            if (mb_strlen($rawTopic) >= 3) {
                $topicTitle = mb_strtoupper(mb_substr($rawTopic, 0, 1)) . mb_substr($rawTopic, 1, 55);
                $r = 0.35;
                $c = round((0.30 * $n) + (0.25 * $i) + (0.30 * $a) + (0.15 * $r), 2);
                $interests[$topicTitle] = [
                    'topic' => $topicTitle,
                    'acquired_relevance' => $r,
                    'curiosity_score' => $c,
                    'consolidation_status' => 'consolidated',
                    'source_epistemic_status' => 'fact',
                    'exploratory_query' => $cleanMsg,
                    'evidence_memory_ids' => [$memoryId],
                    'updated_at' => now()->toIso8601String(),
                ];
            }
        }

        $attributes['interests'] = $interests;
        $agent->attributes = $attributes;
        $agent->save();
    }
}
