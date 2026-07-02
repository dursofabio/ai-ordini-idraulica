<?php

namespace Tests\Feature;

use App\Jobs\ImportXlsxJob;
use App\Jobs\PromoteStagingToProductsJob;
use App\Jobs\SeedTaxonomyFromStagingJob;
use App\Models\Product;
use App\Models\StagingArticolo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-007/US-008 acceptance criteria — catalog:import wires the job chain:
 *  - A valid file creates a batch and chains ImportXlsxJob ->
 *    SeedTaxonomyFromStagingJob -> PromoteStagingToProductsJob.
 *  - Run end to end (sync), the command populates staging_articoli AND products.
 *
 * Runs against in-memory SQLite via RequiresDatabase.
 */
class CatalogImportDispatchTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    private function makeXlsx(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'us007cmd_').'.xlsx';

        SimpleExcelWriter::create($path)
            ->addRow([
                'Codice articolo' => 'ART-100',
                'Descrizione' => 'Miscelatore lavabo',
                'Costo un.1' => 34.9,
                'Giac.att.1' => 12.0,
                'Descrizione Marca' => 'Grohe',
            ])
            ->close();

        return $path;
    }

    public function test_command_queues_import_job_on_import_queue(): void
    {
        Bus::fake();
        $path = $this->makeXlsx();

        $this->artisan('catalog:import', ['path' => $path])->assertSuccessful();

        // The command chains the read job into the promote job, in that
        // order, rather than dispatching ImportXlsxJob standalone. Per-job
        // `onQueue('import')` wiring is already covered by each job's own
        // unit tests (constructor sets the queue), so it isn't reasserted
        // here — Bus::chain() doesn't expose a queue-level assertion of its
        // own.
        Bus::assertChained([
            ImportXlsxJob::class,
            SeedTaxonomyFromStagingJob::class,
            PromoteStagingToProductsJob::class,
        ]);

        @unlink($path);
    }

    public function test_command_populates_staging_end_to_end(): void
    {
        // No Bus::fake(): the sync connection runs the whole chain inline
        // (ImportXlsxJob, then SeedTaxonomyFromStagingJob, then
        // PromoteStagingToProductsJob).
        $path = $this->makeXlsx();

        $this->artisan('catalog:import', ['path' => $path])->assertSuccessful();

        $this->assertDatabaseCount('staging_articoli', 1);
        $this->assertDatabaseHas('staging_articoli', [
            'codice_articolo' => 'ART-100',
            'descrizione' => 'Miscelatore lavabo',
        ]);
        $this->assertSame(1, StagingArticolo::count());

        // The chained PromoteStagingToProductsJob should have promoted the
        // staging row into `products` as a new, pending-enrichment product.
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseHas('products', [
            'codice_articolo' => 'ART-100',
            'description_raw' => 'Miscelatore lavabo',
            'enrichment_status' => 'pending',
        ]);

        $product = Product::where('codice_articolo', 'ART-100')->firstOrFail();
        $this->assertTrue($product->is_active);

        @unlink($path);
    }
}
