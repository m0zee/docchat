<?php

use App\Contracts\ChatProvider;
use App\Exceptions\ChatProviderException;
use App\Exceptions\ChatRateLimitedException;
use App\Services\Chat\ChatResult;
use App\Services\Chat\GroqChatProvider;
use App\Services\Chat\OpenRouterChatProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'docchat.chat.driver' => 'groq',
        'docchat.chat.max_output_tokens' => 800,
        'docchat.chat.drivers.groq' => [
            'model' => 'openai/gpt-oss-20b',
            'base_url' => 'https://api.groq.com/openai/v1',
            'api_key' => 'gsk-test',
            'reasoning_effort' => 'low',
        ],
        'docchat.chat.drivers.openrouter' => [
            'model' => 'test/model:free',
            'base_url' => 'https://openrouter.ai/api/v1',
            'api_key' => 'or-test',
            'referer' => 'https://docchat.test',
            'title' => 'DocChat',
        ],
    ]);
});

/**
 * @param  list<array<string, mixed>|string>  $events
 */
function sseBody(array $events): string
{
    return implode('', array_map(
        fn (array|string $event): string => is_string($event) ? $event : 'data: '.json_encode($event)."\n\n",
        $events,
    ));
}

/**
 * @return array<string, mixed>
 */
function deltaEvent(string $content, ?string $finish = null): array
{
    return [
        'model' => 'openai/gpt-oss-20b',
        'choices' => [['index' => 0, 'delta' => ['content' => $content], 'finish_reason' => $finish]],
    ];
}

/**
 * @return array{0: list<string>, 1: ChatResult}
 */
function drain(Generator $stream): array
{
    $deltas = [];

    foreach ($stream as $delta) {
        $deltas[] = $delta;
    }

    return [$deltas, $stream->getReturn()];
}

$messages = [
    ['role' => 'system', 'content' => 'Answer from context.'],
    ['role' => 'user', 'content' => 'What is DocChat?'],
];

it('streams deltas in order and returns the joined content with usage', function () use ($messages) {
    Http::fake(['api.groq.com/*' => Http::response(sseBody([
        ": keep-alive\n\n",
        ['choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => '']]]],
        deltaEvent('DocChat '),
        deltaEvent('answers [1]'),
        deltaEvent('.', 'stop'),
        ['choices' => [], 'x_groq' => ['usage' => ['prompt_tokens' => 120, 'completion_tokens' => 9]]],
        "data: [DONE]\n\n",
    ]), 200, ['Content-Type' => 'text/event-stream'])]);

    [$deltas, $result] = drain(app(GroqChatProvider::class)->stream($messages));

    expect($deltas)->toBe(['DocChat ', 'answers [1]', '.'])
        ->and($result->content)->toBe('DocChat answers [1].')
        ->and($result->promptTokens)->toBe(120)
        ->and($result->completionTokens)->toBe(9)
        ->and($result->usageEstimated)->toBeFalse()
        ->and($result->finishReason)->toBe('stop')
        ->and($result->model)->toBe('openai/gpt-oss-20b');
});

it('reads usage from a top-level usage chunk', function () use ($messages) {
    Http::fake(['*' => Http::response(sseBody([
        deltaEvent('Hi', 'stop'),
        ['choices' => [], 'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 2]],
        "data: [DONE]\n\n",
    ]))]);

    [, $result] = drain(app(OpenRouterChatProvider::class)->stream($messages));

    expect($result->promptTokens)->toBe(50)
        ->and($result->usageEstimated)->toBeFalse();
});

it('keeps reasoning out of the content', function () use ($messages) {
    Http::fake(['*' => Http::response(sseBody([
        ['choices' => [['index' => 0, 'delta' => ['reasoning' => 'Let me think about the context...']]]],
        deltaEvent('The answer.', 'stop'),
        "data: [DONE]\n\n",
    ]))]);

    [$deltas, $result] = drain(app(GroqChatProvider::class)->stream($messages));

    expect($deltas)->toBe(['The answer.'])
        ->and($result->content)->toBe('The answer.')
        ->and($result->content)->not->toContain('think');
});

it('estimates usage when the stream has none', function () use ($messages) {
    Http::fake(['*' => Http::response(sseBody([deltaEvent('Short answer.', 'stop'), "data: [DONE]\n\n"]))]);

    [, $result] = drain(app(GroqChatProvider::class)->stream($messages));

    expect($result->usageEstimated)->toBeTrue()
        ->and($result->promptTokens)->toBeGreaterThan(0)
        ->and($result->completionTokens)->toBeGreaterThan(0);
});

it('parses an event split across lines without a trailing newline', function () use ($messages) {
    Http::fake(['*' => Http::response(
        'data: '.json_encode(deltaEvent('one '))."\r\n\r\ndata: ".json_encode(deltaEvent('two', 'stop')),
    )]);

    [$deltas] = drain(app(GroqChatProvider::class)->stream($messages));

    expect($deltas)->toBe(['one ', 'two']);
});

it('sends model, token cap, stream flag and bearer key to Groq', function () use ($messages) {
    Http::fake(['*' => Http::response(sseBody(["data: [DONE]\n\n"]))]);

    drain(app(GroqChatProvider::class)->stream($messages));

    Http::assertSent(function (Request $request) use ($messages): bool {
        return $request->url() === 'https://api.groq.com/openai/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer gsk-test')
            && $request['model'] === 'openai/gpt-oss-20b'
            && $request['max_completion_tokens'] === 800
            && $request['stream'] === true
            && $request['reasoning_effort'] === 'low'
            && $request['include_reasoning'] === false
            && $request['stream_options'] === ['include_usage' => true]
            && $request['messages'] === $messages;
    });
});

it('sends attribution headers and excludes reasoning on OpenRouter', function () use ($messages) {
    Http::fake(['*' => Http::response(sseBody(["data: [DONE]\n\n"]))]);

    drain(app(OpenRouterChatProvider::class)->stream($messages));

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
        && $request->hasHeader('Authorization', 'Bearer or-test')
        && $request->hasHeader('HTTP-Referer', 'https://docchat.test')
        && $request->hasHeader('X-Title', 'DocChat')
        && $request['model'] === 'test/model:free'
        && $request['reasoning'] === ['exclude' => true]);
});

