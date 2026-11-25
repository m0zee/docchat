<?php

namespace App\Services\Rag;

use App\Enums\AiSource;
use App\Enums\ConversationChannel;
use App\Enums\MessageRole;
use App\Evals\EvalRunner;
use App\Exceptions\ChatProviderException;
use App\Exceptions\ChatRateLimitedException;
use App\Exceptions\EmbeddingException;
use App\Exceptions\InvalidProviderKeyException;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\Ai\AiProviders;
use App\Services\Cache\AnswerCache;
use App\Services\Cache\CachedAnswer;
use App\Services\Chat\ChatResult;
use App\Services\Usage\UsageRecorder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
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
        private UsageRecorder $usage,
        private AiProviders $providers,
        private AnswerCache $cache,
    ) {}

    /**
     * @param  (callable(string): void)|null  $onDelta  called with each streamed text piece
     * @param  string|null  $style  extra instructions on how to word the answer (calls use it)
     */
    public function answer(Conversation $conversation, string $question, ?callable $onDelta = null, ?string $style = null): Message
    {
        $prepared = $this->retrieve($conversation, $question, $onDelta);

        if ($prepared instanceof Message) {
            return $prepared;
        }

        ['question' => $question, 'history' => $history, 'started' => $started, 'workspace' => $workspace,
            'documentIds' => $documentIds, 'cacheable' => $cacheable, 'vector' => $vector] = $prepared;

        $messages = $this->prompts->build($question, $this->lastRetrieved, $history->all(), $style);
        $content = '';
        $result = null;
        $error = null;
        $chat = $this->providers->chat($workspace);

        try {
            $stream = $chat->provider->stream($messages);

            foreach ($stream as $delta) {
                $content .= $delta;

                if ($onDelta !== null) {
                    $onDelta($delta);
                }
            }

            $result = $stream->getReturn();
            $this->providers->markUsed($chat->key);
        } catch (InvalidProviderKeyException $exception) {
            $this->providers->markRejected($chat->key, $exception->getMessage());
            $error = $exception->getMessage();
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
            source: $chat->source,
        );

        if ($cacheable && $style === null && $error === null && $result instanceof ChatResult
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
     * In-browser AI, step 1: retrieve on the server, but leave writing the
     * answer to the asker's device (Chrome's built-in model or WebLLM).
     * Returns the saved reply when no model is needed (cache hit, nothing
     * found, search failed), otherwise the prompt for the device.
     */
    public function prepareForDevice(Conversation $conversation, string $question): Message|DevicePrompt
    {
        // Small on-device models have small context windows: fewer chunks.
        $topK = max(1, (int) config('docchat.device.top_k'));
        $prepared = $this->retrieve($conversation, $question, null, $topK);

        if ($prepared instanceof Message) {
            return $prepared;
        }

        $token = Str::random(40);
        $ttl = max(60, (int) config('docchat.device.token_ttl_seconds'));

        Cache::put(self::deviceKey($token), [
            'conversation_id' => $conversation->id,
            'question_tokens' => UsageRecorder::estimateTokens($prepared['question']),
            'started_at' => microtime(true),
            'chunks' => array_map(fn (RetrievedChunk $chunk): array => (array) $chunk, $this->lastRetrieved),
        ], $ttl);

        return new DevicePrompt(
            token: $token,
            messages: $this->prompts->build($prepared['question'], $this->lastRetrieved, $prepared['history']->all()),
            maxOutputTokens: (int) config('docchat.chat.max_output_tokens'),
            expiresIn: $ttl,
        );
    }

    /**
     * In-browser AI, step 2: save the answer the device wrote. The token is
     * single use and bound to the conversation. The text came from the
     * browser, so it is only ever shown in this conversation: it is never
     * put in the answer cache, where other people could be served it.
     */
    public function completeFromDevice(Conversation $conversation, string $token, string $answer, ?string $error = null): ?Message
    {
        $pending = Cache::pull(self::deviceKey($token));

        if (! is_array($pending) || (int) ($pending['conversation_id'] ?? 0) !== $conversation->id) {
            return null;
        }

        $chunks = array_values(array_map(fn (array $chunk): RetrievedChunk => new RetrievedChunk(...$chunk), (array) $pending['chunks']));
        $this->lastRetrieved = $chunks;
        $answer = trim(Str::limit($answer, max(500, (int) config('docchat.device.max_answer_chars')), ''));

        if ($answer === '' && $error === null) {
            $error = 'The AI on this device returned no answer. Try again, or turn off on-device answers.';
        }

        $latency = (int) round((microtime(true) - (float) $pending['started_at']) * 1000);

        $message = $conversation->messages()->create([
            'role' => MessageRole::Assistant,
            'content' => $answer,
            'citations' => $this->citations->extract($answer, $chunks),
            'prompt_tokens' => null,
            'completion_tokens' => null,
            'usage_estimated' => false,
            'ai_source' => AiSource::Device->value,
            'latency_ms' => max(0, $latency),
            'error' => $error === null ? null : Str::limit($error, 250),
        ]);

        $conversation->touch();
        $this->log($conversation, $message, 'device', (int) ($pending['question_tokens'] ?? 0));

        return $message;
    }

    public static function deviceKey(string $token): string
    {
        return 'docchat:device:'.hash('sha256', $token);
    }

    /**
     * Save the question, then answer from the cache, refuse, or fail when
     * that ends the turn (returns the saved reply). Otherwise returns what
     * the model call needs, with the chunks in $this->lastRetrieved.
     *
     * @param  (callable(string): void)|null  $onDelta
     * @return Message|array{question: string, history: Collection<int, Message>, started: int, workspace: Workspace, documentIds: list<int>|null, cacheable: bool, vector: array<int, float>}
     */
    private function retrieve(Conversation $conversation, string $question, ?callable $onDelta, ?int $topK = null): Message|array
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
            $vector = $this->providers->embeddings($workspace)->embedQuery($question);

            if ($cacheable && ($hit = $this->cache->findSimilar($workspace, $vector, $documentIds)) !== null) {
                return $this->answerFromCache($conversation, $hit, $started, $onDelta, UsageRecorder::estimateTokens($question));
            }

            $this->lastRetrieved = $this->retriever->search($workspace, $vector, $documentIds, topK: $topK);
        } catch (EmbeddingException $exception) {
            report($exception);

            if ($exception->ownKeyRejected) {
                $this->providers->markRejected($this->providers->embeddingKey($workspace), $exception->getMessage());
            }

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

        return [
            'question' => $question,
            'history' => $history,
            'started' => $started,
            'workspace' => $workspace,
            'documentIds' => $documentIds,
            'cacheable' => $cacheable,
            'vector' => $vector,
        ];
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
            'ai_source' => $message->ai_source,
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
        ?AiSource $source = null,
    ): Message {
        return $conversation->messages()->create([
            'role' => MessageRole::Assistant,
            'content' => $content,
            'citations' => $citations,
            'prompt_tokens' => $result?->promptTokens,
            'completion_tokens' => $result?->completionTokens,
            'usage_estimated' => $result->usageEstimated ?? false,
            'cache_hit' => $cacheHit,
            'ai_source' => $source?->value,
            'latency_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
            'error' => $error === null ? null : Str::limit($error, 250),
        ]);
    }
}
