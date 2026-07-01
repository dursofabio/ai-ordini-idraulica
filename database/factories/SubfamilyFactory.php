<?php

namespace Database\Factories;

use App\Models\Family;
use App\Models\Subfamily;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Subfamily>
 */
class SubfamilyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('####'),
            'aliases' => [
                fake()->word(),
                fake()->word(),
            ],
            'family_id' => Family::factory(),
        ];
    }
}
