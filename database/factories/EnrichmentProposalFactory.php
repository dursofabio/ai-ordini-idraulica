<?php

namespace Database\Factories;

use App\Models\EnrichmentProposal;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnrichmentProposal>
 */
class EnrichmentProposalFactory extends Factory
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
            'field' => fake()->randomElement(['brand', 'family', 'subfamily', 'attribute']),
            'attribute_key' => null,
            'value_id' => null,
            'value_num' => null,
            'value_text' => null,
            'unit' => null,
            'origin' => fake()->randomElement(['file', 'regex', 'dictionary', 'propagated', 'ai']),
            'confidence' => fake()->numberBetween(0, 100),
            'status' => fake()->randomElement(['applied', 'pending']),
        ];
    }
}
