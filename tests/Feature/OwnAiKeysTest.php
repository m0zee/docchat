<?php

use App\Contracts\ChatProvider;
use App\Contracts\EmbeddingProvider;
use App\Enums\AiProvider;
use App\Enums\AiSource;
use App\Models\Chunk;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\UsageEvent;
use App\Models\User;
use App\Models\WorkspaceAiKey;
use App\Services\Ai\AiProviders;
use App\Services\Chat\GeminiChatProvider;
use App\Services\Chat\GroqChatProvider;
use App\Services\Embedding\CachingEmbeddingProvider;
use App\Services\Embedding\GeminiEmbeddingProvider;
use App\Services\Rag\AnswerService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\FakeChatProvider;
use Tests\Support\FakeEmbeddingProvider;

beforeEach(function () {
    config([
        'docchat.rag.similarity_threshold' => 0.5,
        'docchat.chat.drivers.groq.model' => 'openai/gpt-oss-20b',
        'docchat.chat.drivers.groq.base_url' => 'https://api.groq.com/openai/v1',
        'docchat.chat.drivers.groq.api_key' => 'gsk-app-key',
        'docchat.chat.drivers.gemini.model' => 'gemini-test-flash',
        'docchat.chat.drivers.gemini.base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai',
        'docchat.embedding.model' => 'gemini-embedding-2',
        'docchat.embedding.api_key' => 'app-gemini-key',
    ]);

    $this->user = User::factory()->create();
    $this->workspace = $this->user->currentWorkspace();
    $this->document = Document::factory()->ready()->for($this->user)->create(['title' => 'Handbook']);
    Chunk::factory()->for($this->document)->create([
        'content' => 'Refunds take 14 days.',
        'embedding' => FakeEmbeddingProvider::withSimilarity(0.9),
    ]);
    $this->conversation = Conversation::factory()->for($this->user)->create();
});

function ownKey(object $test, AiProvider $provider, string $key, ?string $model = null): WorkspaceAiKey
{
    return WorkspaceAiKey::query()->create([
        'workspace_id' => $test->workspace->id,
        'provider' => $provider,
        'api_key' => $key,
        'key_hint' => WorkspaceAiKey::hintFor($key),
        'model' => $model,
    ]);
}

function chooseChat(object $test, string $provider): void
{
    $test->workspace->update(['settings' => array_merge($test->workspace->settings ?? [], [AiProviders::CHAT_SETTING => $provider])]);
}

