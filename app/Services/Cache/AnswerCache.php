<?php

namespace App\Services\Cache;

use App\Models\Workspace;
use App\Services\Rag\PromptBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Lottery;
use Illuminate\Support\Str;
use Throwable;

/**
 * Per-workspace cache of first-question answers, to save chat tokens on
 * repeated questions (widget FAQs). Exact matches on the normalized
 * question first, then near-identical questions by vector similarity.
 * Entries are tied to the workspace's corpus_version, so any document
 * change makes them stale.
 */
class AnswerCache
{
    public const EXACT = 'exact';

    public const SEMANTIC = 'semantic';

    public function enabled(Workspace $workspace): bool
    {
        return (bool) config('docchat.cache.answers')
            && $workspace->setting('answer_cache', true) !== false;
    }

    public static function normalize(string $question): string
    {
        $question = Str::lower(trim($question));
        $question = (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $question);

        return trim((string) preg_replace('/\s+/u', ' ', $question));
    }

    /**
     * Cache key for the document scope and every setting that changes answers.
     *
     * @param  list<int>|null  $documentIds
     */
    public static function scopeHash(?array $documentIds): string
    {
        $ids = $documentIds ?? [];
        sort($ids);

        return hash('sha256', json_encode([
            'documents' => $documentIds === null ? 'all' : $ids,
            'prompt' => PromptBuilder::VERSION,
            'chat_driver' => config('docchat.chat.driver'),
            'chat_model' => config('docchat.chat.drivers.'.config('docchat.chat.driver').'.model'),
            'embedding_model' => config('docchat.embedding.model'),
            'top_k' => (int) config('docchat.rag.top_k'),
            'threshold' => (float) config('docchat.rag.similarity_threshold'),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  list<int>|null  $documentIds
     */
    public function findExact(Workspace $workspace, string $question, ?array $documentIds): ?CachedAnswer
    {
        $row = $this->fresh($workspace, $documentIds)
            ->where('question_hash', hash('sha256', self::normalize($question)))
            ->orderByDesc('id')
            ->first(['id', 'answer', 'citations', 'prompt_tokens', 'completion_tokens']);

        return $row === null ? null : $this->hit($row, self::EXACT, 1.0);
    }

    /**
     * @param  array<int, float>  $vector
     * @param  list<int>|null  $documentIds
     */
    public function findSimilar(Workspace $workspace, array $vector, ?array $documentIds): ?CachedAnswer
    {
        $threshold = (float) config('docchat.cache.semantic_threshold');

        if ($threshold <= 0 || $threshold > 1) {
            return null;
        }

        $row = $this->fresh($workspace, $documentIds)
            ->select(['id', 'answer', 'citations', 'prompt_tokens', 'completion_tokens'])
            ->selectVectorDistance('embedding', $vector, 'distance')
            ->whereVectorDistanceLessThan('embedding', $vector, 1 - $threshold)
            ->orderByVectorDistance('embedding', $vector)
            ->first();

        return $row === null ? null : $this->hit($row, self::SEMANTIC, round(1 - (float) $row->distance, 6));
    }

    /**
     * @param  array<int, float>  $vector
     * @param  list<int>|null  $documentIds
     * @param  list<array<string, mixed>>  $citations
     */
    public function store(
        Workspace $workspace,
        string $question,
        array $vector,
        ?array $documentIds,
        string $answer,
        array $citations,
        int $promptTokens,
        int $completionTokens,
    ): void {
        try {
            DB::transaction(fn () => DB::table('answer_cache')->insert([
                'workspace_id' => $workspace->id,
                'question_hash' => hash('sha256', self::normalize($question)),
                'scope_hash' => self::scopeHash($documentIds),
                'corpus_version' => $this->corpusVersion($workspace),
                'embedding' => '['.implode(',', array_map('floatval', $vector)).']',
                'answer' => $answer,
                'citations' => json_encode($citations, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'expires_at' => now()->addDays(max(1, (int) config('docchat.cache.ttl_days'))),
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        } catch (Throwable $exception) {
            report($exception);

            return;
        }

        Lottery::odds(1, 50)->winner(fn () => $this->prune())->choose();
    }

    /**
     * Make every cached answer of the workspace stale.
     */
    public static function invalidate(int $workspaceId): void
    {
        DB::table('workspaces')->where('id', $workspaceId)->increment('corpus_version');
    }

    /**
     * Delete expired entries and entries from older document versions.
     */
    public function prune(): int
    {
        $expired = DB::table('answer_cache')->where('expires_at', '<=', now())->delete();

        $stale = DB::table('answer_cache')
            ->whereExists(fn ($query) => $query->select(DB::raw(1))
                ->from('workspaces')
                ->whereColumn('workspaces.id', 'answer_cache.workspace_id')
                ->whereColumn('workspaces.corpus_version', '>', 'answer_cache.corpus_version'))
            ->delete();

        return $expired + $stale;
    }

    /**
     * @param  list<int>|null  $documentIds
     */
    private function fresh(Workspace $workspace, ?array $documentIds): Builder
    {
        return DB::table('answer_cache')
            ->where('workspace_id', $workspace->id)
            ->where('scope_hash', self::scopeHash($documentIds))
            ->where('corpus_version', $this->corpusVersion($workspace))
            ->where('expires_at', '>', now());
    }

    private function corpusVersion(Workspace $workspace): int
    {
        // Read from the database: the model may be older than a document change.
        return (int) DB::table('workspaces')->where('id', $workspace->id)->value('corpus_version');
    }

    private function hit(object $row, string $kind, float $similarity): CachedAnswer
    {
        /** @var array{id: int|string, answer: string, citations: string, prompt_tokens: int|string, completion_tokens: int|string} $data */
        $data = (array) $row;

        DB::table('answer_cache')->where('id', $data['id'])->update([
            'hits' => DB::raw('hits + 1'),
            'last_hit_at' => now(),
        ]);

        /** @var list<array<string, mixed>> $citations */
        $citations = json_decode($data['citations'], true) ?: [];

        return new CachedAnswer(
            id: (int) $data['id'],
            answer: $data['answer'],
            citations: $citations,
            tokensSaved: (int) $data['prompt_tokens'] + (int) $data['completion_tokens'],
            hit: $kind,
            similarity: $similarity,
        );
    }
}
