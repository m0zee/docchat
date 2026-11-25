<?php

namespace App\Services\Cache;

final readonly class CachedAnswer
{
    /**
     * @param  list<array<string, mixed>>  $citations
     */
    public function __construct(
        public int $id,
        public string $answer,
        public array $citations,
        public int $tokensSaved,
        public string $hit,
        public float $similarity,
    ) {}
}
