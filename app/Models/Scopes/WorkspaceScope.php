<?php

namespace App\Models\Scopes;

use App\Support\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Limits queries to the current workspace when there is one.
 *
 * @implements Scope<Model>
 */
class WorkspaceScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $workspaceId = app(CurrentWorkspace::class)->id();

        if ($workspaceId !== null) {
            $builder->where($model->qualifyColumn('workspace_id'), $workspaceId);
        }
    }
}
