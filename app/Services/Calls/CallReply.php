<?php

namespace App\Services\Calls;

final readonly class CallReply
{
    /**
     * @param  list<array<string, mixed>>  $citations
     */
    public function __construct(
        public string $text,
        public bool $end = false,
        public array $citations = [],
        public bool $refused = false,
    ) {}
}
