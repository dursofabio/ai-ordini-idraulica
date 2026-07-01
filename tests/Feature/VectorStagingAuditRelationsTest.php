<?php

namespace Tests\Feature;

use App\Models\EnrichmentLog;
use App\Models\ImportBatch;
use App\Models\Product;
use App\Models\ProductBase;
use App\Models\ProductEmbedding;
use App\Models\StagingArticolo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-005/US-018 acceptance criteria — relations, JSON casts and audit token counting:
 *  - EnrichmentLog stores input/output JSON with a clean array round-trip.
 *  - EnrichmentLog records step, confidence, model and token counts.
 *  - ProductBase hasOne embedding; Product hasMany enrichmentLogs;
 *    ImportBatch hasMany stagingArticoli.
 *  - Cascade / nullOnDelete behave as declared.
 *
 * Runs against in-memory SQLite via RequiresDatabase.
 */
class VectorStagingAuditRelationsTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_enrichment_log_casts_input_output_json_and_counts_tokens(): void
    {
        $product = Product::factory()->create();

        $log = EnrichmentLog::create([
            'product_id' => $product->id,
            'step' => 'ai',
            'input' => ['description' => 'Caldaia a condensazione 24 kW'],
            'output' => ['brand' => 'Vaillant', 'attributes' => ['potenza_kw' => 24]],
            'confidence' => 87,
            'model' => 'gpt-4o-mini',
            'tokens_in' => 1250,
            'tokens_out' => 340,
        ]);

        $fresh = $log->fresh();

        $this->assertIsArray($fresh->input);
        $this->assertIsArray($fresh->output);
        $this->assertSame('Caldaia a condensazione 24 kW', $fresh->input['description']);
        $this->assertSame('Vaillant', $fresh->output['brand']);
        $this->assertSame(24, $fresh->output['attributes']['potenza_kw']);
        $this->assertSame('ai', $fresh->step);
        $this->assertSame(87, $fresh->confidence);
        $this->assertSame(1250, $fresh->tokens_in);
        $this->assertSame(340, $fresh->tokens_out);
    }

    public function test_staging_articolo_casts_raw_row_json(): void
    {
        $row = StagingArticolo::factory()->create([
            'raw_row' => ['codice_articolo' => 'ART-9', 'costo_un_1' => 12.5],
        ]);

        $fresh = $row->fresh();

        $this->assertIsArray($fresh->raw_row);
        $this->assertSame('ART-9', $fresh->raw_row['codice_articolo']);
    }

    public function test_product_base_has_one_embedding(): void
    {
        $productBase = ProductBase::factory()->create();
        ProductEmbedding::factory()->create([
            'product_base_id' => $productBase->id, 'model' => 'model-a',
        ]);

        $this->assertInstanceOf(ProductEmbedding::class, $productBase->embedding);
        $this->assertSame('model-a', $productBase->embedding->model);
    }

    public function test_product_has_many_enrichment_logs(): void
    {
        $product = Product::factory()->create();
        EnrichmentLog::factory()->count(3)->create(['product_id' => $product->id]);

        $this->assertCount(3, $product->enrichmentLogs);
        $this->assertInstanceOf(EnrichmentLog::class, $product->enrichmentLogs->first());
    }

    public function test_embedding_belongs_to_product_base_and_log_belongs_to_product(): void
    {
        $productBase = ProductBase::factory()->create();
        $embedding = ProductEmbedding::factory()->create(['product_base_id' => $productBase->id]);

        $product = Product::factory()->create();
        $log = EnrichmentLog::factory()->create(['product_id' => $product->id]);

        $this->assertSame($productBase->id, $embedding->productBase->id);
        $this->assertSame($product->id, $log->product->id);
    }

    public function test_import_batch_has_many_staging_articoli(): void
    {
        $batch = ImportBatch::factory()->create();
        StagingArticolo::factory()->count(4)->create(['import_batch_id' => $batch->id]);

        $this->assertCount(4, $batch->stagingArticoli);
        $this->assertSame($batch->id, $batch->stagingArticoli->first()->importBatch->id);
    }

    public function test_deleting_product_base_cascades_to_embeddings(): void
    {
        $productBase = ProductBase::factory()->create();
        ProductEmbedding::factory()->create(['product_base_id' => $productBase->id]);

        $productBase->delete();

        $this->assertDatabaseCount('product_embeddings', 0);
    }

    public function test_deleting_product_cascades_to_enrichment_logs(): void
    {
        $product = Product::factory()->create();
        EnrichmentLog::factory()->count(2)->create(['product_id' => $product->id]);

        $product->delete();

        $this->assertDatabaseCount('enrichment_logs', 0);
    }

    public function test_deleting_import_batch_nulls_staging_reference(): void
    {
        $batch = ImportBatch::factory()->create();
        $row = StagingArticolo::factory()->create(['import_batch_id' => $batch->id]);

        $batch->delete();

        $this->assertNull($row->fresh()->import_batch_id);
    }

    public function test_import_batches_hash_dedup_lookup(): void
    {
        $hash = hash('sha256', 'listino-2026-07.xlsx');
        ImportBatch::factory()->create(['hash' => $hash]);

        $this->assertTrue(ImportBatch::where('hash', $hash)->exists());
    }

    public function test_factories_produce_persistable_models(): void
    {
        $batch = ImportBatch::factory()->create();
        $staging = StagingArticolo::factory()->create();
        $embedding = ProductEmbedding::factory()->create();
        $log = EnrichmentLog::factory()->create();

        $this->assertDatabaseHas('import_batches', ['id' => $batch->id]);
        $this->assertDatabaseHas('staging_articoli', ['id' => $staging->id]);
        $this->assertDatabaseHas('product_embeddings', ['id' => $embedding->id]);
        $this->assertDatabaseHas('enrichment_logs', ['id' => $log->id]);
        $this->assertSame(1024, $embedding->dimensions);
    }
}
