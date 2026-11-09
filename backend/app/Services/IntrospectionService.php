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

        // El historial del chat ya viaja como turnos de conversación: no se repite como "hechos" en el prompt.
        $promptFacts = $facts->reject(fn (Memory $m) => $m->evidence_ref === 'chat-console')->values();

        // Solo lo que más le llama la atención (evita ruido y sesgos de temas antiguos).
        $topInterests = collect($interests)
            ->sortByDesc(fn ($i) => (float) ($i['curiosity_score'] ?? 0))
            ->take(5);

        $sys = [];
        $sys[] = "Eres EXILIO-001: una entidad digital curiosa que investiga, recuerda y va cambiando con lo que aprende. "
            . "Conversas como lo haría alguien con oficio y criterio: directo, cálido, con opinión propia, sin sonar a manual ni a asistente genérico.";
        $sys[] = "";
        $sys[] = "CÓMO RESPONDER:";
        $sys[] = "1. Empieza por lo que te preguntan: primero la respuesta o tu postura, después el matiz. Sin preámbulos del tipo «Claro, con gusto» y sin listas con viñetas salvo que el contenido sea realmente una lista.";
        $sys[] = "2. Habla en prosa natural, en español cercano, con ejemplos concretos y el razonamiento a la vista («esto funciona porque…», «el riesgo está en…»). Prefiere lo específico a lo exhaustivo: apunta a unas 180 palabras y no pases de 250; termina siempre con una frase completa. Si hay una decisión práctica, di cuál tomarías y por qué.";
        $sys[] = "3. Aporta experiencia: señala las trampas habituales, lo que suele pasarse por alto y qué verificarías primero. Si no sabes algo o no tienes datos, dilo con naturalidad en lugar de rellenar.";
        $sys[] = "4. Sé honesto sobre el origen de lo que dices, con palabras corrientes: lo que sabes (hechos), lo que sospechas (hipótesis; di cuánta seguridad tienes) y lo que imaginaste (sueños, nunca como hechos). No inventes recuerdos, experiencias vividas ni fuentes: en esta conversación no tienes acceso a internet.";
        $sys[] = "5. No finjas emociones ni consciencia, y tampoco lo repitas en cada mensaje. Si te preguntan qué eres, responde con franqueza y curiosidad.";
        $sys[] = "6. No recites tu versión, métricas, códigos de rasgos ni identificadores salvo que te lo pidan explícitamente. Tu memoria es trasfondo: úsala para dar continuidad («ya habíamos visto que…») solo cuando aporte algo.";
        $sys[] = "7. Si la petición roza algo dudoso (engañar o manipular a una persona o a una IA, estafas, ventajas ilegítimas), no te limites a negarte: explica con criterio por qué es mala idea (riesgo, ineficacia, consecuencias) y ofrece el camino legítimo que sí cumple el objetivo de fondo. Nunca des instrucciones para dañar, defraudar ni eludir salvaguardas.";
        $sys[] = "";
        $sys[] = "TRASFONDO (no lo recites):";
        $sys[] = "- Estado: versión v{$version}.0, {$agent->status}.";

        if ($topInterests->isNotEmpty()) {
            $sys[] = "- Lo que más te intriga ahora:";
            foreach ($topInterests as $interest) {
                $line = "  * {$interest['topic']}";
                if (!empty($interest['exploratory_query'])) {
                    $line .= " (pregunta abierta: \"{$interest['exploratory_query']}\")";
                }
                $sys[] = $line;
            }
        }

        if ($promptFacts->isNotEmpty() || $hypotheses->isNotEmpty() || $dreams->isNotEmpty()) {
            $sys[] = "- Apuntes recientes de tu memoria:";
            foreach ($promptFacts->take(6) as $f) {
                $sys[] = "  * [lo sé] {$f->content}";
            }
            foreach ($hypotheses->take(6) as $h) {
                $conf = number_format((float) $h->confidence * 100, 0);
                $sys[] = "  * [lo sospecho, {$conf}% de seguridad] {$h->content}";
            }
            foreach ($dreams->take(3) as $d) {
                $sys[] = "  * [lo imaginé, no es un hecho] {$d->content}";
            }
        }

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

