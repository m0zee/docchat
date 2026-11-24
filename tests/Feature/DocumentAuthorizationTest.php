<?php

use App\Models\Chunk;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
});

it('allows only the owner to view and delete a document', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $document = Document::factory()->for($owner)->create();

    expect($owner->can('view', $document))->toBeTrue()
        ->and($owner->can('delete', $document))->toBeTrue()
        ->and($other->can('view', $document))->toBeFalse()
        ->and($other->can('delete', $document))->toBeFalse();
});

it('scopes chunks to their workspace', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $ownerDocument = Document::factory()->for($owner)->create();
    $otherDocument = Document::factory()->for($other)->create();
    $ownerChunk = Chunk::factory()->for($ownerDocument)->create();
    Chunk::factory()->for($otherDocument)->create();

    expect(Chunk::inWorkspace($owner->currentWorkspace())->pluck('id')->all())->toBe([$ownerChunk->id])
        ->and(Chunk::inWorkspace($other->currentWorkspace())->count())->toBe(1);
});

it('forbids another user from deleting a document through the page', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $document = Document::factory()->for($owner)->create();

    Livewire::actingAs($other)
        ->test('pages::documents.index')
        ->call('delete', $document->id)
        ->assertStatus(404);

    expect(Document::withoutGlobalScopes()->find($document->id))->not->toBeNull();
});

it('does not show another user documents in the list', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    Document::factory()->for($owner)->create(['title' => 'Private owner document']);

    Livewire::actingAs($other)
        ->test('pages::documents.index')
        ->assertDontSee('Private owner document');
});
