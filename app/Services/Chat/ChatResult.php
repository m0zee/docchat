<?php

namespace App\Services\Chat;

final readonly class ChatResult
{
    public function __construct(
        public string $content,
        public int $promptTokens,
        public int $completionTokens,
        public bool $usageEstimated,
        public string $model,
        public ?string $finishReason,
    ) {}
}
