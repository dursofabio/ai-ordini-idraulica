<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Family;
use App\Models\Product;
use App\Models\Subfamily;
use App\Services\Ai\TaxonomyCatalog;
use App\Services\Enrichment\FileTaxonomyResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-032 acceptance criteria — direct brand/family/subfamily linking from
 * raw file values:
 *  - A raw code matching an existing taxonomy entry links the product with
 *    `*_source = 'file'`.
 *  - A raw label matching an existing taxonomy entry does the same.
 *  - No match leaves the product unlinked.
 *  - Subfamily matching is scoped to the row's own family.
 *  - A field already set is never overwritten.
 *
 * Runs against in-memory SQLite via RequiresDatabase, matching the sibling
 * resolver test suites.
 */
class FileTaxonomyResolverTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    private function resolver(): FileTaxonomyResolver
    {
        return new FileTaxonomyResolver(new TaxonomyCatalog);
    }

    public function test_links_brand_family_and_subfamily_by_exact_code_match(): void
    {
        // Mirrors real seeded data (see CatalogSeedTaxonomyCommand::buildAliases):
        // the raw ERP code lives in `aliases`, while `name`/`slug` carry the
        // human-readable label — so the code-only match must go through the
        // alias, not through name/slug.
        $brand = Brand::factory()->create(['name' => 'Wavin Italia Spa', 'slug' => 'wavin-italia-spa', 'aliases' => ['01', 'WAVIN']]);
        $family = Family::factory()->create(['name' => 'Tubi e Raccordi', 'slug' => 'tubi-e-raccordi', 'aliases' => ['12']]);
        $subfamily = Subfamily::factory()->create(['name' => 'Raccordi a Pressare', 'slug' => 'tubi-e-raccordi-raccordi-a-pressare', 'family_id' => $family->id, 'aliases' => ['RAC']]);

        $product = Product::factory()->create([
            'marca_codice' => '01',
            'descrizione_marca' => null,
            'fam_codice' => '12',
            'fam_descrizione' => null,
            'subfam_codice' => 'RAC',
            'subfam_descrizione' => null,
            'brand_id' => null,
            'family_id' => null,
            'subfamily_id' => null,
        ]);

        $linked = $this->resolver()->resolve($product);

        $fresh = $product->fresh();
        $this->assertTrue($linked['brand']);
        $this->assertTrue($linked['family']);
        $this->assertTrue($linked['subfamily']);
        $this->assertSame($brand->id, $fresh->brand_id);
        $this->assertSame('file', $fresh->brand_source);
        $this->assertSame(100, $fresh->confidence);
        $this->assertSame($family->id, $fresh->family_id);
        $this->assertSame('file', $fresh->family_source);
        $this->assertSame($subfamily->id, $fresh->subfamily_id);
        $this->assertSame('file', $fresh->subfamily_source);
    }

    public function test_links_brand_by_exact_label_match_when_code_does_not_match(): void
    {
        $brand = Brand::factory()->create(['name' => 'WAVIN ITALIA SPA', 'slug' => 'wavin-italia-spa']);

        $product = Product::factory()->create([
            'marca_codice' => '01',
            'descrizione_marca' => 'WAVIN ITALIA SPA',
            'brand_id' => null,
        ]);

        $linked = $this->resolver()->resolve($product);

        $fresh = $product->fresh();
        $this->assertTrue($linked['brand']);
        $this->assertSame($brand->id, $fresh->brand_id);
        $this->assertSame('file', $fresh->brand_source);
    }

    public function test_does_not_link_when_no_raw_value_matches_the_taxonomy(): void
    {
        Brand::factory()->create(['name' => 'WAVIN', 'slug' => 'wavin']);

        $product = Product::factory()->create([
            'marca_codice' => '99',
            'descrizione_marca' => 'MARCA SCONOSCIUTA',
            'brand_id' => null,
            'brand_source' => null,
        ]);

        $linked = $this->resolver()->resolve($product);

        $fresh = $product->fresh();
        $this->assertFalse($linked['brand']);
        $this->assertNull($fresh->brand_id);
        $this->assertNull($fresh->brand_source);
    }

    public function test_subfamily_match_is_scoped_to_the_row_family(): void
    {
        $family = Family::factory()->create(['name' => 'TUBI', 'slug' => 'tubi']);
        $otherFamily = Family::factory()->create(['name' => 'RISCALDAMENTO', 'slug' => 'riscaldamento']);
        $subfamily = Subfamily::factory()->create(['name' => 'ACCESSORI', 'family_id' => $family->id]);
        Subfamily::factory()->create(['name' => 'ACCESSORI', 'family_id' => $otherFamily->id]);

        $product = Product::factory()->create([
            'fam_codice' => 'TUBI',
            'fam_descrizione' => null,
            'subfam_codice' => 'ACCESSORI',
            'subfam_descrizione' => null,
            'family_id' => null,
            'subfamily_id' => null,
        ]);

        $linked = $this->resolver()->resolve($product);

        $fresh = $product->fresh();
        $this->assertTrue($linked['subfamily']);
        $this->assertSame($subfamily->id, $fresh->subfamily_id);
        $this->assertSame('file', $fresh->subfamily_source);
    }

    public function test_does_not_overwrite_a_field_that_is_already_set(): void
    {
        $existingBrand = Brand::factory()->create(['name' => 'Hansgrohe']);
        Brand::factory()->create(['name' => 'WAVIN', 'slug' => 'wavin']);

        $product = Product::factory()->create([
            'marca_codice' => 'WAVIN',
            'brand_id' => $existingBrand->id,
            'brand_source' => 'manual',
        ]);

        $linked = $this->resolver()->resolve($product);

        $fresh = $product->fresh();
        $this->assertFalse($linked['brand']);
        $this->assertSame($existingBrand->id, $fresh->brand_id);
        $this->assertSame('manual', $fresh->brand_source);
    }
}
