<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductBase;
use App\Services\Enrichment\GroupingResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-011 acceptance criteria — deterministic grouping of product variants:
 *  - Variants with the same brand + type + series produce the same
 *    grouping_key (deterministic hash of brand + normalized description).
 *  - The size token (e.g. `025`, `035`) is excluded from the grouping_key
 *    computation, so same-series variants collapse to one key.
 *  - A product_base is created or reused per distinct group, with a
 *    readable title.
 *  - All variants of the group are assigned the same product_base_id.
 *  - A product without a resolved brand is left unassigned.
 *  - Resolving an already-assigned product is a no-op (idempotency).
 *
 * Runs against in-memory SQLite via RequiresDatabase, matching the
 * BrandResolverTest/AttributeResolverTest pattern.
 */
class GroupingResolverTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_same_brand_type_and_series_with_different_sizes_produce_the_same_grouping_key(): void
    {
        $brand = Brand::factory()->create(['name' => 'Vaillant']);

        $small = Product::factory()->create([
            'description_clean' => 'VAI 8-025 WNI',
            'brand_id' => $brand->id,
            'product_base_id' => null,
        ]);
        $large = Product::factory()->create([
            'description_clean' => 'VAI 8-065 WNI',
            'brand_id' => $brand->id,
            'product_base_id' => null,
        ]);

        (new GroupingResolver)->resolve($small);
        (new GroupingResolver)->resolve($large);

        $small->refresh();
        $large->refresh();
        $this->assertNotNull($small->grouping_key);
        $this->assertSame($small->grouping_key, $large->grouping_key);
    }

    public function test_grouping_key_does_not_change_when_size_token_differs(): void
    {
        $brand = Brand::factory()->create(['name' => 'Vaillant']);
        $resolver = new GroupingResolver;

        $normalizedSmall = $resolver->normalizeForGrouping('VAI 8-025 WNI');
        $normalizedLarge = $resolver->normalizeForGrouping('VAI 8-065 WNI');

        $this->assertSame($normalizedSmall, $normalizedLarge);
        $this->assertStringNotContainsString('025', $normalizedSmall);
        $this->assertStringNotContainsString('065', $normalizedLarge);

        // Sanity check the resolver actually persists a key derived from it.
        $product = Product::factory()->create([
            'description_clean' => 'VAI 8-025 WNI',
            'brand_id' => $brand->id,
            'product_base_id' => null,
        ]);
        $resolver->resolve($product);
        $product->refresh();
        $this->assertStringNotContainsString('025', $product->grouping_key);
    }

    public function test_different_brand_produces_a_different_grouping_key(): void
    {
        $vaillant = Brand::factory()->create(['name' => 'Vaillant']);
        $ariston = Brand::factory()->create(['name' => 'Ariston']);

        $vaillantProduct = Product::factory()->create([
            'description_clean' => 'VAI 8-025 WNI',
            'brand_id' => $vaillant->id,
            'product_base_id' => null,
        ]);
        $aristonProduct = Product::factory()->create([
            'description_clean' => 'VAI 8-025 WNI',
            'brand_id' => $ariston->id,
            'product_base_id' => null,
        ]);

        (new GroupingResolver)->resolve($vaillantProduct);
        (new GroupingResolver)->resolve($aristonProduct);

        $vaillantProduct->refresh();
        $aristonProduct->refresh();
        $this->assertNotSame($vaillantProduct->grouping_key, $aristonProduct->grouping_key);
    }

    public function test_different_series_produces_a_different_grouping_key(): void
    {
        $brand = Brand::factory()->create(['name' => 'Vaillant']);

        $seriesEight = Product::factory()->create([
            'description_clean' => 'VAI 8-025 WNI',
            'brand_id' => $brand->id,
            'product_base_id' => null,
        ]);
        $seriesTwelve = Product::factory()->create([
            'description_clean' => 'VAI 12-025 WNI',
            'brand_id' => $brand->id,
            'product_base_id' => null,
        ]);

        (new GroupingResolver)->resolve($seriesEight);
        (new GroupingResolver)->resolve($seriesTwelve);

        $seriesEight->refresh();
        $seriesTwelve->refresh();
        $this->assertNotSame($seriesEight->grouping_key, $seriesTwelve->grouping_key);
    }

    public function test_creates_a_product_base_with_a_readable_title_for_a_new_grouping_key(): void
    {
        $brand = Brand::factory()->create(['name' => 'Vaillant']);
        $product = Product::factory()->create([
            'description_clean' => 'VAI 8-025 WNI',
            'brand_id' => $brand->id,
            'product_base_id' => null,
        ]);

        $resolved = (new GroupingResolver)->resolve($product);

        $this->assertTrue($resolved);
        $product->refresh();
        $this->assertNotNull($product->product_base_id);
        $base = ProductBase::find($product->product_base_id);
        $this->assertNotNull($base);
        $this->assertSame('Vaillant Vai 8 Wni', $base->title);
        $this->assertSame($brand->id, $base->brand_id);
    }

    public function test_reuses_the_existing_product_base_for_an_already_seen_grouping_key(): void
    {
        $brand = Brand::factory()->create(['name' => 'Vaillant']);
        $first = Product::factory()->create([
            'description_clean' => 'VAI 8-025 WNI',
            'brand_id' => $brand->id,
            'product_base_id' => null,
        ]);
        $second = Product::factory()->create([
            'description_clean' => 'VAI 8-065 WNI',
            'brand_id' => $brand->id,
            'product_base_id' => null,
        ]);

        (new GroupingResolver)->resolve($first);
        (new GroupingResolver)->resolve($second);

        $first->refresh();
        $second->refresh();
        $this->assertSame($first->product_base_id, $second->product_base_id);
        $this->assertSame(1, ProductBase::query()->where('grouping_key', $first->grouping_key)->count());
    }

    public function test_leaves_product_base_id_null_when_brand_is_not_resolved(): void
    {
        $product = Product::factory()->create([
            'description_clean' => 'VAI 8-025 WNI',
            'brand_id' => null,
            'product_base_id' => null,
        ]);

        $resolved = (new GroupingResolver)->resolve($product);

        $this->assertFalse($resolved);
        $product->refresh();
        $this->assertNull($product->product_base_id);
    }

    public function test_does_not_reassign_a_product_that_already_has_a_product_base(): void
    {
        $brand = Brand::factory()->create(['name' => 'Vaillant']);
        $existingBase = ProductBase::factory()->create();
        $product = Product::factory()->create([
            'description_clean' => 'VAI 8-025 WNI',
            'brand_id' => $brand->id,
            'product_base_id' => $existingBase->id,
            'grouping_key' => 'already-set',
        ]);

        $resolved = (new GroupingResolver)->resolve($product);

        $this->assertFalse($resolved);
        $product->refresh();
        $this->assertSame($existingBase->id, $product->product_base_id);
        $this->assertSame('already-set', $product->grouping_key);
        $this->assertSame(1, ProductBase::query()->count());
    }

    public function test_vaillant_vai_8_wni_series_with_four_sizes_share_the_same_product_base(): void
    {
        $brand = Brand::factory()->create(['name' => 'Vaillant']);
        $resolver = new GroupingResolver;

        $variants = collect(['025', '035', '050', '065'])->map(
            fn (string $size) => Product::factory()->create([
                'description_clean' => "VAI 8-{$size} WNI",
                'brand_id' => $brand->id,
                'product_base_id' => null,
            ])
        );

        $variants->each(fn (Product $product) => $resolver->resolve($product));
        $variants = $variants->map(fn (Product $product) => $product->refresh());

        $productBaseIds = $variants->pluck('product_base_id')->unique();
        $this->assertCount(1, $productBaseIds);

        $base = ProductBase::find($productBaseIds->first());
        $this->assertNotNull($base);
        $this->assertSame('Vaillant Vai 8 Wni', $base->title);
        $this->assertStringNotContainsString('025', $base->title);
        $this->assertStringNotContainsString('035', $base->title);
        $this->assertStringNotContainsString('050', $base->title);
        $this->assertStringNotContainsString('065', $base->title);
    }
}
