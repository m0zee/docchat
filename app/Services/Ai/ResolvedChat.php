<?php

namespace App\Services\Ai;

use App\Contracts\ChatProvider;
use App\Enums\AiSource;
use App\Models\WorkspaceAiKey;

/**
 * The chat provider a workspace's answers use, and whose key it runs on.
 */
final readonly class ResolvedChat
{
    public function __construct(
        public ChatProvider $provider,
        public AiSource $source,
        public ?WorkspaceAiKey $key = null,
    ) {}
}
