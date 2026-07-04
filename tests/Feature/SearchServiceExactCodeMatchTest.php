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
 * US-019 acceptance criteria — a search by exact `codice_articolo` returns
 * that product first, even when another product scores higher on the fused
 * vector/FTS ranking.
 *
 * US-047 flattens search onto a single `Product` row (no more grouping).
 */
class SearchServiceExactCodeMatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Exact code match assertions require the pgsql driver.');
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

    public function test_exact_code_match_is_first_result_despite_lower_semantic_score(): void
    {
        // Semantically unrelated to the search query, but matched by exact code.
        $exactMatch = Product::factory()->create([
            'product_type' => 'Valvola a sfera in ottone',
            'description_clean' => 'Valvola a sfera in ottone per impianti idraulici',
            'codice_articolo' => 'ABC-12345',
        ]);
        $this->insertEmbedding($exactMatch, $this->pad([-1, 0, 0]));

        // Highly relevant semantically, embedding identical to the query embedding.
        $semanticFavorite = Product::factory()->create([
            'product_type' => 'Scaldabagno a pompa di calore',
            'description_clean' => 'Scaldabagno a pompa di calore risparmio energetico',
        ]);
        $this->insertEmbedding($semanticFavorite, $this->pad([1, 0, 0]));

        Http::fake([
            '*' => Http::response(['embedding' => $this->pad([1, 0, 0])]),
        ]);

        $results = app(SearchService::class)->search('ABC-12345');

        $this->assertSame($exactMatch->id, $results->first()->product->id);
    }

    public function test_exact_code_match_is_case_insensitive(): void
    {
        $exactMatch = Product::factory()->create([
            'product_type' => 'Valvola a sfera in ottone',
            'description_clean' => 'Valvola a sfera in ottone per impianti idraulici',
            'codice_articolo' => 'XYZ-99999',
        ]);
        $this->insertEmbedding($exactMatch, $this->pad([-1, 0, 0]));

        Http::fake([
            '*' => Http::response(['embedding' => $this->pad([1, 0, 0])]),
        ]);

        $results = app(SearchService::class)->search('xyz-99999');

        $this->assertSame($exactMatch->id, $results->first()->product->id);
    }
}
