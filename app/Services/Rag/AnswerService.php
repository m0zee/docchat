<?php

namespace App\Services\Rag;

use App\Contracts\ChatProvider;
use App\Contracts\EmbeddingProvider;
use App\Enums\ConversationChannel;
use App\Enums\MessageRole;
use App\Evals\EvalRunner;
use App\Exceptions\ChatProviderException;
use App\Exceptions\ChatRateLimitedException;
use App\Exceptions\EmbeddingException;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Cache\AnswerCache;
use App\Services\Cache\CachedAnswer;
use App\Services\Chat\ChatResult;
use App\Services\Usage\UsageRecorder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AnswerService
{
    public const NOT_FOUND = "I couldn't find that in your documents.";

    public const BUSY = 'The model is busy, try again in a minute.';

    /**
     * Chunks retrieved for the last answer, for evals and logging.
     *
     * @var list<RetrievedChunk>
     */
    public array $lastRetrieved = [];

    public function __construct(
        private Retriever $retriever,
        private PromptBuilder $prompts,
        private CitationExtractor $citations,
        private ChatProvider $chat,
        private UsageRecorder $usage,
        private EmbeddingProvider $embeddings,
        private AnswerCache $cache,
    ) {}

    /**
     * @param  (callable(string): void)|null  $onDelta  called with each streamed text piece
     */
    public function answer(Conversation $conversation, string $question, ?callable $onDelta = null): Message
    {
        $question = trim($question);
        $history = $conversation->messages()
            ->reorder('id', 'desc')
            ->limit(max(0, (int) config('docchat.rag.history_messages')))
            ->get()
            ->reverse()
            ->values();

        $conversation->messages()->create([
            'role' => MessageRole::User,
            'content' => $question,
        ]);

        if ($conversation->title === null) {
            $conversation->title = Str::limit($question, 77);
        }

        $conversation->touch();

        $started = hrtime(true);
        $this->lastRetrieved = [];
        $workspace = $conversation->workspace;
        $documentIds = $conversation->scopedDocumentIds();

        // Only a first question can be answered from the cache: a follow-up
        // depends on the chat before it. Evals always measure the real path.
        $cacheable = $history->isEmpty()
            && $conversation->channel !== ConversationChannel::Eval
            && $this->cache->enabled($workspace);

        if ($cacheable && ($hit = $this->cache->findExact($workspace, $question, $documentIds)) !== null) {
            return $this->answerFromCache($conversation, $hit, $started, $onDelta, embeddingTokens: 0);
        }

        try {
            $vector = $this->embeddings->embedQuery($question);

            if ($cacheable && ($hit = $this->cache->findSimilar($workspace, $vector, $documentIds)) !== null) {
                return $this->answerFromCache($conversation, $hit, $started, $onDelta, UsageRecorder::estimateTokens($question));
            }

            $this->lastRetrieved = $this->retriever->search($workspace, $vector, $documentIds);
        } catch (EmbeddingException $exception) {
            report($exception);

            $message = $this->saveAssistant($conversation, '', $started, error: $exception->retryable
                ? self::BUSY
                : 'Search is not available: '.$exception->getMessage());
            $this->log($conversation, $message, null, embeddingTokens: 0);

            return $message;
        }

        if ($this->lastRetrieved === []) {
            // Nothing relevant: answer without spending a model call.
            $message = $this->saveAssistant($conversation, self::NOT_FOUND, $started, citations: []);
            $this->log($conversation, $message, null, UsageRecorder::estimateTokens($question));

            return $message;
        }

        $messages = $this->prompts->build($question, $this->lastRetrieved, $history->all());
        $content = '';
        $result = null;
        $error = null;

        try {
            $stream = $this->chat->stream($messages);

            foreach ($stream as $delta) {
                $content .= $delta;

                if ($onDelta !== null) {
                    $onDelta($delta);
                }
            }

            $result = $stream->getReturn();
        } catch (ChatRateLimitedException) {
            $error = self::BUSY;
        } catch (ChatProviderException $exception) {
            report($exception);
            $error = 'The answer could not be completed. '.$exception->getMessage();
        } catch (Throwable $exception) {
            report($exception);
            $error = 'The answer could not be completed. Please try again.';
        }

        $citations = $this->citations->extract($content, $this->lastRetrieved);

        $message = $this->saveAssistant(
            $conversation,
            $result instanceof ChatResult ? $result->content : $content,
            $started,
            citations: $citations,
            result: $result,
            error: $error,
        );

        if ($cacheable && $error === null && $result instanceof ChatResult
            && $citations !== [] && trim($message->content) !== '' && ! EvalRunner::isRefusal($message->content)) {
            $this->cache->store(
                $workspace,
                $question,
                $vector,
                $documentIds,
                $message->content,
                $citations,
                (int) $result->promptTokens,
                (int) $result->completionTokens,
            );
        }

        $this->log($conversation, $message, $result?->model, UsageRecorder::estimateTokens($question));

        return $message;
    }

    /**
     * @param  (callable(string): void)|null  $onDelta
     */
    private function answerFromCache(Conversation $conversation, CachedAnswer $hit, int $started, ?callable $onDelta, int $embeddingTokens): Message
    {
        if ($onDelta !== null) {
            // Same streaming shape as a model answer, in word-sized pieces.
            foreach (preg_split('/(?<=\s)/u', $hit->answer) ?: [$hit->answer] as $piece) {
                if ($piece !== '') {
                    $onDelta($piece);
                }
            }
        }

        $message = $this->saveAssistant($conversation, $hit->answer, $started, citations: $hit->citations, cacheHit: $hit->hit);
        $this->log($conversation, $message, null, $embeddingTokens, $hit);

        return $message;
    }

    private function log(Conversation $conversation, Message $message, ?string $model, int $embeddingTokens, ?CachedAnswer $hit = null): void
    {
        $this->usage->question($conversation, $message, $embeddingTokens, $hit?->hit, $hit->tokensSaved ?? 0);

        Log::channel('docchat')->info('answer', [
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'model' => $model,
            'prompt_tokens' => $message->prompt_tokens,
            'completion_tokens' => $message->completion_tokens,
            'usage_estimated' => $message->usage_estimated,
            'latency_ms' => $message->latency_ms,
            'chunks' => count($this->lastRetrieved),
            'top_similarity' => $this->lastRetrieved === [] ? null : $this->lastRetrieved[0]->similarity,
            'refused' => $message->content === self::NOT_FOUND,
            'cache_hit' => $hit?->hit,
            'error' => $message->error !== null,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $citations
     */
    private function saveAssistant(
        Conversation $conversation,
        string $content,
        int $started,
        array $citations = [],
        ?ChatResult $result = null,
        ?string $error = null,
        ?string $cacheHit = null,
    ): Message {
        return $conversation->messages()->create([
            'role' => MessageRole::Assistant,
            'content' => $content,
            'citations' => $citations,
            'prompt_tokens' => $result?->promptTokens,
            'completion_tokens' => $result?->completionTokens,
            'usage_estimated' => $result->usageEstimated ?? false,
            'cache_hit' => $cacheHit,
            'latency_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
            'error' => $error === null ? null : Str::limit($error, 250),
        ]);
    }
}
