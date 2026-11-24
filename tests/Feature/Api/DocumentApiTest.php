<?php

use App\Enums\ApiAbility;
use App\Jobs\IngestDocumentJob;
use App\Models\ApiKey;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    $this->user = User::factory()->create();
    $this->workspace = $this->user->currentWorkspace();
    [, $this->key] = ApiKey::issue($this->workspace, 'n8n', [ApiAbility::DocumentsRead, ApiAbility::DocumentsWrite]);
});

test('upload a document with source, external id and meta', function () {
    $response = $this->withToken($this->key)->post('/api/v1/documents', [
        'file' => UploadedFile::fake()->createWithContent('faq.md', "# FAQ\nHours are 9 to 5."),
        'title' => 'FAQ',
        'source' => 'google_drive',
        'external_id' => 'drive-123',
        'meta' => json_encode(['chat_id' => 99]),
    ], ['Accept' => 'application/json']);

    $response->assertStatus(202)
        ->assertJsonPath('data.title', 'FAQ')
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.source', 'google_drive')
        ->assertJsonPath('data.external_id', 'drive-123')
        ->assertJsonPath('data.meta.chat_id', 99);

    $document = Document::withoutGlobalScopes()->sole();
    expect($document->workspace_id)->toBe($this->workspace->id);
    Queue::assertPushed(IngestDocumentJob::class);
});

test('the same external id replaces the document', function () {
    $first = $this->withToken($this->key)->post('/api/v1/documents', [
        'file' => UploadedFile::fake()->createWithContent('a.txt', 'one'), 'external_id' => 'x',
    ], ['Accept' => 'application/json'])->json('data.id');

    $second = $this->withToken($this->key)->post('/api/v1/documents', [
        'file' => UploadedFile::fake()->createWithContent('a.txt', 'two'), 'external_id' => 'x',
    ], ['Accept' => 'application/json'])->json('data.id');

    expect($second)->toBe($first)->and(Document::withoutGlobalScopes()->count())->toBe(1);
});

test('invalid uploads get a validation error in the API format', function () {
    $this->withToken($this->key)->post('/api/v1/documents', [
        'file' => UploadedFile::fake()->create('virus.exe', 10),
        'meta' => '[1,2]',
    ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['fields' => ['file', 'meta']]]]);
});

test('list, filter, show and delete', function () {
    $drive = Document::factory()->ready()->for($this->user)->create(['source' => 'google_drive', 'external_id' => 'd1']);
    Document::factory()->for($this->user)->create(['source' => 'api']);

    $this->withToken($this->key)->getJson('/api/v1/documents')->assertOk()->assertJsonCount(2, 'data');
    $this->withToken($this->key)->getJson('/api/v1/documents?source=google_drive')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.external_id', 'd1');
    $this->withToken($this->key)->getJson('/api/v1/documents?external_id=d1')->assertJsonCount(1, 'data');

    $this->withToken($this->key)->getJson('/api/v1/documents/'.$drive->id)->assertOk()->assertJsonPath('data.status', 'ready');

    $this->withToken($this->key)->deleteJson('/api/v1/documents/'.$drive->id)->assertNoContent();
    expect(Document::withoutGlobalScopes()->find($drive->id))->toBeNull();
});

test('list is cursor paginated', function () {
    Document::factory()->count(3)->for($this->user)->create();

    $page = $this->withToken($this->key)->getJson('/api/v1/documents?per_page=2')->assertOk()->assertJsonCount(2, 'data');
    $next = $page->json('meta.next_cursor');

    $this->withToken($this->key)->getJson('/api/v1/documents?per_page=2&cursor='.$next)->assertJsonCount(1, 'data');
});

test('a key never sees or deletes another workspace\'s documents', function () {
    $theirs = Document::factory()->ready()->create(['title' => 'Their secret']);

    $this->withToken($this->key)->getJson('/api/v1/documents')->assertJsonMissing(['title' => 'Their secret']);
    $this->withToken($this->key)->getJson('/api/v1/documents/'.$theirs->id)->assertNotFound()->assertJsonPath('error.code', 'not_found');
    $this->withToken($this->key)->deleteJson('/api/v1/documents/'.$theirs->id)->assertNotFound();

    expect(Document::withoutGlobalScopes()->find($theirs->id))->not->toBeNull();
});

test('abilities are enforced', function () {
    [, $askOnly] = ApiKey::issue($this->workspace, 'ask', [ApiAbility::Ask]);
    [, $readOnly] = ApiKey::issue($this->workspace, 'read', [ApiAbility::DocumentsRead]);

    $this->withToken($askOnly)->getJson('/api/v1/documents')->assertForbidden()->assertJsonPath('error.code', 'missing_ability');
    $this->withToken($readOnly)->getJson('/api/v1/documents')->assertOk();
    $this->withToken($readOnly)->post('/api/v1/documents', ['file' => UploadedFile::fake()->createWithContent('a.txt', 'x')], ['Accept' => 'application/json'])
        ->assertForbidden();
});
