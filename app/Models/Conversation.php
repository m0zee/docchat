<?php

namespace App\Models;

use App\Enums\ConversationChannel;
use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * @property int $id
 * @property int|null $user_id
 * @property ConversationChannel $channel
 * @property string|null $external_id
 * @property string|null $title
 * @property string|null $summary cached AI summary
 * @property int|null $summary_message_id the last message the summary covers
 * @property Carbon|null $summarized_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read Call|null $call
 */
#[Fillable(['workspace_id', 'user_id', 'channel', 'external_id', 'title'])]
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use BelongsToWorkspace, HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'channel' => 'web',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => ConversationChannel::class,
            'summary_message_id' => 'integer',
            'summarized_at' => 'datetime',
        ];
    }

    /**
     * The conversation for a channel's external id (a Telegram chat, a widget
     * visitor), created on first use. Without an external id a new one is
     * always started.
     */
    public static function forChannel(
        Workspace $workspace,
        ConversationChannel $channel,
        ?string $externalId = null,
        ?User $user = null,
    ): self {
        $externalId = $externalId === null || trim($externalId) === '' ? null : mb_substr(trim($externalId), 0, 255);

        $attributes = [
            'workspace_id' => $workspace->id,
            'channel' => $channel,
            'user_id' => $user?->id,
        ];

        if ($externalId === null) {
            return self::query()->create($attributes);
        }

        $find = fn (): ?self => self::query()->inWorkspace($workspace)
            ->where('channel', $channel->value)
            ->where('external_id', $externalId)
            ->first();

        try {
            return $find() ?? self::query()->create($attributes + ['external_id' => $externalId]);
        } catch (UniqueConstraintViolationException) {
            return $find() ?? throw new RuntimeException('Conversation could not be created.');
        }
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The call this conversation is the transcript of, if any.
     *
     * @return HasOne<Call, $this>
     */
    public function call(): HasOne
    {
        return $this->hasOne(Call::class);
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('id');
    }

    /**
     * Documents this conversation is limited to. Empty means all of the user's documents.
     *
     * @return BelongsToMany<Document, $this>
     */
    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class);
    }

    /**
     * @return list<int>|null null when the conversation searches all documents
     */
    public function scopedDocumentIds(): ?array
    {
        $ids = $this->documents()->pluck('documents.id')->map(fn ($id): int => (int) $id)->all();

        return $ids === [] ? null : array_values($ids);
    }
}
