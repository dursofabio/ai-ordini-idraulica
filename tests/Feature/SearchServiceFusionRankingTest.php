<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\Search\SearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PDOException;
use Tests\TestCase;

/**
 * US-019 acceptance criteria — weighted fusion of vector similarity and
 * full-text relevance controls result ordering. Postgres-only (pgvector +
 * tsvector), skips outside Sail like VectorEmbeddingPgvectorTest.
 *
 * US-047 flattens search onto a single `Product` row (no more grouping).
 */
class SearchServiceFusionRankingTest extends TestCase
{
    use RefreshDatabase;

    private const QUERY_TEXT = 'scaldabagno pompa calore';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Fusion ranking assertions require the pgsql driver.');
        }

        try {
            DB::connection()->getPdo();
        } catch (PDOException $e) {
            $this->markTestSkipped('PostgreSQL is not reachable: '.$e->getMessage());
        }

        Artisan::call('migrate', ['--force' => true]);

        Queue::fake();

        config()->set('services.embedding', [
            'base_url' => 'https://embedding.test',
            'model' => 'test-model',
            'api_key' => null,
            'dimensions' => 1024,
            'timeout' => 120,
            'retry_times' => 2,
            'retry_delay_ms' => 1,
        ]);
    }

    /**
     * Pad a short vector head to the real 1024-dimensional column width,
     * same helper as VectorEmbeddingPgvectorTest.
     *
     * @param  array<int, float>  $head
     */
    private function pad(array $head): array
    {
        return array_merge($head, array_fill(0, 1024 - count($head), 0));
    }

    /**
     * @param  array<int, float>  $vector
     */
    private function insertEmbedding(Product $product, array $vector): void
    {
        DB::table('product_embeddings')->insert([
            'product_id' => $product->id,
            'content' => 'seed',
            'content_hash' => hash('sha256', 'seed'),
            'model' => 'test-model',
            'dimensions' => 1024,
            'embedding' => '['.implode(',', $vector).']',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_default_weights_favor_vector_similarity_over_fts(): void
    {
        // High FTS relevance (product_type matches the query), but vector far from the query embedding.
        $ftsFavorite = Product::factory()->create([
            'product_type' => 'Scaldabagno a pompa di calore',
            'description_clean' => 'Scaldabagno a pompa di calore risparmio energetico',
        ]);
        $this->insertEmbedding($ftsFavorite, $this->pad([-1, 0, 0]));

        // Low FTS relevance (unrelated product_type), but vector identical to the query embedding.
        $vectorFavorite = Product::factory()->create([
            'product_type' => 'Valvola a sfera in ottone',
            'description_clean' => 'Valvola a sfera in ottone per impianti idraulici',
        ]);
        $this->insertEmbedding($vectorFavorite, $this->pad([1, 0, 0]));

        Http::fake([
            '*' => Http::response(['embedding' => $this->pad([1, 0, 0])]),
        ]);

        $results = app(SearchService::class)->search(self::QUERY_TEXT);

        $orderedIds = $results->pluck('product.id')->all();

        $this->assertSame(
            [$vectorFavorite->id, $ftsFavorite->id],
            [$orderedIds[0], $orderedIds[1]],
        );
    }

    public function test_inverted_weights_favor_fts_over_vector_similarity(): void
    {
        $ftsFavorite = Product::factory()->create([
            'product_type' => 'Scaldabagno a pompa di calore',
            'description_clean' => 'Scaldabagno a pompa di calore risparmio energetico',
        ]);
        $this->insertEmbedding($ftsFavorite, $this->pad([-1, 0, 0]));

        $vectorFavorite = Product::factory()->create([
            'product_type' => 'Valvola a sfera in ottone',
            'description_clean' => 'Valvola a sfera in ottone per impianti idraulici',
        ]);
        $this->insertEmbedding($vectorFavorite, $this->pad([1, 0, 0]));

        Http::fake([
            '*' => Http::response(['embedding' => $this->pad([1, 0, 0])]),
        ]);

        config()->set('search.weights.vector', 0.1);
        config()->set('search.weights.fts', 0.9);

        $results = app(SearchService::class)->search(self::QUERY_TEXT);

        $orderedIds = $results->pluck('product.id')->all();

        $this->assertSame(
            [$ftsFavorite->id, $vectorFavorite->id],
            [$orderedIds[0], $orderedIds[1]],
        );
    }
}
