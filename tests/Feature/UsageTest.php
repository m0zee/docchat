<?php

use App\Contracts\ChatProvider;
use App\Contracts\EmbeddingProvider;
use App\Enums\ConversationChannel;
use App\Enums\UsageType;
use App\Models\Chunk;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\UsageEvent;
use App\Models\User;
use App\Services\Rag\AnswerService;
use Livewire\Livewire;
use Tests\Support\FakeChatProvider;
use Tests\Support\FakeEmbeddingProvider;

beforeEach(function () {
    config(['docchat.rag.similarity_threshold' => 0.5, 'docchat.ingestion.chars_per_token' => 4]);
    app()->instance(EmbeddingProvider::class, new FakeEmbeddingProvider);
    app()->instance(ChatProvider::class, new FakeChatProvider(['Answer [1].'], promptTokens: 300, completionTokens: 20));
    $this->user = User::factory()->create();
    $this->workspace = $this->user->currentWorkspace();
    $document = Document::factory()->ready()->for($this->user)->create();
    Chunk::factory()->for($document)->create(['embedding' => FakeEmbeddingProvider::withSimilarity(0.9)]);
});

test('every answered question records a usage event with its channel and tokens', function () {
    $conversation = Conversation::forChannel($this->workspace, ConversationChannel::Telegram, 'telegram:1');

    app(AnswerService::class)->answer($conversation, 'What is the refund window?');

    $event = UsageEvent::query()->sole();

    expect($event->workspace_id)->toBe($this->workspace->id)
        ->and($event->channel)->toBe(ConversationChannel::Telegram)
        ->and($event->type)->toBe(UsageType::Question)
        ->and($event->prompt_tokens)->toBe(300)
        ->and($event->completion_tokens)->toBe(20)
        ->and($event->embedding_tokens)->toBe((int) ceil(mb_strlen('What is the refund window?') / 4))
        ->and($event->latency_ms)->not->toBeNull()
        ->and($event->api_key_id)->toBeNull();
});

test('refusals are recorded with no chat tokens', function () {
    app()->instance(EmbeddingProvider::class, new FakeEmbeddingProvider(FakeEmbeddingProvider::withSimilarity(0.1, axis: 5)));
    $conversation = Conversation::factory()->for($this->user)->create();

    app(AnswerService::class)->answer($conversation, 'Unrelated?');

    expect(UsageEvent::query()->sole()->prompt_tokens)->toBe(0);
});

test('the usage page totals the month per channel, for owners only', function () {
    UsageEvent::query()->create(['workspace_id' => $this->workspace->id, 'channel' => 'web', 'type' => 'question', 'prompt_tokens' => 100, 'completion_tokens' => 10]);
    UsageEvent::query()->create(['workspace_id' => $this->workspace->id, 'channel' => 'widget', 'type' => 'question', 'prompt_tokens' => 50, 'cache_hit' => 'exact', 'tokens_saved' => 60]);
    UsageEvent::query()->create(['workspace_id' => $this->workspace->id, 'channel' => 'widget', 'type' => 'question', 'prompt_tokens' => 70]);
    UsageEvent::query()->create(['workspace_id' => $this->workspace->id, 'channel' => 'web', 'type' => 'question', 'prompt_tokens' => 999, 'created_at' => now()->subMonths(2)]);
    UsageEvent::query()->create(['workspace_id' => User::factory()->create()->currentWorkspace()->id, 'channel' => 'web', 'type' => 'question', 'prompt_tokens' => 5000]);

    $component = Livewire::actingAs($this->user)->test('pages::workspace.usage');

    expect($component->get('totals'))->toMatchArray(['questions' => 3, 'prompt_tokens' => 220, 'cache_hits' => 1, 'tokens_saved' => 60])
        ->and(collect($component->get('rows'))->pluck('channel')->all())->toBe(['Web', 'Widget']);

    $component->assertSee('33%');

    $member = User::factory()->create();
    $this->workspace->members()->attach($member->id, ['role' => 'member']);
    $member->switchWorkspace($this->workspace);
    $this->actingAs($member)->get(route('workspace.usage'))->assertForbidden();
});
