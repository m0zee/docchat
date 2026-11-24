<?php

use App\Contracts\EmbeddingProvider;
use App\Enums\DocumentStatus;
use App\Exceptions\EmbeddingException;
use App\Jobs\IngestDocumentJob;
use App\Models\Document;
use App\Services\Ingestion\Chunker;
use App\Services\Ingestion\DocumentParser;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Storage::fake('local');
    Sleep::fake();

    config([
        'docchat.embedding.driver' => 'gemini',
        'docchat.embedding.model' => 'gemini-embedding-2',
        'docchat.embedding.dimensions' => 1536,
        'docchat.embedding.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
        'docchat.embedding.api_key' => 'test-key',
        'docchat.embedding.batch_size' => 50,
        'docchat.embedding.max_retries' => 3,
        'docchat.embedding.retry_base_ms' => 1000,
        'docchat.ingestion.chunk_tokens' => 800,
        'docchat.ingestion.chunk_overlap' => 0.15,
        'docchat.ingestion.chars_per_token' => 4,
    ]);
});

function runIngestJob(Document $document): void
{
    $job = new IngestDocumentJob($document);

    $job->handle(
        app(DocumentParser::class),
        app(Chunker::class),
        app(EmbeddingProvider::class),
    );
}

function fakeGeminiForRequests(): void
{
    Http::fake(function (Request $request) {
        return Http::response([
            'embeddings' => array_fill(0, count($request->data()['requests']), [
                'values' => array_fill(0, 1536, 0.1),
            ]),
        ]);
    });
}

function storeFixtureDocument(
    string $fixture,
    string $storedName,
    string $originalName,
    string $mime,
): Document {
    $path = 'documents/'.$storedName;
    Storage::disk('local')->put($path, file_get_contents(base_path('tests/fixtures/'.$fixture)));

    return Document::factory()->create([
        'storage_path' => $path,
        'original_filename' => $originalName,
        'mime' => $mime,
        'status' => DocumentStatus::Pending,
        'page_count' => null,
    ]);
}

it('ingests a text document into ready chunks', function () {
    fakeGeminiForRequests();
    $document = storeFixtureDocument('hello.txt', 'hello.txt', 'hello.txt', 'text/plain');

    runIngestJob($document);

    $document->refresh();

    expect($document->status)->toBe(DocumentStatus::Ready)
        ->and($document->page_count)->toBe(1)
        ->and($document->embedding_model)->toBe('gemini-embedding-2')
        ->and($document->chunks()->count())->toBe(1);

    $chunk = $document->chunks()->first();

    expect($chunk->page_number)->toBe(1)
        ->and($chunk->chunk_index)->toBe(0)
        ->and($chunk->embedding)->toHaveCount(1536);
});

it('keeps page numbers for a multi-page PDF', function () {
    fakeGeminiForRequests();
    $document = storeFixtureDocument('sample-3-pages.pdf', 'sample.pdf', 'sample.pdf', 'application/pdf');

    runIngestJob($document);

    $document->refresh();

    expect($document->page_count)->toBe(3)
        ->and($document->status)->toBe(DocumentStatus::Ready)
        ->and($document->chunks()->count())->toBe(3)
        ->and($document->chunks()->orderBy('chunk_index')->pluck('page_number')->all())->toBe([1, 2, 3]);
});

it('does not duplicate chunks when the job runs twice', function () {
    fakeGeminiForRequests();
    $document = storeFixtureDocument('sample-3-pages.pdf', 'again.pdf', 'again.pdf', 'application/pdf');

    runIngestJob($document);
    runIngestJob($document->refresh());

    expect($document->refresh()->chunks()->count())->toBe(3);
});

