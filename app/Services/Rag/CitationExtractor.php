<?php

namespace App\Services\Rag;

use Illuminate\Support\Str;

class CitationExtractor
{
    public const SNIPPET_CHARS = 200;

    /**
     * Map [n] markers in the answer to the retrieved chunks they cite.
     * Handles [1], [1, 3] and [1][3]; drops numbers outside the context;
     * keeps the first-seen order without duplicates.
     *
     * @param  list<RetrievedChunk>  $chunks  in the order they were numbered
     * @return list<array{n: int, chunk_id: int, document_id: int, title: string, page: int, snippet: string, similarity: float}>
     */
    public function extract(string $answer, array $chunks): array
    {
        preg_match_all('/\[(\d+(?:\s*,\s*\d+)*)\]/', $answer, $matches);

        $numbers = [];

        foreach ($matches[1] as $group) {
            foreach (preg_split('/\s*,\s*/', $group) ?: [] as $number) {
                $n = (int) $number;

                if ($n >= 1 && $n <= count($chunks) && ! in_array($n, $numbers, true)) {
                    $numbers[] = $n;
                }
            }
        }

        return array_map(function (int $n) use ($chunks): array {
            $chunk = $chunks[$n - 1];

            return [
                'n' => $n,
                'chunk_id' => $chunk->chunkId,
                'document_id' => $chunk->documentId,
                'title' => $chunk->documentTitle,
                'page' => $chunk->pageNumber,
                'snippet' => self::snippet($chunk->content),
                'similarity' => round($chunk->similarity, 4),
            ];
        }, $numbers);
    }

    public static function snippet(string $content): string
    {
        $text = (string) preg_replace('/\s+/u', ' ', trim($content));

        return Str::limit($text, self::SNIPPET_CHARS, '…', preserveWords: true);
    }
}
