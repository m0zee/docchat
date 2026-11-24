<?php

use App\Exceptions\DocumentParsingException;
use App\Services\Ingestion\DocumentParser;
use App\Services\Ingestion\PdfParser;
use App\Services\Ingestion\TextCleaner;
use App\Services\Ingestion\TextParser;

beforeEach(function () {
    $cleaner = new TextCleaner();

    $this->parser = new DocumentParser(
        new PdfParser($cleaner),
        new TextParser($cleaner),
    );
});

it('returns one page per PDF page with the real page numbers', function () {
    $parsed = $this->parser->parse(__DIR__.'/../fixtures/sample-3-pages.pdf', 'pdf');

    expect($parsed->pageCount)->toBe(3)
        ->and($parsed->pages)->toHaveCount(3)
        ->and($parsed->pages[0]->pageNumber)->toBe(1)
        ->and($parsed->pages[0]->text)->toContain('Page one')
        ->and($parsed->pages[1]->pageNumber)->toBe(2)
        ->and($parsed->pages[1]->text)->toContain('Page two')
        ->and($parsed->pages[2]->pageNumber)->toBe(3)
        ->and($parsed->pages[2]->text)->toContain('Page three');
});

it('skips empty PDF pages but keeps the real page numbers of the rest', function () {
    $parsed = $this->parser->parse(__DIR__.'/../fixtures/gap-3-pages.pdf', 'pdf');

    expect($parsed->pageCount)->toBe(3)
        ->and($parsed->pages)->toHaveCount(2)
        ->and($parsed->pages[0]->pageNumber)->toBe(1)
        ->and($parsed->pages[1]->pageNumber)->toBe(3);
});

it('reads txt and markdown files as one page', function (string $filename) {
    $parsed = $this->parser->parse(__DIR__.'/../fixtures/'.$filename, pathinfo($filename, PATHINFO_EXTENSION));

    expect($parsed->pageCount)->toBe(1)
        ->and($parsed->pages)->toHaveCount(1)
        ->and($parsed->pages[0]->pageNumber)->toBe(1);
})->with(['hello.txt', 'hello.md']);

it('strips NUL bytes and invalid UTF-8 from text files', function () {
    $path = tempnam(sys_get_temp_dir(), 'docchat');

    file_put_contents($path, "Hello\0 \xC3\x28 world\r\nnext line");

    try {
        $parsed = $this->parser->parse($path, 'txt');
    } finally {
        unlink($path);
    }

    expect($parsed->pages[0]->text)->not->toContain("\0")
        ->and(mb_check_encoding($parsed->pages[0]->text, 'UTF-8'))->toBeTrue();
});

it('throws the scanned-PDF message when a PDF has no text layer', function () {
    expect(fn () => $this->parser->parse(__DIR__.'/../fixtures/no-text.pdf', 'pdf'))
        ->toThrow(DocumentParsingException::class, 'No extractable text (scanned PDF?)');
});

it('throws a clear message for an empty text file', function () {
    $path = tempnam(sys_get_temp_dir(), 'docchat');
    file_put_contents($path, " \n\t ");

    try {
        expect(fn () => $this->parser->parse($path, 'txt'))
            ->toThrow(DocumentParsingException::class, 'No extractable text in the uploaded file.');
    } finally {
        unlink($path);
    }
});
