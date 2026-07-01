<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\TestCase;

/**
 * US-005 acceptance criteria — pgvector-specific guarantees.
 *
 * These assertions only make sense on PostgreSQL with the pgvector extension,
 * so the test skips on any other driver and when Postgres is unreachable
 * (e.g. running the suite outside Sail). It exercises the real Postgres schema
 * by running the migrations against the live connection, following the same
 * pattern as DatabaseConnectivityTest (US-001).
 */
class VectorEmbeddingPgvectorTest extends TestCase
{
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
}
