<?php

namespace App\Services\Research;

use Illuminate\Container\Attributes\Bind;

#[Bind(SearxngSearchProvider::class)]
interface SearchProvider
{
    /**
     * @return list<array{url: string, title: string, snippet: string}>
     *
     * @throws SearchUnavailableException
     */
    public function search(string $query, int $limit): array;
}
