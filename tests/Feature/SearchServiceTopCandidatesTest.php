<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\Search\MatchOutcomeResolver;
use App\Services\Search\SearchResult;
use App\Services\Search\SearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PDOException;
use Tests\TestCase;

/**
 * US-049 TASK-03 — {@see SearchService::topCandidates()}: same ranking as
 * {@see SearchService::search()}/{@see SearchService::paginate()}, capped to
 * a limit with no offset, and each {@see SearchResult}
 * carries the raw `vector_score` plus the exact-code-match flag that
 * {@see MatchOutcomeResolver} (US-049) needs.
 *
 * Postgres-only (pgvector + tsvector), same defensive skip as
 * SearchServiceFusionRankingTest.
 */
class SearchServiceTopCandidatesTest extends TestCase
{
    use RefreshDatabase;

    private const QUERY_TEXT = 'scaldabagno pompa calore';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('topCandidates() assertions require the pgsql driver.');
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
     * @param  array<int, float>  $head
     * @return array<int, float>
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

    public function test_limit_is_respected(): void
    {
        foreach (range(1, 4) as $i) {
            $product = Product::factory()->create([
                'product_type' => 'Scaldabagno a pompa di calore modello '.$i,
                'description_clean' => 'Scaldabagno a pompa di calore risparmio energetico',
            ]);
            $this->insertEmbedding($product, $this->pad([1 - ($i * 0.1), 0, 0]));
        }

        Http::fake([
            '*' => Http::response(['embedding' => $this->pad([1, 0, 0])]),
        ]);

        $candidates = app(SearchService::class)->topCandidates(self::QUERY_TEXT, [], 2);

        $this->assertCount(2, $candidates);
    }

    public function test_ordering_is_consistent_with_search(): void
    {
        // Same fixture as SearchServiceFusionRankingTest: each product wins
        // on exactly one signal (FTS or vector), so both clear the relevance
        // cutoff and stay in the ranked results — letting this test compare
        // a genuine multi-row ordering instead of a single survivor.
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

        $searchOrder = app(SearchService::class)->search(self::QUERY_TEXT)->pluck('product.id')->all();
        $candidateOrder = app(SearchService::class)->topCandidates(self::QUERY_TEXT, [], 5)->pluck('product.id')->all();

        $this->assertSame($searchOrder, $candidateOrder);
        $this->assertSame([$vectorFavorite->id, $ftsFavorite->id], $candidateOrder);
    }

    public function test_vector_score_is_populated_per_candidate(): void
    {
        $product = Product::factory()->create([
            'product_type' => 'Scaldabagno a pompa di calore',
            'description_clean' => 'Scaldabagno a pompa di calore risparmio energetico',
        ]);
        $this->insertEmbedding($product, $this->pad([1, 0, 0]));

        Http::fake([
            '*' => Http::response(['embedding' => $this->pad([1, 0, 0])]),
        ]);

        $candidates = app(SearchService::class)->topCandidates(self::QUERY_TEXT, [], 5);

        $this->assertNotNull($candidates->first()->vectorScore);
        $this->assertEqualsWithDelta(1.0, $candidates->first()->vectorScore, 0.0001);
    }

    public function test_is_exact_code_match_is_true_only_for_the_matching_product(): void
    {
        $exactMatch = Product::factory()->create([
            'product_type' => 'Valvola a sfera in ottone',
            'description_clean' => 'Valvola a sfera in ottone per impianti idraulici',
            'codice_articolo' => 'ABC-12345',
        ]);
        $this->insertEmbedding($exactMatch, $this->pad([-1, 0, 0]));

        $semanticFavorite = Product::factory()->create([
            'product_type' => 'Scaldabagno a pompa di calore',
            'description_clean' => 'Scaldabagno a pompa di calore risparmio energetico',
        ]);
        $this->insertEmbedding($semanticFavorite, $this->pad([1, 0, 0]));

        Http::fake([
            '*' => Http::response(['embedding' => $this->pad([1, 0, 0])]),
        ]);

        $candidates = app(SearchService::class)->topCandidates('ABC-12345', [], 5);

        $byId = $candidates->keyBy('product.id');

        $this->assertTrue($byId->get($exactMatch->id)->isExactCodeMatch);
        $this->assertFalse($byId->get($semanticFavorite->id)->isExactCodeMatch);
    }
}
