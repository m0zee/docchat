<?php

use App\Models\Chunk;
use App\Models\Document;

it('cascades chunks when a document is deleted', function () {
    $document = Document::factory()->create();
    $chunk = Chunk::factory()->for($document)->create();

    $document->delete();

    expect(Chunk::find($chunk->id))->toBeNull();
});

it('stores embeddings with the configured dimensions', function () {
    $chunk = Chunk::factory()->create();

    expect($chunk->fresh()->embedding)
        ->toHaveCount((int) config('docchat.embedding.dimensions'));
});
