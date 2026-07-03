<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\EnrichmentProposal;
use App\Models\Product;
use App\Services\Enrichment\BrandResolver;
use App\Services\Enrichment\EnrichmentProposalRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-009 acceptance criteria — deterministic brand resolution:
 *  - A trailing `-MARCA-` suffix matching a brand name or alias resolves
 *    with confidence 95 and brand_source 'regex'.
 *  - An inline dictionary alias match resolves with confidence 80 and
 *    brand_source 'dictionary'.
 *  - The matched brand text is removed from description_clean.
 *  - No match, or an ambiguous inline match across 2+ distinct brands,
 *    leaves brand_id null and enrichment_status untouched; description_clean
 *    is still populated with the trimmed source text (needed by US-010).
 *  - A product that already has a brand_id is left untouched (idempotency).
 *
 * Runs against in-memory SQLite via RequiresDatabase.
 */
class BrandResolverTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_resolves_brand_from_trailing_suffix_matching_brand_name(): void
    {
        $brand = Brand::factory()->create(['name' => 'Grohe', 'aliases' => []]);
        $product = Product::factory()->create([
            'description_raw' => 'Miscelatore lavabo monocomando -GROHE-',
            'brand_id' => null,
        ]);

        $resolved = (new BrandResolver(new EnrichmentProposalRecorder))->resolve($product);

        $this->assertTrue($resolved);
        $product->refresh();
        $this->assertSame($brand->id, $product->brand_id);
        $this->assertSame('regex', $product->brand_source);
        $this->assertSame(95, $product->confidence);
        $this->assertSame('Miscelatore lavabo monocomando', $product->description_clean);
        $this->assertDatabaseHas('enrichment_proposals', [
            'product_id' => $product->id,
            'field' => 'brand',
            'origin' => 'regex',
            'status' => 'applied',
            'confidence' => 95,
            'value_id' => $brand->id,
        ]);
    }

    public function test_resolves_brand_from_trailing_suffix_matching_alias(): void
    {
        $brand = Brand::factory()->create(['name' => 'Grohe International', 'aliases' => ['GRH']]);
        $product = Product::factory()->create([
            'description_raw' => 'Miscelatore lavabo monocomando -GRH-',
            'brand_id' => null,
        ]);

        $resolved = (new BrandResolver(new EnrichmentProposalRecorder))->resolve($product);

        $this->assertTrue($resolved);
        $product->refresh();
        $this->assertSame($brand->id, $product->brand_id);
        $this->assertSame('regex', $product->brand_source);
        $this->assertSame(95, $product->confidence);
        $this->assertSame('Miscelatore lavabo monocomando', $product->description_clean);
    }

    public function test_resolves_brand_from_inline_dictionary_match(): void
    {
        $brand = Brand::factory()->create(['name' => 'Grohe', 'aliases' => []]);
        $product = Product::factory()->create([
            'description_raw' => 'Miscelatore GROHE serie eurosmart',
            'brand_id' => null,
        ]);

        $resolved = (new BrandResolver(new EnrichmentProposalRecorder))->resolve($product);

        $this->assertTrue($resolved);
        $product->refresh();
        $this->assertSame($brand->id, $product->brand_id);
        $this->assertSame('dictionary', $product->brand_source);
        $this->assertSame(80, $product->confidence);
        $this->assertSame('Miscelatore serie eurosmart', $product->description_clean);
        $this->assertDatabaseHas('enrichment_proposals', [
            'product_id' => $product->id,
            'field' => 'brand',
            'origin' => 'dictionary',
            'status' => 'applied',
            'confidence' => 80,
            'value_id' => $brand->id,
        ]);
    }

    public function test_leaves_brand_unresolved_when_no_match(): void
    {
        Brand::factory()->create(['name' => 'Grohe', 'aliases' => []]);
        $product = Product::factory()->create([
            'description_raw' => 'Tubo multistrato 16mm',
            'brand_id' => null,
            'enrichment_status' => 'pending',
        ]);

        $resolved = (new BrandResolver(new EnrichmentProposalRecorder))->resolve($product);

        $this->assertFalse($resolved);
        $product->refresh();
        $this->assertNull($product->brand_id);
        $this->assertSame('Tubo multistrato 16mm', $product->description_clean);
        $this->assertSame('pending', $product->enrichment_status);
        $this->assertSame(0, EnrichmentProposal::where('product_id', $product->id)->count());
    }

    public function test_leaves_brand_unresolved_when_inline_match_is_ambiguous(): void
    {
        Brand::factory()->create(['name' => 'Grohe', 'aliases' => []]);
        Brand::factory()->create(['name' => 'Ideal Standard', 'aliases' => []]);
        $product = Product::factory()->create([
            'description_raw' => 'Miscelatore GROHE compatibile IDEAL STANDARD nuovo',
            'brand_id' => null,
            'enrichment_status' => 'pending',
        ]);

        $resolved = (new BrandResolver(new EnrichmentProposalRecorder))->resolve($product);

        $this->assertFalse($resolved);
        $product->refresh();
        $this->assertNull($product->brand_id);
        $this->assertSame('Miscelatore GROHE compatibile IDEAL STANDARD nuovo', $product->description_clean);
        $this->assertSame('pending', $product->enrichment_status);
    }

    public function test_does_not_overwrite_an_already_resolved_brand(): void
    {
        $existingBrand = Brand::factory()->create(['name' => 'Grohe', 'aliases' => []]);
        $otherBrand = Brand::factory()->create(['name' => 'Hansgrohe', 'aliases' => []]);
        $product = Product::factory()->create([
            'description_raw' => 'Miscelatore lavabo -HANSGROHE-',
            'brand_id' => $existingBrand->id,
            'brand_source' => 'ai',
            'confidence' => 60,
            'description_clean' => null,
        ]);

        $resolved = (new BrandResolver(new EnrichmentProposalRecorder))->resolve($product);

        $this->assertFalse($resolved);
        $product->refresh();
        $this->assertSame($existingBrand->id, $product->brand_id);
        $this->assertSame('ai', $product->brand_source);
        $this->assertSame(60, $product->confidence);
        $this->assertNull($product->description_clean);
        $this->assertNotSame($otherBrand->id, $product->brand_id);
        $this->assertSame(0, EnrichmentProposal::where('product_id', $product->id)->count());
    }

    public function test_suffix_match_takes_priority_over_inline_match(): void
    {
        $suffixBrand = Brand::factory()->create(['name' => 'Grohe', 'aliases' => []]);
        Brand::factory()->create(['name' => 'Ideal Standard', 'aliases' => []]);
        $product = Product::factory()->create([
            'description_raw' => 'Miscelatore IDEAL STANDARD compatibile -GROHE-',
            'brand_id' => null,
        ]);

        $resolved = (new BrandResolver(new EnrichmentProposalRecorder))->resolve($product);

        $this->assertTrue($resolved);
        $product->refresh();
        $this->assertSame($suffixBrand->id, $product->brand_id);
        $this->assertSame('regex', $product->brand_source);
        $this->assertSame(95, $product->confidence);
    }

    public function test_resolves_alias_containing_regex_metacharacters(): void
    {
        $brand = Brand::factory()->create(['name' => '3M', 'aliases' => ['Ideal+Standard (IT)']]);
        $product = Product::factory()->create([
            'description_raw' => 'Guarnizione Ideal+Standard (IT) per rubinetto',
            'brand_id' => null,
        ]);

        $resolved = (new BrandResolver(new EnrichmentProposalRecorder))->resolve($product);

        $this->assertTrue($resolved);
        $product->refresh();
        $this->assertSame($brand->id, $product->brand_id);
        $this->assertSame('dictionary', $product->brand_source);
    }
}
