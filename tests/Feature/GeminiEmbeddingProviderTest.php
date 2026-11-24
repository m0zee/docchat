<?php

use App\Contracts\EmbeddingProvider;
use App\Exceptions\EmbeddingException;
use App\Services\Embedding\GeminiEmbeddingProvider;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    config([
        'docchat.embedding.driver' => 'gemini',
        'docchat.embedding.model' => 'gemini-embedding-2',
        'docchat.embedding.dimensions' => 1536,
        'docchat.embedding.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
        'docchat.embedding.api_key' => 'test-key',
        'docchat.embedding.batch_size' => 50,
        'docchat.embedding.max_retries' => 5,
        'docchat.embedding.retry_base_ms' => 1000,
        'docchat.embedding.task_prefixes.query' => 'task: search result | query: ',
        'docchat.embedding.task_prefixes.document' => 'title: {title} | text: ',
    ]);

    Sleep::fake();
});

function geminiEmbeddingResponse(int $count, int $dimensions = 1536): Response|PromiseInterface
{
    return Http::response([
        'embeddings' => array_fill(0, $count, [
            'values' => array_fill(0, $dimensions, 0.1),
        ]),
    ]);
}

it('sends a batch request with the prefix, dimensions and API key header', function () {
    Http::fake(['*' => geminiEmbeddingResponse(1)]);

    $vectors = (new GeminiEmbeddingProvider)->embedDocuments(['chunk one'], 'My Doc');

    expect($vectors)->toHaveCount(1)
        ->and($vectors[0])->toHaveCount(1536);

    Http::assertSent(function (Request $request) {
        $data = $request->data();

        return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-2:batchEmbedContents'
            && $request->hasHeader('x-goog-api-key', 'test-key')
            && $data['requests'][0]['model'] === 'models/gemini-embedding-2'
            && $data['requests'][0]['content']['parts'][0]['text'] === 'title: My Doc | text: chunk one'
            && $data['requests'][0]['embedContentConfig']['outputDimensionality'] === 1536;
    });
});

it('splits inputs into the configured batch size', function () {
    Http::fake(fn (Request $request) => geminiEmbeddingResponse(
        count($request->data()['requests']),
    ));

    $texts = array_map(fn (int $index): string => 'chunk '.$index, range(1, 120));

    (new GeminiEmbeddingProvider)->embedDocuments($texts, 'Batch');

    $sizes = Http::recorded()
        ->map(fn (array $pair): int => count($pair[0]->data()['requests']))
        ->all();

    expect($sizes)->toBe([50, 50, 20]);
    Http::assertSentCount(3);
});

it('retries a rate-limited request and then succeeds', function () {
    Http::fakeSequence()
        ->push(['error' => ['message' => 'rate limited']], 429, ['Retry-After' => '1'])
        ->push([
            'embeddings' => [['values' => array_fill(0, 1536, 0.1)]],
        ], 200);

    $vectors = (new GeminiEmbeddingProvider)->embedDocuments(['chunk'], 'Doc');

    expect($vectors)->toHaveCount(1);
    Http::assertSentCount(2);
});

it('retries server errors', function () {
    Http::fakeSequence()
        ->push(['error' => ['message' => 'server error']], 500)
        ->push([
            'embeddings' => [['values' => array_fill(0, 1536, 0.1)]],
        ], 200);

    $vectors = (new GeminiEmbeddingProvider)->embedDocuments(['chunk'], 'Doc');

    expect($vectors)->toHaveCount(1);
    Http::assertSentCount(2);
});

it('fails after the maximum number of attempts', function () {
    config(['docchat.embedding.max_retries' => 3]);
    Http::fake(['*' => Http::response(['error' => ['message' => 'rate limited']], 429)]);

    expect(fn () => (new GeminiEmbeddingProvider)->embedDocuments(['chunk'], 'Doc'))
        ->toThrow(EmbeddingException::class);

    Http::assertSentCount(3);
});

it('rejects vectors with the wrong dimensions', function () {
    Http::fake(['*' => geminiEmbeddingResponse(1, 10)]);

    expect(fn () => (new GeminiEmbeddingProvider)->embedDocuments(['chunk'], 'Doc'))
        ->toThrow(EmbeddingException::class);
});

it('requires an API key', function () {
    config(['docchat.embedding.api_key' => null]);

    expect(fn () => (new GeminiEmbeddingProvider)->embedDocuments(['chunk'], 'Doc'))
        ->toThrow(EmbeddingException::class, 'Set GEMINI_API_KEY in .env.');
});

it('prefixes query embeddings for retrieval', function () {
    Http::fake(['*' => geminiEmbeddingResponse(1)]);

    $vector = (new GeminiEmbeddingProvider)->embedQuery('what is DocChat?');

    expect($vector)->toHaveCount(1536);

    Http::assertSent(fn (Request $request) => $request->data()['requests'][0]['content']['parts'][0]['text']
        === 'task: search result | query: what is DocChat?');
});

it('binds the configured embedding driver', function () {
    expect(app(EmbeddingProvider::class))->toBeInstanceOf(GeminiEmbeddingProvider::class);

    config(['docchat.embedding.driver' => 'unknown']);

    expect(fn () => app(EmbeddingProvider::class))
        ->toThrow(InvalidArgumentException::class);
});

it('caps a long Retry-After delay at 60 seconds', function () {
    Http::fakeSequence()
        ->push(['error' => ['message' => 'rate limited']], 429, ['Retry-After' => '3600'])
        ->push(['embeddings' => [['values' => array_fill(0, 1536, 0.1)]]], 200);

    (new GeminiEmbeddingProvider)->embedDocuments(['chunk'], 'Doc');

    Sleep::assertSequence([Sleep::for(60_000)->milliseconds()]);
});

it('marks client errors as permanent and rate limits as retryable', function () {
    config(['docchat.embedding.max_retries' => 1]);

    Http::fakeSequence()
        ->push(['error' => ['message' => 'bad key']], 403)
        ->push(['error' => ['message' => 'rate limited']], 429);

    $provider = new GeminiEmbeddingProvider;

    try {
        $provider->embedDocuments(['chunk'], 'Doc');
    } catch (EmbeddingException $exception) {
        expect($exception->retryable)->toBeFalse();
    }

    try {
        $provider->embedDocuments(['chunk'], 'Doc');
    } catch (EmbeddingException $exception) {
        expect($exception->retryable)->toBeTrue();
    }

    Http::assertSentCount(2);
});