it('maps a 429 to a rate-limited exception', function () use ($messages) {
    Http::fake(['*' => Http::response(['error' => ['message' => 'slow down']], 429)]);

    expect(fn () => drain(app(GroqChatProvider::class)->stream($messages)))
        ->toThrow(ChatRateLimitedException::class);
});

it('maps a 500 to a provider exception without leaking the key', function () use ($messages) {
    Http::fake(['*' => Http::response('oops', 500)]);

    try {
        drain(app(GroqChatProvider::class)->stream($messages));
        $this->fail('No exception thrown.');
    } catch (ChatProviderException $exception) {
        expect($exception)->not->toBeInstanceOf(ChatRateLimitedException::class)
            ->and($exception->getMessage())->not->toContain('gsk-test');
    }
});

it('maps an error event mid-stream to an exception', function () use ($messages) {
    Http::fake(['*' => Http::response(sseBody([
        deltaEvent('Partial '),
        ['error' => ['code' => 429, 'message' => 'rate limited'], 'choices' => [['delta' => ['content' => ''], 'finish_reason' => 'error']]],
    ]))]);

    $deltas = [];

    expect(function () use ($messages, &$deltas) {
        foreach (app(OpenRouterChatProvider::class)->stream($messages) as $delta) {
            $deltas[] = $delta;
        }
    })->toThrow(ChatRateLimitedException::class);

    expect($deltas)->toBe(['Partial ']);
});

it('asks for a key when none is set', function () use ($messages) {
    config(['docchat.chat.drivers.groq.api_key' => '']);

    expect(fn () => drain(app(GroqChatProvider::class)->stream($messages)))
        ->toThrow(ChatProviderException::class, 'Set GROQ_API_KEY in .env.');
});

it('binds the chat provider from config', function () {
    expect(app(ChatProvider::class))->toBeInstanceOf(GroqChatProvider::class);

    config(['docchat.chat.driver' => 'openrouter']);
    expect(app(ChatProvider::class))->toBeInstanceOf(OpenRouterChatProvider::class);

    config(['docchat.chat.driver' => 'nope']);
    expect(fn () => app(ChatProvider::class))->toThrow(InvalidArgumentException::class);
});
