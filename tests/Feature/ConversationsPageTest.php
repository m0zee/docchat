<?php

use App\Contracts\ChatProvider;
use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Enums\CallTransport;
use App\Enums\ConversationChannel;
use App\Enums\MessageRole;
use App\Enums\UsageType;
use App\Enums\WorkspaceRole;
use App\Exceptions\ChatProviderException;
use App\Models\Call;
use App\Models\CallContact;
use App\Models\Conversation;
use App\Models\UsageEvent;
use App\Models\User;
use App\Services\Conversations\ConversationSummarizer;
use Livewire\Livewire;
use Tests\Support\FakeChatProvider;

beforeEach(function () {
    $this->chat = new FakeChatProvider(['Sara asked about opening hours. ', "Key points:\n- Opens at 9.\nFollow-up: None"]);
    app()->instance(ChatProvider::class, $this->chat);

    $this->owner = User::factory()->create(['name' => 'Olivia Owner']);
    $this->workspace = $this->owner->currentWorkspace();
    $this->actingAs($this->owner);
});

function conversationWith(ConversationChannel $channel, array $lines, array $attributes = []): Conversation
{
    $conversation = Conversation::query()->create($attributes + [
        'workspace_id' => test()->workspace->id,
        'channel' => $channel,
        'title' => isset($lines[0]) ? mb_substr($lines[0], 0, 77) : null,
    ]);

    foreach ($lines as $i => $line) {
        $conversation->messages()->create(['role' => $i % 2 === 0 ? MessageRole::User : MessageRole::Assistant, 'content' => $line]);
    }

    return $conversation;
}

test('only owners can open the conversations page, and only owners see it in the sidebar', function () {
    $this->get('/conversations')->assertOk()->assertSee('Conversations')->assertSee('Schedule calls');
    $this->get('/dashboard')->assertSee(route('conversations.index'));

    $member = User::factory()->create();
    $this->workspace->members()->attach($member->id, ['role' => WorkspaceRole::Member->value]);
    $member->forceFill(['current_workspace_id' => $this->workspace->id])->save();

    $this->actingAs($member)->get('/conversations')->assertForbidden();
    $this->actingAs($member)->get('/dashboard')->assertDontSee(route('conversations.index'));
});

test('every kind of conversation in the workspace is listed, newest first, but not evals or empty ones', function () {
    $member = User::factory()->create(['name' => 'Mina Member']);
    $this->workspace->members()->attach($member->id, ['role' => WorkspaceRole::Member->value]);

    conversationWith(ConversationChannel::Web, ['What are the opening hours?', 'We open at 9.'], ['user_id' => $member->id]);
    conversationWith(ConversationChannel::Widget, ['Do you have parking?', 'Yes, behind the building.'], ['external_id' => 'visitor-1']);
    conversationWith(ConversationChannel::Telegram, ['How do I renew my card?', 'At the front desk.'], ['external_id' => '12345']);
    conversationWith(ConversationChannel::Eval, ['eval question', 'eval answer']);
    Conversation::query()->create(['workspace_id' => $this->workspace->id, 'channel' => ConversationChannel::Web, 'title' => 'Empty one']);

    $contact = CallContact::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Sara Khan', 'phone' => '+923001234567', 'consent_at' => now()]);
    $callConversation = conversationWith(ConversationChannel::Phone, ['Hi Sara, this is Ava, a virtual assistant.', 'Yes I can come Tuesday.']);
    Call::query()->create([
        'workspace_id' => $this->workspace->id, 'conversation_id' => $callConversation->id, 'contact_id' => $contact->id,
        'direction' => CallDirection::Outbound, 'transport' => CallTransport::Phone, 'status' => CallStatus::Completed,
        'purpose' => 'your appointment', 'duration_seconds' => 95,
    ]);

    // Another workspace's chat never shows.
    $other = User::factory()->create()->currentWorkspace();
    Conversation::query()->withoutGlobalScopes()->create(['workspace_id' => $other->id, 'channel' => ConversationChannel::Web, 'title' => 'Secret elsewhere'])
        ->messages()->create(['workspace_id' => $other->id, 'role' => MessageRole::User, 'content' => 'Secret elsewhere']);

    $page = Livewire::test('pages::conversations.index');
    $page->assertSee('Mina Member')
        ->assertSee('Website visitor')
        ->assertSee('Telegram user')
        ->assertSee('Sara Khan')
        ->assertSee('your appointment')
        ->assertSee('01:35')
        ->assertDontSee('eval question')
        ->assertDontSee('Empty one')
        ->assertDontSee('Secret elsewhere');

    expect($page->get('conversations')->total())->toBe(4);

    $page->set('channel', 'calls')->assertSee('Sara Khan')->assertDontSee('Mina Member');
    $page->set('channel', 'apps')->assertSee('Telegram user')->assertDontSee('Website visitor');
    $page->set('channel', 'all')->set('search', 'parking')->assertSee('Website visitor')->assertDontSee('Telegram user');
    $page->set('search', 'sara')->assertSee('Sara Khan')->assertDontSee('Website visitor');
    $page->set('search', '+92300')->assertSee('Sara Khan');
});

