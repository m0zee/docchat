<?php

use App\Contracts\ChatProvider;
use App\Contracts\EmbeddingProvider;
use App\Enums\ApiAbility;
use App\Enums\ApiKeyType;
use App\Enums\ConversationChannel;
use App\Enums\DocumentSource;
use App\Enums\DocumentStatus;
use App\Jobs\SendChannelWebhookJob;
use App\Models\ApiKey;
use App\Models\ChannelLink;
use App\Models\ChannelLinkCode;
use App\Models\Chunk;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\User;
use App\Services\Webhooks\WebhookDispatcher;
use App\Services\Webhooks\WebhookSigner;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeChatProvider;
use Tests\Support\FakeEmbeddingProvider;

beforeEach(function () {
    config(['docchat.rag.similarity_threshold' => 0.5, 'docchat.cache.answers' => false, 'docchat.channels.telegram.bot_username' => 'DocChatTestBot']);
    app()->instance(EmbeddingProvider::class, new FakeEmbeddingProvider);
    $this->chat = new FakeChatProvider(['Open 9 to 5 [1].']);
    app()->instance(ChatProvider::class, $this->chat);

    $this->owner = User::factory()->create();
    $this->workspace = $this->owner->currentWorkspace();
    [, $this->service] = ApiKey::issue(null, 'telegram bot', ApiAbility::forServiceKeys(), ApiKeyType::Service);
});

function linkChat(string $chatId = '1001', $test = null): ChannelLink
{
    $test ??= test();
    [, $code] = ChannelLinkCode::issue($test->workspace, ConversationChannel::Telegram, $test->owner);

    return ChannelLinkCode::redeem($code, ConversationChannel::Telegram, $chatId, 'Moiz');
}

test('a code links a chat once', function () {
    [, $code] = ChannelLinkCode::issue($this->workspace, ConversationChannel::Telegram, $this->owner);

    $link = ChannelLinkCode::redeem($code, ConversationChannel::Telegram, '42', 'Support group');

    expect($link->workspace_id)->toBe($this->workspace->id)
        ->and($link->label)->toBe('Support group')
        ->and($link->linked_by_user_id)->toBe($this->owner->id)
        ->and(ChannelLinkCode::redeem($code, ConversationChannel::Telegram, '43'))->toBeNull()
        ->and(ChannelLinkCode::query()->sole()->code_hash)->not->toContain($code);
});

test('a code expires after 15 minutes and only works for its channel', function () {
    [, $code] = ChannelLinkCode::issue($this->workspace, ConversationChannel::Telegram, $this->owner);

    expect(ChannelLinkCode::redeem($code, ConversationChannel::WhatsApp, '42'))->toBeNull();

    $this->travel(16)->minutes();

    expect(ChannelLinkCode::redeem($code, ConversationChannel::Telegram, '42'))->toBeNull()
        ->and(ChannelLinkCode::redeem('not a code', ConversationChannel::Telegram, '42'))->toBeNull();
});

test('linking a chat again moves it to the new workspace', function () {
    linkChat('7');
    $other = User::factory()->create();
    [, $code] = ChannelLinkCode::issue($other->currentWorkspace(), ConversationChannel::Telegram, $other);

    ChannelLinkCode::redeem($code, ConversationChannel::Telegram, '7');

    expect(ChannelLink::withoutGlobalScopes()->sole()->workspace_id)->toBe($other->currentWorkspace()->id);
});

test('the bot links, resolves and unlinks a chat through the API', function () {
    [, $code] = ChannelLinkCode::issue($this->workspace, ConversationChannel::Telegram, $this->owner);

    $this->withToken($this->service)->postJson('/api/v1/channels/telegram/resolve', ['external_id' => '555'])
        ->assertOk()->assertExactJson(['data' => ['linked' => false]]);

    $this->withToken($this->service)->postJson('/api/v1/channels/telegram/link', ['code' => $code, 'external_id' => '555', 'label' => 'Moiz'])
        ->assertOk()
        ->assertJsonPath('data.linked', true)
        ->assertJsonPath('data.workspace.id', $this->workspace->id);

    $this->withToken($this->service)->postJson('/api/v1/channels/telegram/link', ['code' => $code, 'external_id' => '556'])
        ->assertStatus(422)->assertJsonPath('error.code', 'invalid_code');

    $this->withToken($this->service)->postJson('/api/v1/channels/telegram/resolve', ['external_id' => '555'])
        ->assertJsonPath('data.linked', true)
        ->assertJsonPath('data.workspace.name', $this->workspace->name);

    $this->withToken($this->service)->postJson('/api/v1/channels/telegram/unlink', ['external_id' => '555'])
        ->assertJsonPath('data.was_linked', true);

    expect(ChannelLink::withoutGlobalScopes()->count())->toBe(0);
});

test('channel routes need a service key with channels:link', function () {
    [, $live] = ApiKey::issue($this->workspace, 'live', ApiAbility::forLiveKeys());
    [, $noLink] = ApiKey::issue(null, 'other', [ApiAbility::Ask], ApiKeyType::Service);

    $this->withToken($live)->postJson('/api/v1/channels/telegram/resolve', ['external_id' => '1'])
        ->assertForbidden()->assertJsonPath('error.code', 'wrong_key_type');
    $this->withToken($noLink)->postJson('/api/v1/channels/telegram/resolve', ['external_id' => '1'])
        ->assertForbidden()->assertJsonPath('error.code', 'missing_ability');
    $this->withToken($this->service)->postJson('/api/v1/channels/web/resolve', ['external_id' => '1'])->assertNotFound();
});

