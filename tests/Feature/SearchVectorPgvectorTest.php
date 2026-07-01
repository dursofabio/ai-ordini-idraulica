<?php

namespace Tests\Feature;

use App\Models\ProductBase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\TestCase;

/**
 * US-019 acceptance criteria — Postgres-specific `search_vector` guarantees.
 *
 * These assertions only make sense on PostgreSQL (tsvector generated column,
 * GIN index), so the test skips on any other driver and when Postgres is
 * unreachable, following the same pattern as VectorEmbeddingPgvectorTest.
 */
class SearchVectorPgvectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('search_vector assertions require the pgsql driver.');
        }

        try {
            DB::connection()->getPdo();
        } catch (PDOException $e) {
            $this->markTestSkipped('PostgreSQL is not reachable: '.$e->getMessage());
        }

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_search_vector_column_is_tsvector_type(): void
    {
        $type = DB::selectOne(
            'SELECT udt_name FROM information_schema.columns '
            .'WHERE table_name = ? AND column_name = ?',
            ['product_bases', 'search_vector'],
        );

        $this->assertNotNull($type);
        $this->assertSame('tsvector', $type->udt_name);
    }

    public function test_search_vector_has_gin_index(): void
    {
        $index = DB::selectOne(
            'SELECT indexdef FROM pg_indexes '
            .'WHERE tablename = ? AND indexname = ?',
            ['product_bases', 'product_bases_search_vector_gin_idx'],
        );

        $this->assertNotNull($index, 'GIN index on product_bases.search_vector is missing.');
        $this->assertStringContainsStringIgnoringCase('gin', $index->indexdef);
    }

    public function test_search_vector_is_populated_from_title_and_description_ai(): void
    {
        $productBase = ProductBase::factory()->create([
            'title' => 'Scaldabagno a pompa di calore',
            'description_ai' => 'Scaldabagno a pompa di calore Ariston risparmio energetico',
        ]);

        $expected = DB::selectOne(
            "SELECT to_tsvector('italian', ?) AS vector",
            [$productBase->title.' '.$productBase->description_ai],
        );

        $actual = DB::selectOne(
            'SELECT search_vector FROM product_bases WHERE id = ?',
            [$productBase->id],
        );

        $this->assertNotNull($actual);
        $this->assertSame($expected->vector, $actual->search_vector);

        $match = DB::selectOne(
            "SELECT search_vector @@ plainto_tsquery('italian', 'scaldabagno pompa calore') AS matches "
            .'FROM product_bases WHERE id = ?',
            [$productBase->id],
        );

        $this->assertTrue((bool) $match->matches);
    }
}
