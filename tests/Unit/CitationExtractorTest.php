<?php

use App\Services\Rag\CitationExtractor;
use App\Services\Rag\RetrievedChunk;
use Tests\TestCase;

uses(TestCase::class);

function contextChunks(int $count): array
{
    return array_map(
        fn (int $i): RetrievedChunk => new RetrievedChunk(100 + $i, 7, 'Doc '.$i, $i, 'Content of chunk '.$i, 0.9 - $i / 100),
        range(1, $count),
    );
}

it('maps [n] to the nth retrieved chunk', function () {
    $citations = (new CitationExtractor)->extract('Fact [2].', contextChunks(5));

    expect($citations)->toHaveCount(1)
        ->and($citations[0])->toMatchArray(['n' => 2, 'chunk_id' => 102, 'document_id' => 7, 'title' => 'Doc 2', 'page' => 2]);
});

it('reads grouped and adjacent markers the same way', function () {
    $extractor = new CitationExtractor;

    expect(array_column($extractor->extract('A [1, 3].', contextChunks(5)), 'n'))->toBe([1, 3])
        ->and(array_column($extractor->extract('A [1][3].', contextChunks(5)), 'n'))->toBe([1, 3]);
});

it('drops numbers outside the context and repeats', function () {
    $citations = (new CitationExtractor)->extract('X [3] Y [9] Z [1] W [3] [0]', contextChunks(5));

    expect(array_column($citations, 'n'))->toBe([3, 1]);
});

it('cuts snippets at a word boundary', function () {
    $snippet = CitationExtractor::snippet(str_repeat('word ', 100));

    expect(mb_strlen($snippet))->toBeLessThanOrEqual(CitationExtractor::SNIPPET_CHARS + 1)
        ->and($snippet)->toEndWith('word…');
});
