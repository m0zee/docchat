<?php

use App\Contracts\ChatProvider;
use App\Contracts\EmbeddingProvider;
use App\Enums\ApiAbility;
use App\Enums\ApiKeyType;
use App\Enums\ConversationChannel;
use App\Models\ApiKey;
use App\Models\Chunk;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\User;
use App\Services\Widget\WidgetSettings;
use Livewire\Livewire;
use Tests\Support\FakeChatProvider;
use Tests\Support\FakeEmbeddingProvider;

beforeEach(function () {
    config(['app.url' => 'https://docchat.test', 'docchat.rag.similarity_threshold' => 0.5, 'docchat.cache.answers' => false]);
    app()->instance(EmbeddingProvider::class, new FakeEmbeddingProvider);
    app()->instance(ChatProvider::class, new FakeChatProvider(['We open at **9** [1].']));

    $this->owner = User::factory()->create();
    $this->workspace = $this->owner->currentWorkspace();
    $this->document = Document::factory()->ready()->for($this->owner)->create(['title' => 'Hours']);
    Chunk::factory()->for($this->document)->create(['page_number' => 1, 'embedding' => FakeEmbeddingProvider::withSimilarity(0.9)]);
    $this->key = WidgetSettings::rotateKey($this->workspace, ['https://shop.example.com']);
    $this->visitor = 'w'.str_repeat('a1', 18);
});

function widgetAsk(string $origin, array $body = [], ?string $key = null)
{
    return test()->withToken($key ?? test()->key)
        ->withHeader('Origin', $origin)
        ->postJson('/api/v1/ask', $body + ['question' => 'When do you open?', 'external_conversation_id' => test()->visitor]);
}

test('a public key asks from an allowed site, as a guest widget conversation', function () {
    $response = widgetAsk('https://shop.example.com', ['channel' => 'telegram', 'document_ids' => [999]])
        ->assertOk()
        ->assertJsonPath('data.answer', 'We open at **9** [1].')
        ->assertHeader('Access-Control-Allow-Origin');

    $conversation = Conversation::withoutGlobalScopes()->find($response->json('data.conversation_id'));
    expect($conversation->channel)->toBe(ConversationChannel::Widget)
        ->and($conversation->user_id)->toBeNull()
        ->and($conversation->external_id)->toBe($this->visitor)
        ->and($conversation->documents()->count())->toBe(0);
});

test('a public key is refused on other sites and without an Origin', function () {
    widgetAsk('https://evil.example.net')->assertForbidden()->assertJsonPath('error.code', 'origin_not_allowed');
    widgetAsk('https://shop.example.com.evil.net')->assertForbidden();

    $this->withToken($this->key)->postJson('/api/v1/ask', ['question' => 'Hi', 'external_conversation_id' => $this->visitor])
        ->assertForbidden();
});

test('DocChat\'s own site is always allowed, for the preview', function () {
    widgetAsk('https://docchat.test')->assertOk();
});

test('a public key can only ask', function () {
    foreach (['/api/v1/documents', '/api/v1/workspace'] as $url) {
        $this->withToken($this->key)->withHeader('Origin', 'https://shop.example.com')->getJson($url)
            ->assertForbidden()->assertJsonPath('error.code', 'wrong_key_type');
    }
});

test('widget conversations need a random visitor id', function () {
    widgetAsk('https://shop.example.com', ['external_conversation_id' => 'telegram:1001'])
        ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['fields' => ['external_conversation_id']]]]);
});

test('the public limit is per visitor', function () {
    $key = WidgetSettings::key($this->workspace);
    $key->update(['rate_limit_per_minute' => 1]);

    widgetAsk('https://shop.example.com')->assertOk();
    widgetAsk('https://shop.example.com')->assertStatus(429);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);
    widgetAsk('https://shop.example.com')->assertOk();
});

