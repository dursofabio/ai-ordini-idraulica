<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Family;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Services\Search\SearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * US-019 acceptance criteria — structured filters (brand, family, numeric
 * attribute ranges) reduce the result pool before ranking, independently of
 * the free-text/vector search term.
 *
 * US-047 flattens search onto a single `Product` row (no more grouping).
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

    public function test_brand_filter_returns_only_matching_products(): void
    {
        $brandA = Brand::factory()->create();
        $brandB = Brand::factory()->create();

        $matching = Product::factory()->create(['brand_id' => $brandA->id]);
        Product::factory()->create(['brand_id' => $brandB->id]);

        $results = app(SearchService::class)
            ->search('scaldabagno', ['brand_id' => $brandA->id]);

        $this->assertCount(1, $results);
        $this->assertSame($matching->id, $results->first()->product->id);
    }

    public function test_family_filter_returns_only_matching_products(): void
    {
        $familyA = Family::factory()->create();
        $familyB = Family::factory()->create();

        $matching = Product::factory()->create(['family_id' => $familyA->id]);
        Product::factory()->create(['family_id' => $familyB->id]);

        $results = app(SearchService::class)
            ->search('scaldabagno', ['family_id' => $familyA->id]);

        $this->assertCount(1, $results);
        $this->assertSame($matching->id, $results->first()->product->id);
    }

    public function test_attribute_range_filter_returns_only_products_within_range(): void
    {
        $inRange = Product::factory()->create();
        ProductAttribute::factory()->create([
            'product_id' => $inRange->id,
            'key' => 'potenza_kw',
            'value_num' => 2.5,
        ]);

        $outOfRange = Product::factory()->create();
        ProductAttribute::factory()->create([
            'product_id' => $outOfRange->id,
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
        $this->assertSame($inRange->id, $results->first()->product->id);
    }

    public function test_text_attribute_filter_matches_value_case_insensitively(): void
    {
        $inox = Product::factory()->create();
        ProductAttribute::factory()->create([
            'product_id' => $inox->id,
            'key' => 'materiale',
            'value_num' => null,
            'value_text' => 'INOX',
        ]);

        $pvc = Product::factory()->create();
        ProductAttribute::factory()->create([
            'product_id' => $pvc->id,
            'key' => 'materiale',
            'value_num' => null,
            'value_text' => 'PVC',
        ]);

        $results = app(SearchService::class)
            ->search('bollitore', [
                'attributes' => [
                    ['key' => 'materiale', 'value' => 'inox'],
                ],
            ]);

        $this->assertCount(1, $results);
        $this->assertSame($inox->id, $results->first()->product->id);
    }

    public function test_open_ended_attribute_range_filters_with_only_min_or_only_max(): void
    {
        $small = Product::factory()->create();
        ProductAttribute::factory()->create([
            'product_id' => $small->id,
            'key' => 'capacita_litri',
            'value_num' => 80,
        ]);

        $large = Product::factory()->create();
        ProductAttribute::factory()->create([
            'product_id' => $large->id,
            'key' => 'capacita_litri',
            'value_num' => 300,
        ]);

        $minOnly = app(SearchService::class)
            ->search('bollitore', [
                'attributes' => [
                    ['key' => 'capacita_litri', 'min' => 100],
                ],
            ]);

        $this->assertCount(1, $minOnly);
        $this->assertSame($large->id, $minOnly->first()->product->id);

        $maxOnly = app(SearchService::class)
            ->search('bollitore', [
                'attributes' => [
                    ['key' => 'capacita_litri', 'max' => 100],
                ],
            ]);

        $this->assertCount(1, $maxOnly);
        $this->assertSame($small->id, $maxOnly->first()->product->id);
    }

    public function test_text_and_numeric_attribute_filters_combine(): void
    {
        $matching = Product::factory()->create();
        ProductAttribute::factory()->create([
            'product_id' => $matching->id,
            'key' => 'materiale',
            'value_num' => null,
            'value_text' => 'INOX',
        ]);
        ProductAttribute::factory()->create([
            'product_id' => $matching->id,
            'key' => 'capacita_litri',
            'value_num' => 200,
        ]);

        $wrongCapacity = Product::factory()->create();
        ProductAttribute::factory()->create([
            'product_id' => $wrongCapacity->id,
            'key' => 'materiale',
            'value_num' => null,
            'value_text' => 'INOX',
        ]);
        ProductAttribute::factory()->create([
            'product_id' => $wrongCapacity->id,
            'key' => 'capacita_litri',
            'value_num' => 80,
        ]);

        $results = app(SearchService::class)
            ->search('bollitore', [
                'attributes' => [
                    ['key' => 'materiale', 'value' => 'INOX'],
                    ['key' => 'capacita_litri', 'min' => 100, 'max' => 500],
                ],
            ]);

        $this->assertCount(1, $results);
        $this->assertSame($matching->id, $results->first()->product->id);
    }

    public function test_combined_filters_narrow_the_pool_regardless_of_search_term(): void
    {
        $brand = Brand::factory()->create();
        $family = Family::factory()->create();

        $matching = Product::factory()->create([
            'brand_id' => $brand->id,
            'family_id' => $family->id,
        ]);

        Product::factory()->create(['brand_id' => $brand->id]);
        Product::factory()->create(['family_id' => $family->id]);
        Product::factory()->create();

        $results = app(SearchService::class)
            ->search('qualsiasi termine', [
                'brand_id' => $brand->id,
                'family_id' => $family->id,
            ]);

        $this->assertCount(1, $results);
        $this->assertSame($matching->id, $results->first()->product->id);
    }
}
