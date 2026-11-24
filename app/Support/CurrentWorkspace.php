<?php

namespace App\Support;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * The workspace this request works in. The API sets it from the API key;
 * on the web it is the signed-in user's current workspace. Queue jobs and
 * console commands have none, so they pass a workspace explicitly.
 */
class CurrentWorkspace
{
    private ?Workspace $workspace = null;

    /**
     * @var array{string, Workspace|null}|null
     */
    private ?array $fromUser = null;

    public function set(?Workspace $workspace): void
    {
        $this->workspace = $workspace;
    }

    public function get(): ?Workspace
    {
        if ($this->workspace !== null) {
            return $this->workspace;
        }

        $user = Auth::guard('web')->user();

        if (! $user instanceof User) {
            return null;
        }

        // Cached per user and choice, so switching takes effect at once.
        $key = $user->id.':'.($user->current_workspace_id ?? '-');

        if ($this->fromUser === null || $this->fromUser[0] !== $key) {
            $workspace = $user->currentWorkspace() ?? Workspace::createFor($user);
            $this->fromUser = [$user->id.':'.($user->current_workspace_id ?? '-'), $workspace];
        }

        return $this->fromUser[1];
    }

    public function id(): ?int
    {
        return $this->get()?->id;
    }

    public function require(): Workspace
    {
        return $this->get() ?? throw new RuntimeException('No current workspace.');
    }

    public function forget(): void
    {
        $this->workspace = null;
        $this->fromUser = null;
    }
}
