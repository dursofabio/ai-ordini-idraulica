<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Family;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductBase;
use App\Services\Search\SearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * US-019 acceptance criteria — structured filters (brand, family, numeric
 * attribute ranges) reduce the result pool before ranking, independently of
 * the free-text/vector search term.
 */
class SearchServiceFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        config()->set('services.embedding', [
            'base_url' => 'https://embedding.test',
            'model' => 'bge-m3',
            'api_key' => null,
            'dimensions' => 4,
            'timeout' => 120,
            'retry_times' => 2,
            'retry_delay_ms' => 1,
        ]);

        Http::fake([
            '*' => Http::response(['embedding' => [0.1, 0.2, 0.3, 0.4]]),
        ]);
    }

    public function test_brand_filter_returns_only_matching_product_bases(): void
    {
        $brandA = Brand::factory()->create();
        $brandB = Brand::factory()->create();

        $matching = ProductBase::factory()->create(['brand_id' => $brandA->id]);
        ProductBase::factory()->create(['brand_id' => $brandB->id]);

        $results = app(SearchService::class)
            ->search('scaldabagno', ['brand_id' => $brandA->id]);

        $this->assertCount(1, $results);
        $this->assertSame($matching->id, $results->first()->productBase->id);
    }

    public function test_family_filter_returns_only_matching_product_bases(): void
    {
        $familyA = Family::factory()->create();
        $familyB = Family::factory()->create();

        $matching = ProductBase::factory()->create(['family_id' => $familyA->id]);
        ProductBase::factory()->create(['family_id' => $familyB->id]);

        $results = app(SearchService::class)
            ->search('scaldabagno', ['family_id' => $familyA->id]);

        $this->assertCount(1, $results);
        $this->assertSame($matching->id, $results->first()->productBase->id);
    }

    public function test_attribute_range_filter_returns_only_product_bases_within_range(): void
    {
        $inRange = ProductBase::factory()->create();
        $product = Product::factory()->create(['product_base_id' => $inRange->id]);
        ProductAttribute::factory()->create([
            'product_id' => $product->id,
            'key' => 'potenza_kw',
            'value_num' => 2.5,
        ]);

        $outOfRange = ProductBase::factory()->create();
        $otherProduct = Product::factory()->create(['product_base_id' => $outOfRange->id]);
        ProductAttribute::factory()->create([
            'product_id' => $otherProduct->id,
            'key' => 'potenza_kw',
            'value_num' => 10,
        ]);

        $results = app(SearchService::class)
            ->search('scaldabagno', [
                'attributes' => [
                    ['key' => 'potenza_kw', 'min' => 1, 'max' => 3],
                ],
            ]);

        $this->assertCount(1, $results);
        $this->assertSame($inRange->id, $results->first()->productBase->id);
    }

    public function test_combined_filters_narrow_the_pool_regardless_of_search_term(): void
    {
        $brand = Brand::factory()->create();
        $family = Family::factory()->create();

        $matching = ProductBase::factory()->create([
            'brand_id' => $brand->id,
            'family_id' => $family->id,
        ]);

        ProductBase::factory()->create(['brand_id' => $brand->id]);
        ProductBase::factory()->create(['family_id' => $family->id]);
        ProductBase::factory()->create();

        $results = app(SearchService::class)
            ->search('qualsiasi termine', [
                'brand_id' => $brand->id,
                'family_id' => $family->id,
            ]);

        $this->assertCount(1, $results);
        $this->assertSame($matching->id, $results->first()->productBase->id);
    }
}
