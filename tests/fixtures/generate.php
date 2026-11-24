<?php

// Usage: php tests/fixtures/generate.php

function escapePdfText(string $text): string
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
}

/**
 * @param  list<string>  $pages  One text value per page; an empty string makes a page with no text layer.
 */
function buildPdf(array $pages, string $path): void
{
    $pageCount = count($pages);
    $fontId = 3 + ($pageCount * 2);
    $objects = [];
    $kids = [];

    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';

    for ($index = 0; $index < $pageCount; $index++) {
        $kids[] = (3 + ($index * 2)).' 0 R';
    }

    $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$pageCount.' >>';

    for ($index = 0; $index < $pageCount; $index++) {
        $pageId = 3 + ($index * 2);
        $contentId = $pageId + 1;
        $text = $pages[$index];

        $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] '
            .'/Resources << /Font << /F1 '.$fontId.' 0 R >> >> /Contents '.$contentId.' 0 R >>';

        $stream = $text === ''
            ? "q\nQ\n"
            : "BT\n/F1 14 Tf\n72 720 Td\n(".escapePdfText($text).") Tj\nET\n";

        $objects[$contentId] = '<< /Length '.strlen($stream)." >>\nstream\n".$stream.'endstream';
    }

    $objects[$fontId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    ksort($objects);

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [];

    foreach ($objects as $id => $body) {
        $offsets[$id] = strlen($pdf);
        $pdf .= $id." 0 obj\n".$body."\nendobj\n";
    }

    $maxId = max(array_keys($objects));
    $xrefOffset = strlen($pdf);

    $pdf .= 'xref'."\n0 ".($maxId + 1)."\n";
    $pdf .= "0000000000 65535 f \n";

    for ($id = 1; $id <= $maxId; $id++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
    }

    $pdf .= 'trailer'."\n<< /Size ".($maxId + 1)." /Root 1 0 R >>\nstartxref\n".$xrefOffset."\n%%EOF\n";

    file_put_contents($path, $pdf);
}

$fixtures = __DIR__;

buildPdf([
    'Page one: DocChat answers questions from the documents you upload.',
    'Page two: Every uploaded document is parsed page by page.',
    'Page three: Every chunk keeps the page number it came from.',
], $fixtures.DIRECTORY_SEPARATOR.'sample-3-pages.pdf');

buildPdf([
    'First page has text.',
    '',
    'Third page has text too.',
], $fixtures.DIRECTORY_SEPARATOR.'gap-3-pages.pdf');

buildPdf([''], $fixtures.DIRECTORY_SEPARATOR.'no-text.pdf');

file_put_contents(
    $fixtures.DIRECTORY_SEPARATOR.'hello.txt',
    "Hello from a plain text document.\n\nThis file has two paragraphs.\n",
);

file_put_contents(
    $fixtures.DIRECTORY_SEPARATOR.'hello.md',
    "# Hello Markdown\n\nThis is **markdown** content.\n\n- one\n- two\n",
);

echo "fixtures written\n";
