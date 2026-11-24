<?php

use App\Services\Ingestion\Chunker;
use App\Services\Ingestion\ParsedPage;

it('reads the chunk settings from config', function () {
    config([
        'docchat.ingestion.chunk_tokens' => 10,
        'docchat.ingestion.chunk_overlap' => 0,
        'docchat.ingestion.chars_per_token' => 1,
    ]);

    $chunks = app(Chunker::class)->chunk([
        new ParsedPage(1, str_repeat('a', 25)),
    ]);

    expect($chunks)->toHaveCount(3);
});
