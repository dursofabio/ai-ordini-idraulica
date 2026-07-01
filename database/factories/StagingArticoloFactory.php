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
        $descrizione = fake()->sentence(4);
        $costo = fake()->randomFloat(2, 1, 5000);
        $giacenza = fake()->randomFloat(3, 0, 500);

        return [
            'import_batch_id' => ImportBatch::factory(),
            'raw_row' => [
                'codice_articolo' => $codice,
                'descrizione' => $descrizione,
                'costo_un_1' => $costo,
                'giac_att_1' => $giacenza,
            ],
            'row_number' => fake()->numberBetween(1, 1000),
            'codice_articolo' => $codice,
            'descrizione' => $descrizione,
            'costo' => $costo,
            'giacenza' => $giacenza,
            'status' => 'pending',
            'error' => null,
        ];
    }
}
