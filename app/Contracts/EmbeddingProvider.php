<?php

namespace App\Contracts;

interface EmbeddingProvider
{
    /**
     * @param  list<string>  $texts
     * @return list<array<int, float>>
     */
    public function embedDocuments(array $texts, string $title = ''): array;

    /**
     * @return array<int, float>
     */
    public function embedQuery(string $text): array;
}
