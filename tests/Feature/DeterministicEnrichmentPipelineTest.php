<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Product;
use App\Services\Ai\TaxonomyCatalog;
use App\Services\Enrichment\BrandResolver;
use App\Services\Enrichment\DeterministicEnrichmentPipeline;
use App\Services\Enrichment\EnrichmentProposalRecorder;
use App\Services\Enrichment\FileTaxonomyResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-026 acceptance criteria — Step A orchestration (US-047 flattens this
 * onto a single `Product` row: grouping/family-propagation no longer exist):
 *  - Runs FileTaxonomyResolver first, so its *_source = 'file' idempotency
 *    guards make BrandResolver no-op on whatever it already linked.
 *  - Returns accurate counts for each resolver stage.
 *  - No-ops (all counts zero) for a product that is not enrichment_status
 *    pending.
 *
 * US-043 removed the regex attribute extraction pass from this pipeline
 * (technical attributes are now extracted exclusively by AI classification,
 * anchored to the attribute registry): this suite covers brand/file-linking
 * orchestration only.
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
            new FileTaxonomyResolver(new TaxonomyCatalog, new EnrichmentProposalRecorder),
            new BrandResolver(new EnrichmentProposalRecorder),
        );
    }

    public function test_resolves_brand_for_a_pending_product(): void
    {
        Brand::factory()->create(['name' => 'Vaillant', 'aliases' => ['VAI']]);

        $product = Product::factory()->create([
            'description_raw' => 'CALDAIA VAI 8-025 WNI 3.5KW',
            'description_clean' => null,
            'enrichment_status' => 'pending',
            'brand_id' => null,
        ]);

        $summary = $this->pipeline()->run($product);

        $product->refresh();
        $this->assertNotNull($product->brand_id, 'Brand should be resolved.');
        $this->assertSame(1, $summary['brands_resolved']);
    }

    public function test_file_taxonomy_link_wins_over_textual_brand_deduction(): void
    {
        $fileBrand = Brand::factory()->create(['name' => 'WAVIN', 'slug' => 'wavin']);
        Brand::factory()->create(['name' => 'Vaillant', 'aliases' => ['VAI']]);

        $product = Product::factory()->create([
            // Contains a valid inline textual match for "Vaillant" (via the
            // "VAI" alias), so a plain BrandResolver run would pick it —
            // the row's marca_codice must win instead.
            'description_raw' => 'TUBO VAI 20MM PPR',
            'description_clean' => null,
            'marca_codice' => 'WAVIN',
            'descrizione_marca' => null,
            'enrichment_status' => 'pending',
            'brand_id' => null,
        ]);

        $summary = $this->pipeline()->run($product);

        $product->refresh();
        $this->assertSame($fileBrand->id, $product->brand_id);
        $this->assertSame('file', $product->brand_source);
        $this->assertSame(1, $summary['brands_linked_from_file']);
        $this->assertSame(0, $summary['brands_resolved'], 'BrandResolver must no-op once the file has already linked a brand.');
    }

    public function test_is_a_noop_for_a_product_that_is_not_pending(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'CALDAIA VAI 8-025 WNI',
            'enrichment_status' => 'enriched',
            'brand_id' => null,
        ]);

        $summary = $this->pipeline()->run($product);

        $product->refresh();
        $this->assertNull($product->brand_id);
        $this->assertSame(0, $summary['brands_resolved']);
    }

    public function test_is_a_noop_for_an_already_enriched_product(): void
    {
        $brand = Brand::factory()->create();
        $product = Product::factory()->create([
            'description_raw' => 'CALDAIA VAI 8-025 WNI',
            'enrichment_status' => 'needs_review',
            'brand_id' => $brand->id,
        ]);

        $summary = $this->pipeline()->run($product);

        $product->refresh();
        $this->assertSame(0, $summary['brands_resolved']);
    }
}
