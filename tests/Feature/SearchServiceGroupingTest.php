<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductAttribute;
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
 * US-019 acceptance criteria — results are grouped by `product_base_id`
 * (one row per base, not per variant), enriched with `variants_count` and
 * `power_range` computed across all of that base's variants.
 */
class SearchServiceGroupingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Grouping assertions require the pgsql driver.');
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

    public function test_product_base_with_multiple_variants_appears_once_with_correct_grouping_meta(): void
    {
        $productBase = ProductBase::factory()->create([
            'title' => 'Scaldabagno a pompa di calore',
            'description_ai' => 'Scaldabagno a pompa di calore risparmio energetico',
        ]);

        $variant1 = Product::factory()->create(['product_base_id' => $productBase->id]);
        ProductAttribute::factory()->create([
            'product_id' => $variant1->id,
            'key' => 'potenza_kw',
            'value_num' => 1.5,
        ]);

        $variant2 = Product::factory()->create(['product_base_id' => $productBase->id]);
        ProductAttribute::factory()->create([
            'product_id' => $variant2->id,
            'key' => 'potenza_kw',
            'value_num' => 3.0,
        ]);

        $variant3 = Product::factory()->create(['product_base_id' => $productBase->id]);
        ProductAttribute::factory()->create([
            'product_id' => $variant3->id,
            'key' => 'potenza_kw',
            'value_num' => 2.0,
        ]);

        $results = app(SearchService::class)->search('scaldabagno a pompa di calore');

        $matches = $results->filter(fn ($result) => $result->productBase->id === $productBase->id);

        $this->assertCount(1, $matches, 'Product-base should appear exactly once, not once per variant.');

        $result = $matches->first();

        $this->assertSame(3, $result->variantsCount);
        $this->assertEqualsWithDelta(1.5, $result->powerRangeMin, 0.001);
        $this->assertEqualsWithDelta(3.0, $result->powerRangeMax, 0.001);
    }

    public function test_product_base_without_power_attribute_has_null_power_range(): void
    {
        $productBase = ProductBase::factory()->create([
            'title' => 'Valvola a sfera in ottone',
            'description_ai' => 'Valvola a sfera in ottone per impianti idraulici',
        ]);

        Product::factory()->create(['product_base_id' => $productBase->id]);

        $results = app(SearchService::class)->search('valvola a sfera in ottone');

        $result = $results->first(fn ($result) => $result->productBase->id === $productBase->id);

        $this->assertNotNull($result);
        $this->assertSame(1, $result->variantsCount);
        $this->assertNull($result->powerRangeMin);
        $this->assertNull($result->powerRangeMax);
    }
}
