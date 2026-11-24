<?php

namespace Database\Factories;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => Str::limit($name, 80, ''),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'settings' => null,
        ];
    }

    public function ownedBy(User $user): static
    {
        return $this->hasAttached($user, ['role' => WorkspaceRole::Owner->value], 'members');
    }

    public function withMember(User $user): static
    {
        return $this->hasAttached($user, ['role' => WorkspaceRole::Member->value], 'members');
    }
}