test('a service key asks in the linked chat\'s workspace and reuses its conversation', function () {
    $document = Document::factory()->ready()->for($this->owner)->create(['title' => 'Hours']);
    Chunk::factory()->for($document)->create(['embedding' => FakeEmbeddingProvider::withSimilarity(0.9)]);
    linkChat('1001');

    $ask = fn (string $question) => $this->withToken($this->service)
        ->withHeader('X-DocChat-Link', 'telegram:1001')
        ->postJson('/api/v1/ask', ['question' => $question, 'channel' => 'telegram', 'external_conversation_id' => 'telegram:1001', 'format' => 'telegram_html']);

    $first = $ask('When are you open?')->assertOk()->assertJsonPath('data.citations.0.document_id', $document->id);
    $second = $ask('And on Sunday?')->assertOk();

    expect($first->json('data.formatted'))->toBeArray()
        ->and($second->json('data.conversation_id'))->toBe($first->json('data.conversation_id'));

    $conversation = Conversation::withoutGlobalScopes()->find($first->json('data.conversation_id'));
    expect($conversation->workspace_id)->toBe($this->workspace->id)
        ->and($conversation->channel)->toBe(ConversationChannel::Telegram);

    // /new: the next question starts a fresh conversation.
    $this->withToken($this->service)->withHeader('X-DocChat-Link', 'telegram:1001')
        ->postJson('/api/v1/conversations/end', ['channel' => 'telegram', 'external_conversation_id' => 'telegram:1001'])
        ->assertJsonPath('data.ended', true);

    expect($ask('Hello?')->json('data.conversation_id'))->not->toBe($first->json('data.conversation_id'));
});

test('a service key without a linked chat is refused', function () {
    $this->withToken($this->service)->postJson('/api/v1/ask', ['question' => 'Hi'])
        ->assertStatus(400)->assertJsonPath('error.code', 'link_required');

    $this->withToken($this->service)->withHeader('X-DocChat-Link', 'telegram:999')->postJson('/api/v1/ask', ['question' => 'Hi'])
        ->assertNotFound()->assertJsonPath('error.code', 'chat_not_linked');

    $this->withToken($this->service)->withHeader('X-DocChat-Link', 'web:1')->getJson('/api/v1/documents')
        ->assertStatus(400);
});

test('a service key cannot reach another workspace\'s documents', function () {
    $other = User::factory()->create();
    $theirs = Document::factory()->ready()->for($other)->create();
    linkChat('1001');

    $this->withToken($this->service)->withHeader('X-DocChat-Link', 'telegram:1001')
        ->getJson('/api/v1/documents/'.$theirs->id)->assertNotFound();
});

test('documents sent to a bot report back through the channel webhook', function () {
    Queue::fake();
    config(['docchat.channels.webhook_url' => 'https://n8n.test/webhook/telegram-docchat', 'docchat.channels.webhook_secret' => 'whsec_bot']);

    $fromBot = Document::factory()->for($this->owner)->create(['source' => DocumentSource::Telegram, 'status' => DocumentStatus::Ready, 'meta' => ['chat_id' => 1001]]);
    $uploaded = Document::factory()->for($this->owner)->create(['status' => DocumentStatus::Ready]);

    app(WebhookDispatcher::class)->document($fromBot, 'document.ready');
    app(WebhookDispatcher::class)->document($uploaded, 'document.ready');

    Queue::assertPushed(SendChannelWebhookJob::class, 1);
    $job = Queue::pushed(SendChannelWebhookJob::class)->first();
    expect($job->data['document']['meta']->chat_id)->toBe(1001);

    Http::fake(['*' => Http::response('ok')]);
    $job->handle();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://n8n.test/webhook/telegram-docchat'
        && WebhookSigner::verify($request->body(), $request->header('X-DocChat-Signature')[0], $request->header('X-DocChat-Timestamp')[0], 'whsec_bot')
        && ! str_contains(serialize($job), 'whsec_bot'));
});

test('owners create a Telegram link code and unlink chats on the Chat apps page', function () {
    $member = User::factory()->create();
    $this->workspace->members()->attach($member->id, ['role' => 'member']);
    $member->switchWorkspace($this->workspace);
    $this->actingAs($member)->get(route('workspace.channels'))->assertForbidden();

    $page = Livewire::actingAs($this->owner)->test('pages::workspace.channels')
        ->call('createCode', 'telegram')
        ->assertSee('https://t.me/DocChatTestBot?start=');

    $code = $page->get('code');
    $link = ChannelLinkCode::redeem($code, ConversationChannel::Telegram, '77', 'Front desk');

    $page->call('unlink', $link->id);
    expect(ChannelLink::withoutGlobalScopes()->count())->toBe(0);
});

test('without a bot username the page explains what is missing', function () {
    config(['docchat.channels.telegram.bot_username' => null]);

    $this->actingAs($this->owner)->get(route('workspace.channels'))
        ->assertOk()
        ->assertSee('TELEGRAM_BOT_USERNAME');
});

test('the service key command creates, lists and revokes keys', function () {
    expect(Artisan::call('docchat:service-key', ['name' => 'tg']))->toBe(0)
        ->and(Artisan::output())->toMatch('/dc_svc_[A-Za-z0-9]{40}/');

    $key = ApiKey::query()->where('name', 'tg')->sole();
    expect($key->workspace_id)->toBeNull()->and($key->allows(ApiAbility::ChannelsLink))->toBeTrue();

    Artisan::call('docchat:service-key', ['--revoke' => $key->id]);
    expect($key->refresh()->isRevoked())->toBeTrue();
});
