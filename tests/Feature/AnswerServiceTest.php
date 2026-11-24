<?php

use App\Contracts\ChatProvider;
use App\Contracts\EmbeddingProvider;
use App\Enums\MessageRole;
use App\Exceptions\ChatRateLimitedException;
use App\Models\Chunk;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\User;
use App\Services\Rag\AnswerService;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeChatProvider;
use Tests\Support\FakeEmbeddingProvider;

beforeEach(function () {
    config(['docchat.rag.top_k' => 5, 'docchat.rag.similarity_threshold' => 0.5]);
    $this->app->instance(EmbeddingProvider::class, new FakeEmbeddingProvider);

    $this->user = User::factory()->create();
    $this->conversation = Conversation::factory()->for($this->user)->create(['title' => null]);
    $this->document = Document::factory()->ready()->for($this->user)->create(['title' => 'Handbook']);
});

function useChat(FakeChatProvider $chat): FakeChatProvider
{
    app()->instance(ChatProvider::class, $chat);

    return $chat;
}

function addChunk(Document $document, float $similarity, int $index = 0, int $page = 1, string $content = 'Refunds take 14 days.'): Chunk
{
    return Chunk::factory()->for($document)->create([
        'chunk_index' => $index,
        'page_number' => $page,
        'content' => $content,
        'embedding' => FakeEmbeddingProvider::withSimilarity($similarity, axis: $index + 1),
    ]);
}

it('refuses without calling the chat model when nothing passes the threshold', function () {
    addChunk($this->document, 0.2);

    $chat = Mockery::mock(ChatProvider::class);
    $chat->shouldNotReceive('stream');
    $this->app->instance(ChatProvider::class, $chat);

    $message = app(AnswerService::class)->answer($this->conversation, 'Unrelated?');

    expect($message->role)->toBe(MessageRole::Assistant)
        ->and($message->content)->toBe("I couldn't find that in your documents.")
        ->and($message->citations)->toBe([])
        ->and($message->latency_ms)->not->toBeNull()
        ->and($message->prompt_tokens)->toBeNull();

    Http::assertNothingSent();
});

it('streams the answer, saves both messages and maps citations', function () {
    $first = addChunk($this->document, 0.9, 0, page: 3, content: 'Refunds take 14 days.');
    addChunk($this->document, 0.8, 1, page: 5, content: 'Shipping is free over 50 euros.');
    $chat = useChat(new FakeChatProvider(['Refunds take ', '14 days [1].'], promptTokens: 321, completionTokens: 12, usageEstimated: true));

    $deltas = [];
    $message = app(AnswerService::class)->answer($this->conversation, 'How long do refunds take?', function (string $delta) use (&$deltas) {
        $deltas[] = $delta;
    });

    expect($deltas)->toBe(['Refunds take ', '14 days [1].'])
        ->and($message->content)->toBe('Refunds take 14 days [1].')
        ->and($message->prompt_tokens)->toBe(321)
        ->and($message->completion_tokens)->toBe(12)
        ->and($message->usage_estimated)->toBeTrue()
        ->and($message->latency_ms)->toBeGreaterThanOrEqual(0)
        ->and($message->error)->toBeNull()
        ->and($message->citations)->toHaveCount(1)
        ->and($message->citations[0])->toMatchArray(['n' => 1, 'chunk_id' => $first->id, 'title' => 'Handbook', 'page' => 3]);

    $roles = $this->conversation->messages()->pluck('role')->all();

    expect($roles)->toBe([MessageRole::User, MessageRole::Assistant])
        ->and($this->conversation->refresh()->title)->toBe('How long do refunds take?')
        ->and($chat->calls[0][0]['content'])->toContain('[1] (Handbook, page 3)');
});

it('sends the last history messages with a follow-up question', function () {
    addChunk($this->document, 0.9);
    $chat = useChat(new FakeChatProvider);

    $service = app(AnswerService::class);
    $service->answer($this->conversation, 'First question?');
    $service->answer($this->conversation, 'And the second one?');

    $sent = $chat->calls[1];

    expect(array_column($sent, 'role'))->toBe(['system', 'user', 'assistant', 'user'])
        ->and($sent[1]['content'])->toBe('First question?')
        ->and(end($sent)['content'])->toBe('And the second one?');
});

it('keeps the partial answer and a busy error when the model is rate limited mid-stream', function () {
    addChunk($this->document, 0.9);
    useChat(new FakeChatProvider(['Partial answer [1]'], throwAfter: new ChatRateLimitedException('429')));

    $message = app(AnswerService::class)->answer($this->conversation, 'Question?');

    expect($message->content)->toBe('Partial answer [1]')
        ->and($message->error)->toBe('The model is busy, try again in a minute.')
        ->and($message->citations)->toHaveCount(1)
        ->and($this->conversation->messages()->count())->toBe(2);
});

it('only searches the documents the conversation is scoped to', function () {
    $other = Document::factory()->ready()->for($this->user)->create();
    addChunk($other, 0.9);
    $this->conversation->documents()->attach($this->document);

    $message = app(AnswerService::class)->answer($this->conversation, 'Question?');

    expect($message->content)->toBe(AnswerService::NOT_FOUND);
});
