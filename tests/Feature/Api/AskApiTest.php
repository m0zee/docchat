<?php

use App\Contracts\ChatProvider;
use App\Contracts\EmbeddingProvider;
use App\Enums\ApiAbility;
use App\Enums\ConversationChannel;
use App\Exceptions\ChatRateLimitedException;
use App\Models\ApiKey;
use App\Models\Chunk;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\UsageEvent;
use App\Models\User;
use Tests\Support\FakeChatProvider;
use Tests\Support\FakeEmbeddingProvider;

beforeEach(function () {
    config(['docchat.rag.similarity_threshold' => 0.5, 'docchat.cache.answers' => false]);
    app()->instance(EmbeddingProvider::class, new FakeEmbeddingProvider);
    $this->chat = new FakeChatProvider(['Refunds take **14 days** [1].'], promptTokens: 200, completionTokens: 9);
    app()->instance(ChatProvider::class, $this->chat);

    $this->user = User::factory()->create();
    $this->workspace = $this->user->currentWorkspace();
    $this->document = Document::factory()->ready()->for($this->user)->create(['title' => 'Policy']);
    Chunk::factory()->for($this->document)->create(['page_number' => 2, 'content' => 'Refunds take 14 days.', 'embedding' => FakeEmbeddingProvider::withSimilarity(0.9)]);
    [$this->apiKey, $this->key] = ApiKey::issue($this->workspace, 'bot', [ApiAbility::Ask]);
});

test('ask returns the answer, citations, conversation and usage', function () {
    $this->withToken($this->key)->postJson('/api/v1/ask', ['question' => 'How long do refunds take?'])
        ->assertOk()
        ->assertJsonPath('data.answer', 'Refunds take **14 days** [1].')
        ->assertJsonPath('data.refused', false)
        ->assertJsonPath('data.citations.0.document_id', $this->document->id)
        ->assertJsonPath('data.citations.0.title', 'Policy')
        ->assertJsonPath('data.citations.0.page', 2)
        ->assertJsonPath('data.usage.prompt_tokens', 200)
        ->assertJsonStructure(['data' => ['conversation_id', 'message_id', 'citations' => [['snippet']]]])
        ->assertJsonMissingPath('data.formatted');

    $event = UsageEvent::query()->sole();
    expect($event->channel)->toBe(ConversationChannel::Api)->and($event->api_key_id)->toBe($this->apiKey->id);
});

test('the same external conversation id continues the conversation', function () {
    $first = $this->withToken($this->key)->postJson('/api/v1/ask', [
        'question' => 'How long do refunds take?', 'channel' => 'telegram', 'external_conversation_id' => 'telegram:42',
    ])->json('data.conversation_id');

    $second = $this->withToken($this->key)->postJson('/api/v1/ask', [
        'question' => 'And for sale items?', 'channel' => 'telegram', 'external_conversation_id' => 'telegram:42',
    ])->json('data.conversation_id');

    $conversation = Conversation::withoutGlobalScopes()->find($first);

    expect($second)->toBe($first)
        ->and($conversation->channel)->toBe(ConversationChannel::Telegram)
        ->and($conversation->messages()->count())->toBe(4)
        // The second prompt carried the first exchange as history.
        ->and(json_encode($this->chat->calls[1]))->toContain('How long do refunds take?');
});

test('format adds ready-to-send parts', function () {
    $this->withToken($this->key)->postJson('/api/v1/ask', ['question' => 'Refunds?', 'format' => 'telegram_html'])
        ->assertOk()
        ->assertJsonPath('data.formatted.0', "Refunds take <b>14 days</b> [1].\n\n<b>Sources</b>\n[1] Policy, p. 2");
});

test('a refusal is a normal answer with refused true', function () {
    app()->instance(EmbeddingProvider::class, new FakeEmbeddingProvider(FakeEmbeddingProvider::withSimilarity(0.1, axis: 9)));

    $this->withToken($this->key)->postJson('/api/v1/ask', ['question' => 'Weather?'])
        ->assertOk()
        ->assertJsonPath('data.refused', true)
        ->assertJsonPath('data.citations', []);
});

test('document_ids limit the search to ready documents of the workspace', function () {
    $theirs = Document::factory()->ready()->create();

    $this->withToken($this->key)->postJson('/api/v1/ask', ['question' => 'q', 'document_ids' => [$theirs->id]])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

    $this->withToken($this->key)->postJson('/api/v1/ask', ['question' => 'q', 'document_ids' => [$this->document->id, $theirs->id]])
        ->assertOk()->assertJsonPath('data.citations.0.document_id', $this->document->id);
});

test('a busy model returns 503 with Retry-After', function () {
    app()->instance(ChatProvider::class, new FakeChatProvider([], throwAfter: new ChatRateLimitedException('429')));

    $this->withToken($this->key)->postJson('/api/v1/ask', ['question' => 'Refunds?'])
        ->assertStatus(503)
        ->assertHeader('Retry-After', '60')
        ->assertJsonPath('error.code', 'model_busy');
});

test('ask needs the ask ability and validates input', function () {
    [, $readOnly] = ApiKey::issue($this->workspace, 'read', [ApiAbility::DocumentsRead]);

    $this->withToken($readOnly)->postJson('/api/v1/ask', ['question' => 'q'])->assertForbidden();
    $this->withToken($this->key)->postJson('/api/v1/ask', ['question' => '', 'channel' => 'web'])
        ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['fields' => ['question', 'channel']]]]);
});

test('a key never retrieves another workspace\'s chunks', function () {
    $other = User::factory()->create();
    [, $otherKey] = ApiKey::issue($other->currentWorkspace(), 'other', [ApiAbility::Ask]);

    $this->withToken($otherKey)->postJson('/api/v1/ask', ['question' => 'How long do refunds take?'])
        ->assertOk()->assertJsonPath('data.refused', true);
});