function openAiStream(string $text): string
{
    return 'data: '.json_encode(['model' => 'm', 'choices' => [['delta' => ['content' => $text], 'finish_reason' => 'stop']]])."\n\n"
        .'data: '.json_encode(['choices' => [], 'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 5]])."\n\n"
        ."data: [DONE]\n\n";
}

it('stores the key encrypted and never serializes it', function () {
    $key = ownKey($this, AiProvider::Groq, 'gsk_secret_value_1234567890');

    $raw = DB::table('workspace_ai_keys')->where('id', $key->id)->value('api_key');

    expect($raw)->not->toContain('gsk_secret_value')
        ->and(decrypt($raw, false))->toBe('gsk_secret_value_1234567890')
        ->and($key->fresh()->api_key)->toBe('gsk_secret_value_1234567890')
        ->and($key->toArray())->not->toHaveKey('api_key')
        ->and($key->key_hint)->toBe('…7890');
});

it('uses the app provider when the workspace has no own key or did not choose it', function () {
    $this->app->instance(ChatProvider::class, $fake = new FakeChatProvider);
    ownKey($this, AiProvider::Groq, 'gsk_workspace_key_1234567890');

    $resolved = app(AiProviders::class)->chat($this->workspace);

    expect($resolved->provider)->toBe($fake)
        ->and($resolved->source)->toBe(AiSource::Platform);
});

it('falls back to the workspace key when the app has no chat key of its own', function () {
    config(['docchat.chat.driver' => 'groq', 'docchat.chat.drivers.groq.api_key' => '']);
    ownKey($this, AiProvider::Gemini, 'gemini_workspace_key_1234567890');
    $key = ownKey($this, AiProvider::Groq, 'gsk_workspace_key_1234567890');

    $resolved = app(AiProviders::class)->chat($this->workspace);

    expect($resolved->provider)->toBeInstanceOf(GroqChatProvider::class)
        ->and($resolved->source)->toBe(AiSource::Workspace)
        ->and($resolved->key->id)->toBe($key->id);
});

it('falls back to a workspace gemini key when that is the only one', function () {
    config(['docchat.chat.driver' => 'groq', 'docchat.chat.drivers.groq.api_key' => '']);
    ownKey($this, AiProvider::Gemini, 'gemini_workspace_key_1234567890');

    expect(app(AiProviders::class)->chat($this->workspace)->provider)->toBeInstanceOf(GeminiChatProvider::class);
});

it('stays on the app key when the app has one, even with workspace keys saved', function () {
    $this->app->instance(ChatProvider::class, $fake = new FakeChatProvider);
    ownKey($this, AiProvider::Groq, 'gsk_workspace_key_1234567890');

    expect(app(AiProviders::class)->chat($this->workspace)->provider)->toBe($fake);
});

it('answers with the workspace groq key and model and records the source', function () {
    $this->app->instance(EmbeddingProvider::class, new FakeEmbeddingProvider);
    $key = ownKey($this, AiProvider::Groq, 'gsk_workspace_key_1234567890', 'llama-own-model');
    chooseChat($this, 'groq');

    Http::fake(['api.groq.com/*' => Http::response(openAiStream('Refunds take 14 days [1].'), 200, ['Content-Type' => 'text/event-stream'])]);

    $message = app(AnswerService::class)->answer($this->conversation, 'How long do refunds take?');

    expect($message->content)->toBe('Refunds take 14 days [1].')
        ->and($message->ai_source)->toBe('workspace')
        ->and(UsageEvent::query()->latest('id')->first()->ai_source)->toBe('workspace')
        ->and($key->fresh()->last_used_at)->not->toBeNull();

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer gsk_workspace_key_1234567890')
        && $request['model'] === 'llama-own-model');
});

it('answers with the workspace gemini key on the OpenAI-compatible endpoint', function () {
    $this->app->instance(EmbeddingProvider::class, new FakeEmbeddingProvider);
    ownKey($this, AiProvider::Gemini, 'AIza_workspace_key_1234567890');
    chooseChat($this, 'gemini');
    $this->workspace->update(['settings' => array_merge($this->workspace->settings, [AiProviders::EMBEDDING_SETTING => false])]);

    Http::fake(['generativelanguage.googleapis.com/v1beta/openai/chat/completions' => Http::response(openAiStream('Fourteen days [1].'), 200, ['Content-Type' => 'text/event-stream'])]);

    $message = app(AnswerService::class)->answer($this->conversation, 'Refunds?');

    expect($message->content)->toBe('Fourteen days [1].')->and($message->ai_source)->toBe('workspace');

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer AIza_workspace_key_1234567890')
        && $request['model'] === 'gemini-test-flash'
        && isset($request['max_tokens'])
        && $request['stream'] === true);
});

it('shows a clear error and marks the key when the provider rejects it', function () {
    $this->app->instance(EmbeddingProvider::class, new FakeEmbeddingProvider);
    $key = ownKey($this, AiProvider::Groq, 'gsk_revoked_key_1234567890');
    chooseChat($this, 'groq');

    Http::fake(['api.groq.com/*' => Http::response(['error' => ['message' => 'Invalid API Key']], 401)]);

    $message = app(AnswerService::class)->answer($this->conversation, 'Refunds?');

    expect($message->error)->toContain('did not accept your workspace key')
        ->and($key->fresh()->last_error)->toContain('status 401');
});

it('embeds questions and documents with the workspace gemini key', function () {
    $this->app->instance(EmbeddingProvider::class, new FakeEmbeddingProvider);
    ownKey($this, AiProvider::Gemini, 'AIza_workspace_key_1234567890');

    $embeddings = app(AiProviders::class)->embeddings($this->workspace);

    expect($embeddings)->toBeInstanceOf(CachingEmbeddingProvider::class);

    config(['docchat.cache.query_embeddings' => false]);
    $embeddings = app(AiProviders::class)->embeddings($this->workspace);
    expect($embeddings)->toBeInstanceOf(GeminiEmbeddingProvider::class);

    $dimensions = (int) config('docchat.embedding.dimensions');
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'embeddings' => [['values' => array_fill(0, $dimensions, 0.1)]],
    ])]);

    $embeddings->embedQuery('hello');

    Http::assertSent(fn (Request $request) => $request->hasHeader('x-goog-api-key', 'AIza_workspace_key_1234567890'));
});

