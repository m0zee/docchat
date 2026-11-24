<?php

namespace App\Services\Ingestion;

final readonly class ParsedDocument
{
    /**
     * @param  list<ParsedPage>  $pages
     */
    public function __construct(
        public array $pages,
        public int $pageCount,
    ) {
    }
}
