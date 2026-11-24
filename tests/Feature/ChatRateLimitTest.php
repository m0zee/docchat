<?php

use App\Contracts\ChatProvider;
use App\Contracts\EmbeddingProvider;
use App\Models\Document;
use App\Models\Message;
use App\Models\User;
use Livewire\Livewire;
use Tests\Support\FakeChatProvider;
use Tests\Support\FakeEmbeddingProvider;

beforeEach(function () {
    config(['docchat.chat.rate_limit_per_minute' => 20]);
    $this->embeddings = new FakeEmbeddingProvider;
    $this->chat = new FakeChatProvider;
    $this->app->instance(EmbeddingProvider::class, $this->embeddings);
    $this->app->instance(ChatProvider::class, $this->chat);
});

function silently(callable $callback): void
{
    ob_start(fn (): string => '', 1);

    try {
        $callback();
    } finally {
        ob_end_clean();
    }
}

function chatAs(User $user)
{
    Document::factory()->ready()->for($user)->create();

    return Livewire::actingAs($user)->test('pages::chat.index');
}

it('refuses the 21st question in a minute without saving or calling a provider', function () {
    $user = User::factory()->create();
    $component = chatAs($user);

    foreach (range(1, 20) as $i) {
        $component->set('question', 'Question '.$i)->call('send')->assertHasNoErrors();
        silently(fn () => $component->call('ask'));
    }

    $this->chat->calls = [];

    $before = Message::count();
    $queries = count($this->embeddings->queries);

    $component->set('question', 'One too many')
        ->call('send')
        ->assertHasErrors('question')
        ->assertSee("You've sent too many questions.")
        ->assertSet('pending', null);

    expect(Message::count())->toBe($before)
        ->and(count($this->embeddings->queries))->toBe($queries)
        ->and($this->chat->calls)->toBe([]);

    // Another user is not affected.
    chatAs(User::factory()->create())->set('question', 'Hi')->call('send')->assertHasNoErrors();

    // After the window it works again.
    $this->travel(61)->seconds();
    $component->set('question', 'Later')->call('send')->assertHasNoErrors();
});
