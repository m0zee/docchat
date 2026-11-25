<?php

namespace App\Services\Usage;

use App\Enums\AiSource;
use App\Enums\ConversationChannel;
use App\Enums\DocumentSource;
use App\Enums\UsageType;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Message;
use App\Models\UsageEvent;
use App\Support\CurrentWorkspace;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Writes usage_events. Never lets a logging failure break an answer.
 */
class UsageRecorder
{
    public function __construct(private CurrentWorkspace $current) {}

    public function question(Conversation $conversation, Message $answer, int $embeddingTokens, ?string $cacheHit = null, int $tokensSaved = 0): void
    {
        $this->write([
            'workspace_id' => $conversation->workspace_id,
            'api_key_id' => $this->current->apiKey()?->id,
            'channel' => $conversation->channel,
            'type' => UsageType::Question,
            'prompt_tokens' => (int) $answer->prompt_tokens,
            'completion_tokens' => (int) $answer->completion_tokens,
            'embedding_tokens' => $embeddingTokens,
            'latency_ms' => $answer->latency_ms,
            'cache_hit' => $cacheHit,
            'tokens_saved' => $tokensSaved,
            'ai_source' => $answer->ai_source,
        ]);
    }

    public function summary(Conversation $conversation, int $promptTokens, int $completionTokens, int $latencyMs, ?string $source = null): void
    {
        $this->write([
            'workspace_id' => $conversation->workspace_id,
            'api_key_id' => null,
            'channel' => $conversation->channel,
            'type' => UsageType::Summary,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'latency_ms' => $latencyMs,
            'ai_source' => $source,
        ]);
    }

    public function ingest(Document $document, int $embeddingTokens, int $latencyMs, ?AiSource $source = null): void
    {
        $this->write([
            'workspace_id' => $document->workspace_id,
            'api_key_id' => null,
            'channel' => self::channelFor($document->source),
            'type' => UsageType::Ingest,
            'embedding_tokens' => $embeddingTokens,
            'latency_ms' => $latencyMs,
            'ai_source' => $source?->value,
        ]);
    }

    public static function channelFor(DocumentSource $source): ConversationChannel
    {
        return match ($source) {
            DocumentSource::Upload => ConversationChannel::Web,
            DocumentSource::Telegram => ConversationChannel::Telegram,
            DocumentSource::WhatsApp => ConversationChannel::WhatsApp,
            DocumentSource::GoogleDrive, DocumentSource::Api => ConversationChannel::Api,
        };
    }

    public static function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / max(1, (int) config('docchat.ingestion.chars_per_token')));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function write(array $attributes): void
    {
        try {
            // A savepoint, so a failed insert can't abort a surrounding transaction.
            DB::transaction(fn () => UsageEvent::query()->create($attributes));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
