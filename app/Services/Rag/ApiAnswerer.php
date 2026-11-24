<?php

namespace App\Services\Rag;

use App\Enums\ConversationChannel;
use App\Enums\DocumentStatus;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\Formatting\MessageFormat;
use App\Services\Formatting\MessageFormatter;
use Illuminate\Validation\ValidationException;

/**
 * The API, widget and bot entry point to AnswerService: finds the channel
 * conversation, applies the document scope, and shapes the reply.
 */
class ApiAnswerer
{
    public function __construct(
        private AnswerService $answers,
        private MessageFormatter $formatter,
    ) {}

    /**
     * @param  list<int>|null  $documentIds
     */
    public function conversation(Workspace $workspace, ConversationChannel $channel, ?string $externalId, ?array $documentIds): Conversation
    {
        $conversation = Conversation::forChannel($workspace, $channel, $externalId);

        if ($documentIds !== null) {
            // Only ready documents of this workspace, whatever ids are sent.
            $allowed = Document::query()->inWorkspace($workspace)
                ->where('status', DocumentStatus::Ready)
                ->whereIn('id', $documentIds)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            if ($allowed === []) {
                throw ValidationException::withMessages([
                    'document_ids' => 'None of these ids is a ready document in this workspace.',
                ]);
            }

            $conversation->documents()->sync($allowed);
        }

        return $conversation;
    }

    /**
     * @param  (callable(string): void)|null  $onDelta
     */
    public function answer(Conversation $conversation, string $question, ?callable $onDelta = null): Message
    {
        return $this->answers->answer($conversation, $question, $onDelta);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(Conversation $conversation, Message $message, ?MessageFormat $format): array
    {
        $citations = array_map(fn (array $c): array => [
            'n' => (int) ($c['n'] ?? 0),
            'document_id' => (int) ($c['document_id'] ?? 0),
            'title' => (string) ($c['title'] ?? ''),
            'page' => (int) ($c['page'] ?? 0),
            'snippet' => (string) ($c['snippet'] ?? ''),
        ], $message->citations ?? []);

        $payload = [
            'answer' => $message->content,
            'citations' => $citations,
            'refused' => $message->content === AnswerService::NOT_FOUND,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'usage' => [
                'prompt_tokens' => (int) $message->prompt_tokens,
                'completion_tokens' => (int) $message->completion_tokens,
                'latency_ms' => (int) $message->latency_ms,
                'cache_hit' => $message->cache_hit,
            ],
        ];

        if ($format !== null) {
            $payload['formatted'] = $this->formatter->format($message->content, $citations, $format);
        }

        return $payload;
    }
}