it('keeps embeddings on the app key when the owner turns that off', function () {
    $this->app->instance(EmbeddingProvider::class, $fake = new FakeEmbeddingProvider);
    ownKey($this, AiProvider::Gemini, 'AIza_workspace_key_1234567890');
    $this->workspace->update(['settings' => [AiProviders::EMBEDDING_SETTING => false]]);

    expect(app(AiProviders::class)->embeddings($this->workspace->fresh()))->toBe($fake);
});

it('reports a rejected own gemini embedding key clearly', function () {
    config(['docchat.cache.query_embeddings' => false]);
    $key = ownKey($this, AiProvider::Gemini, 'AIza_bad_key_1234567890');

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['status' => 'INVALID_ARGUMENT']], 400)]);

    $message = app(AnswerService::class)->answer($this->conversation, 'Refunds?');

    expect($message->error)->toContain('did not accept your workspace key')
        ->and($key->fresh()->last_error)->not->toBeNull();
});

it('lets the owner check and save a key without ever showing it again', function () {
    Http::fake(['api.groq.com/openai/v1/models' => Http::response(['data' => []])]);

    Livewire::actingAs($this->user)->test('pages::workspace.ai-keys')
        ->set('keys.groq', 'gsk_new_owner_key_1234567890')
        ->call('saveKey', 'groq')
        ->assertHasNoErrors()
        ->assertSet('keys.groq', '')
        ->assertDontSee('gsk_new_owner_key_1234567890')
        ->assertSee('…7890')
        ->set('chatProvider', 'groq')
        ->call('saveChoice')
        ->assertHasNoErrors();

    $key = WorkspaceAiKey::query()->sole();

    expect($key->api_key)->toBe('gsk_new_owner_key_1234567890')
        ->and($key->verified_at)->not->toBeNull()
        ->and($this->workspace->fresh()->setting(AiProviders::CHAT_SETTING))->toBe('groq');

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer gsk_new_owner_key_1234567890'));
});

it('refuses a key the provider rejects', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => []], 400)]);

    Livewire::actingAs($this->user)->test('pages::workspace.ai-keys')
        ->set('keys.gemini', 'AIza_wrong_key_1234567890')
        ->call('saveKey', 'gemini')
        ->assertHasErrors('keys.gemini');

    expect(WorkspaceAiKey::query()->count())->toBe(0);
});

it('cannot choose a provider without a saved key, and removing the key switches back', function () {
    Http::fake(['api.groq.com/*' => Http::response(['data' => []])]);

    $page = Livewire::actingAs($this->user)->test('pages::workspace.ai-keys')
        ->set('chatProvider', 'gemini')
        ->call('saveChoice')
        ->assertHasErrors('chatProvider');

    $page->set('keys.groq', 'gsk_new_owner_key_1234567890')
        ->call('saveKey', 'groq')
        ->set('chatProvider', 'groq')
        ->call('saveChoice')
        ->call('removeKey', 'groq')
        ->assertSet('chatProvider', 'platform');

    expect(WorkspaceAiKey::query()->count())->toBe(0)
        ->and($this->workspace->fresh()->setting(AiProviders::CHAT_SETTING))->toBe('platform');
});

it('is only for workspace owners', function () {
    $member = User::factory()->create();
    $this->workspace->members()->attach($member->id, ['role' => 'member']);
    $member->forceFill(['current_workspace_id' => $this->workspace->id])->save();

    $this->actingAs($member)->get(route('workspace.ai-keys'))->assertForbidden();
    $this->actingAs($this->user)->get(route('workspace.ai-keys'))->assertOk()->assertSee('AI keys');
});

it('builds the provider classes with an own key', function () {
    expect((new GroqChatProvider('k'))->usesOwnKey())->toBeTrue()
        ->and(app(GroqChatProvider::class)->usesOwnKey())->toBeFalse()
        ->and(app(GeminiChatProvider::class)->usesOwnKey())->toBeFalse();
});
