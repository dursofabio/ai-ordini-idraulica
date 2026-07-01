<?php

namespace Database\Factories;

use App\Models\ImportBatch;
use App\Models\StagingArticolo;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StagingArticolo>
 */
class StagingArticoloFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $codice = Str::upper(fake()->bothify('???-#####'));

        return [
            'import_batch_id' => ImportBatch::factory(),
            'payload' => [
                'codice_articolo' => $codice,
                'descrizione' => fake()->sentence(4),
                'costo' => fake()->randomFloat(2, 1, 5000),
            ],
            'row_number' => fake()->numberBetween(1, 1000),
            'codice_articolo' => $codice,
            'status' => 'pending',
            'error' => null,
        ];
    }
}
