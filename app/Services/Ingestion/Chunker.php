<?php

namespace App\Services\Ingestion;

class Chunker
{
    private int $chunkChars;

    private int $overlapChars;

    private int $charsPerToken;

    public function __construct(
        ?int $chunkTokens = null,
        ?float $chunkOverlap = null,
        ?int $charsPerToken = null,
    ) {
        $chunkTokens = max(1, $chunkTokens ?? (int) config('docchat.ingestion.chunk_tokens'));
        $chunkOverlap = $chunkOverlap ?? (float) config('docchat.ingestion.chunk_overlap');
        $this->charsPerToken = max(1, $charsPerToken ?? (int) config('docchat.ingestion.chars_per_token'));

        $this->chunkChars = max(1, $chunkTokens * $this->charsPerToken);
        $this->overlapChars = min(
            max(0, (int) round($this->chunkChars * $chunkOverlap)),
            $this->chunkChars - 1,
        );
    }

    /**
     * @param  list<ParsedPage>  $pages
     * @return list<ChunkData>
     */
    public function chunk(array $pages): array
    {
        $chunks = [];
        $chunkIndex = 0;

        foreach ($pages as $page) {
            $text = $page->text;
            $length = mb_strlen($text);
            $start = 0;

            while ($start < $length) {
                $windowEnd = min($start + $this->chunkChars, $length);
                $end = $windowEnd < $length
                    ? $this->findBreakPosition($text, $start, $windowEnd)
                    : $length;

                $end = max($start + 1, min($end, $length));
                $content = trim(mb_substr($text, $start, $end - $start));

                if ($content !== '') {
                    $chunks[] = new ChunkData(
                        pageNumber: $page->pageNumber,
                        chunkIndex: $chunkIndex++,
                        content: $content,
                        tokenEstimate: (int) ceil(mb_strlen($content) / $this->charsPerToken),
                    );
                }

                if ($end >= $length) {
                    break;
                }

                $start = max($start + 1, $end - $this->overlapChars);
            }
        }

        return $chunks;
    }

    private function findBreakPosition(string $text, int $start, int $windowEnd): int
    {
        $minimum = $start + max(1, (int) floor(($windowEnd - $start) * 0.9));
        $tail = mb_substr($text, $minimum, $windowEnd - $minimum);

        $paragraph = mb_strrpos($tail, "\n\n");

        if ($paragraph !== false) {
            return $minimum + $paragraph + 2;
        }

        if (preg_match_all('/[.!?](?=\s)/u', $tail, $matches, PREG_OFFSET_CAPTURE) > 0) {
            $last = end($matches[0]);
            $byteOffset = (int) $last[1];

            return $minimum + mb_strlen(substr($tail, 0, $byteOffset)) + 1;
        }

        $space = mb_strrpos($tail, ' ');

        if ($space !== false) {
            return $minimum + $space + 1;
        }

        return $windowEnd;
    }
}
