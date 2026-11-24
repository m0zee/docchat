<?php

namespace App\Evals;

final readonly class EvalCase
{
    /**
     * @param  list<string>  $expectedKeywords
     */
    public function __construct(
        public string $question,
        public array $expectedKeywords = [],
        public ?string $expectedDocument = null,
        public ?int $expectedPage = null,
        public bool $shouldRefuse = false,
    ) {}
}
