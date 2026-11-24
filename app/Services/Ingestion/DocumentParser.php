<?php

namespace App\Services\Ingestion;

use App\Exceptions\DocumentParsingException;

class DocumentParser
{
    public function __construct(
        private readonly PdfParser $pdfParser,
        private readonly TextParser $textParser,
    ) {}

    public function parse(string $absolutePath, string $extension): ParsedDocument
    {
        $extension = strtolower($extension);

        $parsed = match ($extension) {
            'pdf' => $this->pdfParser->parse($absolutePath),
            'txt', 'md' => $this->textParser->parse($absolutePath),
            default => throw new DocumentParsingException('Unsupported file type.'),
        };

        if ($parsed->pages === []) {
            throw new DocumentParsingException(
                $extension === 'pdf'
                    ? 'No extractable text (scanned PDF?)'
                    : 'No extractable text in the uploaded file.',
            );
        }

        return $parsed;
    }
}
