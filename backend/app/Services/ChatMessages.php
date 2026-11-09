<?php

namespace App\Services;

/**
 * Arma la lista de turnos de conversación que se envía al modelo (historial + mensaje actual).
 * Garantiza el formato que exigen las APIs: empieza en `user`, alterna roles y termina en `user`.
 */
class ChatMessages
{
    public const MAX_CONTENT_CHARS = 2000;

    /**
     * Si la respuesta se cortó por límite de tokens, la recorta al último final de frase para no dejarla
     * a mitad de palabra. No toca respuestas JSON (las usa el motor de indagación).
     */
    public static function trimToLastSentence(string $text): string
    {
        $text = rtrim($text);
        if ($text === '' || str_starts_with($text, '{') || preg_match('/[.!?…»")\]]$/u', $text)) {
            return $text;
        }

        if (preg_match('/^(.*[.!?…])\s/us', $text, $m) && mb_strlen($m[1]) >= mb_strlen($text) * 0.5) {
            return $m[1];
        }

        return $text . '…';
    }

    /**
     * @param  array<int, array{role?: string, content?: string}>  $history  turnos previos, del más antiguo al más reciente
     * @return list<array{role: 'user'|'assistant', content: string}>
     */
    public static function build(array $history, string $userMessage): array
    {
        $turns = [];

        foreach ($history as $turn) {
            $text = trim((string) ($turn['content'] ?? ''));
            if ($text === '') {
                continue;
            }
            $role = in_array($turn['role'] ?? '', ['assistant', 'agent', 'model'], true) ? 'assistant' : 'user';
            $turns[] = ['role' => $role, 'content' => mb_substr($text, 0, self::MAX_CONTENT_CHARS)];
        }

        $turns[] = ['role' => 'user', 'content' => $userMessage];

        // Une turnos consecutivos del mismo rol.
        $merged = [];
        foreach ($turns as $t) {
            $last = count($merged) - 1;
            if ($last >= 0 && $merged[$last]['role'] === $t['role']) {
                $merged[$last]['content'] .= "\n\n" . $t['content'];
            } else {
                $merged[] = $t;
            }
        }

        // Debe empezar por el usuario.
        while ($merged && $merged[0]['role'] !== 'user') {
            array_shift($merged);
        }

        return $merged;
    }
}
