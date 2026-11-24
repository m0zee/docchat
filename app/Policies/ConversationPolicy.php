<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;
use App\Models\Workspace;

/**
 * Web conversations are private to the member who started them, and only
 * while they still belong to the conversation's workspace.
 */
class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        return $this->owns($user, $conversation);
    }

    public function update(User $user, Conversation $conversation): bool
    {
        return $this->owns($user, $conversation);
    }

    public function delete(User $user, Conversation $conversation): bool
    {
        return $this->owns($user, $conversation);
    }

    private function owns(User $user, Conversation $conversation): bool
    {
        if ($conversation->user_id !== $user->id) {
            return false;
        }

        $workspace = Workspace::query()->find($conversation->workspace_id);

        return $workspace !== null && $workspace->hasMember($user);
    }
}
