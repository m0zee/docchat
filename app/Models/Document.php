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
 * @property int $user_id
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
 * @property-read User $user
 */
#[Fillable([
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class);
    }
}
