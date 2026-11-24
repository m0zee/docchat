<?php

namespace App\Services\Ingestion;

final readonly class ParsedPage
{
    public function __construct(
        public int $pageNumber,
        public string $text,
    ) {
    }
}
