<?php

namespace Tests\Feature;

use App\Enums\ImportBatchStatus;
use App\Jobs\PromoteStagingToProductsJob;
use App\Models\ImportBatch;
use App\Models\Product;
use App\Models\StagingArticolo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-008 acceptance criteria — promoting staging rows into products:
 *  - New codice_articolo values are inserted as pending products.
 *  - Existing products with a changed description are updated and reset to
 *    pending so enrichment re-runs; unchanged descriptions leave
 *    enrichment_status untouched.
 *  - is_active is derived from costo/giacenza (inactive only when both are
 *    exhausted; null treated as zero).
 *  - rows_new/rows_updated counters on the batch reflect the upsert outcome.
 *  - Staging rows spanning multiple chunks are all promoted.
 *  - Re-running the job on unchanged staging data is idempotent.
 *  - The batch advances enriching -> completed on success, and -> failed on
 *    error.
 *
 * Runs against in-memory SQLite via RequiresDatabase.
 */
class PromoteStagingToProductsJobTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    private function enrichingBatch(): ImportBatch
    {
        return ImportBatch::factory()->create(['status' => ImportBatchStatus::Enriching]);
    }

    public function test_inserts_new_product_as_pending(): void
    {
        $batch = $this->enrichingBatch();

        StagingArticolo::factory()->create([
            'import_batch_id' => $batch->id,
            'codice_articolo' => 'ART-NEW1',
            'descrizione' => 'Tubo rame 18mm',
            'costo' => 12.5,
            'giacenza' => 40,
        ]);

        PromoteStagingToProductsJob::dispatchSync($batch);

        $product = Product::where('codice_articolo', 'ART-NEW1')->firstOrFail();

        $this->assertSame('pending', $product->enrichment_status);
        $this->assertSame('Tubo rame 18mm', $product->description_raw);
        $this->assertTrue($product->is_active);

        $fresh = $batch->fresh();
        $this->assertSame(1, $fresh->rows_new);
        $this->assertSame(0, $fresh->rows_updated);
    }

    public function test_update_with_changed_description_resets_to_pending(): void
    {
        $batch = $this->enrichingBatch();

        Product::factory()->create([
            'codice_articolo' => 'ART-CHG1',
            'description_raw' => 'Vecchia descrizione',
            'enrichment_status' => 'enriched',
            'costo' => 5,
            'giacenza' => 5,
            'is_active' => true,
        ]);

        StagingArticolo::factory()->create([
            'import_batch_id' => $batch->id,
            'codice_articolo' => 'ART-CHG1',
            'descrizione' => 'Nuova descrizione',
            'costo' => 6,
            'giacenza' => 7,
        ]);

        PromoteStagingToProductsJob::dispatchSync($batch);

        $product = Product::where('codice_articolo', 'ART-CHG1')->firstOrFail();

        $this->assertSame('Nuova descrizione', $product->description_raw);
        $this->assertSame('pending', $product->enrichment_status);

        $fresh = $batch->fresh();
        $this->assertSame(0, $fresh->rows_new);
        $this->assertSame(1, $fresh->rows_updated);
    }

    public function test_update_with_unchanged_description_does_not_reset_enrichment_status(): void
    {
        $batch = $this->enrichingBatch();

        Product::factory()->create([
            'codice_articolo' => 'ART-SAME1',
            'description_raw' => 'Tubo rame 18mm',
            'enrichment_status' => 'enriched',
            'costo' => 5,
            'giacenza' => 5,
            'is_active' => true,
        ]);

        StagingArticolo::factory()->create([
            'import_batch_id' => $batch->id,
            'codice_articolo' => 'ART-SAME1',
            'descrizione' => 'Tubo rame 18mm',
            'costo' => 20,
            'giacenza' => 100,
        ]);

        PromoteStagingToProductsJob::dispatchSync($batch);

        $product = Product::where('codice_articolo', 'ART-SAME1')->firstOrFail();

        $this->assertSame('enriched', $product->enrichment_status);
        $this->assertSame('Tubo rame 18mm', $product->description_raw);
        $this->assertEquals(20, (float) $product->costo);
        $this->assertEquals(100, (float) $product->giacenza);
        $this->assertTrue($product->is_active);
    }

    public function test_is_active_matrix_from_costo_and_giacenza(): void
    {
        $cases = [
            ['ART-IA1', 0, 0, false],
            ['ART-IA2', 0, -1, false],
            ['ART-IA3', 5, 0, true],
            ['ART-IA4', 5, -3, true],
            ['ART-IA5', 0, 10, true],
            ['ART-IA6', null, null, false],
            ['ART-IA7', null, 3, true],
            ['ART-IA8', 3, null, true],
        ];

        $batch = $this->enrichingBatch();

        foreach ($cases as [$codice, $costo, $giacenza]) {
            StagingArticolo::factory()->create([
                'import_batch_id' => $batch->id,
                'codice_articolo' => $codice,
                'descrizione' => "Articolo {$codice}",
                'costo' => $costo,
                'giacenza' => $giacenza,
            ]);
        }

        PromoteStagingToProductsJob::dispatchSync($batch);

        foreach ($cases as [$codice, $costo, $giacenza, $expectedActive]) {
            $product = Product::where('codice_articolo', $codice)->firstOrFail();
            $this->assertSame(
                $expectedActive,
                $product->is_active,
                "is_active mismatch for {$codice} (costo=".var_export($costo, true).', giacenza='.var_export($giacenza, true).')',
            );
        }
    }

    public function test_rows_new_and_rows_updated_counts_are_correct(): void
    {
        $batch = $this->enrichingBatch();

        // 3 pre-existing products: 2 with a changed description, 1 unchanged.
        Product::factory()->create([
            'codice_articolo' => 'ART-MIX1',
            'description_raw' => 'Descrizione originale 1',
            'enrichment_status' => 'enriched',
        ]);
        Product::factory()->create([
            'codice_articolo' => 'ART-MIX2',
            'description_raw' => 'Descrizione originale 2',
            'enrichment_status' => 'enriched',
        ]);
        Product::factory()->create([
            'codice_articolo' => 'ART-MIX3',
            'description_raw' => 'Descrizione invariata',
            'enrichment_status' => 'enriched',
        ]);

        StagingArticolo::factory()->create([
            'import_batch_id' => $batch->id,
            'codice_articolo' => 'ART-MIX1',
            'descrizione' => 'Descrizione cambiata 1',
            'costo' => 1,
            'giacenza' => 1,
        ]);
        StagingArticolo::factory()->create([
            'import_batch_id' => $batch->id,
            'codice_articolo' => 'ART-MIX2',
            'descrizione' => 'Descrizione cambiata 2',
            'costo' => 1,
            'giacenza' => 1,
        ]);
        StagingArticolo::factory()->create([
            'import_batch_id' => $batch->id,
            'codice_articolo' => 'ART-MIX3',
            'descrizione' => 'Descrizione invariata',
            'costo' => 1,
            'giacenza' => 1,
        ]);
        StagingArticolo::factory()->create([
            'import_batch_id' => $batch->id,
            'codice_articolo' => 'ART-MIX4',
            'descrizione' => 'Nuovo articolo 4',
            'costo' => 1,
            'giacenza' => 1,
        ]);
        StagingArticolo::factory()->create([
            'import_batch_id' => $batch->id,
            'codice_articolo' => 'ART-MIX5',
            'descrizione' => 'Nuovo articolo 5',
            'costo' => 1,
            'giacenza' => 1,
        ]);

        PromoteStagingToProductsJob::dispatchSync($batch);

        $fresh = $batch->fresh();
        $this->assertSame(2, $fresh->rows_new);
        $this->assertSame(3, $fresh->rows_updated);
    }

    public function test_processes_staging_rows_spanning_multiple_chunks(): void
    {
        $count = PromoteStagingToProductsJob::CHUNK_SIZE * 2 + 500; // 2500 rows -> 3 chunks

        $batch = $this->enrichingBatch();

        for ($i = 1; $i <= $count; $i++) {
            StagingArticolo::factory()->create([
                'import_batch_id' => $batch->id,
                'codice_articolo' => sprintf('ART-%05d', $i),
                'descrizione' => "Articolo {$i}",
                'costo' => $i + 0.5,
                'giacenza' => $i,
            ]);
        }

        PromoteStagingToProductsJob::dispatchSync($batch);

        $fresh = $batch->fresh();
        $this->assertSame($count, $fresh->rows_new);
        $this->assertSame(0, $fresh->rows_updated);
        $this->assertSame($count, Product::count());
        $this->assertDatabaseCount('products', $count);
    }

    public function test_second_run_on_unchanged_staging_data_is_idempotent(): void
    {
        $batch = $this->enrichingBatch();

        StagingArticolo::factory()->create([
            'import_batch_id' => $batch->id,
            'codice_articolo' => 'ART-IDEMP1',
            'descrizione' => 'Tubo rame 18mm',
            'costo' => 12.5,
            'giacenza' => 40,
        ]);
        StagingArticolo::factory()->create([
            'import_batch_id' => $batch->id,
            'codice_articolo' => 'ART-IDEMP2',
            'descrizione' => 'Raccordo 3 pezzi',
            'costo' => 3.0,
            'giacenza' => 10,
        ]);

        PromoteStagingToProductsJob::dispatchSync($batch);

        $firstRunBatch = $batch->fresh();
        $this->assertSame(2, $firstRunBatch->rows_new);
        $this->assertSame(ImportBatchStatus::Completed, $firstRunBatch->status);
        $productCountAfterFirstRun = Product::count();

        // Simulate re-running the promotion on the same batch/staging data:
        // put the batch back into the precondition status for the job.
        $firstRunBatch->forceFill(['status' => ImportBatchStatus::Enriching])->save();

        PromoteStagingToProductsJob::dispatchSync($firstRunBatch);

        $secondRunBatch = $firstRunBatch->fresh();
        $this->assertSame(0, $secondRunBatch->rows_new);
        $this->assertSame(2, $secondRunBatch->rows_updated);
        $this->assertSame($productCountAfterFirstRun, Product::count());
        $this->assertSame(ImportBatchStatus::Completed, $secondRunBatch->status);
    }

    public function test_advances_batch_from_enriching_to_completed_on_success(): void
    {
        $batch = $this->enrichingBatch();

        StagingArticolo::factory()->create([
            'import_batch_id' => $batch->id,
            'codice_articolo' => 'ART-OK1',
            'descrizione' => 'Tubo',
            'costo' => 3,
            'giacenza' => 5,
        ]);

        PromoteStagingToProductsJob::dispatchSync($batch);

        $this->assertSame(ImportBatchStatus::Completed, $batch->fresh()->status);
    }

    public function test_failed_callback_transitions_batch_to_failed(): void
    {
        $batch = $this->enrichingBatch();

        $job = new PromoteStagingToProductsJob($batch);
        $job->failed(new \Exception('simulated upsert failure'));

        $this->assertSame(ImportBatchStatus::Failed, $batch->fresh()->status);
    }
}
