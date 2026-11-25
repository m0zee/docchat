<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property array<string, mixed>|null $settings
 * @property int $corpus_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'slug', 'settings'])]
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        // Rows cascade in the database; the uploaded files need deleting here.
        static::deleted(function (Workspace $workspace): void {
            Storage::disk('local')->deleteDirectory('documents/'.$workspace->id);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    /**
     * Create a workspace with $owner as its owner, and make it the owner's
     * current workspace if they don't have one yet.
     */
    public static function createFor(User $owner, ?string $name = null): self
    {
        return DB::transaction(function () use ($owner, $name): self {
            $name ??= Str::before(trim($owner->name), ' ')."'s workspace";
            $name = Str::limit(trim($name) ?: 'My workspace', 80, '');

            $workspace = self::create([
                'name' => $name,
                'slug' => self::uniqueSlug($name),
            ]);

            $workspace->members()->attach($owner->id, ['role' => WorkspaceRole::Owner->value]);

            if ($owner->current_workspace_id === null) {
                $owner->forceFill(['current_workspace_id' => $workspace->id])->save();
            }

            return $workspace;
        });
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::limit(Str::slug($name) ?: 'workspace', 80, '');

        do {
            $slug = $base.'-'.Str::lower(Str::random(6));
        } while (self::query()->where('slug', $slug)->exists());

        return $slug;
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')->withPivot('role')->withTimestamps();
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * @return HasMany<Conversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function roleOf(User $user): ?WorkspaceRole
    {
        $role = $this->members()->whereKey($user->id)->value('role');

        return is_string($role) ? WorkspaceRole::tryFrom($role) : null;
    }

    public function hasMember(User $user): bool
    {
        return $this->roleOf($user) !== null;
    }

    public function isOwner(User $user): bool
    {
        return $this->roleOf($user) === WorkspaceRole::Owner;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }
}