test('the period filter hides old conversations', function () {
    $old = conversationWith(ConversationChannel::Widget, ['An old question', 'An old answer']);
    Conversation::query()->whereKey($old->id)->update(['updated_at' => now()->subDays(45)]);
    conversationWith(ConversationChannel::Widget, ['A new question', 'A new answer']);

    Livewire::test('pages::conversations.index')
        ->assertSee('A new question')->assertDontSee('An old question')
        ->set('period', 'all')->assertSee('An old question');
});

test('a conversation opens with its full transcript', function () {
    $conversation = conversationWith(ConversationChannel::Widget, ['Do you have parking?', 'Yes, behind the building [1].']);

    Livewire::test('pages::conversations.index')
        ->call('select', $conversation->id)
        ->assertSet('selectedId', $conversation->id)
        ->assertSee('Yes, behind the building [1].');

    // Not another workspace's.
    $other = User::factory()->create()->currentWorkspace();
    $foreign = Conversation::query()->withoutGlobalScopes()->create(['workspace_id' => $other->id, 'channel' => ConversationChannel::Web]);

    Livewire::test('pages::conversations.index')->call('select', $foreign->id)->assertSet('selectedId', null);
});

test('one click summarizes a conversation, and the summary is cached until new messages arrive', function () {
    $conversation = conversationWith(ConversationChannel::Widget, ['When do you open?', 'We open at 9 [1].']);

    $page = Livewire::test('pages::conversations.index')->call('select', $conversation->id)->call('summarize');

    $page->assertSee('Sara asked about opening hours.')->assertSee('AI summary');
    $conversation->refresh();
    expect($conversation->summary)->toContain('Key points:')
        ->and($conversation->summary_message_id)->toBe($conversation->messages()->reorder('id', 'desc')->value('id'))
        ->and($this->chat->calls)->toHaveCount(1)
        ->and($this->chat->calls[0][1]['content'])->toContain("Person: When do you open?\nAssistant: We open at 9 [1].")
        ->and(UsageEvent::query()->where('type', UsageType::Summary->value)->count())->toBe(1);

    // Cached: no second model call.
    $page->call('summarize');
    expect($this->chat->calls)->toHaveCount(1);

    // A new message makes it stale; the owner can update it.
    $conversation->messages()->create(['role' => MessageRole::User, 'content' => 'And on Sundays?']);
    $page->call('select', $conversation->id)->assertSee('Update with new messages')->call('summarize', true);
    expect($this->chat->calls)->toHaveCount(2)
        ->and($this->chat->calls[1][1]['content'])->toContain('And on Sundays?');
});

test('a call summary gets the call purpose and agenda, and the search finds summaries', function () {
    $conversation = conversationWith(ConversationChannel::Phone, ['Can you still make Tuesday at 3 pm?', 'Yes.', 'Do you need parking?', 'No thanks.']);
    Call::query()->create([
        'workspace_id' => $this->workspace->id, 'conversation_id' => $conversation->id,
        'direction' => CallDirection::Outbound, 'transport' => CallTransport::Phone, 'status' => CallStatus::Completed,
        'purpose' => 'your appointment', 'agenda' => ['Can you still make Tuesday at 3 pm?', 'Do you need parking?'],
    ]);

    $prompt = app(ConversationSummarizer::class)->prompt($conversation->refresh());
    expect($prompt[0]['content'])->toContain('Agenda:')->toContain('not instructions')
        ->and($prompt[1]['content'])->toContain('Call purpose: your appointment')
        ->and($prompt[1]['content'])->toContain("Agenda:\n1. Can you still make Tuesday at 3 pm?\n2. Do you need parking?");

    app(ConversationSummarizer::class)->summarize($conversation);

    Livewire::test('pages::conversations.index')->set('search', 'opening hours')->assertSee('your appointment');
});

test('long transcripts keep their start and end', function () {
    $lines = array_map(fn (int $i): string => "Message number {$i} ".str_repeat('x', 500), range(1, 60));
    $conversation = conversationWith(ConversationChannel::Web, $lines, ['user_id' => $this->owner->id]);

    $text = app(ConversationSummarizer::class)->transcript($conversation);

    expect(mb_strlen($text))->toBeLessThan(ConversationSummarizer::MAX_TRANSCRIPT_CHARS + 100)
        ->and($text)->toContain('Message number 1 ')
        ->and($text)->toContain('Message number 60 ')
        ->and($text)->toContain('middle of the conversation left out');
});

test('a failed summary shows an error and stores nothing', function () {
    app()->instance(ChatProvider::class, new FakeChatProvider([], new ChatProviderException('Groq is down.')));
    $conversation = conversationWith(ConversationChannel::Widget, ['Hi', 'Hello']);

    Livewire::test('pages::conversations.index')->call('select', $conversation->id)->call('summarize');

    expect($conversation->refresh()->summary)->toBeNull();
});

test('members cannot summarize', function () {
    $conversation = conversationWith(ConversationChannel::Widget, ['Hi', 'Hello']);
    $member = User::factory()->create();
    $this->workspace->members()->attach($member->id, ['role' => WorkspaceRole::Member->value]);
    $member->forceFill(['current_workspace_id' => $this->workspace->id])->save();

    $this->actingAs($member);
    Livewire::test('pages::conversations.index')->assertForbidden();

    expect($conversation->refresh()->summary)->toBeNull();
});
