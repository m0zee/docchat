<?php

namespace Tests\Support;

use App\Contracts\ChatProvider;
use App\Services\Chat\ChatResult;
use Generator;
use Throwable;

/**
 * Streams scripted deltas, optionally throwing part-way through.
 */
class FakeChatProvider implements ChatProvider
{
    /** @var list<list<array{role: string, content: string}>> */
    public array $calls = [];

    /**
     * @param  list<string>  $deltas
     */
    public function __construct(
        public array $deltas = ['Answer [1].'],
        public ?Throwable $throwAfter = null,
        public int $promptTokens = 100,
        public int $completionTokens = 10,
        public bool $usageEstimated = false,
    ) {}

    public function stream(array $messages, array $options = []): Generator
    {
        $this->calls[] = $messages;

        foreach ($this->deltas as $delta) {
            yield $delta;
        }

        if ($this->throwAfter !== null) {
            throw $this->throwAfter;
        }

        return new ChatResult(
            content: implode('', $this->deltas),
            promptTokens: $this->promptTokens,
            completionTokens: $this->completionTokens,
            usageEstimated: $this->usageEstimated,
            model: 'fake-model',
            finishReason: 'stop',
        );
    }
}
