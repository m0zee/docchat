<?php

namespace App\Models;

use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int|null $user_id
 * @property string|null $title
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read Workspace $workspace
 */
#[Fillable(['workspace_id', 'user_id', 'title'])]
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        // Until callers pass a workspace (P6-3): use the creator's current one.
        static::creating(function (Conversation $model): void {
            if ($model->getAttribute('workspace_id') === null && $model->user_id !== null) {
                $model->workspace_id = User::query()->whereKey($model->user_id)->first()?->currentWorkspace()?->id;
            }
        });
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
