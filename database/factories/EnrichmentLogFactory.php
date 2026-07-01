<?php

namespace Database\Factories;

use App\Models\EnrichmentLog;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnrichmentLog>
 */
class EnrichmentLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'step' => fake()->randomElement(['deterministic', 'ai', 'embedding']),
            'input' => ['description' => fake()->sentence(6)],
            'output' => ['brand' => fake()->company(), 'family' => fake()->word()],
            'confidence' => fake()->numberBetween(0, 100),
            'model' => fake()->randomElement(['gpt-4o-mini', 'claude-3-5-sonnet', null]),
            'tokens_in' => fake()->numberBetween(0, 4000),
            'tokens_out' => fake()->numberBetween(0, 2000),
        ];
    }
}
