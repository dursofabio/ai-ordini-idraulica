<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Family;
use App\Models\ProductBase;
use App\Models\Subfamily;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductBase>
 */
class ProductBaseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = Str::title(fake()->unique()->words(3, true));

        return [
            'title' => $title,
            'grouping_key' => Str::slug($title).'-'.fake()->unique()->numerify('######'),
            'brand_id' => Brand::factory(),
            'family_id' => Family::factory(),
            'subfamily_id' => Subfamily::factory(),
        ];
    }
}
