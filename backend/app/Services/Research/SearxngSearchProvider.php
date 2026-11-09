<?php

namespace App\Services\Research;

use Illuminate\Support\Facades\Http;

/**
 * Buscador general autoalojado. Habla con nuestra propia infraestructura local (SearXNG),
 * no con la web abierta, por lo que no pasa por WebGate.
 */
class SearxngSearchProvider implements SearchProvider
{
    public function search(string $query, int $limit): array
    {
        $base = rtrim((string) config('research.searxng_url'), '/');

        try {
            $response = Http::timeout(15)->acceptJson()->get("{$base}/search", [
                'q' => $query,
                'format' => 'json',
            ]);
        } catch (\Throwable $e) {
            throw new SearchUnavailableException(
                "No se pudo contactar al buscador ({$base}). ¿Está corriendo SearXNG (docker compose up searxng)?"
            );
        }

        if (!$response->successful()) {
            throw new SearchUnavailableException(
                "El buscador respondió HTTP {$response->status()}. Verifica que SearXNG tenga habilitado el formato json."
            );
        }

        return collect($response->json('results') ?? [])
            ->filter(fn ($r) => is_array($r) && !empty($r['url']) && is_string($r['url']))
            ->map(fn ($r) => [
                'url' => $r['url'],
                'title' => (string) ($r['title'] ?? ''),
                'snippet' => (string) ($r['content'] ?? ''),
            ])
            ->take($limit)
            ->values()
            ->all();
    }
}
