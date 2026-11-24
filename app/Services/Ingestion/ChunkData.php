<?php

namespace App\Services\Ingestion;

final readonly class ChunkData
{
    public function __construct(
        public int $pageNumber,
        public int $chunkIndex,
        public string $content,
        public int $tokenEstimate,
    ) {}
}
