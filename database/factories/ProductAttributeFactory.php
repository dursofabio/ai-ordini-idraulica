<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductAttribute;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductAttribute>
 */
class ProductAttributeFactory extends Factory
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
            'key' => fake()->randomElement(['potenza_kw', 'capacita_litri', 'materiale']),
            'value_num' => fake()->randomFloat(3, 1, 100),
            'value_text' => null,
            'unit' => fake()->randomElement(['kW', 'L', 'mm']),
            'source' => fake()->randomElement(['regex', 'ai']),
            'confidence' => null,
        ];
    }
}
