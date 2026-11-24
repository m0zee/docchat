<?php

use App\Enums\ConversationChannel;
use App\Models\Conversation;
use App\Models\User;

beforeEach(function () {
    $this->workspace = User::factory()->create()->currentWorkspace();
});

test('web conversations default to the web channel', function () {
    expect(Conversation::factory()->create()->fresh()->channel)->toBe(ConversationChannel::Web);
});

test('the same external id reuses the conversation', function () {
    $first = Conversation::forChannel($this->workspace, ConversationChannel::Telegram, 'telegram:42');
    $again = Conversation::forChannel($this->workspace, ConversationChannel::Telegram, 'telegram:42');

    expect($again->id)->toBe($first->id)
        ->and($first->external_id)->toBe('telegram:42')
        ->and($first->user_id)->toBeNull();
});

test('external ids are separate per channel and per workspace', function () {
    $other = User::factory()->create()->currentWorkspace();

    $a = Conversation::forChannel($this->workspace, ConversationChannel::Telegram, 'x');
    $b = Conversation::forChannel($this->workspace, ConversationChannel::WhatsApp, 'x');
    $c = Conversation::forChannel($other, ConversationChannel::Telegram, 'x');

    expect(collect([$a->id, $b->id, $c->id])->unique())->toHaveCount(3);
});

test('without an external id a new conversation starts every time', function () {
    $a = Conversation::forChannel($this->workspace, ConversationChannel::Api);
    $b = Conversation::forChannel($this->workspace, ConversationChannel::Api);

    expect($a->id)->not->toBe($b->id);
});

test('the web chat list does not show api or bot conversations', function () {
    $user = User::factory()->create();
    Conversation::factory()->for($user)->create(['title' => 'My web chat']);
    Conversation::forChannel($user->currentWorkspace(), ConversationChannel::Telegram, 'telegram:1', $user)->update(['title' => 'Telegram thread']);

    $this->actingAs($user)->get(route('chat.index'))->assertSee('My web chat')->assertDontSee('Telegram thread');
});
