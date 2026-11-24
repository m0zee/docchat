<?php

use App\Evals\EvalFile;

it('loads the sample eval file', function () {
    $cases = EvalFile::load(dirname(__DIR__, 2).'/evals/sample.json');

    expect($cases)->toHaveCount(10)
        ->and(array_filter($cases, fn ($case) => $case->shouldRefuse))->toHaveCount(2)
        ->and($cases[0]->expectedPage)->toBe(1)
        ->and($cases[0]->expectedKeywords)->toBe(['30']);
});

it('reports each bad case by number and field', function () {
    $path = tempnam(sys_get_temp_dir(), 'eval');
    file_put_contents($path, json_encode([
        ['question' => 'Fine?', 'expected_keywords' => ['a'], 'expected_document' => 'Doc', 'expected_page' => 1],
        ['question' => '', 'expected_keywords' => ['a'], 'expected_document' => 'Doc', 'expected_page' => 1],
        ['question' => 'Page?', 'expected_keywords' => ['a'], 'expected_document' => 'Doc', 'expected_page' => 'two'],
        ['question' => 'Refuse?', 'should_refuse' => 'yes'],
    ]));

    try {
        EvalFile::load($path);
        $this->fail('No exception thrown.');
    } catch (InvalidArgumentException $exception) {
        expect($exception->getMessage())
            ->toContain('Case 2: question must be a non-empty string')
            ->toContain('Case 3: expected_page must be a whole number from 1')
            ->toContain('Case 4: should_refuse must be true or false')
            ->not->toContain('Case 1');
    } finally {
        unlink($path);
    }
});

it('rejects a missing file and broken JSON', function () {
    expect(fn () => EvalFile::load('/nope.json'))->toThrow(InvalidArgumentException::class, 'not found');

    $path = tempnam(sys_get_temp_dir(), 'eval');
    file_put_contents($path, '{broken');

    expect(fn () => EvalFile::load($path))->toThrow(InvalidArgumentException::class, 'not valid JSON');

    unlink($path);
});
