<?php

use App\Contracts\ChatProvider;
use App\Contracts\EmbeddingProvider;
use App\Enums\ConversationChannel;
use App\Jobs\DiscordAnswerJob;
use App\Models\ChannelLink;
use App\Models\ChannelLinkCode;
use App\Models\Chunk;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\User;
use App\Services\Discord\DiscordSignature;
use App\Services\Rag\ApiAnswerer;
use App\Support\CurrentWorkspace;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeChatProvider;
use Tests\Support\FakeEmbeddingProvider;

beforeEach(function () {
    $keys = sodium_crypto_sign_keypair();
    $this->secretKey = sodium_crypto_sign_secretkey($keys);
    config([
        'docchat.channels.discord.public_key' => bin2hex(sodium_crypto_sign_publickey($keys)),
        'docchat.channels.discord.application_id' => '111',
        'docchat.channels.discord.bot_token' => 'bot-token',
        'docchat.rag.similarity_threshold' => 0.5,
        'docchat.cache.answers' => false,
    ]);

    $this->owner = User::factory()->create();
    $this->workspace = $this->owner->currentWorkspace();
});

function discordPost(array $interaction, ?int $timestamp = null, ?string $secretKey = null)
{
    $body = json_encode($interaction);
    $timestamp = (string) ($timestamp ?? time());
    $signature = bin2hex(sodium_crypto_sign_detached($timestamp.$body, $secretKey ?? test()->secretKey));

    return test()->call('POST', '/discord/interactions', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SIGNATURE_ED25519' => $signature,
        'HTTP_X_SIGNATURE_TIMESTAMP' => $timestamp,
    ], $body);
}

function command(string $name, array $options = [], array $extra = []): array
{
    return array_merge([
        'type' => 2,
        'application_id' => '111',
        'token' => 'interaction-token',
        'guild_id' => '900',
        'guild' => ['name' => 'Harbor server'],
        'channel_id' => '42',
        'member' => ['permissions' => (string) 0x20],
        'data' => ['name' => $name, 'options' => array_map(fn ($v, $k) => ['name' => $k, 'type' => 3, 'value' => $v], $options, array_keys($options))],
    ], $extra);
}

test('a signed PING gets a PONG', function () {
    discordPost(['type' => 1])->assertOk()->assertExactJson(['type' => 1]);
});

test('bad signatures, other keys and old timestamps are refused', function () {
    discordPost(['type' => 1], secretKey: sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))->assertUnauthorized();
    discordPost(['type' => 1], timestamp: time() - 301)->assertUnauthorized();

    $this->call('POST', '/discord/interactions', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"type":1}')->assertUnauthorized();

    // A body changed after signing.
    $timestamp = (string) time();
    $signature = bin2hex(sodium_crypto_sign_detached($timestamp.'{"type":1}', $this->secretKey));
    $this->call('POST', '/discord/interactions', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE_ED25519' => $signature, 'HTTP_X_SIGNATURE_TIMESTAMP' => $timestamp,
    ], '{"type":1} ')->assertUnauthorized();
});

test('signature checks reject malformed input', function () {
    expect(DiscordSignature::verify('{}', 'zz', (string) time(), str_repeat('a', 64)))->toBeFalse()
        ->and(DiscordSignature::verify('{}', str_repeat('a', 128), 'yesterday', str_repeat('a', 64)))->toBeFalse();
});

test('/link links the server with a code and needs Manage Server', function () {
    [, $code] = ChannelLinkCode::issue($this->workspace, ConversationChannel::Discord, $this->owner);

    discordPost(command('link', ['code' => $code], ['member' => ['permissions' => '0']]))
        ->assertJsonPath('data.content', 'Only members with **Manage Server** can link this server.')
        ->assertJsonPath('data.flags', 64);

    discordPost(command('link', ['code' => $code]))->assertJsonPath('type', 4);

    $link = ChannelLink::withoutGlobalScopes()->sole();
    expect($link->external_id)->toBe('900')->and($link->label)->toBe('Harbor server')->and($link->workspace_id)->toBe($this->workspace->id);

    discordPost(command('link', ['code' => $code]))->assertJsonPath('data.content', fn (string $c) => str_contains($c, 'did not work'));
});