test('the widget streams and marks refusals so it can show the handoff line', function () {
    WidgetSettings::save($this->workspace, ['handoff_email' => 'help@shop.example.com', 'handoff_text' => 'Talk to us:']);
    app()->instance(EmbeddingProvider::class, new FakeEmbeddingProvider(FakeEmbeddingProvider::withSimilarity(0.1, axis: 2)));

    $body = $this->withToken($this->key)->withHeader('Origin', 'https://shop.example.com')
        ->post('/api/v1/ask/stream', ['question' => 'Do you sell boats?', 'external_conversation_id' => $this->visitor], ['Accept' => 'text/event-stream'])
        ->assertOk()
        ->streamedContent();

    expect($body)->toContain('event: done')->toContain('"refused":true');

    $this->withToken($this->key)->withHeader('Origin', 'https://shop.example.com')->getJson('/api/v1/widget')
        ->assertOk()
        ->assertJsonPath('data.handoff.email', 'help@shop.example.com')
        ->assertJsonPath('data.handoff.text', 'Talk to us:')
        ->assertJsonPath('data.title', 'Ask our documents');
});

test('CORS preflight lets the widget send its key header', function () {
    $this->call('OPTIONS', '/api/v1/ask/stream', [], [], [], [
        'HTTP_ORIGIN' => 'https://shop.example.com',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
    ])->assertNoContent()->assertHeader('Access-Control-Allow-Methods');
});

test('widget.js is served small and cacheable', function () {
    $response = $this->get('/widget.js')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/javascript; charset=utf-8')
        ->assertHeader('Cache-Control', 'max-age=3600, public');

    expect(strlen($response->getContent()))->toBeLessThan(30 * 1024)
        ->and($response->headers->get('Set-Cookie'))->toBeNull();

    $this->get('/widget.js', ['If-None-Match' => $response->headers->get('ETag')])->assertStatus(304);
});

test('owners set up the widget, get the embed code and rotate the key', function () {
    $member = User::factory()->create();
    $this->workspace->members()->attach($member->id, ['role' => 'member']);
    $member->switchWorkspace($this->workspace);
    $this->actingAs($member)->get(route('workspace.widget'))->assertForbidden();

    $old = WidgetSettings::key($this->workspace);

    $page = Livewire::actingAs($this->owner)->test('pages::workspace.widget')
        ->set('title', 'Ask Harbor')
        ->set('color', '#FF0000')
        ->set('origins', "harbor.example\nhttps://www.harbor.example/contact")
        ->set('handoffEmail', 'team@harbor.example')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('data-key="'.$this->key.'"');

    expect(WidgetSettings::for($this->workspace->refresh()))->toMatchArray(['title' => 'Ask Harbor', 'color' => '#ff0000', 'handoff_email' => 'team@harbor.example'])
        ->and($old->refresh()->allowed_origins)->toBe(['https://harbor.example', 'https://www.harbor.example']);

    $page->set('origins', 'not a site, ftp://x.example')->call('save')->assertHasErrors('origins');
    expect($old->refresh()->allowed_origins)->toBe(['https://harbor.example', 'https://www.harbor.example']);

    $page->call('rotateKey');
    expect($old->refresh()->isRevoked())->toBeTrue()
        ->and(WidgetSettings::plainKey($this->workspace->refresh()))->not->toBe($this->key)
        ->and(WidgetSettings::key($this->workspace)->allowed_origins)->toBe(['https://harbor.example', 'https://www.harbor.example']);
});

test('the first save creates the key, and the preview needs one', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)->get(route('workspace.widget.preview'))->assertNotFound();

    Livewire::actingAs($owner)->test('pages::workspace.widget')->call('save')->assertHasNoErrors();

    $key = WidgetSettings::key($owner->currentWorkspace()->refresh());
    expect($key->type)->toBe(ApiKeyType::Public)
        ->and($key->abilities)->toBe([ApiAbility::Ask->value]);

    $this->actingAs($owner)->get(route('workspace.widget.preview'))
        ->assertOk()
        ->assertSee('data-key="'.WidgetSettings::plainKey($owner->currentWorkspace()).'"', false);
});

test('a live key still works without an Origin', function () {
    [, $live] = ApiKey::issue($this->workspace, 'server', [ApiAbility::Ask]);

    $this->withToken($live)->postJson('/api/v1/ask', ['question' => 'When do you open?'])->assertOk();
});
