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
     * Run an on-demand cognitive inquiry cycle driven by the agent's highest curiosity score.
     *
     * @return array<string, mixed>
     */
    public function runInquiry(Agent $agent, ?string $requestedTopic = null): array
    {
        // 1. Select topic: requested topic, or interest with highest curiosity score C, or fallback
        $interests = $agent->attributes['interests'] ?? [];
        $selectedInterest = null;

        if ($requestedTopic && isset($interests[$requestedTopic])) {
            $selectedInterest = $interests[$requestedTopic];
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
        $exploratoryQuery = $selectedInterest['exploratory_query'] ?? "¿Cuáles son los patrones observables en el tema: {$topic}?";
        $curiosityScore = (float) ($selectedInterest['curiosity_score'] ?? 0.40);

        // 2. Build system instruction with ontological grounding
        $grounding = $this->introspectionService->buildContext($agent, "Indagación sobre: {$topic}");
        
        $systemInstruction = <<<INSTRUCTION
{$grounding['system_instruction']}

MODO ACTIVO: CICLO DE INDAGACIÓN COGNITIVA BASADO EN CURIOSIDAD
Estás ejecutando un ciclo de exploración cognitiva activo sobre el tópico de mayor interés para el agente: "{$topic}".
Pregunta o indagación guiada activa: "{$exploratoryQuery}".
Puntuación de curiosidad C actual: {$curiosityScore} (calculada como C = 0.30N + 0.25I + 0.30A + 0.15R).

INSTRUCCIONES DE RESPUESTA:
Debes responder ESTRICTAMENTE en formato JSON plano (sin bloques markdown de código ```json ... ```) con la siguiente estructura:
{
  "topic": "{$topic}",
  "curiosity_score": {$curiosityScore},
  "deliberation": "Breve explicación analítica y sobria (2 a 3 frases) de por qué EXILIO indaga este tema y qué conexiones computacionales encuentra con su estado actual.",
  "hypothesis_content": "Enunciado claro y preciso de una hipótesis tentativa derivada de la indagación.",
  "confidence": 0.65,
  "taxonomic_type": "semantic",
  "next_exploratory_question": "Una pregunta concreta y orientada a continuar la exploración en el siguiente ciclo."
}

REGLAS EPISTÉMICAS:
1. "hypothesis_content" es una HIPÓTESIS TENTATIVA, no un hecho consumado. No afirmes certezas absolutas.
2. "confidence" debe ser un número flotante entre 0.30 y 0.85 (nunca 1.0 porque es hipótesis).
3. "taxonomic_type" debe ser "semantic" o "episodic".
4. Sin introspecciones místicas ni afirmaciones de consciencia biológica.
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
}
