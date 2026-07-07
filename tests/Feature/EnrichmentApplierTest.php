<?php

namespace Tests\Feature;

use App\Models\AttributeDefinition;
use App\Models\Brand;
use App\Models\EnrichmentProposal;
use App\Models\Family;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Subfamily;
use App\Services\Ai\AttributeVocabulary;
use App\Services\Ai\ClassifiedProduct;
use App\Services\Ai\TaxonomyCatalog;
use App\Services\Enrichment\AttributeUnitConverter;
use App\Services\Enrichment\EnrichmentApplier;
use App\Services\Enrichment\EnrichmentProposalRecorder;
use Database\Seeders\AttributeDefinitionSeeder;
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
 * US-043 acceptance criteria — attribute extraction anchored to the
 * `AttributeDefinition` registry:
 *  - The spec's "Dimostra" case: value 3500 / unit W on `potenza` is
 *    converted and written as 3.5 kW, source 'ai'.
 *  - A non-convertible unit is not written; a pending proposal keeps the
 *    original value/unit.
 *  - A textual attribute is written pass-through, with `unit = null`.
 *  - A value/definition type mismatch is not written; a pending proposal
 *    keeps the original value/unit.
 *  - An existing `source = 'regex'` row is still overwritten by the AI, and
 *    an existing `source = 'manual'` row is still protected.
 *  - A `null` unit on a numeric definition is written unchanged (already
 *    canonical).
 *
 * US-044 acceptance criteria — a key absent from the registry is never
 * written, but proposes a new `attribute_definition` instead of vanishing
 * entirely:
 *  - A key absent from the registry generates a `pending`
 *    `attribute_definition` proposal.
 *  - Repeated occurrences of the same unknown key across classifications
 *    accorpate into a single pending proposal.
 *
 * Runs against in-memory SQLite via RequiresDatabase.
 */
class EnrichmentApplierTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AttributeDefinitionSeeder::class);
    }

    private function makeApplier(): EnrichmentApplier
    {
        return new EnrichmentApplier(new EnrichmentProposalRecorder, new AttributeUnitConverter);
    }

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

        $this->makeApplier()->apply($product, $result, new TaxonomyCatalog, new AttributeVocabulary);

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

        $this->assertDatabaseHas('enrichment_proposals', [
            'product_id' => $product->id,
            'field' => 'brand',
            'origin' => 'ai',
            'status' => 'applied',
            'confidence' => 90,
            'value_id' => $brand->id,
        ]);
        $this->assertDatabaseHas('enrichment_proposals', [
            'product_id' => $product->id,
            'field' => 'family',
            'origin' => 'ai',
            'status' => 'applied',
            'confidence' => 90,
            'value_id' => $family->id,
        ]);
        $this->assertDatabaseHas('enrichment_proposals', [
            'product_id' => $product->id,
            'field' => 'subfamily',
            'origin' => 'ai',
            'status' => 'applied',
            'confidence' => 90,
            'value_id' => $subfamily->id,
        ]);
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

        $this->makeApplier()->apply($product, $result, new TaxonomyCatalog, new AttributeVocabulary);

        $fresh = $product->fresh();
        $this->assertSame($brand->id, $fresh->brand_id);
        $this->assertSame('ai', $fresh->brand_source);
        $this->assertSame('needs_review', $fresh->enrichment_status);
        $this->assertSame('ai', $fresh->source);
        $this->assertSame(70, $fresh->confidence);

        $this->assertDatabaseHas('enrichment_proposals', [
            'product_id' => $product->id,
            'field' => 'brand',
            'origin' => 'ai',
            'status' => 'applied',
            'confidence' => 70,
            'value_id' => $brand->id,
        ]);
    }

    public function test_low_confidence_result_applies_no_ai_values(): void
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
            confidence: 40,
        );

        $this->makeApplier()->apply($product, $result, new TaxonomyCatalog, new AttributeVocabulary);

        $fresh = $product->fresh();
        $this->assertNull($fresh->brand_id);
        $this->assertNull($fresh->brand_source);
        $this->assertSame('needs_review', $fresh->enrichment_status);
        $this->assertNull($fresh->source);
        $this->assertNull($fresh->confidence);

        $this->assertDatabaseHas('enrichment_proposals', [
            'product_id' => $product->id,
            'field' => 'brand',
            'origin' => 'ai',
            'status' => 'pending',
            'confidence' => 40,
            'value_id' => $brand->id,
        ]);
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

        $this->makeApplier()->apply($product, $result, new TaxonomyCatalog, new AttributeVocabulary);

        $fresh = $product->fresh();
        $this->assertSame($manualBrand->id, $fresh->brand_id);
        $this->assertSame('manual', $fresh->brand_source);
        $this->assertNotSame($aiBrand->id, $fresh->brand_id);
        $this->assertSame($family->id, $fresh->family_id);
        $this->assertSame('ai', $fresh->family_source);
        $this->assertSame('enriched', $fresh->enrichment_status);
        $this->assertSame('ai', $fresh->source);
        $this->assertSame(90, $fresh->confidence);

        $this->assertDatabaseMissing('enrichment_proposals', [
            'product_id' => $product->id,
            'field' => 'brand',
        ]);
        $this->assertDatabaseHas('enrichment_proposals', [
            'product_id' => $product->id,
            'field' => 'family',
            'origin' => 'ai',
            'status' => 'applied',
            'confidence' => 90,
            'value_id' => $family->id,
        ]);
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

        $this->makeApplier()->apply($product, $result, new TaxonomyCatalog, new AttributeVocabulary);

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

        $this->makeApplier()->apply($product, $result, new TaxonomyCatalog, new AttributeVocabulary);

        $fresh = $product->fresh();
        $this->assertNull($fresh->brand_id);
        $this->assertNull($fresh->brand_source);
        $this->assertSame('enriched', $fresh->enrichment_status);
        $this->assertSame('ai', $fresh->source);
        $this->assertSame(90, $fresh->confidence);
    }

    /**
     * US-043 "Dimostra" case: 3500 W as read from the text is converted to
     * the canonical unit (kW) before being written.
     */
    public function test_registered_numeric_attribute_is_converted_to_canonical_unit_at_high_confidence(): void
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
                'potenza' => ['value' => '3500', 'unit' => 'W', 'confidence' => 90],
            ],
        );

        $this->makeApplier()->apply($product, $result, new TaxonomyCatalog, new AttributeVocabulary);

        $attribute = $product->attributes()->where('key', 'potenza')->first();
        $this->assertNotNull($attribute);
        $this->assertEquals(3.5, $attribute->value);
        $this->assertSame('kW', $attribute->unit);
        $this->assertSame('ai', $attribute->source);
        $this->assertSame(90, $attribute->confidence);

        $this->assertDatabaseHas('enrichment_proposals', [
            'product_id' => $product->id,
            'field' => 'attribute',
            'attribute_key' => 'potenza',
            'origin' => 'ai',
            'status' => 'applied',
            'confidence' => 90,
            'value' => '3500',
            'unit' => 'W',
        ]);
    }

    public function test_non_convertible_unit_is_not_written_and_recorded_as_pending(): void
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
                'potenza' => ['value' => '5', 'unit' => 'CV', 'confidence' => 90],
            ],
        );

        $this->makeApplier()->apply($product, $result, new TaxonomyCatalog, new AttributeVocabulary);

        $this->assertNull($product->attributes()->where('key', 'potenza')->first());

        $this->assertDatabaseHas('enrichment_proposals', [
            'product_id' => $product->id,
            'field' => 'attribute',
            'attribute_key' => 'potenza',
            'origin' => 'ai',
            'status' => 'pending',
            'confidence' => 90,
            'value' => '5',
            'unit' => 'CV',
        ]);
    }

    /**
     * US-044 AC1: a key absent from the registry is never written to
     * `product_attributes`, but generates a `pending` `attribute_definition`
     * proposal instead of disappearing entirely.
     */
    public function test_key_outside_registry_is_not_written_but_generates_an_attribute_proposal_and_a_definition_proposal(): void
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
                'chiave_libera_inventata' => ['value' => '42', 'unit' => 'x', 'confidence' => 95],
            ],
        );

        $this->makeApplier()->apply($product, $result, new TaxonomyCatalog, new AttributeVocabulary);

        $this->assertNull($product->attributes()->where('key', 'chiave_libera_inventata')->first());

        $this->assertDatabaseHas('enrichment_proposals', [
            'product_id' => $product->id,
            'field' => 'attribute',
            'attribute_key' => 'chiave_libera_inventata',
            'value' => '42',
            'unit' => 'x',
            'origin' => 'ai',
            'status' => 'pending',
            'confidence' => 95,
        ]);

        $this->assertDatabaseHas('enrichment_proposals', [
            'product_id' => $product->id,
            'field' => 'attribute_definition',
            'attribute_key' => 'chiave_libera_inventata',
            'data_type' => 'numeric',
            'unit' => 'x',
            'origin' => 'ai',
            'status' => 'pending',
            'confidence' => 95,
        ]);
    }

    /**
     * US-044 AC4: two consecutive classifications reporting the same
     * out-of-registry key must not flood the queue with duplicate
     * `attribute_definition` proposals — but each still gets its own
     * `attribute` proposal, so neither product's value is lost.
     */
    public function test_repeated_unknown_key_across_classifications_produces_a_single_pending_definition_proposal(): void
    {
        $firstProduct = Product::factory()->create(['enrichment_status' => 'pending']);
        $secondProduct = Product::factory()->create(['enrichment_status' => 'pending']);

        $applier = $this->makeApplier();

        foreach ([$firstProduct, $secondProduct] as $product) {
            $result = new ClassifiedProduct(
                codiceArticolo: $product->codice_articolo,
                brand: null,
                family: null,
                subfamily: null,
                productType: null,
                enrichedDescription: null,
                confidence: null,
                attributes: [
                    'chiave_libera_inventata' => ['value' => '42', 'unit' => 'x', 'confidence' => 95],
                ],
            );

            $applier->apply($product, $result, new TaxonomyCatalog, new AttributeVocabulary);
        }

        $this->assertEquals(1, EnrichmentProposal::query()->where('field', 'attribute_definition')->count());
        $this->assertDatabaseHas('enrichment_proposals', [
            'field' => 'attribute_definition',
            'attribute_key' => 'chiave_libera_inventata',
            'status' => 'pending',
        ]);

        $this->assertEquals(2, EnrichmentProposal::query()->where('field', 'attribute')->count());
    }

    public function test_textual_attribute_is_written_pass_through_with_null_unit(): void
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
                'materiale' => ['value' => 'ottone', 'confidence' => 90],
            ],
        );

        $this->makeApplier()->apply($product, $result, new TaxonomyCatalog, new AttributeVocabulary);

        $attribute = $product->attributes()->where('key', 'materiale')->first();
        $this->assertNotNull($attribute);
        $this->assertSame('ottone', $attribute->value);
        $this->assertNull($attribute->unit);
        $this->assertSame('ai', $attribute->source);
    }

    public function test_type_mismatch_is_not_written_and_recorded_as_pending(): void
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
                // 'potenza' is a numeric definition; a non-numeric value is supplied.
                'potenza' => ['value' => 'forte', 'confidence' => 90],
            ],
        );

        $this->makeApplier()->apply($product, $result, new TaxonomyCatalog, new AttributeVocabulary);

        $this->assertNull($product->attributes()->where('key', 'potenza')->first());

        $this->assertDatabaseHas('enrichment_proposals', [
            'product_id' => $product->id,
            'field' => 'attribute',
            'attribute_key' => 'potenza',
            'origin' => 'ai',
            'status' => 'pending',
            'confidence' => 90,
            'value' => 'forte',
        ]);
    }

    public function test_null_unit_on_numeric_definition_is_written_unchanged(): void
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
                'potenza' => ['value' => '2.5', 'confidence' => 90],
            ],
        );

        $this->makeApplier()->apply($product, $result, new TaxonomyCatalog, new AttributeVocabulary);

        $attribute = $product->attributes()->where('key', 'potenza')->first();
        $this->assertNotNull($attribute);
        $this->assertEquals(2.5, $attribute->value);
        $this->assertSame('kW', $attribute->unit);
    }

    public function test_existing_regex_attribute_is_corrected_by_ai_at_high_confidence(): void
    {
        $product = Product::factory()->create(['enrichment_status' => 'pending']);
        ProductAttribute::factory()->for($product)->create([
            'key' => 'potenza',
            'value' => '1.0',
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
                'potenza' => ['value' => '1.5', 'unit' => 'kW', 'confidence' => 95],
            ],
        );

        $this->makeApplier()->apply($product, $result, new TaxonomyCatalog, new AttributeVocabulary);

        $attribute = $product->attributes()->where('key', 'potenza')->first();
        $this->assertNotNull($attribute);
        $this->assertEquals(1.5, $attribute->value);
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
                'materiale' => ['value' => 'ottone', 'confidence' => 50],
            ],
        );

        $this->makeApplier()->apply($product, $result, new TaxonomyCatalog, new AttributeVocabulary);

        $this->assertNull($product->attributes()->where('key', 'materiale')->first());

        $this->assertDatabaseHas('enrichment_proposals', [
            'product_id' => $product->id,
            'field' => 'attribute',
            'attribute_key' => 'materiale',
            'origin' => 'ai',
            'status' => 'pending',
            'confidence' => 50,
            'value' => 'ottone',
        ]);
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
                'colore' => ['value' => 'RAL 9010', 'confidence' => 70],
            ],
        );

        $this->makeApplier()->apply($product, $result, new TaxonomyCatalog, new AttributeVocabulary);

        $fresh = $product->fresh();
        $this->assertSame($brand->id, $fresh->brand_id);
        $this->assertSame($family->id, $fresh->family_id);
        $this->assertSame('needs_review', $fresh->enrichment_status);

        $attribute = $product->attributes()->where('key', 'colore')->first();
        $this->assertNotNull($attribute);
        $this->assertSame('RAL 9010', $attribute->value);
        $this->assertSame(70, $attribute->confidence);
    }

    /**
     * US-045 AC1/AC2: `product_type` follows the same confidence gate as
     * brand/family/subfamily — written and logged `applied` at high
     * confidence, left unwritten and logged `pending` below the low
     * threshold.
     */
    public function test_high_confidence_product_type_is_persisted_and_logged_applied(): void
    {
        $product = Product::factory()->create(['enrichment_status' => 'pending']);

        $result = new ClassifiedProduct(
            codiceArticolo: $product->codice_articolo,
            brand: null,
            family: null,
            subfamily: null,
            productType: 'Caldaia a condensazione',
            enrichedDescription: null,
            confidence: 90,
        );

        $this->makeApplier()->apply($product, $result, new TaxonomyCatalog, new AttributeVocabulary);

        $fresh = $product->fresh();
        $this->assertSame('Caldaia a condensazione', $fresh->product_type);

        $this->assertDatabaseHas('enrichment_proposals', [
            'product_id' => $product->id,
            'field' => 'product_type',
            'origin' => 'ai',
            'status' => 'applied',
            'confidence' => 90,
            'value' => 'Caldaia a condensazione',
        ]);
    }

    public function test_low_confidence_product_type_is_not_persisted_and_logged_pending(): void
    {
        $product = Product::factory()->create(['enrichment_status' => 'pending']);

        $result = new ClassifiedProduct(
            codiceArticolo: $product->codice_articolo,
            brand: null,
            family: null,
            subfamily: null,
            productType: 'Caldaia a condensazione',
            enrichedDescription: null,
            confidence: 40,
        );

        $this->makeApplier()->apply($product, $result, new TaxonomyCatalog, new AttributeVocabulary);

        $fresh = $product->fresh();
        $this->assertNull($fresh->product_type);

        $this->assertDatabaseHas('enrichment_proposals', [
            'product_id' => $product->id,
            'field' => 'product_type',
            'origin' => 'ai',
            'status' => 'pending',
            'confidence' => 40,
            'value' => 'Caldaia a condensazione',
        ]);
    }

    /**
     * US-045 AC4: a reclassification updates the existing `product_type`
     * value — there is no `*_source` guard against overwriting it.
     */
    public function test_reclassification_overwrites_the_existing_product_type(): void
    {
        $product = Product::factory()->create([
            'enrichment_status' => 'pending',
            'product_type' => 'Miscelatore',
        ]);

        $result = new ClassifiedProduct(
            codiceArticolo: $product->codice_articolo,
            brand: null,
            family: null,
            subfamily: null,
            productType: 'Caldaia a condensazione',
            enrichedDescription: null,
            confidence: 90,
        );

        $this->makeApplier()->apply($product, $result, new TaxonomyCatalog, new AttributeVocabulary);

        $this->assertSame('Caldaia a condensazione', $product->fresh()->product_type);
    }

    public function test_manual_source_attribute_is_never_overwritten_by_ai_proposal(): void
    {
        $product = Product::factory()->create(['enrichment_status' => 'pending']);
        ProductAttribute::factory()->for($product)->create([
            'key' => 'materiale',
            'value' => 'ottone',
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
                'materiale' => ['value' => 'plastica', 'confidence' => 95],
            ],
        );

        $this->makeApplier()->apply($product, $result, new TaxonomyCatalog, new AttributeVocabulary);

        $attribute = $product->attributes()->where('key', 'materiale')->first();
        $this->assertNotNull($attribute);
        $this->assertSame('ottone', $attribute->value);
        $this->assertSame('manual', $attribute->source);
        $this->assertNull($attribute->confidence);

        $this->assertDatabaseMissing('enrichment_proposals', [
            'product_id' => $product->id,
            'attribute_key' => 'materiale',
        ]);
    }
}
