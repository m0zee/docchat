<?php

namespace App\Models;

use App\Enums\AiProvider;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A workspace's own Groq or Gemini API key. The key is encrypted at rest
 * with APP_KEY and never shown again; only the last four characters are.
 *
 * @property int $id
 * @property int $workspace_id
 * @property AiProvider $provider
 * @property string $api_key
 * @property string $key_hint
 * @property string|null $model
 * @property Carbon|null $verified_at
 * @property Carbon|null $last_used_at
 * @property string|null $last_error
 * @property Carbon|null $last_error_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Workspace $workspace
 */
#[Fillable([
    'workspace_id',
    'provider',
    'api_key',
    'key_hint',
    'model',
    'verified_at',
    'last_used_at',
    'last_error',
    'last_error_at',
])]
#[Hidden(['api_key'])]
class WorkspaceAiKey extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => AiProvider::class,
            'api_key' => 'encrypted',
            'verified_at' => 'datetime',
            'last_used_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public static function hintFor(string $key): string
    {
        return '…'.Str::substr(trim($key), -4);
    }
}
