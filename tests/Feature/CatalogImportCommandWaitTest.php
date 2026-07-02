<?php

namespace Tests\Feature;

use App\Models\ImportBatch;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-026 (TASK-07/08) acceptance criteria — `catalog:import --wait`:
 *  - Waits for the job chain to reach a terminal batch status and prints the
 *    final summary (totali, nuovi, aggiornati, saltati) from ImportBatch.
 *  - Without --wait, the existing async behaviour (and its message) is
 *    unchanged (see CatalogImportCommandTest / CatalogImportDispatchTest for
 *    the base command's own regression coverage).
 *  - A failed batch is reported with a non-zero exit code.
 *
 * QUEUE_CONNECTION is `sync` in the test environment (see phpunit.xml), so
 * the chained ImportXlsxJob -> PromoteStagingToProductsJob already runs
 * inline by the time `--wait` starts polling: no real sleep is exercised in
 * the happy path, only the terminal-status check and summary output.
 */
class CatalogImportCommandWaitTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    private function makeXlsx(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'us026wait_').'.xlsx';

        SimpleExcelWriter::create($path)
            ->addRow([
                'Codice articolo' => 'ART-200',
                'Descrizione' => 'Rubinetto cucina',
                'Costo un.1' => 19.5,
                'Giac.att.1' => 4.0,
                'Descrizione Marca' => 'Grohe',
            ])
            ->close();

        return $path;
    }

    public function test_wait_prints_final_summary_on_completion(): void
    {
        $path = $this->makeXlsx();

        $this->artisan('catalog:import', ['path' => $path, '--wait' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Import completato')
            ->expectsOutputToContain('Totali: 1')
            ->expectsOutputToContain('Nuovi: 1')
            ->expectsOutputToContain('Aggiornati: 0')
            ->expectsOutputToContain('Saltati: 0');

        $batch = ImportBatch::firstOrFail();
        $this->assertSame('completed', $batch->status->value);
        $this->assertSame(1, Product::where('codice_articolo', 'ART-200')->count());

        @unlink($path);
    }

    public function test_without_wait_reports_async_message_and_does_not_block(): void
    {
        $path = $this->makeXlsx();

        $this->artisan('catalog:import', ['path' => $path])
            ->assertSuccessful()
            ->expectsOutputToContain('Import accodato sulla coda «import»')
            ->doesntExpectOutputToContain('Import completato');

        @unlink($path);
    }

    public function test_wait_fails_fast_on_missing_file_before_polling(): void
    {
        $this->artisan('catalog:import', ['path' => '/path/che/non/esiste.xlsx', '--wait' => true])
            ->assertFailed();

        $this->assertDatabaseCount('import_batches', 0);
    }

    public function test_wait_reports_failure_with_non_zero_exit_code(): void
    {
        // A file that exists but is not a valid XLSX makes ImportXlsxJob blow
        // up during the (sync) read, which its failed() hook turns into a
        // Failed batch — exactly the path --wait must surface as FAILURE.
        $path = tempnam(sys_get_temp_dir(), 'us026waitbad_').'.xlsx';
        file_put_contents($path, 'not a real xlsx file');

        $this->artisan('catalog:import', ['path' => $path, '--wait' => true])
            ->assertFailed()
            ->expectsOutputToContain('Import fallito');

        $batch = ImportBatch::firstOrFail();
        $this->assertSame('failed', $batch->status->value);

        @unlink($path);
    }

    public function test_wait_accepts_custom_timeout_option(): void
    {
        // QUEUE_CONNECTION=sync means the chain always finishes before
        // waitForCompletion's first poll, so a tiny --timeout still succeeds
        // here; this test only guards that --timeout is parsed and does not
        // itself break the happy path.
        $path = $this->makeXlsx();

        $this->artisan('catalog:import', ['path' => $path, '--wait' => true, '--timeout' => 5])
            ->assertSuccessful()
            ->expectsOutputToContain('Import completato');

        @unlink($path);
    }

    public function test_wait_fails_with_timeout_when_batch_never_reaches_terminal_status(): void
    {
        // Force the chain onto the `database` queue driver instead of `sync`
        // for this test only, so ImportXlsxJob/PromoteStagingToProductsJob
        // are queued but never actually run: the batch stays `uploaded`
        // (non-terminal) for the whole test, letting waitForCompletion's
        // deadline branch fire for real instead of resolving instantly.
        config(['queue.default' => 'database']);

        $path = $this->makeXlsx();

        $this->artisan('catalog:import', ['path' => $path, '--wait' => true, '--timeout' => 1])
            ->assertFailed()
            ->expectsOutputToContain('Timeout raggiunto');

        $batch = ImportBatch::firstOrFail();
        $this->assertSame('uploaded', $batch->status->value);

        @unlink($path);
    }
}
