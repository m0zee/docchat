<?php

use App\Contracts\ChatProvider;
use App\Contracts\EmbeddingProvider;
use App\Enums\ApiAbility;
use App\Enums\ApiKeyType;
use App\Models\ApiKey;
use App\Models\Chunk;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Message;
use App\Models\UsageEvent;
use App\Models\User;
use App\Services\Cache\AnswerCache;
use App\Services\Rag\AnswerService;
use App\Services\Rag\DevicePrompt;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\FakeEmbeddingProvider;

beforeEach(function () {
    config(['docchat.rag.similarity_threshold' => 0.5, 'docchat.device.top_k' => 2]);
    $this->app->instance(EmbeddingProvider::class, new FakeEmbeddingProvider);

    // The server model must never run in device mode.
    $chat = Mockery::mock(ChatProvider::class);
    $chat->shouldNotReceive('stream');
    $this->app->instance(ChatProvider::class, $chat);

    $this->user = User::factory()->create();
    $this->workspace = $this->user->currentWorkspace();
    $this->document = Document::factory()->ready()->for($this->user)->create(['title' => 'Handbook']);

    foreach ([0.9, 0.85, 0.8] as $i => $similarity) {
        Chunk::factory()->for($this->document)->create([
            'chunk_index' => $i,
            'page_number' => $i + 1,
            'content' => 'Fact number '.($i + 1).'.',
            'embedding' => FakeEmbeddingProvider::withSimilarity($similarity, axis: $i + 1),
        ]);
    }
});

function allowDevice(object $test): void
{
    $test->workspace->update(['settings' => array_merge($test->workspace->settings ?? [], ['device_answers' => true])]);
}

it('prepares a prompt with fewer chunks and saves the device answer with citations', function () {
    $conversation = Conversation::factory()->for($this->user)->create();
    $answers = app(AnswerService::class);

    $prompt = $answers->prepareForDevice($conversation, 'What are the facts?');

    expect($prompt)->toBeInstanceOf(DevicePrompt::class)
        ->and($prompt->messages[0]['role'])->toBe('system')
        ->and($prompt->messages[0]['content'])->toContain('[2] (Handbook, page 2)')
        ->and($prompt->messages[0]['content'])->not->toContain('[3]')
        ->and($prompt->messages[count($prompt->messages) - 1])->toBe(['role' => 'user', 'content' => 'What are the facts?']);

    $message = $answers->completeFromDevice($conversation, $prompt->token, 'Fact one [1] and two [2].');

    expect($message->content)->toBe('Fact one [1] and two [2].')
        ->and($message->ai_source)->toBe('device')
        ->and($message->prompt_tokens)->toBeNull()
        ->and($message->citations)->toHaveCount(2)
        ->and($message->citations[1])->toMatchArray(['n' => 2, 'page' => 2, 'title' => 'Handbook']);

    $usage = UsageEvent::query()->latest('id')->first();
    expect($usage->ai_source)->toBe('device')
        ->and($usage->prompt_tokens)->toBe(0)
        ->and($usage->completion_tokens)->toBe(0);
});

it('accepts a device token only once and only for its conversation', function () {
    $conversation = Conversation::factory()->for($this->user)->create();
    $other = Conversation::factory()->for($this->user)->create();
    $answers = app(AnswerService::class);

    $prompt = $answers->prepareForDevice($conversation, 'Facts?');

    expect($answers->completeFromDevice($other, $prompt->token, 'Hijack [1]'))->toBeNull();

    // A wrong conversation burns the token too.
    expect($answers->completeFromDevice($conversation, $prompt->token, 'Late [1]'))->toBeNull();

    $prompt = $answers->prepareForDevice($conversation, 'Facts again?');
    expect($answers->completeFromDevice($conversation, $prompt->token, 'Yes [1]'))->toBeInstanceOf(Message::class)
        ->and($answers->completeFromDevice($conversation, $prompt->token, 'Again [1]'))->toBeNull();
});

it('never puts device answers in the shared answer cache', function () {
    $conversation = Conversation::factory()->for($this->user)->create();
    $answers = app(AnswerService::class);

    $prompt = $answers->prepareForDevice($conversation, 'Facts?');
    $answers->completeFromDevice($conversation, $prompt->token, 'Made up by the browser [1].');

    expect(DB::table('answer_cache')->count())->toBe(0);
});

