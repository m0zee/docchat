<?php

use App\Services\Formatting\MessageFormat;
use App\Services\Formatting\MessageFormatter;

$citations = [['n' => 1, 'title' => 'Fees & <Fines>', 'page' => 3]];

test('plain text lists sources and drops markdown bold', function () use ($citations) {
    $parts = (new MessageFormatter)->format('Late fees are **$0.25** a day [1].', $citations, MessageFormat::Plain);

    expect($parts)->toBe(["Late fees are \$0.25 a day [1].\n\nSources:\n[1] Fees & <Fines>, p. 3"]);
});

test('telegram html escapes text and keeps bold as <b>', function () use ($citations) {
    $parts = (new MessageFormatter)->format('Use <script> & **bold** [1].', $citations, MessageFormat::TelegramHtml);

    expect($parts)->toBe(["Use &lt;script&gt; &amp; <b>bold</b> [1].\n\n<b>Sources</b>\n[1] Fees &amp; &lt;Fines&gt;, p. 3"]);
});

test('whatsapp uses single-star bold', function () use ($citations) {
    $parts = (new MessageFormatter)->format('It is **free** [1].', $citations, MessageFormat::WhatsApp);

    expect($parts[0])->toStartWith('It is *free* [1].')->and($parts[0])->toContain("*Sources*\n[1] Fees & <Fines>, p. 3");
});

test('long answers are split under the limit at line and word boundaries', function () {
    $paragraph = trim(str_repeat('word ', 300));
    $answer = implode("\n\n", array_fill(0, 6, $paragraph));

    foreach ([MessageFormat::TelegramHtml, MessageFormat::WhatsApp, MessageFormat::Plain] as $format) {
        $parts = (new MessageFormatter)->format($answer, [], $format);

        expect(count($parts))->toBeGreaterThan(1);
        foreach ($parts as $part) {
            expect(mb_strlen($part))->toBeLessThanOrEqual(4000)->and($part)->not->toStartWith(' ');
        }
        expect(str_replace(["\n", ' '], '', implode('', $parts)))->toBe(str_replace(["\n", ' '], '', $answer));
    }
});

test('a single huge line is cut without breaking html tags or entities', function () {
    $answer = '**start** '.str_repeat('a&b ', 2000);

    $parts = (new MessageFormatter)->format($answer, [], MessageFormat::TelegramHtml);

    foreach ($parts as $part) {
        expect(mb_strlen($part))->toBeLessThanOrEqual(4000)
            ->and(preg_match('/&(?![a-z]+;)/', $part))->toBe(0)
            ->and(substr_count($part, '<b>'))->toBe(substr_count($part, '</b>'));
    }
});

test('discord parts stay under 2000 characters', function () {
    $parts = (new MessageFormatter)->format(trim(str_repeat('word ', 1000)), [], MessageFormat::Discord);

    expect(count($parts))->toBe(3);
    foreach ($parts as $part) {
        expect(mb_strlen($part))->toBeLessThanOrEqual(2000);
    }
});
