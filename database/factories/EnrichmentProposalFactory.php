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
            'data_type' => null,
            'origin' => fake()->randomElement(['file', 'regex', 'dictionary', 'propagated', 'ai']),
            'confidence' => fake()->numberBetween(0, 100),
            'status' => fake()->randomElement(['applied', 'pending']),
        ];
    }

    /**
     * US-044: a proposal for a new attribute-definition registry entry, with
     * the columns reused by this proposal type — `attribute_key` (the
     * proposed key), `data_type`, `unit` (the proposed canonical unit) —
     * populated, and `value_id`/`value_num` left null (`value_text` carries
     * the proposed description, initially null until a reviewer fills it in).
     */
    public function attributeDefinition(): static
    {
        return $this->state(fn (array $attributes): array => [
            'field' => 'attribute_definition',
            'attribute_key' => fake()->unique()->word(),
            'value_id' => null,
            'value_num' => null,
            'value_text' => null,
            'unit' => null,
            'data_type' => 'numeric',
            'origin' => 'ai',
            'status' => 'pending',
        ]);
    }
}
