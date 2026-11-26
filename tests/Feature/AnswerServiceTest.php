<?php

use App\Contracts\ChatProvider;
use App\Contracts\EmbeddingProvider;
use App\Enums\MessageRole;
use App\Exceptions\ChatRateLimitedException;
use App\Models\Chunk;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Message;
use App\Models\User;
use App\Services\Rag\AnswerService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

it('searches for a follow-up together with the earlier questions, but asks the model the question as typed', function () {
    addChunk($this->document, 0.9);
    $embeddings = new FakeEmbeddingProvider;
    $this->app->instance(EmbeddingProvider::class, $embeddings);
    $chat = useChat(new FakeChatProvider);

    $service = app(AnswerService::class);
    $service->answer($this->conversation, 'What tax does a freelancer pay?');
    $service->answer($this->conversation, 'And a software exporter?');
    $service->answer($this->conversation, 'What about who is not registered?');

    expect($embeddings->queries)->toBe([
        'What tax does a freelancer pay?',
        "What tax does a freelancer pay?\nAnd a software exporter?",
        "What tax does a freelancer pay?\nAnd a software exporter?\nWhat about who is not registered?",
    ])->and(end($chat->calls[2])['content'])->toBe('What about who is not registered?');
});

it('keeps only the last two earlier questions in the search text', function () {
    $history = collect(['One?', 'Answer', 'Two?', 'Answer', 'Three?', 'Answer'])->map(fn (string $content, int $i) => new Message([
        'role' => $i % 2 === 0 ? MessageRole::User : MessageRole::Assistant,
        'content' => $content,
    ]));

    expect(AnswerService::searchText('Four?', $history))->toBe("Two?\nThree?\nFour?")
        ->and(AnswerService::searchText('Only?', collect()))->toBe('Only?');
});

it('leaves questions that found nothing out of the search text', function () {
    $history = collect([
        [MessageRole::User, 'Freelancer tax 2026?'],
        [MessageRole::Assistant, 'It is 15% [1].'],
        [MessageRole::User, 'What about the attacks?'],
        [MessageRole::Assistant, "I don't know based on your documents."],
    ])->map(fn (array $m) => new Message(['role' => $m[0], 'content' => $m[1]]));

    expect(AnswerService::searchText('And exporters?', $history))->toBe("Freelancer tax 2026?\nAnd exporters?");
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

it('logs usage and retrieval stats but never the question or document text', function () {
    addChunk($this->document, 0.9, content: 'Secret document text.');
    useChat(new FakeChatProvider(['Answer [1].'], promptTokens: 50, completionTokens: 5));

    $channel = Mockery::mock();
    $channel->shouldReceive('info')->once()->withArgs(function (string $event, array $context): bool {
        $encoded = json_encode($context);

        return $event === 'answer'
            && $context['prompt_tokens'] === 50
            && $context['chunks'] === 1
            && $context['refused'] === false
            && ! str_contains($encoded, 'Private question')
            && ! str_contains($encoded, 'Secret document text');
    });
    Log::shouldReceive('channel')->with('docchat')->andReturn($channel);

    app(AnswerService::class)->answer($this->conversation, 'Private question?');
});

it('shows only the time under a refusal that made no model call', function () {
    $message = app(AnswerService::class)->answer($this->conversation, 'Nothing matches');

    Livewire\Livewire::actingAs($this->user)
        ->test('pages::chat.index', ['conversation' => $this->conversation])
        ->assertSee(number_format($message->latency_ms / 1000, 1).' s')
        ->assertDontSee('tokens');
});
