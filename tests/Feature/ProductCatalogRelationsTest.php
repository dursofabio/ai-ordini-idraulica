<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Family;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Subfamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-004 acceptance criteria — relations, range queries and factories:
 *  - Product belongsTo Brand/Family/Subfamily and hasMany attributes.
 *  - ProductAttribute belongsTo Product.
 *  - Attributes can be queried by key and numeric value range (the "Demonstrates" flow).
 *  - Factories produce valid, persistable models.
 *
 * US-047 drops `ProductBase`/`product_base_id` entirely (search operates
 * flat on `Product` now).
 *
 * Runs against in-memory SQLite via RequiresDatabase.
 */
class ProductCatalogRelationsTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_product_belongs_to_taxonomy(): void
    {
        $brand = Brand::factory()->create();
        $family = Family::factory()->create();
        $subfamily = Subfamily::factory()->create();

        $product = Product::factory()->create([
            'brand_id' => $brand->id,
            'family_id' => $family->id,
            'subfamily_id' => $subfamily->id,
        ]);

        $this->assertInstanceOf(Brand::class, $product->brand);
        $this->assertInstanceOf(Family::class, $product->family);
        $this->assertInstanceOf(Subfamily::class, $product->subfamily);
    }

    public function test_product_foreign_keys_are_nullable(): void
    {
        $product = Product::factory()->create([
            'brand_id' => null,
            'family_id' => null,
            'subfamily_id' => null,
        ]);

        $fresh = $product->fresh();

        $this->assertNull($fresh->brand);
        $this->assertNull($fresh->family);
        $this->assertNull($fresh->subfamily);
    }

    public function test_product_has_many_attributes(): void
    {
        $product = Product::factory()->create();
        ProductAttribute::factory()->count(4)->create(['product_id' => $product->id]);

        $this->assertCount(4, $product->attributes);
        $this->assertInstanceOf(ProductAttribute::class, $product->attributes->first());
    }

    public function test_product_attribute_belongs_to_product(): void
    {
        $product = Product::factory()->create();
        $attribute = ProductAttribute::factory()->create(['product_id' => $product->id]);

        $this->assertInstanceOf(Product::class, $attribute->product);
        $this->assertSame($product->id, $attribute->product->id);
    }

    public function test_deleting_product_cascades_to_attributes(): void
    {
        $product = Product::factory()->create();
        ProductAttribute::factory()->count(2)->create(['product_id' => $product->id]);

        $product->delete();

        $this->assertDatabaseCount('product_attributes', 0);
    }

    public function test_attributes_can_be_queried_by_key_and_numeric_range(): void
    {
        $inRangeA = Product::factory()->create();
        $inRangeB = Product::factory()->create();
        $outOfRange = Product::factory()->create();

        ProductAttribute::factory()->create([
            'product_id' => $inRangeA->id, 'key' => 'potenza_kw', 'value_num' => 3.5,
        ]);
        ProductAttribute::factory()->create([
            'product_id' => $inRangeB->id, 'key' => 'potenza_kw', 'value_num' => 5.0,
        ]);
        ProductAttribute::factory()->create([
            'product_id' => $outOfRange->id, 'key' => 'potenza_kw', 'value_num' => 12.0,
        ]);
        // Same range but different key — must be excluded.
        ProductAttribute::factory()->create([
            'product_id' => $outOfRange->id, 'key' => 'capacita_litri', 'value_num' => 4.0,
        ]);

        $matchedProductIds = ProductAttribute::query()
            ->where('key', 'potenza_kw')
            ->whereBetween('value_num', [2, 6])
            ->pluck('product_id')
            ->all();

        $this->assertEqualsCanonicalizing(
            [$inRangeA->id, $inRangeB->id],
            $matchedProductIds,
        );
    }

    public function test_factories_produce_persistable_models(): void
    {
        $product = Product::factory()->create();
        $attribute = ProductAttribute::factory()->create();

        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertDatabaseHas('product_attributes', ['id' => $attribute->id]);

        $this->assertNotEmpty($product->codice_articolo);
        $this->assertInstanceOf(Product::class, $attribute->product);
    }
}
