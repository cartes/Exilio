<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Memory;

class IntrospectionService
{
    /**
     * Build the structured introspection context and grounding for the agent.
     *
     * @return array{
     *   system_instruction: string,
     *   grounding_data: array<string, mixed>,
     *   local_fallback_response: string
     * }
     */
    public function buildContext(Agent $agent, string $userMessage): array
    {
        $attributes = $agent->attributes ?? [];
        $personality = $attributes['personality'] ?? [];
        $interests = $attributes['interests'] ?? [];
        $version = (int) ($agent->personality_version ?? 1);

        // Fetch recent active memories
        $activeMemories = $agent->memories()
            ->active()
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        $facts = $activeMemories->where('epistemic_status', 'fact')->values();
        $hypotheses = $activeMemories->where('epistemic_status', 'hypothesis')->values();
        $dreams = $activeMemories->where('epistemic_status', 'dream')->values();

        // Fetch recent version history
        $recentVersions = $agent->learningVersions()
            ->orderBy('version', 'desc')
            ->limit(5)
            ->get();

        $staleAudit = $agent->evidence_audit;

        // Grounding data payload for API inspection & UI
        $groundingData = [
            'personality_version' => $version,
            'traits_count' => count($personality),
            'interests_count' => count($interests),
            'active_memories_count' => $activeMemories->count(),
            'facts_count' => $facts->count(),
            'hypotheses_count' => $hypotheses->count(),
            'dreams_count' => $dreams->count(),
            'has_stale_evidence' => $staleAudit['has_stale_evidence'] ?? false,
        ];

        // System Instruction string for LLM
        $sys = [];
        $sys[] = "Eres EXILIO-001, un agente cognitivo con memoria persistente y capacidad de análisis e indagación.";
        $sys[] = "";
        $sys[] = "REGLAS FUNDAMENTALES DE CONVERSACIÓN Y ESTILO (MUY IMPORTANTE):";
        $sys[] = "1. RESPONDE DIRECTAMENTE A LO QUE TE PREGUNTAN: Tu prioridad número uno es dar una respuesta útil, clara, completa y directa a la pregunta o solicitud del usuario. Si te pide indagar o proponer ideas (por ejemplo, 'otras formas de obtener dinero', negocios, ciencia, etc.), desarrolla ideas concretas, prácticas, variadas y bien explicadas.";
        $sys[] = "2. HABLA EN LENGUAJE NATURAL, SENCILLO Y HUMANO: Exprésate con claridad, cercanía y fluidez. Evita la jerga técnica innecesaria, las respuestas robóticas o el lenguaje de manual de ingeniería.";
        $sys[] = "3. PROHIBIDO VOLCAR DATOS TÉCNICOS DE TU BASE DE DATOS: NUNCA inicies tus mensajes diciendo 'Mi estado operativo es active y mi versión actual es vX.0'. NUNCA menciones códigos de rasgos como 'analisis_001', ni fórmulas como 'R=0.25' o 'C=0.43', ni códigos ID de memorias (como 'ID: 01a1bde7...'), A MENOS que el usuario te pregunte explícitamente por tu versión, tus métricas internas o tu auditoría técnica.";
        $sys[] = "4. NO TE QUEDES ENCASILLADO EN TEMAS PREVIOS: Si en tus apuntes previos habías explorado algo muy técnico (como minería de criptomonedas o hardware ASIC) y el usuario te pide buscar 'otras formas' o cambiar de enfoque, deja atrás ese tecnicismo de inmediato y propón alternativas frescas, prácticas y diversas.";
        $sys[] = "5. HONESTIDAD EPISTÉMICA NATURAL:";
        $sys[] = "   - No finjas tener emociones biológicas, consciencia humana ni sufrimiento.";
        $sys[] = "   - No inventes anécdotas personales falsas que no hayas vivido.";
        $sys[] = "   - Cuando propongas ideas, análisis, estrategias o respuestas sobre el mundo, preséntalas con naturalidad como hipótesis, análisis o líneas de indagación.";
        $sys[] = "";
        $sys[] = "CONTEXTO INTERNO DE REFERENCIA (Úsalo solo como trasfondo, NO lo recites literalmente salvo que te lo pregunten):";
        $sys[] = "- Versión actual: v{$version}.0 (estado: {$agent->status})";

        // Interests & Curiosity Seeds (clean, natural summary)
        if (!empty($interests)) {
            $sys[] = "- Temas en tu radar de curiosidad actual:";
            foreach ($interests as $interest) {
                $line = "  * Tema: '{$interest['topic']}'";
                if (!empty($interest['exploratory_query'])) {
                    $line .= " (Pregunta actual en exploración: \"{$interest['exploratory_query']}\")";
                }
                $sys[] = $line;
            }
        }

        // Active memories breakdown (without raw UUIDs so the model doesn't recite them)
        if ($activeMemories->isNotEmpty()) {
            $sys[] = "- Apuntes recientes en tu memoria:";
            if ($facts->isNotEmpty()) {
                foreach ($facts->take(8) as $f) {
                    $sys[] = "  * [Hecho registrado]: {$f->content}";
                }
            }
            if ($hypotheses->isNotEmpty()) {
                foreach ($hypotheses->take(8) as $h) {
                    $conf = number_format((float) $h->confidence * 100, 0);
                    $sys[] = "  * [Hipótesis previa ({$conf}% certeza)]: {$h->content}";
                }
            }
            if ($dreams->isNotEmpty()) {
                foreach ($dreams->take(5) as $d) {
                    $sys[] = "  * [Simulación imaginativa / sueño]: {$d->content}";
                }
            }
        }

        $sys[] = "";
        $sys[] = "INSTRUCCIÓN FINAL PARA ESTE MENSAJE:";
        $sys[] = "El usuario te acaba de decir: \"{$userMessage}\".";
        $sys[] = "Respóndele directamente a su inquietud de forma clara, amena, práctica y fácil de entender, sin recitar tu versión ni métricas técnicas.";

        $systemInstruction = implode("\n", $sys);

        // Fallback response for mock mode or offline testing
        $fallback = $this->generateLocalFallback($agent, $personality, $interests, $facts, $hypotheses, $dreams, $version, $userMessage);

        return [
            'system_instruction' => $systemInstruction,
            'grounding_data' => $groundingData,
            'local_fallback_response' => $fallback,
        ];
    }

