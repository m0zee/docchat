<?php

use App\Jobs\IngestDocumentJob;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
});

it('accepts a supported file, stores it privately and queues ingestion', function (string $name, string $mime) {
    $user = User::factory()->create();
    $file = UploadedFile::fake()->create($name, 1, $mime);

    Livewire::actingAs($user)
        ->test('pages::documents.index')
        ->set('file', $file)
        ->call('upload')
        ->assertHasNoErrors();

    $document = Document::first();

    expect($document)->not->toBeNull()
        ->and($document->user_id)->toBe($user->id)
        ->and($document->status->value)->toBe('pending')
        ->and($document->embedding_model)->toBeNull()
        ->and($document->title)->toBe(pathinfo($name, PATHINFO_FILENAME))
        ->and($document->original_filename)->toBe($name)
        ->and($document->size_bytes)->toBeGreaterThan(0)
        ->and($document->storage_path)->toStartWith('documents/'.$user->id.'/');

    Storage::disk('local')->assertExists($document->storage_path);

    Queue::assertPushed(
        IngestDocumentJob::class,
        fn (IngestDocumentJob $job): bool => $job->document->is($document),
    );
})->with([
    ['notes.txt', 'text/plain'],
    ['notes.md', 'text/markdown'],
    ['notes.pdf', 'application/pdf'],
]);

it('rejects an unsupported extension', function () {
    $user = User::factory()->create();
    $file = UploadedFile::fake()->create('notes.docx', 1, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

    Livewire::actingAs($user)
        ->test('pages::documents.index')
        ->set('file', $file)
        ->call('upload')
        ->assertHasErrors(['file']);

    expect(Document::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rejects a file whose content does not match its extension', function () {
    $user = User::factory()->create();
    $file = UploadedFile::fake()->create('notes.pdf', 1, 'application/x-msdownload');

    Livewire::actingAs($user)
        ->test('pages::documents.index')
        ->set('file', $file)
        ->call('upload')
        ->assertHasErrors(['file']);

    expect(Document::count())->toBe(0);
});

it('rejects a file over 10 MB', function () {
    $user = User::factory()->create();
    $file = UploadedFile::fake()->create('big.txt', 11_000, 'text/plain');

    Livewire::actingAs($user)
        ->test('pages::documents.index')
        ->set('file', $file)
        ->call('upload')
        ->assertHasErrors(['file']);

    expect(Document::count())->toBe(0);
});

it('accepts a 9 MB PDF', function () {
    $user = User::factory()->create();
    $file = UploadedFile::fake()->create('large.pdf', 9 * 1024, 'application/pdf');

    Livewire::actingAs($user)
        ->test('pages::documents.index')
        ->set('file', $file)
        ->call('upload')
        ->assertHasNoErrors();

    expect(Document::first()->size_bytes)->toBe(9 * 1024 * 1024);
});

it('redirects a guest from the documents page', function () {
    $this->get(route('documents.index'))->assertRedirect(route('login'));
});
