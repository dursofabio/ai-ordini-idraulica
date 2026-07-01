<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductBase;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $description = fake()->unique()->sentence(4);

        return [
            'codice_articolo' => Str::upper(fake()->unique()->bothify('???-#####')),
            'description_raw' => $description,
            'descrizione_marca' => fake()->company(),
            'costo' => fake()->randomFloat(2, 1, 5000),
            'giacenza' => fake()->numberBetween(0, 500),
            'is_active' => true,
            'enrichment_status' => 'pending',
            'product_base_id' => ProductBase::factory(),
            'grouping_key' => Str::slug(Str::words($description, 3, '')).'-'.fake()->unique()->numerify('######'),
        ];
    }
}
