<?php

namespace Database\Factories;

use App\Models\Chunk;
use App\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Chunk>
 */
class ChunkFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $dimensions = max(1, (int) config('docchat.embedding.dimensions'));

        return [
            'document_id' => Document::factory(),
            'page_number' => 1,
            'chunk_index' => 0,
            'content' => fake()->paragraph(),
            'token_estimate' => fake()->numberBetween(50, 800),
            'embedding' => array_fill(0, $dimensions, 0.0),
        ];
    }
}
