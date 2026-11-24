<?php

namespace App\Models;

use Database\Factories\ChunkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\AsVector;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $document_id
 * @property int $page_number
 * @property int $chunk_index
 * @property string $content
 * @property int $token_estimate
 * @property array<int, float> $embedding
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Document $document
 */
#[Fillable([
    'document_id',
    'page_number',
    'chunk_index',
    'content',
    'token_estimate',
    'embedding',
])]
class Chunk extends Model
{
    /** @use HasFactory<ChunkFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'page_number' => 'integer',
            'chunk_index' => 'integer',
            'token_estimate' => 'integer',
            'embedding' => AsVector::class,
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
