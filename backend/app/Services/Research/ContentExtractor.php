<?php

namespace App\Services\Research;

/**
 * Extracción determinista (sin IA) de texto legible y frases relevantes.
 * El HTML se trata siempre como dato no confiable: nunca se ejecuta ni se interpreta como instrucción.
 */
class ContentExtractor
{
    private const STOPWORDS = [
        'para', 'como', 'pero', 'sobre', 'entre', 'desde', 'hasta', 'cuando', 'donde', 'porque', 'esta', 'este',
        'esto', 'estos', 'estas', 'unos', 'unas', 'cual', 'cuales', 'tiene', 'tienen', 'puede', 'pueden', 'hace',
        'what', 'which', 'with', 'that', 'this', 'from', 'have', 'does', 'about', 'there', 'their', 'into', 'than',
        'qué', 'que', 'por', 'una', 'del', 'los', 'las', 'con', 'son', 'ser', 'hay', 'más', 'muy', 'the', 'and', 'are',
    ];

    /**
     * @return array{title: string, text: string}
     */
    public function extract(string $body, string $contentType): array
    {
        $max = (int) config('research.max_text_chars');

        if ($contentType === 'text/plain') {
            return ['title' => '', 'text' => $this->clip($this->normalize($body), $max)];
        }

        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // Fuerza UTF-8 sin ejecutar nada; las entidades externas no se cargan.
        $dom->loadHTML('<?xml encoding="UTF-8">' . $body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $title = trim((string) $dom->getElementsByTagName('title')->item(0)?->textContent);

        foreach (['script', 'style', 'noscript', 'nav', 'header', 'footer', 'aside', 'form', 'iframe', 'svg', 'template'] as $tag) {
            $nodes = $dom->getElementsByTagName($tag);
            for ($i = $nodes->length - 1; $i >= 0; $i--) {
                $node = $nodes->item($i);
                $node?->parentNode?->removeChild($node);
            }
        }

        $root = $dom->getElementsByTagName('article')->item(0)
            ?? $dom->getElementsByTagName('main')->item(0)
            ?? $dom->getElementsByTagName('body')->item(0);

        // Sin separadores, textContent pega encabezados, divs y listas en una sola línea.
        if ($root instanceof \DOMElement) {
            $this->separateBlocks($dom, $root);
        }

        $paragraphs = [];
        if ($root instanceof \DOMElement) {
            foreach ($root->getElementsByTagName('p') as $p) {
                $t = $this->normalize($p->textContent);
                if (mb_strlen($t) >= 40) {
                    $paragraphs[] = $t;
                }
            }
        }

        $text = $paragraphs ? implode("\n\n", $paragraphs) : $this->normalize((string) $root?->textContent);

        return ['title' => mb_substr($this->normalize($title), 0, 300), 'text' => $this->clip($text, $max)];
    }

    /**
     * Frases del texto más relacionadas con la consulta (coincidencia de términos), en su orden original.
     *
     * @return list<array{text: string, score: float}>
     */
    public function relevantSentences(string $text, string $query, int $limit = 3): array
    {
        $terms = $this->terms($query);
        if (!$terms) {
            return [];
        }

        // Se parte por puntuación y por saltos de línea (los bloques HTML ya están separados).
        $sentences = array_map(
            fn ($x) => preg_replace('/\s+/u', ' ', $x) ?? $x,
            preg_split('/(?<=[.!?])\s+|\R+/u', $text) ?: []
        );
        $scored = [];

        foreach ($sentences as $i => $sentence) {
            $len = mb_strlen($sentence);
            if ($len < 40 || $len > 500) {
                continue;
            }
            $lower = mb_strtolower($sentence);
            $hits = 0;
            foreach ($terms as $term) {
                if (str_contains($lower, $term)) {
                    $hits++;
                }
            }
            if ($hits > 0) {
                $scored[] = ['i' => $i, 'text' => trim($sentence), 'score' => round($hits / count($terms), 4)];
            }
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score'] ?: $a['i'] <=> $b['i']);
        $top = array_slice($scored, 0, $limit);
        usort($top, fn ($a, $b) => $a['i'] <=> $b['i']);

        return array_map(fn ($s) => ['text' => $s['text'], 'score' => $s['score']], $top);
    }

    /** @return list<string> */
    public function terms(string $query): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(
            $words,
            fn ($w) => mb_strlen($w) >= 4 && !in_array($w, self::STOPWORDS, true)
        )));
    }

    private function separateBlocks(\DOMDocument $dom, \DOMElement $root): void
    {
        $blocks = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'div', 'li', 'tr', 'section', 'blockquote', 'pre', 'br', 'dt', 'dd', 'figcaption'];
        $nodes = [];
        foreach ($blocks as $tag) {
            foreach ($root->getElementsByTagName($tag) as $n) {
                $nodes[] = $n;
            }
        }
        foreach ($nodes as $n) {
            $n->parentNode?->insertBefore($dom->createTextNode("\n"), $n);
            $n->parentNode?->insertBefore($dom->createTextNode("\n"), $n->nextSibling);
        }
    }

    private function normalize(string $s): string
    {
        return trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', preg_replace('/\R{3,}/u', "\n\n", $s) ?? '') ?? '');
    }

    private function clip(string $s, int $max): string
    {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max) : $s;
    }
}
