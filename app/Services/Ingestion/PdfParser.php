<?php

namespace App\Services\Ingestion;

use App\Exceptions\DocumentParsingException;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
use Throwable;

class PdfParser
{
    public function __construct(private readonly TextCleaner $cleaner)
    {
    }

    public function parse(string $absolutePath): ParsedDocument
    {
        try {
            $config = new Config();
            $config->setDecodeMemoryLimit(100 * 1024 * 1024);

            $pdf = (new Parser([], $config))->parseFile($absolutePath);
            $rawPages = $pdf->getPages();
        } catch (Throwable $exception) {
            throw new DocumentParsingException('Unable to read the PDF.', previous: $exception);
        }

        $pages = [];

        foreach ($rawPages as $index => $page) {
            $text = $this->cleaner->clean((string) $page->getText());

            if ($text !== '') {
                $pages[] = new ParsedPage($index + 1, $text);
            }
        }

        return new ParsedDocument($pages, count($rawPages));
    }
}
