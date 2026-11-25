<?php

use App\Contracts\EmbeddingProvider;
use App\Enums\DocumentSource;
use App\Enums\DocumentStatus;
use App\Jobs\IngestDocumentJob;
use App\Models\Document;
use App\Models\User;
use App\Services\Documents\DocumentService;
use App\Services\Ingestion\Chunker;
use App\Services\Ingestion\DocumentParser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeEmbeddingProvider;

beforeEach(function () {
    Storage::fake('local');
    config(['docchat.embedding.model' => 'fake-embedding']);
    $this->embeddings = new FakeEmbeddingProvider;
    app()->instance(EmbeddingProvider::class, $this->embeddings);
    $this->user = User::factory()->create();
    $this->workspace = $this->user->currentWorkspace();
    $this->service = app(DocumentService::class);
});

function ingestNow(Document $document): void
{
    (new IngestDocumentJob($document))->handle(app(DocumentParser::class), app(Chunker::class), app(EmbeddingProvider::class));
}

function textFile(string $name, string $content): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

test('documents record their source, external id and meta', function () {
    Queue::fake();

    $document = $this->service->store(
        textFile('faq.txt', 'Hello'),
        $this->workspace,
        null,
        DocumentSource::GoogleDrive,
        'drive-file-1',
        'Company FAQ',
        ['chat_id' => 42],
    );

    expect($document->source)->toBe(DocumentSource::GoogleDrive)
        ->and($document->external_id)->toBe('drive-file-1')
        ->and($document->title)->toBe('Company FAQ')
        ->and($document->meta)->toBe(['chat_id' => 42])
        ->and($document->user_id)->toBeNull();

    Queue::assertPushed(IngestDocumentJob::class);
});

test('a plain upload defaults to the upload source', function () {
    Queue::fake();

    $document = $this->service->createFromUpload(textFile('a.txt', 'x'), $this->workspace, $this->user);

    expect($document->source)->toBe(DocumentSource::Upload)->and($document->external_id)->toBeNull();
});

test('uploading the same external id again replaces the file and re-ingests the same document', function () {
    $first = $this->service->store(textFile('policy.txt', 'Refunds take 14 days.'), $this->workspace, null, DocumentSource::Api, 'policy');
    ingestNow($first);
    $oldPath = $first->storage_path;
    $calls = $this->embeddings->documentCalls;

    $second = $this->service->store(textFile('policy.txt', 'Refunds take 30 days now.'), $this->workspace, null, DocumentSource::Api, 'policy');
    ingestNow($second);

    expect($second->id)->toBe($first->id)
        ->and(Document::query()->count())->toBe(1)
        ->and($second->fresh()->status)->toBe(DocumentStatus::Ready)
        ->and($second->fresh()->chunks()->pluck('content')->implode(' '))->toContain('30 days')
        ->and($this->embeddings->documentCalls)->toBe($calls + 1);

    Storage::disk('local')->assertMissing($oldPath);
});

test('an unchanged file is not embedded again', function () {
    $document = $this->service->store(textFile('policy.txt', 'Same text.'), $this->workspace, null, DocumentSource::Api, 'policy');
    ingestNow($document);
    $calls = $this->embeddings->documentCalls;
    $hash = $document->fresh()->content_hash;

    $again = $this->service->store(textFile('policy.txt', 'Same text.'), $this->workspace, null, DocumentSource::Api, 'policy');
    ingestNow($again);

    expect($hash)->toBe(hash('sha256', 'Same text.'))
        ->and($this->embeddings->documentCalls)->toBe($calls)
        ->and($again->fresh()->status)->toBe(DocumentStatus::Ready)
        ->and($again->fresh()->chunks()->count())->toBeGreaterThan(0);
});

test('a new embedding model re-embeds even if the content is the same', function () {
    $document = $this->service->store(textFile('p.txt', 'Same text.'), $this->workspace, null, DocumentSource::Api, 'p');
    ingestNow($document);
    $calls = $this->embeddings->documentCalls;

    config(['docchat.embedding.model' => 'another-model']);
    ingestNow($document->fresh());

    expect($this->embeddings->documentCalls)->toBe($calls + 1);
});

test('external ids are per workspace and per source', function () {
    Queue::fake();
    $other = User::factory()->create()->currentWorkspace();

    $a = $this->service->store(textFile('a.txt', 'a'), $this->workspace, null, DocumentSource::Api, 'same');
    $b = $this->service->store(textFile('b.txt', 'b'), $other, null, DocumentSource::Api, 'same');
    $c = $this->service->store(textFile('c.txt', 'c'), $this->workspace, null, DocumentSource::GoogleDrive, 'same');

    expect(collect([$a->id, $b->id, $c->id])->unique())->toHaveCount(3);
});
