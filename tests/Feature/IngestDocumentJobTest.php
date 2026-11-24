<?php

use App\Contracts\EmbeddingProvider;
use App\Enums\DocumentStatus;
use App\Exceptions\DocumentParsingException;
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

it('marks the document failed when the embedding provider fails', function () {
    config(['docchat.embedding.max_retries' => 1]);
    Http::fake(['*' => Http::response(['error' => ['message' => 'boom']], 500)]);

    $document = storeFixtureDocument('hello.txt', 'fail.txt', 'fail.txt', 'text/plain');

    expect(fn () => runIngestJob($document))->toThrow(EmbeddingException::class);

    $document->refresh();

    expect($document->status)->toBe(DocumentStatus::Failed)
        ->and($document->error)->not->toBeNull()
        ->and($document->chunks()->count())->toBe(0);
});

it('marks a scanned PDF failed with the R13 message', function () {
    $document = storeFixtureDocument('no-text.pdf', 'empty.pdf', 'empty.pdf', 'application/pdf');

    expect(fn () => runIngestJob($document))
        ->toThrow(DocumentParsingException::class, 'No extractable text (scanned PDF?)');

    $document->refresh();

    expect($document->status)->toBe(DocumentStatus::Failed)
        ->and($document->error)->toBe('No extractable text (scanned PDF?)');
});

it('marks a document failed when its file is missing', function () {
    $document = Document::factory()->create([
        'storage_path' => 'documents/missing.txt',
        'original_filename' => 'missing.txt',
        'status' => DocumentStatus::Pending,
    ]);

    expect(fn () => runIngestJob($document))
        ->toThrow(DocumentParsingException::class, 'The uploaded file is missing.');

    expect($document->refresh()->status)->toBe(DocumentStatus::Failed);
});
