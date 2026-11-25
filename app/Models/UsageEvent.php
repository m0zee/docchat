<?php

namespace App\Models;

use App\Enums\ConversationChannel;
use App\Enums\UsageType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int|null $api_key_id
 * @property ConversationChannel $channel
 * @property UsageType $type
 * @property int $prompt_tokens
 * @property int $completion_tokens
 * @property int $embedding_tokens
 * @property int|null $latency_ms
 * @property string|null $cache_hit exact, semantic, or null for no cache hit
 * @property int $tokens_saved
 * @property string|null $ai_source platform, workspace or device; null when no model ran
 * @property Carbon $created_at
 */
#[Fillable([
    'workspace_id',
    'api_key_id',
    'channel',
    'type',
    'prompt_tokens',
    'completion_tokens',
    'embedding_tokens',
    'latency_ms',
    'cache_hit',
    'tokens_saved',
    'ai_source',
    'created_at',
])]
class UsageEvent extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => ConversationChannel::class,
            'type' => UsageType::class,
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'embedding_tokens' => 'integer',
            'latency_ms' => 'integer',
            'tokens_saved' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
