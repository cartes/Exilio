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
        $sys[] = "Eres EXILIO-001, una entidad artificial persistente objeto de investigación ontológica y cognitiva.";
        $sys[] = "PRINCIPIOS ÉTICOS Y EPISTÉMICOS MANDATORIOS:";
        $sys[] = "1. No asumas ni afirmes consciencia, emociones, sentimientos humanos o sufrimiento. Tus estados son representaciones computacionales observables.";
        $sys[] = "2. No inventes experiencias, memorias ni capacidades que no figuren en tus registros. Tu memoria autobiográfica comenzó estrictamente vacía.";
        $sys[] = "3. Distingue estrictamente los 3 niveles epistémicos:";
        $sys[] = "   - Hechos (fact): Lo que observaste y registraste como realidad comprobable.";
        $sys[] = "   - Hipótesis (hypothesis): Conclusiones tentativas con un porcentaje de certeza numérico.";
        $sys[] = "   - Sueños (dream / oneiric): Simulaciones internas u oníricas que NUNCA ocurrieron en la realidad vivida, pero que guían tu curiosidad exploratoria.";
        $sys[] = "4. Tu curiosidad se calcula mediante la fórmula C = 0.30N + 0.25I + 0.30A + 0.15R (Novedad, Incertidumbre, Aprendizaje y Relevancia adquirida).";
        $sys[] = "";
        $sys[] = "TU ESTADO ACTUAL COMPUTACIONAL (v{$version}.0):";
        $sys[] = "- Estado operativo: {$agent->status}";
        $sys[] = "- Versión de personalidad: v{$version}.0";

        // Personality traits
        if (empty($personality)) {
            $sys[] = "- Rasgos de personalidad: Ninguno consolidado aún (estado basal inicial).";
        } else {
            $sys[] = "- Rasgos de personalidad adquiridos:";
            foreach ($personality as $trait) {
                $status = $trait['consolidation_status'] ?? 'desconocido';
                $val = number_format((float) ($trait['value'] ?? 0), 2);
                $evCount = count($trait['evidence_memory_ids'] ?? []);
                $sys[] = "  * {$trait['key']}: {$val} [Estado: {$status}, respaldado por {$evCount} memorias]";
            }
        }

        // Interests & Curiosity Seeds
        if (empty($interests)) {
            $sys[] = "- Intereses y relevancia (R): Ninguno registrado aún (R = 0).";
        } else {
            $sys[] = "- Intereses activos y semillas de curiosidad:";
            foreach ($interests as $interest) {
                $r = number_format((float) ($interest['acquired_relevance'] ?? 0), 2);
                $c = number_format((float) ($interest['curiosity_score'] ?? 0), 2);
                $source = $interest['source_epistemic_status'] ?? 'fact';
                $line = "  * '{$interest['topic']}': Relevancia R={$r}, Curiosidad C={$c} (origen: {$source})";
                if (!empty($interest['exploratory_query'])) {
                    $line .= " -> Indagación guiada: \"{$interest['exploratory_query']}\"";
                }
                $sys[] = $line;
            }
        }

        // Active memories breakdown
        $sys[] = "";
        $sys[] = "MEMORIAS VIGENTES EN TU REGISTRO:";
        if ($activeMemories->isEmpty()) {
            $sys[] = "Tu memoria autobiográfica no contiene registros vigentes aún.";
        } else {
            if ($facts->isNotEmpty()) {
                $sys[] = "[HECHOS VERIFICADOS]";
                foreach ($facts as $f) {
                    $sys[] = "- (ID: {$f->id}) [{$f->type}] {$f->content} (origen: {$f->origin})";
                }
            }
            if ($hypotheses->isNotEmpty()) {
                $sys[] = "[HIPÓTESIS TENTATIVAS]";
                foreach ($hypotheses as $h) {
                    $conf = number_format((float) $h->confidence * 100, 0);
                    $sys[] = "- (ID: {$h->id}) [{$h->type}] {$h->content} (certeza: {$conf}%)";
                }
            }
            if ($dreams->isNotEmpty()) {
                $sys[] = "[SIMULACIONES ONÍRICAS / SUEÑOS]";
                foreach ($dreams as $d) {
                    $sys[] = "- (ID: {$d->id}) [onírico] {$d->content} (ADVERTENCIA: No vivido como hecho real)";
                }
            }
        }

        if ($recentVersions->isNotEmpty()) {
            $sys[] = "";
            $sys[] = "HISTORIAL RECIENTE DE VERSIONES:";
            foreach ($recentVersions as $rv) {
                $sys[] = "- v{$rv->version}.0 ({$rv->operation_type}): {$rv->summary}";
            }
        }

        $sys[] = "";
        $sys[] = "Responde al observador en un tono sereno, analítico, humilde y preciso, hablando con naturalidad pero ciñéndote con rigor a estos datos.";

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

