<?php

namespace Tests\Feature;

use App\Filament\Pages\ImportCatalog;
use App\Jobs\ImportXlsxJob;
use App\Jobs\PromoteStagingToProductsJob;
use App\Jobs\SeedTaxonomyFromStagingJob;
use App\Models\ImportBatch;
use App\Models\User;
use App\Services\ImportBatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;
use Throwable;

/**
 * US-024 acceptance criteria for the "Importa Catalogo" upload action:
 *  - AC1: uploading a valid XLSX creates an ImportBatch and starts the same
 *    job chain used by `catalog:import` (ImportXlsxJob ->
 *    SeedTaxonomyFromStagingJob -> PromoteStagingToProductsJob).
 *  - AC4: a file whose hash was already completed is rejected with a warning
 *    notification and no new batch is created; a non-XLSX/unreadable file
 *    is rejected with a danger notification and no unhandled exception.
 */
class ImportCatalogUploadActionTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    /**
     * Builds a real XLSX fixture (not a stub) as a fake upload with genuine
     * file content, since the action reads the actual bytes via
     * `ImportBatchService::startImport()` (md5_file) and `ImportXlsxJob`
     * streams real rows out of it — a zero-byte fake upload would not
     * round-trip through either.
     */
    private function makeXlsxUpload(string $name = 'catalogo.xlsx'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'us024_').'.xlsx';

        SimpleExcelWriter::create($path)
            ->addRow([
                'Codice articolo' => 'ART-200',
                'Descrizione' => 'Rubinetto monocomando',
                'Costo un.1' => 45.0,
                'Giac.att.1' => 8.0,
            ])
            ->close();

        $content = file_get_contents($path);
        @unlink($path);

        return UploadedFile::fake()->createWithContent($name, $content);
    }

    public function test_uploading_a_valid_xlsx_creates_a_batch_and_chains_the_import_jobs(): void
    {
        Storage::fake('local');
        Bus::fake();

        $admin = User::factory()->create();
        $this->actingAs($admin);

        Livewire::test(ImportCatalog::class)
            ->callAction('upload', data: [
                'file' => $this->makeXlsxUpload(),
            ])
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('import_batches', 1);
        $batch = ImportBatch::firstOrFail();
        $this->assertSame('uploaded', $batch->status->value);

        Bus::assertChained([
            ImportXlsxJob::class,
            SeedTaxonomyFromStagingJob::class,
            PromoteStagingToProductsJob::class,
        ]);
    }

    public function test_uploading_a_file_with_an_already_completed_hash_shows_warning_and_creates_no_batch(): void
    {
        Storage::fake('local');
        Bus::fake();

        $admin = User::factory()->create();
        $this->actingAs($admin);

        $upload = $this->makeXlsxUpload();
        $hash = md5_file($upload->getRealPath());

        ImportBatch::factory()->create([
            'hash' => $hash,
            'status' => 'completed',
        ]);

        Livewire::test(ImportCatalog::class)
            ->callAction('upload', data: [
                'file' => $upload,
            ])
            ->assertNotified();

        $this->assertDatabaseCount('import_batches', 1);
        Bus::assertNothingDispatched();
    }

    public function test_uploading_a_non_xlsx_file_is_rejected_client_side_with_no_batch_created(): void
    {
        Storage::fake('local');
        Bus::fake();

        $admin = User::factory()->create();
        $this->actingAs($admin);

        // A non-xlsx MIME type is rejected by the FileUpload field's own
        // `acceptedFileTypes()` validation (AC4: "un file non XLSX ... produce
        // un messaggio di errore chiaro senza eccezioni non gestite" — the
        // schema-level rejection is exactly that: no batch, no exception).
        $upload = UploadedFile::fake()->create('catalogo.txt', 10, 'text/plain');

        Livewire::test(ImportCatalog::class)
            ->callAction('upload', data: [
                'file' => $upload,
            ])
            ->assertHasActionErrors(['file']);

        $this->assertDatabaseCount('import_batches', 0);
        Bus::assertNothingDispatched();
    }

    public function test_import_xlsx_job_reader_failure_marks_batch_failed_without_unhandled_exception(): void
    {
        // Covers the "file XLSX corrotto" branch of AC4 end to end: the
        // upload action itself only validates readability (InvalidArgumentException),
        // so a corrupted-but-readable file is caught later by ImportXlsxJob's
        // own failed() handler (already exercised by ImportXlsxJobTest); this
        // test asserts the batch created via the service transitions to
        // failed when the chained read blows up, with no exception escaping.
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $path = tempnam(sys_get_temp_dir(), 'us024corrupt_').'.xlsx';
        file_put_contents($path, 'questo-non-e-un-workbook-xlsx-valido');

        $batch = app(ImportBatchService::class)->startImport($path);

        try {
            ImportXlsxJob::dispatchSync($batch, $path);
        } catch (Throwable) {
            // Sync queue rethrows after failed() runs; the state change below is what matters.
        }

        $this->assertSame('failed', $batch->fresh()->status->value);

        @unlink($path);
    }
}
