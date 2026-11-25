<?php

namespace App\Evals;

use App\Contracts\EmbeddingProvider;
use App\Enums\ConversationChannel;
use App\Models\Workspace;
use App\Services\Ai\AiProviders;
use App\Services\Rag\AnswerService;
use App\Services\Rag\PromptBuilder;
use App\Services\Rag\RetrievedChunk;
use App\Services\Rag\Retriever;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * Runs eval cases through the same retrieval and answer path the chat uses.
 */
class EvalRunner
{
    public const REFUSAL_PHRASES = ["I couldn't find that in your documents", "I don't know"];

    public function __construct(
        private AnswerService $answers,
        private Retriever $retriever,
        private EmbeddingProvider $embeddings,
        private AiProviders $providers,
    ) {}

    /**
     * @param  list<EvalCase>  $cases
     * @param  list<int>|null  $documentIds
     * @param  (callable(int, array<string, mixed>): void)|null  $onResult
     * @return array{config: array<string, mixed>, cases: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function run(Workspace $workspace, array $cases, ?array $documentIds, float $delaySeconds, ?callable $onResult = null): array
    {
        $results = [];
        $modelCalled = false;

        foreach ($cases as $index => $case) {
            if ($modelCalled && $delaySeconds > 0) {
                // Stay under the free tier's tokens-per-minute limit.
                Sleep::for((int) round($delaySeconds * 1000))->milliseconds();
            }

            $conversation = $workspace->conversations()->create(['channel' => ConversationChannel::Eval, 'title' => 'eval: '.Str::limit($case->question, 60)]);

            if ($documentIds !== null) {
                $conversation->documents()->sync($documentIds);
            }

            try {
                $message = $this->answers->answer($conversation, $case->question);
                $retrieved = $this->answers->lastRetrieved;
            } finally {
                $conversation->delete();
            }

            $modelCalled = $retrieved !== [];
            $refused = self::isRefusal($message->content);

            $result = [
                'question' => $case->question,
                'should_refuse' => $case->shouldRefuse,
                'expected_document' => $case->expectedDocument,
                'expected_page' => $case->expectedPage,
                'retrieved' => array_map(fn (RetrievedChunk $chunk): array => [
                    'document' => $chunk->documentTitle,
                    'page' => $chunk->pageNumber,
                    'similarity' => round($chunk->similarity, 4),
                ], $retrieved),
                'hit' => $case->shouldRefuse ? null : self::isHit($case, $retrieved),
                'keyword_match' => $case->shouldRefuse ? null : self::keywordMatch($case, $message->content),
                'refused' => $refused,
                'answer' => $message->content,
                'error' => $message->error,
                'latency_ms' => $message->latency_ms,
                'prompt_tokens' => $message->prompt_tokens,
                'completion_tokens' => $message->completion_tokens,
                'usage_estimated' => $message->usage_estimated,
            ];

            $results[] = $result;

            if ($onResult !== null) {
                $onResult($index, $result);
            }
        }

        return [
            'config' => self::config($delaySeconds),
            'cases' => $results,
            'summary' => self::summarize($results),
        ];
    }

    /**
     * Retrieval only, at several thresholds. Embeds each question once and
     * never calls the chat model, so it is cheap on free tiers.
     *
     * @param  list<EvalCase>  $cases
     * @param  list<float>  $thresholds
     * @param  list<int>|null  $documentIds
     * @return list<array{threshold: float, hit_rate: float|null, correct_refusal_rate: float|null, false_refusal_rate: float|null}>
     */
    public function sweep(Workspace $workspace, array $cases, array $thresholds, ?array $documentIds): array
    {
        $embeddings = $this->providers->embeddings($workspace, $this->embeddings);
        $vectors = array_map(fn (EvalCase $case): array => $embeddings->embedQuery($case->question), $cases);
        $rows = [];

        foreach ($thresholds as $threshold) {
            $hits = $answerable = $refusedWhenShould = $shouldRefuse = $falseRefusals = 0;

            foreach ($cases as $index => $case) {
                $retrieved = $this->retriever->search($workspace, $vectors[$index], $documentIds, $threshold);

                if ($case->shouldRefuse) {
                    $shouldRefuse++;
                    $refusedWhenShould += $retrieved === [] ? 1 : 0;
                } else {
                    $answerable++;
                    $hits += self::isHit($case, $retrieved) ? 1 : 0;
                    $falseRefusals += $retrieved === [] ? 1 : 0;
                }
            }

            $rows[] = [
                'threshold' => $threshold,
                'hit_rate' => self::rate($hits, $answerable),
                'correct_refusal_rate' => self::rate($refusedWhenShould, $shouldRefuse),
                'false_refusal_rate' => self::rate($falseRefusals, $answerable),
            ];
        }

        return $rows;
    }

