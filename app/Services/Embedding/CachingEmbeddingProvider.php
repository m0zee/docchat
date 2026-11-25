<?php

namespace App\Services\Embedding;

use App\Contracts\EmbeddingProvider;
use App\Services\Cache\AnswerCache;
use Illuminate\Support\Facades\Cache;

/**
 * Caches query embeddings, so a repeated question costs no embedding call.
 * Document embeddings are not cached (content_hash already skips re-embedding).
 */
class CachingEmbeddingProvider implements EmbeddingProvider
{
    public function __construct(private EmbeddingProvider $inner) {}

    public function embedDocuments(array $texts, string $title = ''): array
    {
        return $this->inner->embedDocuments($texts, $title);
    }

    public function embedQuery(string $text): array
    {
        $key = 'docchat:qemb:'.hash('sha256', implode('|', [
            config('docchat.embedding.model'),
            config('docchat.embedding.dimensions'),
            config('docchat.embedding.task_prefixes.query'),
            AnswerCache::normalize($text),
        ]));

        /** @var array<int, float> $vector */
        $vector = Cache::remember(
            $key,
            now()->addDays(max(1, (int) config('docchat.cache.embedding_ttl_days'))),
            fn (): array => $this->inner->embedQuery($text),
        );

        return $vector;
    }
}
