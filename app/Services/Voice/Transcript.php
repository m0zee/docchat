<?php

namespace App\Services\Voice;

final readonly class Transcript
{
    public function __construct(
        public string $text,
        public ?float $seconds = null,
        public ?string $language = null,
    ) {}
}