test('/ask is deferred and answered by a job that edits the message', function () {
    Queue::fake();
    ChannelLink::query()->create(['workspace_id' => $this->workspace->id, 'channel' => ConversationChannel::Discord, 'external_id' => '900']);

    discordPost(command('ask', ['question' => 'When do you open?']))->assertExactJson(['type' => 5]);

    Queue::assertPushed(DiscordAnswerJob::class, fn (DiscordAnswerJob $job): bool => $job->workspaceId === $this->workspace->id
        && $job->conversationId === 'discord:42' && $job->token === 'interaction-token' && $job->question === 'When do you open?');
});

test('the answer job sends the answer in parts under 2000 characters', function () {
    app()->instance(EmbeddingProvider::class, new FakeEmbeddingProvider);
    app()->instance(ChatProvider::class, new FakeChatProvider([trim(str_repeat('Long answer @everyone [1]. ', 120))]));
    $document = Document::factory()->ready()->for($this->owner)->create(['title' => 'Hours']);
    Chunk::factory()->for($document)->create(['embedding' => FakeEmbeddingProvider::withSimilarity(0.9)]);
    Http::fake(['discord.com/*' => Http::response(['id' => '1'])]);

    (new DiscordAnswerJob('111', 'tok', $this->workspace->id, 'discord:42', 'When?'))->handle(app(ApiAnswerer::class), app(CurrentWorkspace::class));

    $sent = Http::recorded()->map(fn ($pair) => $pair[0]);
    expect($sent)->toHaveCount(2)
        ->and($sent[0]->method())->toBe('PATCH')
        ->and($sent[0]->url())->toBe('https://discord.com/api/v10/webhooks/111/tok/messages/@original')
        ->and($sent[0]['content'])->toStartWith('> When?')
        ->and($sent[1]->method())->toBe('POST');

    $sent->each(fn (Request $request) => expect(mb_strlen($request['content']))->toBeLessThanOrEqual(2000)
        ->and($request['allowed_mentions'])->toBe(['parse' => []]));

    $conversation = Conversation::withoutGlobalScopes()->sole();
    expect($conversation->channel)->toBe(ConversationChannel::Discord)->and($conversation->external_id)->toBe('discord:42');
});

test('commands in an unlinked server explain how to link', function () {
    Queue::fake();

    discordPost(command('ask', ['question' => 'Hi']))
        ->assertJsonPath('data.content', fn (string $c) => str_contains($c, '/link code:'));

    Queue::assertNothingPushed();
});

test('/new starts over and /unlink unlinks', function () {
    ChannelLink::query()->create(['workspace_id' => $this->workspace->id, 'channel' => ConversationChannel::Discord, 'external_id' => '900']);
    $conversation = Conversation::forChannel($this->workspace, ConversationChannel::Discord, 'discord:42');

    discordPost(command('new'))->assertJsonPath('data.content', 'Started a new conversation in this channel.');
    expect($conversation->refresh()->external_id)->toBeNull();

    discordPost(command('unlink', [], ['member' => ['permissions' => '0']]))->assertJsonPath('data.content', fn (string $c) => str_contains($c, 'Manage Server'));
    discordPost(command('unlink'));
    expect(ChannelLink::withoutGlobalScopes()->count())->toBe(0);
});

test('direct messages link the DM channel', function () {
    [, $code] = ChannelLinkCode::issue($this->workspace, ConversationChannel::Discord, $this->owner);

    discordPost(command('link', ['code' => $code], ['guild_id' => null, 'guild' => null, 'member' => null, 'user' => ['username' => 'moiz'], 'channel_id' => '77']));

    expect(ChannelLink::withoutGlobalScopes()->sole()->external_id)->toBe('dm:77');
});

test('the register command PUTs the slash commands', function () {
    Http::fake(['discord.com/*' => Http::response([['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4]])]);

    expect(Artisan::call('docchat:discord-register-commands', ['--guild' => '900']))->toBe(0);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->url() === 'https://discord.com/api/v10/applications/111/guilds/900/commands'
        && $request->header('Authorization')[0] === 'Bot bot-token'
        && collect($request->data())->pluck('name')->all() === ['ask', 'link', 'new', 'unlink']);
});

test('the Chat apps page shows Discord and WhatsApp when configured', function () {
    config(['docchat.channels.whatsapp.number' => '+92 300 1234567']);

    Livewire::actingAs($this->owner)->test('pages::workspace.channels')
        ->call('createCode', 'discord')
        ->assertSee('/link code:')
        ->assertSee('client_id=111', false)
        ->call('createCode', 'whatsapp')
        ->assertSee('https://wa.me/923001234567?text=link%20', false);
});
