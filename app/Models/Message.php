<?php

namespace App\Models;

use App\Enums\MessageRole;
use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $conversation_id
 * @property MessageRole $role
 * @property string $content
 * @property list<array<string, mixed>>|null $citations
 * @property int|null $prompt_tokens
 * @property int|null $completion_tokens
 * @property int|null $latency_ms
 * @property bool $usage_estimated
 * @property string|null $cache_hit
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Conversation $conversation
 */
#[Fillable([
    'workspace_id',
    'conversation_id',
    'role',
    'content',
    'citations',
    'prompt_tokens',
    'completion_tokens',
    'latency_ms',
    'usage_estimated',
    'cache_hit',
    'error',
])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use BelongsToWorkspace, HasFactory;

    /**
     * A message always lives in its conversation's workspace.
     */
    protected function workspaceIdForNewRow(): ?int
    {
        $id = Conversation::query()->withoutGlobalScopes()->whereKey($this->conversation_id)->value('workspace_id');

        return $id === null ? null : (int) $id;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => MessageRole::class,
            'citations' => 'array',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'latency_ms' => 'integer',
            'usage_estimated' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
