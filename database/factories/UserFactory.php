<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use WeakMap;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * @var WeakMap<User, bool>|null
     */
    private static ?WeakMap $skipWorkspace = null;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            /* @chisel-2fa */
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            /* @end-chisel-2fa */
        ];
    }

    /**
     * Every user gets a personal workspace, as on registration.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            if (isset(self::skipWorkspace()[$user])) {
                return;
            }

            Workspace::createFor($user);
        });
    }

    public function withoutWorkspace(): static
    {
        return $this->afterMaking(function (User $user): void {
            self::skipWorkspace()[$user] = true;
        });
    }

    /**
     * Users made by withoutWorkspace(). A WeakMap, so it never becomes a column.
     *
     * @return WeakMap<User, bool>
     */
    private static function skipWorkspace(): WeakMap
    {
        return self::$skipWorkspace ??= new WeakMap;
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /* @chisel-2fa */
    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
    /* @end-chisel-2fa */
}
