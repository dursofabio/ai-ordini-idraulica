<?php

namespace Tests\Feature;

use App\Enums\ImportBatchStatus;
use App\Exceptions\DuplicateImportException;
use App\Models\ImportBatch;
use App\Services\ImportBatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-006 acceptance criteria — batch creation and hash deduplication:
 *  - startImport() creates an import_batches record with filename, MD5 hash and
 *    status `uploaded`.
 *  - A second import of the same file whose hash matches a completed batch is
 *    blocked and creates no new record.
 *  - A previous failed batch does not block re-importing the same file.
 *
 * Runs against in-memory SQLite via RequiresDatabase.
 */
class ImportBatchDeduplicationTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    private function makeFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'us006_').'.xlsx';
        file_put_contents($path, $contents);

        return $path;
    }

    public function test_start_import_creates_batch_with_md5_hash_and_uploaded_status(): void
    {
        $path = $this->makeFile('contenuto-listino-2026');
        $expectedHash = md5_file($path);

        $batch = app(ImportBatchService::class)->startImport($path);

        $this->assertSame(basename($path), $batch->filename);
        $this->assertSame($expectedHash, $batch->hash);
        $this->assertSame(ImportBatchStatus::Uploaded, $batch->status);
        $this->assertNotNull($batch->started_at);
        $this->assertDatabaseHas('import_batches', [
            'id' => $batch->id,
            'hash' => $expectedHash,
            'status' => ImportBatchStatus::Uploaded->value,
        ]);

        @unlink($path);
    }

    public function test_start_import_throws_on_missing_file(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ImportBatchService::class)->startImport('/path/che/non/esiste.xlsx');
    }

    public function test_duplicate_of_completed_batch_is_blocked_and_creates_no_new_record(): void
    {
        $path = $this->makeFile('stesso-contenuto');
        $hash = md5_file($path);

        ImportBatch::factory()->create([
            'hash' => $hash,
            'status' => ImportBatchStatus::Completed,
        ]);

        try {
            app(ImportBatchService::class)->startImport($path);
            $this->fail('Expected DuplicateImportException was not thrown.');
        } catch (DuplicateImportException $e) {
            $this->assertSame($hash, $e->existingBatch->hash);
        }

        $this->assertDatabaseCount('import_batches', 1);

        @unlink($path);
    }

    public function test_previous_failed_batch_does_not_block_reimport(): void
    {
        $path = $this->makeFile('contenuto-da-riprovare');
        $hash = md5_file($path);

        ImportBatch::factory()->create([
            'hash' => $hash,
            'status' => ImportBatchStatus::Failed,
        ]);

        $batch = app(ImportBatchService::class)->startImport($path);

        $this->assertSame(ImportBatchStatus::Uploaded, $batch->status);
        $this->assertDatabaseCount('import_batches', 2);

        @unlink($path);
    }
}
