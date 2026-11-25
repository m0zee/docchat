<?php

namespace App\Models\Concerns;

use App\Models\Scopes\WorkspaceScope;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For models with a workspace_id: every query is scoped to the current
 * workspace (when one is set), and new rows get it automatically.
 *
 * @property int $workspace_id
 * @property-read Workspace $workspace
 */
trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope(new WorkspaceScope);

        static::creating(function (self $model): void {
            if ($model->getAttribute('workspace_id') === null) {
                $model->setAttribute('workspace_id', $model->workspaceIdForNewRow());
            }
        });
    }

    /**
     * Where a new row goes when no workspace_id was given.
     */
    protected function workspaceIdForNewRow(): ?int
    {
        return app(CurrentWorkspace::class)->id();
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Explicit workspace filter, independent of the global scope.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeInWorkspace(Builder $query, Workspace|int $workspace): Builder
    {
        return $query->withoutGlobalScope(WorkspaceScope::class)
            ->where($this->qualifyColumn('workspace_id'), $workspace instanceof Workspace ? $workspace->id : $workspace);
    }
}