it('answers from the cache without involving the device', function () {
    $conversation = Conversation::factory()->for($this->user)->create();
    app(AnswerCache::class)->store($this->workspace, 'Facts?', FakeEmbeddingProvider::withSimilarity(1.0), null, 'Cached [1].', [['n' => 1, 'chunk_id' => 1, 'document_id' => $this->document->id, 'title' => 'Handbook', 'page' => 1, 'snippet' => 'x', 'similarity' => 0.9]], 10, 5);

    $result = app(AnswerService::class)->prepareForDevice($conversation, 'Facts?');

    expect($result)->toBeInstanceOf(Message::class)
        ->and($result->content)->toBe('Cached [1].')
        ->and($result->cache_hit)->toBe('exact');
});

it('records a device failure as an error message', function () {
    $conversation = Conversation::factory()->for($this->user)->create();
    $answers = app(AnswerService::class);
    $prompt = $answers->prepareForDevice($conversation, 'Facts?');

    $message = $answers->completeFromDevice($conversation, $prompt->token, '', 'The AI on this device failed: QuotaExceededError');

    expect($message->error)->toContain('QuotaExceededError')->and($message->content)->toBe('');
});

it('runs the two device steps over the API for widget visitors when the owner allows it', function () {
    [, $plain] = ApiKey::issue($this->workspace, 'Website widget', [ApiAbility::Ask], ApiKeyType::Public, null, ['https://shop.example.com']);
    $headers = ['Authorization' => 'Bearer '.$plain, 'Origin' => 'https://shop.example.com'];
    $body = ['question' => 'What are the facts?', 'external_conversation_id' => 'visitor_abcdefghijklmnop'];

    $this->postJson('/api/v1/ask/device', $body, $headers)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'device_answers_off');

    $this->getJson('/api/v1/widget', $headers)->assertJsonPath('data.device_ai', false);

    allowDevice($this);

    $this->getJson('/api/v1/widget', $headers)->assertJsonPath('data.device_ai', true);

    $prepared = $this->postJson('/api/v1/ask/device', $body, $headers)
        ->assertOk()
        ->assertJsonPath('data.status', 'generate')
        ->json('data');

    expect($prepared['token'])->toHaveLength(40)
        ->and($prepared['messages'])->toBeArray();

    $this->postJson('/api/v1/ask/device/complete', [
        'conversation_id' => $prepared['conversation_id'],
        'token' => $prepared['token'],
        'answer' => 'Fact one [1].',
        'engine' => 'chrome',
    ], $headers)
        ->assertOk()
        ->assertJsonPath('data.answer', 'Fact one [1].')
        ->assertJsonPath('data.citations.0.page', 1);

    $this->postJson('/api/v1/ask/device/complete', [
        'conversation_id' => $prepared['conversation_id'],
        'token' => $prepared['token'],
        'answer' => 'Twice',
    ], $headers)->assertStatus(410);
});

it('keeps widget visitors out of other conversations', function () {
    allowDevice($this);
    [, $plain] = ApiKey::issue($this->workspace, 'Website widget', [ApiAbility::Ask], ApiKeyType::Public, null, ['https://shop.example.com']);
    $web = Conversation::factory()->for($this->user)->create();

    $this->postJson('/api/v1/ask/device/complete', [
        'conversation_id' => $web->id,
        'token' => str_repeat('a', 40),
        'answer' => 'x',
    ], ['Authorization' => 'Bearer '.$plain, 'Origin' => 'https://shop.example.com'])->assertNotFound();
});

it('lets a member answer on their device from the chat page', function () {
    $page = Livewire::actingAs($this->user)->test('pages::chat.index')
        ->set('question', 'What are the facts?')
        ->call('sendOnDevice')
        ->assertSet('pending', 'What are the facts?')
        ->assertSet('question', '');

    $prepared = $page->effects['returns'][0];

    expect($prepared['status'])->toBe('generate');

    $page->call('completeOnDevice', $prepared['token'], 'Fact two [2].')
        ->assertSet('pending', null)
        ->assertSee('Fact two [2].')
        ->assertSee('written on this device');
});

it('shows the device picker only where device AI is enabled', function () {
    $this->actingAs($this->user)->get(route('chat.index'))->assertSee('data-device-picker', false);

    config(['docchat.device.enabled' => false]);

    $this->actingAs($this->user)->get(route('chat.index'))->assertDontSee('data-device-picker', false);
});
