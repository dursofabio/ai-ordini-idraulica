<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Family;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Subfamily;
use App\Services\Ai\ClassifiedProduct;
use App\Services\Ai\TaxonomyCatalog;
use App\Services\Enrichment\EnrichmentApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-015 acceptance criteria — confidence-gated enrichment write-back:
 *  - confidence >= 85: AI values applied, enrichment_status = 'enriched'.
 *  - confidence 60-84: AI values applied, enrichment_status = 'needs_review'.
 *  - confidence < 60: no AI value applied, enrichment_status = 'needs_review'.
 *  - brand_source = 'manual' is never overwritten by the AI.
 *  - Every application at confidence >= 60 sets source = 'ai' and
 *    confidence = <received value> on the product.
 *
 * Runs against in-memory SQLite via RequiresDatabase.
 */
class EnrichmentApplierTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_high_confidence_result_is_applied_and_marks_product_enriched(): void
    {
        $brand = Brand::factory()->create(['name' => 'Grohe']);
        $family = Family::factory()->create(['name' => 'Rubinetteria']);
        $subfamily = Subfamily::factory()->create(['name' => 'Miscelatori', 'family_id' => $family->id]);
        $product = Product::factory()->create(['enrichment_status' => 'pending']);

        $result = new ClassifiedProduct(
            codiceArticolo: $product->codice_articolo,
            brand: 'Grohe',
            family: 'Rubinetteria',
            subfamily: 'Miscelatori',
            productType: 'Miscelatore',
            enrichedDescription: 'Descrizione arricchita',
            confidence: 90,
        );

        (new EnrichmentApplier)->apply($product, $result, new TaxonomyCatalog);

        $fresh = $product->fresh();
        $this->assertSame($brand->id, $fresh->brand_id);
        $this->assertSame('ai', $fresh->brand_source);
        $this->assertSame($family->id, $fresh->family_id);
        $this->assertSame('ai', $fresh->family_source);
        $this->assertSame($subfamily->id, $fresh->subfamily_id);
        $this->assertSame('ai', $fresh->subfamily_source);
        $this->assertSame('enriched', $fresh->enrichment_status);
        $this->assertSame('ai', $fresh->source);
        $this->assertSame(90, $fresh->confidence);
    }

    public function test_medium_confidence_result_is_applied_but_marks_product_needs_review(): void
    {
        $brand = Brand::factory()->create(['name' => 'Grohe']);
        $product = Product::factory()->create(['enrichment_status' => 'pending']);

        $result = new ClassifiedProduct(
            codiceArticolo: $product->codice_articolo,
            brand: 'Grohe',
            family: null,
            subfamily: null,
            productType: null,
            enrichedDescription: null,
            confidence: 70,
        );

        (new EnrichmentApplier)->apply($product, $result, new TaxonomyCatalog);

        $fresh = $product->fresh();
        $this->assertSame($brand->id, $fresh->brand_id);
        $this->assertSame('ai', $fresh->brand_source);
        $this->assertSame('needs_review', $fresh->enrichment_status);
        $this->assertSame('ai', $fresh->source);
        $this->assertSame(70, $fresh->confidence);
    }

    public function test_low_confidence_result_applies_no_ai_values(): void
    {
        Brand::factory()->create(['name' => 'Grohe']);
        $product = Product::factory()->create(['enrichment_status' => 'pending']);

        $result = new ClassifiedProduct(
            codiceArticolo: $product->codice_articolo,
            brand: 'Grohe',
            family: null,
            subfamily: null,
            productType: null,
            enrichedDescription: null,
            confidence: 40,
        );

        (new EnrichmentApplier)->apply($product, $result, new TaxonomyCatalog);

        $fresh = $product->fresh();
        $this->assertNull($fresh->brand_id);
        $this->assertNull($fresh->brand_source);
        $this->assertSame('needs_review', $fresh->enrichment_status);
        $this->assertNull($fresh->source);
        $this->assertNull($fresh->confidence);
    }

    public function test_manual_brand_source_is_never_overwritten_but_other_fields_and_status_still_update(): void
    {
        $manualBrand = Brand::factory()->create(['name' => 'Hansgrohe']);
        $aiBrand = Brand::factory()->create(['name' => 'Grohe']);
        $family = Family::factory()->create(['name' => 'Rubinetteria']);

        $product = Product::factory()->create([
            'enrichment_status' => 'pending',
            'brand_id' => $manualBrand->id,
            'brand_source' => 'manual',
        ]);

        $result = new ClassifiedProduct(
            codiceArticolo: $product->codice_articolo,
            brand: 'Grohe',
            family: 'Rubinetteria',
            subfamily: null,
            productType: null,
            enrichedDescription: null,
            confidence: 90,
        );

        (new EnrichmentApplier)->apply($product, $result, new TaxonomyCatalog);

        $fresh = $product->fresh();
        $this->assertSame($manualBrand->id, $fresh->brand_id);
        $this->assertSame('manual', $fresh->brand_source);
        $this->assertNotSame($aiBrand->id, $fresh->brand_id);
        $this->assertSame($family->id, $fresh->family_id);
        $this->assertSame('ai', $fresh->family_source);
        $this->assertSame('enriched', $fresh->enrichment_status);
        $this->assertSame('ai', $fresh->source);
        $this->assertSame(90, $fresh->confidence);
    }

    public function test_file_brand_source_is_never_overwritten_but_other_fields_and_status_still_update(): void
    {
        $fileBrand = Brand::factory()->create(['name' => 'Hansgrohe']);
        $aiBrand = Brand::factory()->create(['name' => 'Grohe']);
        $family = Family::factory()->create(['name' => 'Rubinetteria']);

        $product = Product::factory()->create([
            'enrichment_status' => 'pending',
            'brand_id' => $fileBrand->id,
            'brand_source' => 'file',
        ]);

        $result = new ClassifiedProduct(
            codiceArticolo: $product->codice_articolo,
            brand: 'Grohe',
            family: 'Rubinetteria',
            subfamily: null,
            productType: null,
            enrichedDescription: null,
            confidence: 90,
        );

        (new EnrichmentApplier)->apply($product, $result, new TaxonomyCatalog);

        $fresh = $product->fresh();
        $this->assertSame($fileBrand->id, $fresh->brand_id);
        $this->assertSame('file', $fresh->brand_source);
        $this->assertNotSame($aiBrand->id, $fresh->brand_id);
        $this->assertSame($family->id, $fresh->family_id);
        $this->assertSame('ai', $fresh->family_source);
        $this->assertSame('enriched', $fresh->enrichment_status);
        $this->assertSame('ai', $fresh->source);
        $this->assertSame(90, $fresh->confidence);
    }

    public function test_ai_value_not_present_in_taxonomy_is_ignored_without_exception(): void
    {
        $product = Product::factory()->create(['enrichment_status' => 'pending']);

        $result = new ClassifiedProduct(
            codiceArticolo: $product->codice_articolo,
            brand: 'MarcaInesistente',
            family: null,
            subfamily: null,
            productType: null,
            enrichedDescription: null,
            confidence: 90,
        );

        (new EnrichmentApplier)->apply($product, $result, new TaxonomyCatalog);

        $fresh = $product->fresh();
        $this->assertNull($fresh->brand_id);
        $this->assertNull($fresh->brand_source);
        $this->assertSame('enriched', $fresh->enrichment_status);
        $this->assertSame('ai', $fresh->source);
        $this->assertSame(90, $fresh->confidence);
    }

    public function test_new_key_attribute_is_written_at_high_confidence(): void
    {
        $product = Product::factory()->create(['enrichment_status' => 'pending']);

        $result = new ClassifiedProduct(
            codiceArticolo: $product->codice_articolo,
            brand: null,
            family: null,
            subfamily: null,
            productType: null,
            enrichedDescription: null,
            confidence: null,
            attributes: [
                'portata_lmin' => ['value_num' => 12.5, 'unit' => 'L/min', 'confidence' => 90],
            ],
        );

        (new EnrichmentApplier)->apply($product, $result, new TaxonomyCatalog);

        $attribute = $product->attributes()->where('key', 'portata_lmin')->first();
        $this->assertNotNull($attribute);
        $this->assertEquals(12.5, $attribute->value_num);
        $this->assertSame('L/min', $attribute->unit);
        $this->assertSame('ai', $attribute->source);
        $this->assertSame(90, $attribute->confidence);
    }

    public function test_existing_regex_attribute_is_corrected_by_ai_at_high_confidence(): void
    {
        $product = Product::factory()->create(['enrichment_status' => 'pending']);
        ProductAttribute::factory()->for($product)->create([
            'key' => 'potenza_kw',
            'value_num' => 1.0,
            'unit' => 'kW',
            'source' => 'regex',
            'confidence' => null,
        ]);

        $result = new ClassifiedProduct(
            codiceArticolo: $product->codice_articolo,
            brand: null,
            family: null,
            subfamily: null,
            productType: null,
            enrichedDescription: null,
            confidence: null,
            attributes: [
                'potenza_kw' => ['value_num' => 1.5, 'unit' => 'kW', 'confidence' => 95],
            ],
        );

        (new EnrichmentApplier)->apply($product, $result, new TaxonomyCatalog);

        $attribute = $product->attributes()->where('key', 'potenza_kw')->first();
        $this->assertNotNull($attribute);
        $this->assertEquals(1.5, $attribute->value_num);
        $this->assertSame('ai', $attribute->source);
        $this->assertSame(95, $attribute->confidence);
    }

    public function test_low_confidence_attribute_is_not_written(): void
    {
        $product = Product::factory()->create(['enrichment_status' => 'pending']);

        $result = new ClassifiedProduct(
            codiceArticolo: $product->codice_articolo,
            brand: null,
            family: null,
            subfamily: null,
            productType: null,
            enrichedDescription: null,
            confidence: null,
            attributes: [
                'materiale' => ['value_text' => 'ottone', 'confidence' => 50],
            ],
        );

        (new EnrichmentApplier)->apply($product, $result, new TaxonomyCatalog);

        $this->assertNull($product->attributes()->where('key', 'materiale')->first());
    }

    public function test_medium_confidence_attribute_is_written_but_forces_needs_review_even_with_high_overall_confidence(): void
    {
        $brand = Brand::factory()->create(['name' => 'Grohe']);
        $family = Family::factory()->create(['name' => 'Rubinetteria']);
        $product = Product::factory()->create(['enrichment_status' => 'pending']);

        $result = new ClassifiedProduct(
            codiceArticolo: $product->codice_articolo,
            brand: 'Grohe',
            family: 'Rubinetteria',
            subfamily: null,
            productType: null,
            enrichedDescription: null,
            confidence: 95,
            attributes: [
                'colore_ral' => ['value_text' => 'RAL 9010', 'confidence' => 70],
            ],
        );

        (new EnrichmentApplier)->apply($product, $result, new TaxonomyCatalog);

        $fresh = $product->fresh();
        $this->assertSame($brand->id, $fresh->brand_id);
        $this->assertSame($family->id, $fresh->family_id);
        $this->assertSame('needs_review', $fresh->enrichment_status);

        $attribute = $product->attributes()->where('key', 'colore_ral')->first();
        $this->assertNotNull($attribute);
        $this->assertSame('RAL 9010', $attribute->value_text);
        $this->assertSame(70, $attribute->confidence);
    }

    public function test_manual_source_attribute_is_never_overwritten_by_ai_proposal(): void
    {
        $product = Product::factory()->create(['enrichment_status' => 'pending']);
        ProductAttribute::factory()->for($product)->create([
            'key' => 'materiale',
            'value_num' => null,
            'value_text' => 'ottone',
            'source' => 'manual',
            'confidence' => null,
        ]);

        $result = new ClassifiedProduct(
            codiceArticolo: $product->codice_articolo,
            brand: null,
            family: null,
            subfamily: null,
            productType: null,
            enrichedDescription: null,
            confidence: null,
            attributes: [
                'materiale' => ['value_text' => 'plastica', 'confidence' => 95],
            ],
        );

        (new EnrichmentApplier)->apply($product, $result, new TaxonomyCatalog);

        $attribute = $product->attributes()->where('key', 'materiale')->first();
        $this->assertNotNull($attribute);
        $this->assertSame('ottone', $attribute->value_text);
        $this->assertSame('manual', $attribute->source);
        $this->assertNull($attribute->confidence);
    }
}