it('keeps the document processing during a retryable failure and fails it after the last try', function () {
    config(['docchat.embedding.max_retries' => 1]);
    Http::fake(['*' => Http::response(['error' => ['message' => 'boom']], 500)]);

    $document = storeFixtureDocument('hello.txt', 'fail.txt', 'fail.txt', 'text/plain');
    $job = (new IngestDocumentJob($document))->withFakeQueueInteractions();

    $thrown = null;

    try {
        $job->handle(app(DocumentParser::class), app(Chunker::class), app(EmbeddingProvider::class));
    } catch (EmbeddingException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->not->toBeNull()
        ->and($thrown->retryable)->toBeTrue()
        ->and($document->refresh()->status)->toBe(DocumentStatus::Processing);

    $job->assertNotFailed();

    // The worker calls failed() once the last attempt is used up.
    $job->failed($thrown);

    $document->refresh();

    expect($document->status)->toBe(DocumentStatus::Failed)
        ->and($document->error)->toBe('Gemini request failed with status 500.')
        ->and($document->embedding_model)->toBeNull()
        ->and($document->chunks()->count())->toBe(0);
});

it('recovers from a 503 on a later try without ever storing failed', function () {
    config(['docchat.embedding.max_retries' => 1]);

    Http::fakeSequence()
        ->push(['error' => ['message' => 'unavailable']], 503)
        ->push(['embeddings' => [['values' => array_fill(0, 1536, 0.1)]]]);

    $document = storeFixtureDocument('hello.txt', 'retry.txt', 'retry.txt', 'text/plain');

    $statuses = [];
    Document::updated(function (Document $updated) use (&$statuses): void {
        $statuses[] = $updated->status;
    });

    expect(fn () => runIngestJob($document))->toThrow(EmbeddingException::class);

    runIngestJob($document->refresh());

    expect($document->refresh()->status)->toBe(DocumentStatus::Ready)
        ->and($statuses)->not->toContain(DocumentStatus::Failed);
});

it('fails a scanned PDF at once without retrying', function () {
    Http::fake();

    $document = storeFixtureDocument('no-text.pdf', 'empty.pdf', 'empty.pdf', 'application/pdf');
    $job = (new IngestDocumentJob($document))->withFakeQueueInteractions();

    $job->handle(app(DocumentParser::class), app(Chunker::class), app(EmbeddingProvider::class));

    $job->assertFailed();
    $job->assertNotReleased();
    Http::assertNothingSent();

    $document->refresh();

    expect($document->status)->toBe(DocumentStatus::Failed)
        ->and($document->error)->toBe('No extractable text (scanned PDF?)');
});

it('fails at once on a rejected Gemini key with a single request', function () {
    Http::fake(['*' => Http::response(['error' => ['message' => 'API key not valid']], 401)]);

    $document = storeFixtureDocument('hello.txt', 'badkey.txt', 'badkey.txt', 'text/plain');
    $job = (new IngestDocumentJob($document))->withFakeQueueInteractions();

    $job->handle(app(DocumentParser::class), app(Chunker::class), app(EmbeddingProvider::class));

    $job->assertFailed();
    $job->assertNotReleased();
    Http::assertSentCount(1);

    expect($document->refresh()->status)->toBe(DocumentStatus::Failed)
        ->and($document->error)->toBe('Gemini request failed with status 401.');
});

it('fails at once when the file is missing', function () {
    $document = Document::factory()->create([
        'storage_path' => 'documents/missing.txt',
        'original_filename' => 'missing.txt',
        'status' => DocumentStatus::Pending,
    ]);

    $job = (new IngestDocumentJob($document))->withFakeQueueInteractions();
    $job->handle(app(DocumentParser::class), app(Chunker::class), app(EmbeddingProvider::class));

    $job->assertFailed();

    expect($document->refresh()->status)->toBe(DocumentStatus::Failed)
        ->and($document->error)->toBe('The uploaded file is missing.');
});

it('deletes the job instead of failing when the document is gone', function () {
    expect((new IngestDocumentJob(new Document))->deleteWhenMissingModels)->toBeTrue();

    $document = Document::factory()->create();
    $job = new IngestDocumentJob($document);
    $document->delete();

    $job->failed(new RuntimeException('gone'));

    expect(Document::query()->count())->toBe(0);
});