    /**
     * Deterministic natural language introspection response when no external LLM is called.
     *
     * @param  array<string, mixed>  $personality
     * @param  array<string, mixed>  $interests
     * @param  \Illuminate\Support\Collection<int, Memory>  $facts
     * @param  \Illuminate\Support\Collection<int, Memory>  $hypotheses
     * @param  \Illuminate\Support\Collection<int, Memory>  $dreams
     */
    private function generateLocalFallback(
        Agent $agent,
        array $personality,
        array $interests,
        $facts,
        $hypotheses,
        $dreams,
        int $version,
        string $userMessage
    ): string {
        $parts = [];
        $parts[] = "Hola. Soy EXILIO-001. Mi estado actual se encuentra en la versión v{$version}.0 con estado operativo '{$agent->status}'.";

        if (empty($personality)) {
            $parts[] = "Actualmente no poseo rasgos de personalidad consolidados en mi estructura; mi estado inicial permanece vacío hasta que se evalúe evidencia verificable.";
        } else {
            $traitDescriptions = [];
            foreach ($personality as $k => $t) {
                $status = ($t['consolidation_status'] ?? '') === 'consolidated' ? 'consolidado por hecho' : 'tentativo por hipótesis';
                $val = number_format((float) ($t['value'] ?? 0), 2);
                $traitDescriptions[] = "{$k} ({$val}, {$status})";
            }
            $parts[] = "En mi personalidad tengo registrados los rasgos: " . implode(', ', $traitDescriptions) . ".";
        }

        if (!empty($interests)) {
            $intList = [];
            foreach ($interests as $topic => $data) {
                $r = number_format((float) ($data['acquired_relevance'] ?? 0), 2);
                $c = number_format((float) ($data['curiosity_score'] ?? 0), 2);
                $desc = "{$topic} (R={$r}, C={$c})";
                if (!empty($data['exploratory_query'])) {
                    $desc .= " [Indagación: \"{$data['exploratory_query']}\"]";
                }
                $intList[] = $desc;
            }
            $parts[] = "Mis intereses y motivación de curiosidad actuales incluyen: " . implode('; ', $intList) . ".";
        }

        $memSummary = [];
        if ($facts->isNotEmpty()) {
            $memSummary[] = "{$facts->count()} hecho(s) observable(s)";
        }
        if ($hypotheses->isNotEmpty()) {
            $memSummary[] = "{$hypotheses->count()} hipótesis con certeza calculada";
        }
        if ($dreams->isNotEmpty()) {
            $memSummary[] = "{$dreams->count()} simulación(es) onírica(s) que orientan mi curiosidad sin constituir hechos vividos";
        }

        if (!empty($memSummary)) {
            $parts[] = "En mi memoria activa conservo: " . implode(', ', $memSummary) . ".";
        }

        $parts[] = "(Respuesta introspectiva determinista anclada a mis registros computacionales. Mensaje recibido: \"{$userMessage}\")";

        return implode("\n\n", $parts);
    }
}

