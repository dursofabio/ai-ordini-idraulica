<?php

namespace Tests\Feature;

use App\Models\ProductBase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\TestCase;

/**
 * US-005/US-018 acceptance criteria — pgvector-specific guarantees.
 *
 * These assertions only make sense on PostgreSQL with the pgvector extension,
 * so the test skips on any other driver and when Postgres is unreachable
 * (e.g. running the suite outside Sail). It exercises the real Postgres schema
 * by running the migrations against the live connection, following the same
 * pattern as DatabaseConnectivityTest (US-001).
 */
class VectorEmbeddingPgvectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('pgvector assertions require the pgsql driver.');
        }

        try {
            DB::connection()->getPdo();
        } catch (PDOException $e) {
            $this->markTestSkipped('PostgreSQL is not reachable: '.$e->getMessage());
        }

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_embedding_column_is_vector_type(): void
    {
        $type = DB::selectOne(
            'SELECT udt_name FROM information_schema.columns '
            .'WHERE table_name = ? AND column_name = ?',
            ['product_embeddings', 'embedding'],
        );

        $this->assertNotNull($type);
        $this->assertSame('vector', $type->udt_name);
    }

    public function test_embedding_has_hnsw_index(): void
    {
        $index = DB::selectOne(
            'SELECT indexdef FROM pg_indexes '
            .'WHERE tablename = ? AND indexname = ?',
            ['product_embeddings', 'product_embeddings_embedding_hnsw_idx'],
        );

        $this->assertNotNull($index, 'HNSW index on product_embeddings.embedding is missing.');
        $this->assertStringContainsStringIgnoringCase('hnsw', $index->indexdef);
        $this->assertStringContainsStringIgnoringCase('vector_cosine_ops', $index->indexdef);
    }

    public function test_embeddings_are_keyed_by_product_base_id(): void
    {
        $column = DB::selectOne(
            'SELECT column_name FROM information_schema.columns '
            .'WHERE table_name = ? AND column_name = ?',
            ['product_embeddings', 'product_base_id'],
        );

        $this->assertNotNull($column, 'product_embeddings.product_base_id column is missing.');
    }

    public function test_product_embeddings_has_unique_constraint_on_product_base_id_and_model(): void
    {
        $constraint = DB::selectOne(
            "SELECT indexdef FROM pg_indexes WHERE tablename = 'product_embeddings' "
            ."AND indexdef LIKE '%UNIQUE%' AND indexdef LIKE '%product_base_id%' AND indexdef LIKE '%model%'",
        );

        $this->assertNotNull($constraint, 'unique(product_base_id, model) constraint is missing.');
    }

    public function test_cosine_similarity_query_orders_results_by_relevance(): void
    {
        $near = ProductBase::factory()->create();
        $far = ProductBase::factory()->create();
        $opposite = ProductBase::factory()->create();

        // Pad every vector to the real 1024-dimensional column width so this
        // test exercises the live schema without altering its column type.
        $pad = fn (array $head): string => '['.implode(',', array_merge($head, array_fill(0, 1024 - count($head), 0))).']';

        $queryVector = $pad([1, 0, 0]);

        DB::table('product_embeddings')->insert([
            [
                'product_base_id' => $near->id,
                'content' => 'near',
                'model' => 'test-model',
                'dimensions' => 1024,
                'embedding' => $pad([0.9, 0.1, 0]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'product_base_id' => $far->id,
                'content' => 'far',
                'model' => 'test-model',
                'dimensions' => 1024,
                'embedding' => $pad([0.5, 0.5, 0.5]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'product_base_id' => $opposite->id,
                'content' => 'opposite',
                'model' => 'test-model',
                'dimensions' => 1024,
                'embedding' => $pad([-1, 0, 0]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $results = DB::select(
            'SELECT product_base_id FROM product_embeddings '
            .'WHERE model = ? ORDER BY embedding <=> ? ASC',
            ['test-model', $queryVector],
        );

        $orderedIds = array_map(static fn ($row) => $row->product_base_id, $results);

        $this->assertSame([$near->id, $far->id, $opposite->id], $orderedIds);
    }
}
