<?php

namespace Database\Factories;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $filename = fake()->slug().'.txt';

        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(3),
            'original_filename' => $filename,
            'storage_path' => 'documents/'.fake()->uuid().'.txt',
            'mime' => 'text/plain',
            'size_bytes' => fake()->numberBetween(200, 100_000),
            'page_count' => fake()->numberBetween(1, 5),
            'status' => DocumentStatus::Pending,
            'error' => null,
            'embedding_model' => null,
        ];
    }

    public function processing(): static
    {
        return $this->state(fn () => [
            'status' => DocumentStatus::Processing,
        ]);
    }

    public function ready(): static
    {
        return $this->state(fn () => [
            'status' => DocumentStatus::Ready,
            'embedding_model' => config('docchat.embedding.model'),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => DocumentStatus::Failed,
            'error' => 'The document could not be processed.',
        ]);
    }
}
