<?php

use App\Contracts\EmbeddingProvider;
use App\Enums\DocumentStatus;
use App\Models\Chunk;
use App\Models\Document;
use App\Models\User;
use App\Services\Rag\Retriever;
use Tests\Support\FakeEmbeddingProvider;

beforeEach(function () {
    config(['docchat.rag.top_k' => 5, 'docchat.rag.similarity_threshold' => 0.5]);
    $this->app->instance(EmbeddingProvider::class, new FakeEmbeddingProvider);
    $this->user = User::factory()->create();
});

function chunkAt(Document $document, float $similarity, int $index = 0, int $page = 1): Chunk
{
    return Chunk::factory()->for($document)->create([
        'chunk_index' => $index,
        'page_number' => $page,
        'content' => 'chunk '.$similarity,
        'embedding' => FakeEmbeddingProvider::withSimilarity($similarity, axis: $index + 1),
    ]);
}

it('returns chunks ordered by similarity and capped at top_k', function () {
    $document = Document::factory()->ready()->for($this->user)->create(['title' => 'Guide']);

    foreach ([0.6, 0.95, 0.7, 0.9, 0.8, 0.85, 0.75] as $index => $similarity) {
        chunkAt($document, $similarity, $index, page: $index + 1);
    }

    $results = app(Retriever::class)->retrieve($this->user->currentWorkspace(), 'question');

    expect($results)->toHaveCount(5)
        ->and(array_map(fn ($chunk) => round($chunk->similarity, 2), $results))->toBe([0.95, 0.9, 0.85, 0.8, 0.75])
        ->and($results[0]->documentTitle)->toBe('Guide')
        ->and($results[0]->pageNumber)->toBe(2);
});

it('drops chunks under the similarity threshold', function () {
    $document = Document::factory()->ready()->for($this->user)->create();
    chunkAt($document, 0.9, 0);
    chunkAt($document, 0.55, 1);
    chunkAt($document, 0.3, 2);

    expect(app(Retriever::class)->retrieve($this->user->currentWorkspace(), 'q'))->toHaveCount(2);

    config(['docchat.rag.similarity_threshold' => 0.8]);
    expect(app(Retriever::class)->retrieve($this->user->currentWorkspace(), 'q'))->toHaveCount(1);

    config(['docchat.rag.similarity_threshold' => 0.95]);
    expect(app(Retriever::class)->retrieve($this->user->currentWorkspace(), 'q'))->toBe([]);
});

it('never returns another user\'s chunks, even when asked for their document', function () {
    $mine = Document::factory()->ready()->for($this->user)->create();
    chunkAt($mine, 0.6, 0);

    $theirs = Document::factory()->ready()->create();
    $closest = chunkAt($theirs, 0.99, 1);

    $all = app(Retriever::class)->retrieve($this->user->currentWorkspace(), 'q');
    $forged = app(Retriever::class)->retrieve($this->user->currentWorkspace(), 'q', [$theirs->id]);

    expect(array_map(fn ($chunk) => $chunk->chunkId, $all))->not->toContain($closest->id)
        ->and($all)->toHaveCount(1)
        ->and($forged)->toBe([]);
});

it('skips documents that are not ready', function () {
    foreach ([DocumentStatus::Pending, DocumentStatus::Processing, DocumentStatus::Failed] as $index => $status) {
        $document = Document::factory()->for($this->user)->create(['status' => $status]);
        chunkAt($document, 0.9, $index);
    }

    expect(app(Retriever::class)->retrieve($this->user->currentWorkspace(), 'q'))->toBe([]);
});

it('limits the search to the chosen documents', function () {
    $first = Document::factory()->ready()->for($this->user)->create();
    $second = Document::factory()->ready()->for($this->user)->create();
    chunkAt($first, 0.9, 0);
    $wanted = chunkAt($second, 0.7, 1);

    $results = app(Retriever::class)->retrieve($this->user->currentWorkspace(), 'q', [$second->id]);

    expect($results)->toHaveCount(1)
        ->and($results[0]->chunkId)->toBe($wanted->id)
        ->and($results[0]->documentId)->toBe($second->id);
});

it('embeds the question once with the query embedding', function () {
    $fake = new FakeEmbeddingProvider;
    $this->app->instance(EmbeddingProvider::class, $fake);

    app(Retriever::class)->retrieve($this->user->currentWorkspace(), 'What is DocChat?');

    expect($fake->queries)->toBe(['What is DocChat?']);
});
