<?php

namespace Tests\Feature;

use App\Enums\ImportBatchStatus;
use App\Jobs\ImportXlsxJob;
use App\Models\ImportBatch;
use App\Services\ImportBatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-027 acceptance criteria — the catalog:import-if-changed command:
 *  - Starts a new import batch when the watched file's hash has never been
 *    completed before, logging the batch it started.
 *  - Is a silent no-op when the watched file does not exist.
 *  - Is a silent no-op when the file's hash matches an already-completed
 *    batch (no log noise on the steady state — see the spec's AC).
 *  - Logs an error and fails when startImport() throws unexpectedly.
 *
 * Runs against in-memory SQLite via RequiresDatabase.
 */
class CatalogImportIfChangedCommandTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    private function makeFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'us027cmd_').'.xlsx';
        file_put_contents($path, $contents);

        return $path;
    }

    public function test_command_is_silent_success_when_watch_path_missing(): void
    {
        config(['catalog.watch_path' => '/path/che/non/esiste.xlsx']);

        Log::shouldReceive('info')->never();
        Log::shouldReceive('error')->never();

        $this->artisan('catalog:import-if-changed')->assertSuccessful();

        $this->assertDatabaseCount('import_batches', 0);
    }

    public function test_command_starts_batch_and_logs_on_new_file(): void
    {
        $path = $this->makeFile('contenuto-nuovo-catalogo');
        config(['catalog.watch_path' => $path]);

        Log::shouldReceive('info')
            ->once()
            ->with('catalog:import-if-changed ha avviato un nuovo batch', \Mockery::on(
                static fn (array $context): bool => $context['filename'] === basename($path)
            ));

        $this->artisan('catalog:import-if-changed')->assertSuccessful();

        $this->assertDatabaseCount('import_batches', 1);
        $this->assertDatabaseHas('import_batches', [
            'filename' => basename($path),
            'hash' => md5_file($path),
            'status' => ImportBatchStatus::Uploaded->value,
        ]);

        Queue::assertPushed(ImportXlsxJob::class);

        @unlink($path);
    }

    public function test_command_is_silent_no_op_when_hash_unchanged(): void
    {
        $path = $this->makeFile('contenuto-gia-importato');
        config(['catalog.watch_path' => $path]);

        ImportBatch::factory()->create([
            'hash' => md5_file($path),
            'status' => ImportBatchStatus::Completed,
        ]);

        Log::shouldReceive('info')->never();
        Log::shouldReceive('error')->never();

        $this->artisan('catalog:import-if-changed')->assertSuccessful();

        // Only the pre-existing completed batch remains; no new one created.
        $this->assertDatabaseCount('import_batches', 1);
        Queue::assertNotPushed(ImportXlsxJob::class);

        @unlink($path);
    }

    public function test_command_logs_error_and_fails_on_unexpected_exception(): void
    {
        // startImport() only ever throws InvalidArgumentException (missing
        // /unreadable file) or DuplicateImportException (unchanged hash),
        // both handled explicitly above. To exercise the catch-all
        // Throwable branch without relying on filesystem permission bits
        // (unreliable under root, e.g. inside Docker/Sail containers), the
        // service itself is swapped for a fake that throws a generic
        // exception.
        $path = $this->makeFile('contenuto-qualunque');
        config(['catalog.watch_path' => $path]);

        $this->mock(ImportBatchService::class, function ($mock): void {
            $mock->shouldReceive('startImport')->once()->andThrow(new RuntimeException('boom'));
        });

        Log::shouldReceive('error')
            ->once()
            ->with('catalog:import-if-changed fallito', \Mockery::on(
                static fn (array $context): bool => $context['path'] === $path
            ));

        $this->artisan('catalog:import-if-changed')->assertFailed();

        $this->assertDatabaseCount('import_batches', 0);

        @unlink($path);
    }
}
