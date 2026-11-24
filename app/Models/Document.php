<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int|null $user_id
 * @property string $title
 * @property string $original_filename
 * @property string $storage_path
 * @property string $mime
 * @property int $size_bytes
 * @property int|null $page_count
 * @property DocumentStatus $status
 * @property string|null $error
 * @property string|null $embedding_model
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int $chunks_count
 * @property-read User|null $user
 * @property-read Workspace $workspace
 */
#[Fillable([
    'workspace_id',
    'user_id',
    'title',
    'original_filename',
    'storage_path',
    'mime',
    'size_bytes',
    'page_count',
    'status',
    'error',
    'embedding_model',
])]
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'page_count' => 'integer',
            'status' => DocumentStatus::class,
        ];
    }

    protected static function booted(): void
    {
        // Until callers pass a workspace (P6-3): use the creator's current one.
        static::creating(function (Document $model): void {
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
     * @return HasMany<Chunk, $this>
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class);
    }
}
