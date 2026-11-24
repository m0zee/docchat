<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $url
 * @property list<string> $events
 * @property string $secret
 * @property bool $active
 * @property int|null $last_status
 * @property string|null $last_error
 * @property Carbon|null $last_delivered_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Workspace $workspace
 */
#[Fillable(['workspace_id', 'url', 'events', 'secret', 'active', 'last_status', 'last_error', 'last_delivered_at'])]
#[Hidden(['secret'])]
class Webhook extends Model
{
    public const EVENTS = [
        'document.ready' => 'A document finished processing',
        'document.failed' => 'A document could not be processed',
        'eval.completed' => 'An eval run finished',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'events' => 'array',
            'secret' => 'encrypted',
            'active' => 'boolean',
            'last_status' => 'integer',
            'last_delivered_at' => 'datetime',
        ];
    }

    public static function newSecret(): string
    {
        return 'whsec_'.Str::random(40);
    }

    public function wants(string $event): bool
    {
        return $this->active && in_array($event, $this->events, true);
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
