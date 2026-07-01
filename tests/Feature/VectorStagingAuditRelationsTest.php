<?php

namespace Tests\Feature;

use App\Models\EnrichmentLog;
use App\Models\ImportBatch;
use App\Models\Product;
use App\Models\ProductEmbedding;
use App\Models\StagingArticolo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-005 acceptance criteria — relations, JSON casts and audit token counting:
 *  - EnrichmentLog stores input/output JSON with a clean array round-trip.
 *  - EnrichmentLog records step, confidence, model and token counts.
 *  - Product hasMany embeddings / enrichmentLogs; ImportBatch hasMany stagingArticoli.
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

    public function test_staging_articolo_casts_payload_json(): void
    {
        $row = StagingArticolo::factory()->create([
            'payload' => ['codice_articolo' => 'ART-9', 'costo' => 12.5],
        ]);

        $fresh = $row->fresh();

        $this->assertIsArray($fresh->payload);
        $this->assertSame('ART-9', $fresh->payload['codice_articolo']);
    }

    public function test_product_has_many_embeddings(): void
    {
        $product = Product::factory()->create();
        ProductEmbedding::factory()->create([
            'product_id' => $product->id, 'model' => 'model-a',
        ]);
        ProductEmbedding::factory()->create([
            'product_id' => $product->id, 'model' => 'model-b',
        ]);

        $this->assertCount(2, $product->embeddings);
        $this->assertInstanceOf(ProductEmbedding::class, $product->embeddings->first());
    }

    public function test_product_has_many_enrichment_logs(): void
    {
        $product = Product::factory()->create();
        EnrichmentLog::factory()->count(3)->create(['product_id' => $product->id]);

        $this->assertCount(3, $product->enrichmentLogs);
        $this->assertInstanceOf(EnrichmentLog::class, $product->enrichmentLogs->first());
    }

    public function test_embedding_and_enrichment_log_belong_to_product(): void
    {
        $product = Product::factory()->create();
        $embedding = ProductEmbedding::factory()->create(['product_id' => $product->id]);
        $log = EnrichmentLog::factory()->create(['product_id' => $product->id]);

        $this->assertSame($product->id, $embedding->product->id);
        $this->assertSame($product->id, $log->product->id);
    }

    public function test_import_batch_has_many_staging_articoli(): void
    {
        $batch = ImportBatch::factory()->create();
        StagingArticolo::factory()->count(4)->create(['import_batch_id' => $batch->id]);

        $this->assertCount(4, $batch->stagingArticoli);
        $this->assertSame($batch->id, $batch->stagingArticoli->first()->importBatch->id);
    }

    public function test_deleting_product_cascades_to_embeddings_and_logs(): void
    {
        $product = Product::factory()->create();
        ProductEmbedding::factory()->create(['product_id' => $product->id]);
        EnrichmentLog::factory()->count(2)->create(['product_id' => $product->id]);

        $product->delete();

        $this->assertDatabaseCount('product_embeddings', 0);
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
