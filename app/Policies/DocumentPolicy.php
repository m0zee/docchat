<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;

/**
 * Documents are shared by everyone in their workspace.
 */
class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Document $document): bool
    {
        return $this->isMember($user, $document);
    }

    public function create(User $user, Workspace $workspace): bool
    {
        return $workspace->hasMember($user);
    }

    public function delete(User $user, Document $document): bool
    {
        return $this->isMember($user, $document);
    }

    private function isMember(User $user, Document $document): bool
    {
        $workspace = Workspace::query()->find($document->workspace_id);

        return $workspace !== null && $workspace->hasMember($user);
    }
}
