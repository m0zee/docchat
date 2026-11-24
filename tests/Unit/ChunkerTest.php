<?php

use App\Services\Ingestion\Chunker;
use App\Services\Ingestion\ParsedPage;

it('overlaps consecutive chunks by the configured amount', function () {
    $chunks = (new Chunker(10, 0.2, 1))->chunk([
        new ParsedPage(1, 'abcdefghijklmnopqrstuvwxyz0123456789'),
    ]);

    expect($chunks)->toHaveCount(5)
        ->and($chunks[0]->content)->toBe('abcdefghij')
        ->and($chunks[1]->content)->toBe('ijklmnopqr')
        ->and(mb_substr($chunks[0]->content, -2))->toBe(mb_substr($chunks[1]->content, 0, 2))
        ->and($chunks[1]->chunkIndex)->toBe(1);
});

it('never crosses page boundaries', function () {
    $chunks = (new Chunker(10, 0.2, 1))->chunk([
        new ParsedPage(1, str_repeat('A', 20)),
        new ParsedPage(2, str_repeat('B', 20)),
    ]);

    expect($chunks)->toHaveCount(6)
        ->and(array_map(fn ($chunk) => $chunk->pageNumber, $chunks))->toBe([1, 1, 1, 2, 2, 2]);

    foreach ($chunks as $chunk) {
        $expected = $chunk->pageNumber === 1 ? 'A' : 'B';

        expect(trim($chunk->content, $expected))->toBe('');
    }
});

it('returns one chunk for a page shorter than the window', function () {
    $chunks = (new Chunker(10, 0.2, 4))->chunk([
        new ParsedPage(1, 'short page'),
    ]);

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0]->content)->toBe('short page')
        ->and($chunks[0]->tokenEstimate)->toBe(3);
});

it('returns no chunks for an empty page list', function () {
    expect((new Chunker(10, 0.2, 1))->chunk([]))->toBe([]);
});

it('never cuts a multibyte character', function () {
    $chunks = (new Chunker(10, 0.2, 1))->chunk([
        new ParsedPage(1, str_repeat('مرحبا ', 40)),
    ]);

    expect($chunks)->not->toBeEmpty();

    foreach ($chunks as $chunk) {
        expect(mb_check_encoding($chunk->content, 'UTF-8'))->toBeTrue();
    }
});
