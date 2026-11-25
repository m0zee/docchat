<?php

namespace App\Models;

use App\Enums\EvalRunStatus;
use App\Evals\EvalCase;
use App\Evals\EvalFile;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One eval run started through the API, worked through one case per job.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $api_key_id
 * @property EvalRunStatus $status
 * @property list<array<string, mixed>> $cases
 * @property list<int>|null $document_ids
 * @property float $delay_seconds
 * @property list<array<string, mixed>> $results
 * @property array<string, mixed>|null $summary
 * @property string|null $callback_url
 * @property string|null $callback_secret
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Workspace $workspace
 */
#[Fillable([
    'workspace_id', 'api_key_id', 'status', 'cases', 'document_ids', 'delay_seconds', 'results',
    'summary', 'callback_url', 'callback_secret', 'error', 'started_at', 'finished_at',
])]
#[Hidden(['callback_secret'])]
class EvalRun extends Model
{
    use BelongsToWorkspace;

    public const MAX_CASES = 50;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'queued',
        'results' => '[]',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => EvalRunStatus::class,
            'cases' => 'array',
            'document_ids' => 'array',
            'delay_seconds' => 'float',
            'results' => 'array',
            'summary' => 'array',
            'callback_secret' => 'encrypted',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return list<EvalCase>
     */
    public function evalCases(): array
    {
        return EvalFile::fromArray($this->cases);
    }

    /**
     * The shape sent in the API, the callback and the eval.completed webhook.
     *
     * @return array<string, mixed>
     */
    public function payload(bool $withCases = true): array
    {
        return array_filter([
            'id' => $this->id,
            'status' => $this->status->value,
            'cases' => count($this->cases),
            'completed_cases' => count($this->results),
            'summary' => $this->summary,
            'error' => $this->error,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'results' => $withCases ? $this->results : null,
        ], fn (mixed $value, string $key): bool => $value !== null || in_array($key, ['summary', 'error'], true), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
