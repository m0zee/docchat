<?php

namespace App\Contracts;

use App\Services\Chat\ChatResult;
use Generator;

interface ChatProvider
{
    /**
     * Stream an answer. Yields text deltas and returns the final result.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return Generator<int, string, mixed, ChatResult>
     */
    public function stream(array $messages, array $options = []): Generator;
}
