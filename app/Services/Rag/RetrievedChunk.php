<?php

namespace App\Services\Rag;

final readonly class RetrievedChunk
{
    public function __construct(
        public int $chunkId,
        public int $documentId,
        public string $documentTitle,
        public int $pageNumber,
        public string $content,
        public float $similarity,
    ) {}
}
