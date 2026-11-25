<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An emailed link to join a workspace. Only a SHA-256 hash of the token is
 * stored; the link itself is shown once and sent by email.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $email
 * @property WorkspaceRole $role
 * @property string $token_hash
 * @property int|null $invited_by_user_id
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Workspace $workspace
 * @property-read User|null $invitedBy
 */
#[Fillable(['workspace_id', 'email', 'role', 'token_hash', 'invited_by_user_id', 'expires_at', 'accepted_at'])]
class WorkspaceInvitation extends Model
{
    public const VALID_DAYS = 7;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => WorkspaceRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * Create an invitation and return it with the plain token for the link.
     * A new invitation replaces any open one for the same email.
     *
     * @return array{self, string}
     */
    public static function issue(Workspace $workspace, string $email, WorkspaceRole $role, ?User $invitedBy): array
    {
        $email = Str::lower(trim($email));

        self::query()->where('workspace_id', $workspace->id)
            ->where('email', $email)
            ->whereNull('accepted_at')
            ->delete();

        $token = Str::random(48);

        $invitation = self::create([
            'workspace_id' => $workspace->id,
            'email' => $email,
            'role' => $role,
            'token_hash' => self::hash($token),
            'invited_by_user_id' => $invitedBy?->id,
            'expires_at' => now()->addDays(self::VALID_DAYS),
        ]);

        return [$invitation, $token];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function findOpen(string $token): ?self
    {
        return self::query()->open()->where('token_hash', self::hash($token))->first();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    /**
     * Add $user to the workspace. Returns false if the invitation is used,
     * expired, or for another email address.
     */
    public function accept(User $user): bool
    {
        if ($this->accepted_at !== null || $this->expires_at->isPast()) {
            return false;
        }

        if (Str::lower($user->email) !== $this->email) {
            return false;
        }

        $workspace = $this->workspace;

        if (! $workspace->hasMember($user)) {
            $workspace->members()->attach($user->id, ['role' => $this->role->value]);
        }

        $this->update(['accepted_at' => now()]);
        $user->switchWorkspace($workspace);

        return true;
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }
}
