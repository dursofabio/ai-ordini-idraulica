<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\Ai\ClassifiedProduct;
use App\Services\Enrichment\EnrichmentCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-016 acceptance criteria — enrichment cache keyed by description_raw
 * hash:
 *  - put() followed by get() on the same description_raw returns the same
 *    ClassifiedProduct.
 *  - get() on a description never cached returns null.
 *  - Two products with identical description_raw share the same cache hit.
 *  - A different description_raw produces a miss.
 */
class EnrichmentCacheTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
    }

    public function test_put_then_get_returns_the_same_result(): void
    {
        $product = Product::factory()->create(['description_raw' => 'Miscelatore lavabo Grohe cromato']);
        $result = $this->classifiedProduct($product->codice_articolo);

        $cache = new EnrichmentCache;
        $cache->put($product, $result);

        $cached = $cache->get($product);

        $this->assertNotNull($cached);
        $this->assertSame($result->brand, $cached->brand);
        $this->assertSame($result->codiceArticolo, $cached->codiceArticolo);
    }

    public function test_get_on_never_cached_description_returns_null(): void
    {
        $product = Product::factory()->create(['description_raw' => 'Descrizione mai cacheata']);

        $this->assertNull((new EnrichmentCache)->get($product));
    }

    public function test_two_products_with_identical_description_share_the_same_cache_hit(): void
    {
        $descriptione = 'Valvola a sfera 1/2 pollice ottone';

        $firstProduct = Product::factory()->create(['description_raw' => $descriptione]);
        $secondProduct = Product::factory()->create(['description_raw' => $descriptione]);

        $cache = new EnrichmentCache;
        $cache->put($firstProduct, $this->classifiedProduct($firstProduct->codice_articolo));

        $cached = $cache->get($secondProduct);

        $this->assertNotNull($cached);
        $this->assertSame($firstProduct->codice_articolo, $cached->codiceArticolo);
    }

    public function test_a_different_description_raw_produces_a_miss(): void
    {
        $product = Product::factory()->create(['description_raw' => 'Descrizione originale']);

        $cache = new EnrichmentCache;
        $cache->put($product, $this->classifiedProduct($product->codice_articolo));

        $product->description_raw = 'Descrizione modificata';

        $this->assertNull($cache->get($product));
    }

    private function classifiedProduct(string $codiceArticolo): ClassifiedProduct
    {
        return new ClassifiedProduct(
            codiceArticolo: $codiceArticolo,
            brand: 'Grohe',
            family: null,
            subfamily: null,
            productType: 'Miscelatore',
            enrichedDescription: 'Descrizione arricchita',
            confidence: 90,
        );
    }
}
