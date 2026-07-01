<?php

namespace Tests\Feature;

use App\Enums\ImportBatchStatus;
use App\Models\ImportBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-006 acceptance criteria — the catalog:import command:
 *  - Accepts the file path as an argument and creates a batch on success.
 *  - Warns and creates no new batch when the file hash already matches a
 *    completed batch.
 *  - Fails when the path does not point to a readable file.
 *
 * Runs against in-memory SQLite via RequiresDatabase.
 */
class CatalogImportCommandTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    private function makeFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'us006cmd_').'.xlsx';
        file_put_contents($path, $contents);

        return $path;
    }

    public function test_command_creates_batch_from_valid_file(): void
    {
        $path = $this->makeFile('contenuto-catalogo');

        $this->artisan('catalog:import', ['path' => $path])
            ->assertSuccessful();

        $this->assertDatabaseCount('import_batches', 1);
        $this->assertDatabaseHas('import_batches', [
            'filename' => basename($path),
            'hash' => md5_file($path),
            'status' => ImportBatchStatus::Uploaded->value,
        ]);

        @unlink($path);
    }

    public function test_command_warns_on_duplicate_completed_file(): void
    {
        $path = $this->makeFile('contenuto-gia-importato');

        ImportBatch::factory()->create([
            'hash' => md5_file($path),
            'status' => ImportBatchStatus::Completed,
        ]);

        $this->artisan('catalog:import', ['path' => $path])
            ->assertSuccessful();

        // Only the pre-existing completed batch remains; no new one created.
        $this->assertDatabaseCount('import_batches', 1);

        @unlink($path);
    }

    public function test_command_fails_on_missing_file(): void
    {
        $this->artisan('catalog:import', ['path' => '/path/che/non/esiste.xlsx'])
            ->assertFailed();

        $this->assertDatabaseCount('import_batches', 0);
    }
}
