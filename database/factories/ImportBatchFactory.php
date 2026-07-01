<?php

namespace Database\Factories;

use App\Enums\ImportBatchStatus;
use App\Models\ImportBatch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ImportBatch>
 */
class ImportBatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'filename' => fake()->unique()->lexify('listino_??????').'.xlsx',
            'hash' => hash('sha256', (string) Str::uuid()),
            'status' => ImportBatchStatus::Uploaded,
            'total_rows' => fake()->numberBetween(0, 1000),
            'processed_rows' => 0,
            'error_rows' => 0,
            'skipped_rows' => 0,
            'started_at' => null,
            'finished_at' => null,
        ];
    }
}