    public static function isRefusal(string $answer): bool
    {
        foreach (self::REFUSAL_PHRASES as $phrase) {
            if (stripos($answer, $phrase) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<RetrievedChunk>  $retrieved
     */
    public static function isHit(EvalCase $case, array $retrieved): bool
    {
        foreach ($retrieved as $chunk) {
            if ($chunk->pageNumber === $case->expectedPage
                && strcasecmp(trim($chunk->documentTitle), trim((string) $case->expectedDocument)) === 0) {
                return true;
            }
        }

        return false;
    }

    public static function keywordMatch(EvalCase $case, string $answer): float
    {
        if ($case->expectedKeywords === []) {
            return 1.0;
        }

        $found = array_filter(
            $case->expectedKeywords,
            fn (string $keyword): bool => mb_stripos($answer, $keyword) !== false,
        );

        return round(count($found) / count($case->expectedKeywords), 4);
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    public static function summarize(array $results): array
    {
        $answerable = array_values(array_filter($results, fn (array $r): bool => ! $r['should_refuse']));
        $refuse = array_values(array_filter($results, fn (array $r): bool => (bool) $r['should_refuse']));
        $latencies = array_filter(array_column($results, 'latency_ms'), fn ($v): bool => $v !== null);

        return [
            'cases' => count($results),
            'answerable' => count($answerable),
            'should_refuse' => count($refuse),
            'retrieval_hit_rate' => self::rate(count(array_filter($answerable, fn (array $r): bool => $r['hit'] === true)), count($answerable)),
            'keyword_match_rate' => $answerable === [] ? null : round(array_sum(array_column($answerable, 'keyword_match')) / count($answerable), 4),
            'correct_refusal_rate' => self::rate(count(array_filter($refuse, fn (array $r): bool => $r['refused'] === true)), count($refuse)),
            'false_refusal_rate' => self::rate(count(array_filter($answerable, fn (array $r): bool => $r['refused'] === true)), count($answerable)),
            'errors' => count(array_filter($results, fn (array $r): bool => $r['error'] !== null)),
            'avg_latency_ms' => $latencies === [] ? null : (int) round(array_sum($latencies) / count($latencies)),
            'total_tokens' => array_sum(array_map(fn (array $r): int => (int) $r['prompt_tokens'] + (int) $r['completion_tokens'], $results)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function config(float $delaySeconds = 0): array
    {
        $driver = (string) config('docchat.chat.driver');

        return [
            'embedding_model' => config('docchat.embedding.model'),
            'embedding_dimensions' => (int) config('docchat.embedding.dimensions'),
            'chat_driver' => $driver,
            'chat_model' => config('docchat.chat.drivers.'.$driver.'.model'),
            'top_k' => (int) config('docchat.rag.top_k'),
            'similarity_threshold' => (float) config('docchat.rag.similarity_threshold'),
            'history_messages' => (int) config('docchat.rag.history_messages'),
            'chunk_tokens' => (int) config('docchat.ingestion.chunk_tokens'),
            'chunk_overlap' => (float) config('docchat.ingestion.chunk_overlap'),
            'prompt_version' => PromptBuilder::VERSION,
            'delay_seconds' => $delaySeconds,
        ];
    }

    private static function rate(int $count, int $total): ?float
    {
        return $total === 0 ? null : round($count / $total, 4);
    }
}
