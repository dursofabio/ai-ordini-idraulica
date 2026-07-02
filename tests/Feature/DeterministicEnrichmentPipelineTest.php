<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Family;
use App\Models\Product;
use App\Models\ProductBase;
use App\Services\Enrichment\AttributeResolver;
use App\Services\Enrichment\BrandResolver;
use App\Services\Enrichment\DeterministicEnrichmentPipeline;
use App\Services\Enrichment\FamilyPropagationResolver;
use App\Services\Enrichment\GroupingResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-026 acceptance criteria — Step A orchestration:
 *  - Runs brand resolution before grouping (grouping requires a resolved
 *    brand), and family propagation only after a product_base is assigned.
 *  - Returns accurate counts for each resolver stage.
 *  - No-ops (all counts zero) for a product that is not enrichment_status
 *    pending.
 *
 * Runs against in-memory SQLite via RequiresDatabase, matching the sibling
 * resolver test suites.
 */
class DeterministicEnrichmentPipelineTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    private function pipeline(): DeterministicEnrichmentPipeline
    {
        return new DeterministicEnrichmentPipeline(
            new BrandResolver,
            new AttributeResolver,
            new GroupingResolver,
            new FamilyPropagationResolver,
        );
    }

    public function test_resolves_brand_before_grouping_so_a_product_base_is_assigned(): void
    {
        Brand::factory()->create(['name' => 'Vaillant', 'aliases' => ['VAI']]);

        $product = Product::factory()->create([
            'description_raw' => 'CALDAIA VAI 8-025 WNI 3.5KW',
            'description_clean' => null,
            'enrichment_status' => 'pending',
            'brand_id' => null,
            'product_base_id' => null,
        ]);

        $summary = $this->pipeline()->run($product);

        $product->refresh();
        $this->assertNotNull($product->brand_id, 'Brand should be resolved before grouping runs.');
        $this->assertNotNull($product->product_base_id, 'Grouping should succeed once the brand is resolved.');
        $this->assertSame(1, $summary['brands_resolved']);
        $this->assertSame(1, $summary['groups_resolved']);
        $this->assertGreaterThan(0, $summary['attributes_written']);
    }

    public function test_propagates_family_to_sibling_variant_after_grouping(): void
    {
        $brand = Brand::factory()->create(['name' => 'Vaillant', 'aliases' => ['VAI']]);
        $family = Family::factory()->create();

        $base = ProductBase::factory()->create([
            'grouping_key' => hash('sha256', $brand->id.'|VAI 8 WNI'),
            'brand_id' => $brand->id,
        ]);

        // Sibling already in the group, carrying the family that should
        // propagate to the pending product once it joins the same base.
        Product::factory()->create([
            'description_clean' => 'VAI 8-035 WNI',
            'brand_id' => $brand->id,
            'product_base_id' => $base->id,
            'grouping_key' => $base->grouping_key,
            'family_id' => $family->id,
            'enrichment_status' => 'enriched',
        ]);

        $product = Product::factory()->create([
            'description_raw' => 'VAI 8-025 WNI',
            'description_clean' => null,
            'enrichment_status' => 'pending',
            'brand_id' => $brand->id,
            'product_base_id' => null,
            'family_id' => null,
        ]);

        $summary = $this->pipeline()->run($product);

        $product->refresh();
        $this->assertSame($base->id, $product->product_base_id);
        $this->assertSame($family->id, $product->family_id);
        $this->assertSame('propagated', $product->family_source);
        $this->assertGreaterThan(0, $summary['families_propagated']);
    }

    public function test_is_a_noop_for_a_product_that_is_not_pending(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'CALDAIA VAI 8-025 WNI',
            'enrichment_status' => 'enriched',
            'brand_id' => null,
            'product_base_id' => null,
        ]);

        $summary = $this->pipeline()->run($product);

        $product->refresh();
        $this->assertNull($product->brand_id);
        $this->assertNull($product->product_base_id);
        $this->assertSame(0, $summary['brands_resolved']);
        $this->assertSame(0, $summary['attributes_written']);
        $this->assertSame(0, $summary['groups_resolved']);
        $this->assertSame(0, $summary['families_propagated']);
    }

    public function test_is_a_noop_for_an_already_enriched_product(): void
    {
        $brand = Brand::factory()->create();
        $product = Product::factory()->create([
            'description_raw' => 'CALDAIA VAI 8-025 WNI',
            'enrichment_status' => 'needs_review',
            'brand_id' => $brand->id,
            'product_base_id' => null,
        ]);

        $summary = $this->pipeline()->run($product);

        $product->refresh();
        $this->assertNull($product->product_base_id);
        $this->assertSame(0, $summary['groups_resolved']);
    }
}
