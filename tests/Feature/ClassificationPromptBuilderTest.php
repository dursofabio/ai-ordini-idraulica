<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Family;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Services\Ai\ClassificationPromptBuilder;
use App\Services\Ai\TaxonomyCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-014 acceptance criteria — classification prompt payload:
 *  - The generated Messages API payload includes every codice_articolo of
 *    the given batch.
 *  - The closed taxonomy (existing brands/families) is embedded in the
 *    prompt text.
 *
 * US-043 acceptance criteria — technical attribute extraction:
 *  - The prompt instructs a unit-free attribute key naming convention.
 *  - The prompt requires value/unit exactly as read from the text and
 *    forbids unit conversion, in a strict decimal format.
 *
 * Runs against in-memory SQLite via RequiresDatabase.
 */
class ClassificationPromptBuilderTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_payload_includes_every_product_codice_articolo_in_the_batch(): void
    {
        $products = Product::factory()->count(3)->create();

        $payload = (new ClassificationPromptBuilder)->build($products, new TaxonomyCatalog, 'claude-test-model');

        $prompt = $payload['messages'][0]['content'];

        foreach ($products as $product) {
            $this->assertStringContainsString($product->codice_articolo, $prompt);
        }
    }

    public function test_max_tokens_scales_with_batch_size_so_a_full_batch_response_is_never_truncated(): void
    {
        $builder = new ClassificationPromptBuilder;
        $taxonomy = new TaxonomyCatalog;

        // A verbose real-world result costs ~210 output tokens per product:
        // a 40-product batch must get well more than 40 x 210 = 8400.
        $fullBatch = $builder->build(Product::factory()->count(40)->create(), $taxonomy, 'm');
        $this->assertGreaterThanOrEqual(40 * 300, $fullBatch['max_tokens']);

        // A single-product escalation call still gets a workable floor.
        $single = $builder->build(Product::factory()->count(1)->create(), $taxonomy, 'm');
        $this->assertGreaterThanOrEqual(1024, $single['max_tokens']);
    }

    public function test_payload_includes_existing_taxonomy_brands_and_families(): void
    {
        Brand::factory()->create(['name' => 'Grohe']);
        Family::factory()->create(['name' => 'Rubinetteria']);

        $products = Product::factory()->count(1)->create();

        $payload = (new ClassificationPromptBuilder)->build($products, new TaxonomyCatalog, 'claude-test-model');

        $prompt = $payload['messages'][0]['content'];

        $this->assertStringContainsString('Grohe', $prompt);
        $this->assertStringContainsString('Rubinetteria', $prompt);
    }

    public function test_payload_uses_the_given_model_and_role(): void
    {
        $products = Product::factory()->count(1)->create();

        $payload = (new ClassificationPromptBuilder)->build($products, new TaxonomyCatalog, 'claude-fast-model');

        $this->assertSame('claude-fast-model', $payload['model']);
        $this->assertSame('user', $payload['messages'][0]['role']);
    }

    public function test_payload_includes_known_regex_attributes_as_product_context(): void
    {
        $product = Product::factory()->create();

        ProductAttribute::factory()->for($product)->create([
            'key' => 'potenza',
            'value' => '1.5',
            'unit' => 'kW',
            'source' => 'regex',
        ]);

        $products = Product::query()->with('attributes')->whereKey($product->id)->get();

        $payload = (new ClassificationPromptBuilder)->build($products, new TaxonomyCatalog, 'claude-test-model');

        $prompt = $payload['messages'][0]['content'];

        $this->assertStringContainsString('potenza', $prompt);
        $this->assertStringContainsString('1.5', $prompt);
        $this->assertStringContainsString('kW', $prompt);
        $this->assertStringContainsString('regex', $prompt);
    }

    public function test_payload_instructs_on_attributes_field_with_confidence_in_response_schema(): void
    {
        $products = Product::factory()->count(1)->create();

        $payload = (new ClassificationPromptBuilder)->build($products, new TaxonomyCatalog, 'claude-test-model');

        $prompt = $payload['messages'][0]['content'];

        $this->assertStringContainsString('"attributes"', $prompt);
        $this->assertStringContainsString('"key"', $prompt);
        $this->assertStringContainsString('"value"', $prompt);
        $this->assertStringContainsString('"confidence"', $prompt);
    }

    /**
     * US-045: `product_type` must be restricted to the product's name/type,
     * explicitly excluding brand/family/subfamily/attribute values, so the
     * persisted column is a reliable text base for embedding and search.
     */
    public function test_payload_restricts_product_type_to_the_product_name_excluding_brand_and_taxonomy(): void
    {
        $products = Product::factory()->count(1)->create();

        $payload = (new ClassificationPromptBuilder)->build($products, new TaxonomyCatalog, 'claude-test-model');

        $prompt = $payload['messages'][0]['content'];

        $this->assertStringContainsString('product_type', $prompt);
        $this->assertStringContainsString('MAI la marca, la famiglia, la sottofamiglia o', $prompt);
    }

    /**
     * Attribute keys must name the type of characteristic without the unit
     * embedded in it (e.g. "potenza", not "potenza_kw"), since the unit is
     * already its own "unit" field.
     */
    public function test_payload_instructs_a_unit_free_key_naming_convention(): void
    {
        $products = Product::factory()->count(1)->create();

        $payload = (new ClassificationPromptBuilder)->build($products, new TaxonomyCatalog, 'claude-test-model');

        $prompt = $payload['messages'][0]['content'];

        $this->assertStringContainsString('SENZA includere l\'unità di misura nel nome', $prompt);
        $this->assertStringContainsString('MAI "potenza_kw"', $prompt);
    }

    /**
     * The AI must never propose the product's own article code or
     * description as a technical attribute — {@see ClassificationResponseValidator}
     * also enforces this as a hard filter, this only checks the prompt's own
     * instruction.
     */
    public function test_payload_forbids_proposing_the_products_own_identity_fields_as_attributes(): void
    {
        $products = Product::factory()->count(1)->create();

        $payload = (new ClassificationPromptBuilder)->build($products, new TaxonomyCatalog, 'claude-test-model');

        $prompt = $payload['messages'][0]['content'];

        $this->assertStringContainsString('NON includere MAI', $prompt);
        $this->assertStringContainsString('tra gli attributi il codice articolo o la descrizione', $prompt);
    }

    /**
     * The AI must report value/unit exactly as read from the text; unit
     * conversion is explicitly forbidden and left to the application
     * (AttributeUnitConverter), and a numeric value must use a strict
     * decimal format (dot separator, no thousands grouping).
     */
    public function test_payload_forbids_unit_conversion_and_requires_a_strict_decimal_format(): void
    {
        $products = Product::factory()->count(1)->create();

        $payload = (new ClassificationPromptBuilder)->build($products, new TaxonomyCatalog, 'claude-test-model');

        $prompt = $payload['messages'][0]['content'];

        $this->assertStringContainsString('ESATTAMENTE come letta nel testo del prodotto', $prompt);
        $this->assertStringContainsString('NON convertire mai il valore', $prompt);
        $this->assertStringContainsString('come separatore decimale', $prompt);
        $this->assertStringContainsString('MAI un separatore delle migliaia', $prompt);
    }
}
