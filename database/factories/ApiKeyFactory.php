<?php

namespace Database\Factories;

use App\Enums\ApiKeyType;
use App\Models\ApiKey;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Use ApiKey::issue() in tests that need the plain key.
 *
 * @extends Factory<ApiKey>
 */
class ApiKeyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $plain = 'dc_live_'.Str::random(40);

        return [
            'workspace_id' => Workspace::factory(),
            'type' => ApiKeyType::Live,
            'name' => fake()->words(2, true),
            'prefix' => substr($plain, 0, 14),
            'secret_hash' => ApiKey::hash($plain),
            'abilities' => ['ask', 'documents:read', 'documents:write'],
        ];
    }
}
