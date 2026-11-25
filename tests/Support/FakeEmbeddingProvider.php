<?php

namespace Tests\Support;

use App\Contracts\EmbeddingProvider;

/**
 * Returns hand-made vectors so tests control cosine similarity exactly.
 */
class FakeEmbeddingProvider implements EmbeddingProvider
{
    /** @var list<string> */
    public array $queries = [];

    /** Number of embedDocuments() calls. */
    public int $documentCalls = 0;

    /**
     * @param  array<int, float>|null  $queryVector
     */
    public function __construct(public ?array $queryVector = null) {}

    /**
     * A unit vector whose cosine similarity with axis(0) is $similarity.
     *
     * @return array<int, float>
     */
    public static function withSimilarity(float $similarity, int $axis = 1): array
    {
        $vector = array_fill(0, (int) config('docchat.embedding.dimensions'), 0.0);
        $vector[0] = $similarity;
        $vector[$axis] = sqrt(max(0.0, 1 - $similarity ** 2));

        return $vector;
    }

    public function embedDocuments(array $texts, string $title = ''): array
    {
        $this->documentCalls++;

        return array_map(fn (): array => self::withSimilarity(1.0), $texts);
    }

    public function embedQuery(string $text): array
    {
        $this->queries[] = $text;

        return $this->queryVector ?? self::withSimilarity(1.0);
    }
}
