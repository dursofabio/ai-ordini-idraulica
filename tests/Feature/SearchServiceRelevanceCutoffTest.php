<?php

namespace Tests\Feature;

use App\Models\Brand;
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
 * US-019 acceptance criteria — a free-text search excludes products with no
 * real relevance signal (neither an FTS match nor a meaningful vector
 * match), instead of ranking (and returning) the entire catalog for a query
 * that matches nothing.
 *
 * US-047 flattens search onto a single `Product` row (no more grouping).
 */
class SearchServiceRelevanceCutoffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Relevance cutoff assertions require the pgsql driver.');
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

    public function test_query_with_no_fts_or_vector_signal_excludes_unrelated_results(): void
    {
        $matching = Product::factory()->create([
            'product_type' => 'Scaldabagno a pompa di calore',
            'description_clean' => 'Scaldabagno a pompa di calore risparmio energetico',
        ]);
        $this->insertEmbedding($matching, $this->pad([1, 0, 0]));

        $unrelated = Product::factory()->create([
            'product_type' => 'Valvola a sfera in ottone',
            'description_clean' => 'Valvola a sfera in ottone per impianti idraulici',
        ]);
        $this->insertEmbedding($unrelated, $this->pad([-1, 0, 0]));

        Http::fake([
            '*' => Http::response(['embedding' => $this->pad([1, 0, 0])]),
        ]);

        $results = app(SearchService::class)->search('scaldabagno pompa calore');

        $this->assertCount(1, $results);
        $this->assertSame($matching->id, $results->first()->product->id);
    }

    public function test_query_matching_nothing_returns_no_results(): void
    {
        $product = Product::factory()->create([
            'product_type' => 'Valvola a sfera in ottone',
            'description_clean' => 'Valvola a sfera in ottone per impianti idraulici',
        ]);
        $this->insertEmbedding($product, $this->pad([-1, 0, 0]));

        Http::fake([
            '*' => Http::response(['embedding' => $this->pad([1, 0, 0])]),
        ]);

        $results = app(SearchService::class)->search('scaldabagno pompa di calore');

        $this->assertCount(0, $results);
    }

    public function test_cutoff_is_skipped_when_a_structured_filter_is_applied(): void
    {
        $brand = Brand::factory()->create();

        $product = Product::factory()->create([
            'brand_id' => $brand->id,
            'product_type' => 'Valvola a sfera in ottone',
            'description_clean' => 'Valvola a sfera in ottone per impianti idraulici',
        ]);
        $this->insertEmbedding($product, $this->pad([-1, 0, 0]));

        Http::fake([
            '*' => Http::response(['embedding' => $this->pad([1, 0, 0])]),
        ]);

        $results = app(SearchService::class)->search('scaldabagno pompa di calore', [
            'brand_id' => $brand->id,
        ]);

        $this->assertCount(1, $results);
        $this->assertSame($product->id, $results->first()->product->id);
    }

    public function test_cutoff_is_skipped_for_a_blank_query(): void
    {
        $brand = Brand::factory()->create();

        $product = Product::factory()->create([
            'brand_id' => $brand->id,
            'product_type' => 'Valvola a sfera in ottone',
            'description_clean' => 'Valvola a sfera in ottone per impianti idraulici',
        ]);
        $this->insertEmbedding($product, $this->pad([-1, 0, 0]));

        Http::fake([
            '*' => Http::response(['embedding' => $this->pad([1, 0, 0])]),
        ]);

        $results = app(SearchService::class)->search('', [
            'brand_id' => $brand->id,
        ]);

        $this->assertCount(1, $results);
    }
}
