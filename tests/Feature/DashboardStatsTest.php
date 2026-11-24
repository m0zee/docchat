<?php

use App\Models\Chunk;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\User;

it('shows only the user\'s own counts and recent conversations', function () {
    $user = User::factory()->create();
    $ready = Document::factory()->ready()->for($user)->create();
    Document::factory()->for($user)->create();
    Chunk::factory()->count(3)->for($ready)->sequence(fn ($s) => ['chunk_index' => $s->index])->create();
    Conversation::factory()->for($user)->create(['title' => 'My refund question']);

    $other = Document::factory()->ready()->create();
    Chunk::factory()->for($other)->create();
    Conversation::factory()->create(['title' => 'Someone else']);

    $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $stats = collect(explode('data-stat', $response->getContent()))->skip(1)
        ->map(fn (string $html): string => trim(strip_tags('<div '.$html)))
        ->map(fn (string $text): string => preg_replace('/\s+/', ' ', $text))
        ->take(3)->values()->all();

    expect($stats[0])->toContain('Ready documents 1')
        ->and($stats[1])->toContain('Chunks indexed 3')
        ->and($stats[2])->toContain('Your conversations 1');

    $response->assertSee('My refund question')->assertDontSee('Someone else');
});

it('shows a DocChat landing page with sign-up links to guests', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Ask your documents. Get answers with sources.')
        ->assertSee(route('register'));
});
