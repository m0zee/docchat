<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;

/**
 * Members use a workspace; owners manage it (name, API keys, webhooks,
 * usage, widget and channel settings).
 */
class WorkspacePolicy
{
    public function view(User $user, Workspace $workspace): bool
    {
        return $workspace->hasMember($user);
    }

    public function update(User $user, Workspace $workspace): bool
    {
        return $workspace->isOwner($user);
    }

    public function manage(User $user, Workspace $workspace): bool
    {
        return $workspace->isOwner($user);
    }
}
