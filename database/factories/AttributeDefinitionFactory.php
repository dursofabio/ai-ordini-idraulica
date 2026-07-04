<?php

namespace Database\Factories;

use App\Models\AttributeDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttributeDefinition>
 */
class AttributeDefinitionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->word(),
            'data_type' => 'numeric',
            'canonical_unit' => 'kW',
            'accepted_units' => ['kW' => 1, 'W' => 0.001],
            'description' => fake()->sentence(),
        ];
    }

    /**
     * A numeric attribute definition with a canonical unit and accepted
     * unit conversion factors.
     */
    public function numeric(): static
    {
        return $this->state(fn (array $attributes): array => [
            'data_type' => 'numeric',
            'canonical_unit' => 'kW',
            'accepted_units' => ['kW' => 1, 'W' => 0.001],
        ]);
    }

    /**
     * A textual attribute definition, with no canonical unit or accepted
     * units — normalization for text attributes is a plain pass-through.
     */
    public function text(): static
    {
        return $this->state(fn (array $attributes): array => [
            'data_type' => 'text',
            'canonical_unit' => null,
            'accepted_units' => null,
        ]);
    }
}
