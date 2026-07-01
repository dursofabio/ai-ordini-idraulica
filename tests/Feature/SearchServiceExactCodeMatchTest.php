<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductBase;
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
 * that product's product-base first, even when another product-base scores
 * higher on the fused vector/FTS ranking.
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

    private function insertEmbedding(ProductBase $productBase, array $vector): void
    {
        DB::table('product_embeddings')->insert([
            'product_base_id' => $productBase->id,
            'content' => 'seed',
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
        $exactMatchBase = ProductBase::factory()->create([
            'title' => 'Valvola a sfera in ottone',
            'description_ai' => 'Valvola a sfera in ottone per impianti idraulici',
        ]);
        Product::factory()->create([
            'product_base_id' => $exactMatchBase->id,
            'codice_articolo' => 'ABC-12345',
        ]);
        $this->insertEmbedding($exactMatchBase, $this->pad([-1, 0, 0]));

        // Highly relevant semantically, embedding identical to the query embedding.
        $semanticFavorite = ProductBase::factory()->create([
            'title' => 'Scaldabagno a pompa di calore',
            'description_ai' => 'Scaldabagno a pompa di calore risparmio energetico',
        ]);
        $this->insertEmbedding($semanticFavorite, $this->pad([1, 0, 0]));

        Http::fake([
            '*' => Http::response(['embedding' => $this->pad([1, 0, 0])]),
        ]);

        $results = app(SearchService::class)->search('ABC-12345');

        $this->assertSame($exactMatchBase->id, $results->first()->productBase->id);
    }

    public function test_exact_code_match_is_case_insensitive(): void
    {
        $exactMatchBase = ProductBase::factory()->create([
            'title' => 'Valvola a sfera in ottone',
            'description_ai' => 'Valvola a sfera in ottone per impianti idraulici',
        ]);
        Product::factory()->create([
            'product_base_id' => $exactMatchBase->id,
            'codice_articolo' => 'XYZ-99999',
        ]);
        $this->insertEmbedding($exactMatchBase, $this->pad([-1, 0, 0]));

        Http::fake([
            '*' => Http::response(['embedding' => $this->pad([1, 0, 0])]),
        ]);

        $results = app(SearchService::class)->search('xyz-99999');

        $this->assertSame($exactMatchBase->id, $results->first()->productBase->id);
    }
}
