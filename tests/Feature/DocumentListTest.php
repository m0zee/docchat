<?php

use App\Models\Chunk;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
});

it('lists only the authenticated user documents', function () {
    $user = User::factory()->create();
    Document::factory()->for($user)->create(['title' => 'Own document']);
    Document::factory()->create(['title' => 'Other document']);

    Livewire::actingAs($user)
        ->test('pages::documents.index')
        ->assertSee('Own document')
        ->assertDontSee('Other document');
});

it('polls only while a document is in progress', function () {
    $user = User::factory()->create();
    Document::factory()->for($user)->ready()->create();

    Livewire::actingAs($user)
        ->test('pages::documents.index')
        ->assertDontSeeHtml('wire:poll.3s');

    Document::factory()->for($user)->processing()->create();

    Livewire::actingAs($user)
        ->test('pages::documents.index')
        ->assertSeeHtml('wire:poll.3s');
});

it('shows the failure reason for a failed document', function () {
    $user = User::factory()->create();
    Document::factory()->for($user)->failed()->create([
        'error' => 'Scanned PDF with no text.',
    ]);

    Livewire::actingAs($user)
        ->test('pages::documents.index')
        ->assertSee('Scanned PDF with no text.');
});

it('deletes the document, its chunks and its file', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create([
        'storage_path' => 'documents/'.$user->id.'/delete-me.txt',
    ]);

    Storage::disk('local')->put($document->storage_path, 'content');
    Chunk::factory()->for($document)->create();

    Livewire::actingAs($user)
        ->test('pages::documents.index')
        ->call('delete', $document->id)
        ->assertHasNoErrors();

    expect(Document::find($document->id))->toBeNull()
        ->and(Chunk::where('document_id', $document->id)->count())->toBe(0);

    Storage::disk('local')->assertMissing($document->storage_path);
});
