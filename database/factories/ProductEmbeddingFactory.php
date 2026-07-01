<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductEmbedding;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductEmbedding>
 */
class ProductEmbeddingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $dimensions = 1024;

        // Serialisable, SQLite-safe representation of a vector. The real pgsql
        // `vector` type accepts this bracketed float list literal as well, so
        // this stays compatible without touching the column type in tests.
        $values = array_map(
            static fn (): float => round(fake()->randomFloat(6, -1, 1), 6),
            range(1, $dimensions),
        );

        return [
            'product_id' => Product::factory(),
            'content' => fake()->sentence(8),
            // Randomised so two default embeddings for the same product don't
            // collide on the unique (product_id, model) index.
            'model' => fake()->unique()->lexify('text-embedding-??????'),
            'dimensions' => $dimensions,
            'embedding' => '['.implode(',', $values).']',
        ];
    }
}
