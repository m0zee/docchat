<?php

namespace App\Services\Ingestion;

use App\Exceptions\DocumentParsingException;

class TextParser
{
    public function __construct(private readonly TextCleaner $cleaner)
    {
    }

    public function parse(string $absolutePath): ParsedDocument
    {
        $contents = file_get_contents($absolutePath);

        if ($contents === false) {
            throw new DocumentParsingException('Unable to read the file.');
        }

        $text = $this->cleaner->clean($contents);

        return new ParsedDocument(
            $text === '' ? [] : [new ParsedPage(1, $text)],
            1,
        );
    }
}
