<?php

use App\Contracts\ChatProvider;
use App\Contracts\EmbeddingProvider;
use App\Enums\ConversationChannel;
use App\Models\Chunk;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Message;
use App\Models\UsageEvent;
use App\Models\User;
use App\Services\Cache\AnswerCache;
use App\Services\Documents\DocumentService;
use App\Services\Embedding\CachingEmbeddingProvider;
use App\Services\Rag\AnswerService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Lottery;
use Tests\Support\FakeChatProvider;
use Tests\Support\FakeEmbeddingProvider;

beforeEach(function () {
    // Storing an entry prunes by lottery; keep it out of these counts.
    Lottery::alwaysLose();
    config([
        'docchat.rag.similarity_threshold' => 0.5,
        'docchat.cache.answers' => true,
        'docchat.cache.semantic_threshold' => 0.95,
        'docchat.cache.ttl_days' => 7,
    ]);
    $this->embeddings = new FakeEmbeddingProvider;
    app()->instance(EmbeddingProvider::class, $this->embeddings);
    $this->chat = new FakeChatProvider(['Refunds take ', '14 days [1].'], promptTokens: 400, completionTokens: 30);
    app()->instance(ChatProvider::class, $this->chat);

    $this->user = User::factory()->create();
    $this->workspace = $this->user->currentWorkspace();
    $this->document = Document::factory()->ready()->for($this->user)->create();
    Chunk::factory()->for($this->document)->create(['content' => 'Refunds take 14 days.', 'embedding' => FakeEmbeddingProvider::withSimilarity(0.9)]);
});

function ask(string $question, ?Conversation $conversation = null, ?callable $onDelta = null): Message
{
    $conversation ??= Conversation::forChannel(test()->workspace, ConversationChannel::Widget, uniqid('visitor-'));

    return app(AnswerService::class)->answer($conversation, $question, $onDelta);
}

afterEach(function () {
    Lottery::determineResultsNormally();
});

test('an exact repeat is answered from the cache without calling the model', function () {
    $first = ask('How long do refunds take?');
    $streamed = '';
    $second = ask('  how long do REFUNDS take ', onDelta: function (string $d) use (&$streamed) {
        $streamed .= $d;
    });

    expect(count($this->chat->calls))->toBe(1)
        ->and($second->content)->toBe($first->content)
        ->and($second->citations)->toEqual($first->citations)
        ->and($second->cache_hit)->toBe('exact')
        ->and($second->prompt_tokens)->toBeNull()
        ->and($streamed)->toBe($first->content)
        ->and(count($this->embeddings->queries))->toBe(1);

    $event = UsageEvent::query()->latest('id')->first();
    expect($event->cache_hit)->toBe('exact')
        ->and($event->tokens_saved)->toBe(430)
        ->and($event->prompt_tokens)->toBe(0);
});

test('a near-identical question is a semantic hit', function () {
    ask('How long do refunds take?');

    // 0.99 similar to the cached question's vector (axis 0).
    $this->embeddings->queryVector = FakeEmbeddingProvider::withSimilarity(0.99, axis: 7);
    $hit = ask('What is the refund time?');

    expect($hit->cache_hit)->toBe('semantic')->and(count($this->chat->calls))->toBe(1);
});

test('a different question under the threshold calls the model', function () {
    ask('How long do refunds take?');

    $this->embeddings->queryVector = FakeEmbeddingProvider::withSimilarity(0.9, axis: 7);
    $miss = ask('Can I return opened items?');

    expect($miss->cache_hit)->toBeNull()->and(count($this->chat->calls))->toBe(2);
});

test('a document change makes cached answers stale', function () {
    Storage::fake('local');
    ask('How long do refunds take?');

    app(DocumentService::class)->delete(Document::factory()->ready()->for($this->user)->create());

    expect(ask('How long do refunds take?')->cache_hit)->toBeNull()
        ->and(count($this->chat->calls))->toBe(2)
        ->and(app(AnswerCache::class)->prune())->toBe(1);
});

test('the cache never crosses workspaces or document scopes', function () {
    ask('How long do refunds take?');

    $other = User::factory()->create();
    $theirDoc = Document::factory()->ready()->for($other)->create();
    Chunk::factory()->for($theirDoc)->create(['embedding' => FakeEmbeddingProvider::withSimilarity(0.9)]);
    $theirs = app(AnswerService::class)->answer(Conversation::factory()->for($other)->create(), 'How long do refunds take?');

    $scoped = Conversation::factory()->for($this->user)->create();
    $scoped->documents()->attach($this->document);
    $narrow = ask('How long do refunds take?', $scoped);

    expect($theirs->cache_hit)->toBeNull()
        ->and($narrow->cache_hit)->toBeNull()
        ->and(count($this->chat->calls))->toBe(3);
});

test('follow-up questions are never served or stored from the cache', function () {
    $conversation = Conversation::forChannel($this->workspace, ConversationChannel::Widget, 'visitor-1');
    ask('Hello?', $conversation);

    // A different vector, so only an entry stored for the follow-up could match.
    $this->embeddings->queryVector = FakeEmbeddingProvider::withSimilarity(0.6, axis: 7);
    ask('How long do refunds take?', $conversation);

    expect(ask('How long do refunds take?')->cache_hit)->toBeNull()
        ->and(count($this->chat->calls))->toBe(3);
});

test('refusals and errors are not cached', function () {
    app()->instance(ChatProvider::class, $chat = new FakeChatProvider(["I couldn't find that in your documents."]));
    ask('How long do refunds take?');
    ask('How long do refunds take?');

    expect(count($chat->calls))->toBe(2);
});

test('the cache can be turned off per workspace and for evals', function () {
    $this->workspace->update(['settings' => ['answer_cache' => false]]);
    ask('How long do refunds take?');
    ask('How long do refunds take?');
    expect(count($this->chat->calls))->toBe(2);

    $this->workspace->update(['settings' => ['answer_cache' => true]]);
    $eval = $this->workspace->conversations()->create(['channel' => ConversationChannel::Eval]);
    ask('Is there a fee?');
    expect(ask('Is there a fee?', $eval)->cache_hit)->toBeNull();
});

test('expired answers are not served', function () {
    ask('How long do refunds take?');
    $this->travel(8)->days();

    expect(ask('How long do refunds take?')->cache_hit)->toBeNull();
});

test('query embeddings are cached', function () {
    $inner = new FakeEmbeddingProvider;
    $cached = new CachingEmbeddingProvider($inner);

    $a = $cached->embedQuery('What are the hours?');
    $b = $cached->embedQuery('what are the HOURS');
    $cached->embedQuery('Something else');

    expect($a)->toBe($b)->and($inner->queries)->toBe(['What are the hours?', 'Something else']);
});

test('the web chat shows when an answer came from the cache', function () {
    $conversation = Conversation::factory()->for($this->user)->create();
    $conversation->messages()->create(['role' => 'user', 'content' => 'Q']);
    $conversation->messages()->create(['role' => 'assistant', 'content' => 'A', 'citations' => [], 'latency_ms' => 12, 'cache_hit' => 'exact']);

    $this->actingAs($this->user)->get(route('chat.show', $conversation))->assertSee('from cache');
});
