<?php

namespace App\Services\Rag;

use App\Contracts\ChatProvider;
use App\Enums\MessageRole;
use App\Exceptions\ChatProviderException;
use App\Exceptions\ChatRateLimitedException;
use App\Exceptions\EmbeddingException;
use App\Models\Conversation;
use App\Models\Message;
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

        try {
            $this->lastRetrieved = $this->retriever->retrieve(
                $conversation->workspace,
                $question,
                $conversation->scopedDocumentIds(),
            );
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

        $message = $this->saveAssistant(
            $conversation,
            $result instanceof ChatResult ? $result->content : $content,
            $started,
            citations: $this->citations->extract($content, $this->lastRetrieved),
            result: $result,
            error: $error,
        );

        $this->log($conversation, $message, $result?->model, UsageRecorder::estimateTokens($question));

        return $message;
    }

    private function log(Conversation $conversation, Message $message, ?string $model, int $embeddingTokens): void
    {
        $this->usage->question($conversation, $message, $embeddingTokens);

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
    ): Message {
        return $conversation->messages()->create([
            'role' => MessageRole::Assistant,
            'content' => $content,
            'citations' => $citations,
            'prompt_tokens' => $result?->promptTokens,
            'completion_tokens' => $result?->completionTokens,
            'usage_estimated' => $result->usageEstimated ?? false,
            'latency_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
            'error' => $error === null ? null : Str::limit($error, 250),
        ]);
    }
}
