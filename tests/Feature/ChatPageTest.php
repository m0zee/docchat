<?php

use App\Contracts\ChatProvider;
use App\Contracts\EmbeddingProvider;
use App\Enums\MessageRole;
use App\Models\Chunk;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Message;
use App\Models\User;
use Livewire\Livewire;
use Tests\Support\FakeChatProvider;
use Tests\Support\FakeEmbeddingProvider;

beforeEach(function () {
    config(['docchat.rag.similarity_threshold' => 0.5]);
    $this->app->instance(EmbeddingProvider::class, new FakeEmbeddingProvider);
    $this->app->instance(ChatProvider::class, new FakeChatProvider(['Refunds take 14 days [1].']));

    $this->user = User::factory()->create();
    $this->document = Document::factory()->ready()->for($this->user)->create(['title' => 'Handbook']);
    $this->chunk = Chunk::factory()->for($this->document)->create([
        'page_number' => 4,
        'content' => 'Refunds are paid within 14 days of the request.',
        'embedding' => FakeEmbeddingProvider::withSimilarity(0.9),
    ]);
});

/**
 * Livewire flushes streamed chunks straight out, so collect them with a callback buffer.
 */
function captureStream(callable $callback): string
{
    $captured = '';

    ob_start(function (string $buffer) use (&$captured): string {
        $captured .= $buffer;

        return '';
    }, 1);

    try {
        $callback();
    } finally {
        ob_end_clean();
    }

    return $captured;
}

it('redirects guests to login', function () {
    $this->get(route('chat.index'))->assertRedirect(route('login'));
});

it('shows the chat page with the sidebar link', function () {
    $this->actingAs($this->user)
        ->get(route('chat.index'))
        ->assertOk()
        ->assertSee('Ask a question about your documents')
        ->assertSee(route('chat.index'));
});

it('asks to upload a document when none is ready', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::chat.index')
        ->assertSee('Upload a document first');
});

it('hides a conversation from another workspace', function () {
    $conversation = Conversation::factory()->for($this->user)->create();

    $this->actingAs(User::factory()->create())
        ->get(route('chat.show', $conversation))
        ->assertNotFound();
});

it('keeps chats private between members of the same workspace', function () {
    $conversation = Conversation::factory()->for($this->user)->create();
    $teammate = User::factory()->create();
    $this->user->currentWorkspace()->members()->attach($teammate->id, ['role' => 'member']);
    $teammate->switchWorkspace($this->user->currentWorkspace());

    $this->actingAs($teammate)
        ->get(route('chat.show', $conversation))
        ->assertForbidden();
});

it('answers a question, saves both messages and shows citations', function () {
    $component = Livewire::actingAs($this->user)
        ->test('pages::chat.index')
        ->set('question', 'How long do refunds take?')
        ->call('send')
        ->assertSet('pending', 'How long do refunds take?')
        ->assertSet('question', '');

    $streamed = captureStream(fn () => $component->call('ask')->assertSet('pending', null));

    expect($streamed)->toContain('"name":"answer"')
        ->and($streamed)->toContain('Refunds take 14 days [1].');

    $conversation = $this->user->conversations()->sole();

    expect($component->get('conversationId'))->toBe($conversation->id)
        ->and($conversation->messages()->pluck('role')->all())->toBe([MessageRole::User, MessageRole::Assistant]);

    $component->assertSee('Refunds take 14 days [1].')
        ->assertSee('[1] Handbook, page 4')
        ->assertSee('Refunds are paid within 14 days');
});

it('expands a citation to the full chunk but not another user\'s chunk', function () {
    $theirs = Chunk::factory()->create(['content' => 'Secret chunk of another user.']);

    Livewire::actingAs($this->user)
        ->test('pages::chat.index')
        ->call('expand', $this->chunk->id)
        ->assertSet('expanded', [$this->chunk->id => $this->chunk->content])
        ->call('expand', $theirs->id)
        ->assertSet('expanded', [$this->chunk->id => $this->chunk->content]);
});

it('ignores another user\'s document ids in the scope picker', function () {
    $theirs = Document::factory()->ready()->create();
    $conversation = Conversation::factory()->for($this->user)->create();

    Livewire::actingAs($this->user)
        ->test('pages::chat.index', ['conversation' => $conversation])
        ->set('documentIds', [$this->document->id, $theirs->id])
        ->assertSet('documentIds', [$this->document->id]);

    expect($conversation->documents()->pluck('documents.id')->all())->toBe([$this->document->id]);
});

it('does not let the browser switch to another user\'s conversation', function () {
    $other = Conversation::factory()->create();

    Livewire::actingAs($this->user)
        ->test('pages::chat.index')
        ->set('conversationId', $other->id)
        ->assertStatus(500);
})->throws(Exception::class);

it('shows model output as text, never as HTML', function () {
    $conversation = Conversation::factory()->for($this->user)->create();
    Message::factory()->assistant()->for($conversation)->create([
        'content' => '<script>alert(1)</script>',
    ]);

    Livewire::actingAs($this->user)
        ->test('pages::chat.index', ['conversation' => $conversation])
        ->assertDontSeeHtml('<script>alert(1)</script>')
        ->assertSeeHtml('&lt;script&gt;alert(1)&lt;/script&gt;');
});

it('shows tokens and time under an answer', function () {
    $conversation = Conversation::factory()->for($this->user)->create();
    Message::factory()->assistant()->for($conversation)->create([
        'content' => 'Answer',
        'prompt_tokens' => 1200,
        'completion_tokens' => 34,
        'latency_ms' => 2500,
        'usage_estimated' => true,
    ]);

    Livewire::actingAs($this->user)
        ->test('pages::chat.index', ['conversation' => $conversation])
        ->assertSee('~1,234 tokens')
        ->assertSee('2.5 s');
});

it('escapes streamed deltas before they reach the page', function () {
    $this->app->instance(ChatProvider::class, new FakeChatProvider(['<img src=x onerror=alert(1)> [1]']));

    $component = Livewire::actingAs($this->user)
        ->test('pages::chat.index')
        ->set('question', 'Q?')
        ->call('send');

    $streamed = captureStream(fn () => $component->call('ask'));

    expect($streamed)->not->toContain('<img')
        ->and($streamed)->toContain('&lt;img');
});

it('lets the owner rename and delete a conversation', function () {
    $conversation = Conversation::factory()->for($this->user)->create(['title' => 'Old']);
    Message::factory()->for($conversation)->create();

    Livewire::actingAs($this->user)
        ->test('pages::chat.index', ['conversation' => $conversation])
        ->call('rename', '  New title  ')
        ->assertSee('New title');

    expect($conversation->refresh()->title)->toBe('New title');

    Livewire::actingAs($this->user)
        ->test('pages::chat.index', ['conversation' => $conversation])
        ->call('deleteConversation')
        ->assertRedirect(route('chat.index'));

    expect(Conversation::find($conversation->id))->toBeNull()
        ->and(Message::count())->toBe(0);
});

it('cannot rename or delete another user\'s conversation', function () {
    $theirs = Conversation::factory()->create(['title' => 'Theirs']);

    $this->actingAs($this->user)->get(route('chat.show', $theirs))->assertNotFound();

    // The component only ever loads conversations through the signed-in user.
    Livewire::actingAs($this->user)
        ->test('pages::chat.index')
        ->call('rename', 'Hacked')
        ->call('deleteConversation');

    expect(Conversation::withoutGlobalScopes()->find($theirs->id)->title)->toBe('Theirs');
});
