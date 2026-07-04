<?php

namespace Tests\Feature;

use App\Enums\ImportBatchStatus;
use App\Models\ImportBatch;
use App\Models\StagingArticolo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-005 acceptance criteria — schema, indexes and defaults:
 *  - product_embeddings, staging_articoli, import_batches, enrichment_logs exist.
 *  - import_batches.hash is indexed (deduplication).
 *  - status/step columns and composite indexes exist.
 *  - status columns default to 'pending'.
 *
 * Runs against in-memory SQLite via RequiresDatabase (pgvector-specific
 * assertions live in VectorEmbeddingPgvectorTest).
 */
class VectorStagingAuditSchemaTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_import_batches_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('import_batches'));
        $this->assertTrue(Schema::hasColumns('import_batches', [
            'id', 'filename', 'hash', 'status', 'total_rows', 'processed_rows',
            'error_rows', 'skipped_rows', 'rows_new', 'rows_updated', 'started_at', 'finished_at', 'created_at', 'updated_at',
        ]));
    }

    public function test_staging_articoli_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('staging_articoli'));
        $this->assertTrue(Schema::hasColumns('staging_articoli', [
            'id', 'import_batch_id', 'raw_row', 'row_number', 'codice_articolo',
            'descrizione', 'costo', 'giacenza', 'status', 'error', 'created_at', 'updated_at',
        ]));
    }

    public function test_product_embeddings_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('product_embeddings'));
        $this->assertTrue(Schema::hasColumns('product_embeddings', [
            'id', 'product_id', 'content', 'content_hash', 'model', 'dimensions', 'embedding',
            'created_at', 'updated_at',
        ]));
    }

    public function test_enrichment_logs_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('enrichment_logs'));
        $this->assertTrue(Schema::hasColumns('enrichment_logs', [
            'id', 'product_id', 'step', 'input', 'output', 'confidence', 'model',
            'tokens_in', 'tokens_out', 'created_at', 'updated_at',
        ]));
    }

    public function test_import_batches_hash_is_indexed(): void
    {
        $this->assertColumnIndexed('import_batches', 'hash');
    }

    public function test_import_batches_status_is_indexed(): void
    {
        $this->assertColumnIndexed('import_batches', 'status');
    }

    public function test_staging_articoli_status_and_codice_are_indexed(): void
    {
        $this->assertColumnIndexed('staging_articoli', 'status');
        $this->assertColumnIndexed('staging_articoli', 'codice_articolo');
    }

    public function test_enrichment_logs_step_is_indexed(): void
    {
        $this->assertColumnIndexed('enrichment_logs', 'step');
    }

    public function test_enrichment_logs_has_product_step_composite_index(): void
    {
        $this->assertCompositeIndex('enrichment_logs', ['product_id', 'step']);
    }

    public function test_product_embeddings_has_product_model_unique_index(): void
    {
        $this->assertCompositeIndex('product_embeddings', ['product_id', 'model'], unique: true);
    }

    public function test_import_batch_status_defaults_to_uploaded(): void
    {
        $batch = ImportBatch::create([
            'filename' => 'listino.xlsx',
            'hash' => hash('sha256', 'listino.xlsx'),
        ]);

        $this->assertSame(ImportBatchStatus::Uploaded, $batch->fresh()->status);
    }

    public function test_staging_articolo_status_defaults_to_pending(): void
    {
        $row = StagingArticolo::create([
            'raw_row' => ['codice_articolo' => 'ART-1'],
            'row_number' => 1,
        ]);

        $this->assertSame('pending', $row->fresh()->status);
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function assertCompositeIndex(string $table, array $columns, bool $unique = false): void
    {
        $matching = collect(Schema::getIndexes($table))
            ->filter(fn (array $index): bool => $index['columns'] === $columns);

        $this->assertTrue(
            $matching->isNotEmpty(),
            "Expected composite index on {$table} (".implode(', ', $columns).').',
        );

        if ($unique) {
            $this->assertTrue(
                $matching->contains(fn (array $index): bool => $index['unique'] === true),
                "Expected composite index on {$table} (".implode(', ', $columns).') to be unique.',
            );
        }
    }

    private function assertColumnIndexed(string $table, string $column): void
    {
        $columns = collect(Schema::getIndexes($table))
            ->flatMap(fn (array $index): array => $index['columns'])
            ->all();

        $this->assertContains($column, $columns);
    }
}
