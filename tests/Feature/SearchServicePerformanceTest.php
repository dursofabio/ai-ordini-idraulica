<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Family;
use App\Models\Subfamily;
use App\Services\Search\SearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PDOException;
use Tests\TestCase;

/**
 * US-019 acceptance criteria — the search query performs a fixed, small
 * number of database queries regardless of the result set size (no N+1),
 * and completes within a CI-appropriate time budget on a moderate seed.
 *
 * The spec's real target (p95 < 300ms on 42k products with an active HNSW
 * index) is not reproducible reliably in CI and must be validated in
 * staging/production instead; this test only guards against gross
 * regressions (N+1 queries, missing indexes) on a much smaller seed.
 *
 * US-047 flattens search onto a single `Product` row (no more grouping).
 */
class SearchServicePerformanceTest extends TestCase
{
    use RefreshDatabase;

    private const SEED_COUNT = 300;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Performance assertions require the pgsql driver.');
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

        Http::fake([
            '*' => Http::response(['embedding' => array_fill(0, 1024, 0.1)]),
        ]);
    }

    public function test_search_runs_a_fixed_low_number_of_queries_and_within_ci_time_budget(): void
    {
        $this->seedProducts(self::SEED_COUNT);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $start = microtime(true);

        $results = app(SearchService::class)->search('scaldabagno pompa di calore');

        $elapsedMs = (microtime(true) - $start) * 1000;

        // 1 query to resolve an exact codice_articolo match (none expected here)
        // + 1 main ranked query. Must not scale with the result count.
        $this->assertLessThanOrEqual(3, $queryCount, 'Search should not issue N+1 queries.');

        // Generous CI budget — the real p95 < 300ms target (42k rows, HNSW
        // index) is validated in staging/production, not here.
        $this->assertLessThan(5000, $elapsedMs, 'Search took too long on a moderate seed.');

        $this->assertGreaterThan(0, $results->count());
    }

    private function seedProducts(int $count): void
    {
        $brandId = Brand::factory()->create()->id;
        $familyId = Family::factory()->create()->id;
        $subfamilyId = Subfamily::factory()->create()->id;

        $now = now();
        $productRows = [];

        for ($i = 0; $i < $count; $i++) {
            $productRows[] = [
                'codice_articolo' => "PERF-{$i}",
                'product_type' => "Scaldabagno modello {$i}",
                'description_clean' => "Scaldabagno a pompa di calore modello {$i} risparmio energetico",
                'brand_id' => $brandId,
                'family_id' => $familyId,
                'subfamily_id' => $subfamilyId,
                'is_active' => true,
                'enrichment_status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('products')->insert($productRows);

        $productIds = DB::table('products')
            ->where('codice_articolo', 'like', 'PERF-%')
            ->pluck('id');

        $vector = '['.implode(',', array_fill(0, 1024, 0.1)).']';
        $embeddingRows = $productIds->map(fn ($productId) => [
            'product_id' => $productId,
            'content' => 'seed',
            'content_hash' => hash('sha256', 'seed'),
            'model' => 'test-model',
            'dimensions' => 1024,
            'embedding' => $vector,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        DB::table('product_embeddings')->insert($embeddingRows);
    }
}
