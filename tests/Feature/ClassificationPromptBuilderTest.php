<?php

namespace Tests\Feature;

use App\Models\AttributeDefinition;
use App\Models\Brand;
use App\Models\Family;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Services\Ai\AttributeVocabulary;
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
 * US-043 acceptance criteria — attribute extraction anchored to the
 * attribute registry:
 *  - The registry vocabulary (key, type, canonical unit, description) is
 *    embedded in the prompt.
 *  - The prompt imposes the exclusive use of canonical keys and no longer
 *    contains the free-key instruction.
 *  - The prompt requires value/unit exactly as read from the text and
 *    forbids unit conversion.
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

        $payload = (new ClassificationPromptBuilder)->build($products, new TaxonomyCatalog, new AttributeVocabulary, 'claude-test-model');

        $prompt = $payload['messages'][0]['content'];

        foreach ($products as $product) {
            $this->assertStringContainsString($product->codice_articolo, $prompt);
        }
    }

    public function test_max_tokens_scales_with_batch_size_so_a_full_batch_response_is_never_truncated(): void
    {
        $builder = new ClassificationPromptBuilder;
        $taxonomy = new TaxonomyCatalog;
        $vocabulary = new AttributeVocabulary;

        // A verbose real-world result costs ~210 output tokens per product:
        // a 40-product batch must get well more than 40 x 210 = 8400.
        $fullBatch = $builder->build(Product::factory()->count(40)->create(), $taxonomy, $vocabulary, 'm');
        $this->assertGreaterThanOrEqual(40 * 300, $fullBatch['max_tokens']);

        // A single-product escalation call still gets a workable floor.
        $single = $builder->build(Product::factory()->count(1)->create(), $taxonomy, $vocabulary, 'm');
        $this->assertGreaterThanOrEqual(1024, $single['max_tokens']);
    }

    public function test_payload_includes_existing_taxonomy_brands_and_families(): void
    {
        Brand::factory()->create(['name' => 'Grohe']);
        Family::factory()->create(['name' => 'Rubinetteria']);

        $products = Product::factory()->count(1)->create();

        $payload = (new ClassificationPromptBuilder)->build($products, new TaxonomyCatalog, new AttributeVocabulary, 'claude-test-model');

        $prompt = $payload['messages'][0]['content'];

        $this->assertStringContainsString('Grohe', $prompt);
        $this->assertStringContainsString('Rubinetteria', $prompt);
    }

    public function test_payload_uses_the_given_model_and_role(): void
    {
        $products = Product::factory()->count(1)->create();

        $payload = (new ClassificationPromptBuilder)->build($products, new TaxonomyCatalog, new AttributeVocabulary, 'claude-fast-model');

        $this->assertSame('claude-fast-model', $payload['model']);
        $this->assertSame('user', $payload['messages'][0]['role']);
    }

    public function test_payload_includes_known_regex_attributes_as_product_context(): void
    {
        $product = Product::factory()->create();

        ProductAttribute::factory()->for($product)->create([
            'key' => 'potenza_kw',
            'value_num' => 1.5,
            'value_text' => null,
            'unit' => 'kW',
            'source' => 'regex',
        ]);

        $products = Product::query()->with('attributes')->whereKey($product->id)->get();

        $payload = (new ClassificationPromptBuilder)->build($products, new TaxonomyCatalog, new AttributeVocabulary, 'claude-test-model');

        $prompt = $payload['messages'][0]['content'];

        $this->assertStringContainsString('potenza_kw', $prompt);
        $this->assertStringContainsString('1.500', $prompt);
        $this->assertStringContainsString('kW', $prompt);
        $this->assertStringContainsString('regex', $prompt);
    }

    public function test_payload_instructs_on_attributes_field_with_confidence_in_response_schema(): void
    {
        $products = Product::factory()->count(1)->create();

        $payload = (new ClassificationPromptBuilder)->build($products, new TaxonomyCatalog, new AttributeVocabulary, 'claude-test-model');

        $prompt = $payload['messages'][0]['content'];

        $this->assertStringContainsString('"attributes"', $prompt);
        $this->assertStringContainsString('"key"', $prompt);
        $this->assertStringContainsString('"value_num"', $prompt);
        $this->assertStringContainsString('"value_text"', $prompt);
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

        $payload = (new ClassificationPromptBuilder)->build($products, new TaxonomyCatalog, new AttributeVocabulary, 'claude-test-model');

        $prompt = $payload['messages'][0]['content'];

        $this->assertStringContainsString('product_type', $prompt);
        $this->assertStringContainsString('MAI la marca, la famiglia, la sottofamiglia o', $prompt);
    }

    /**
     * US-043 AC1: the prompt embeds the registry vocabulary — key, type,
     * canonical unit, and description — for every seeded definition.
     */
    public function test_payload_includes_the_attribute_registry_vocabulary(): void
    {
        AttributeDefinition::factory()->numeric()->create([
            'key' => 'potenza_kw',
            'canonical_unit' => 'kW',
            'description' => 'Potenza nominale dell\'apparecchio, espressa in kilowatt (kW).',
        ]);

        $products = Product::factory()->count(1)->create();

        $payload = (new ClassificationPromptBuilder)->build($products, new TaxonomyCatalog, new AttributeVocabulary, 'claude-test-model');

        $prompt = $payload['messages'][0]['content'];

        $this->assertStringContainsString('potenza_kw', $prompt);
        $this->assertStringContainsString('kW', $prompt);
        $this->assertStringContainsString("Potenza nominale dell'apparecchio, espressa in kilowatt (kW).", $prompt);
    }

    /**
     * US-043 AC1: the free-key instruction is gone — the prompt requires
     * exclusive use of canonical registry keys.
     */
    public function test_payload_requires_canonical_keys_and_drops_the_free_key_instruction(): void
    {
        $products = Product::factory()->count(1)->create();

        $payload = (new ClassificationPromptBuilder)->build($products, new TaxonomyCatalog, new AttributeVocabulary, 'claude-test-model');

        $prompt = $payload['messages'][0]['content'];

        $this->assertStringContainsString('ESCLUSIVAMENTE le chiavi del registro attributi', $prompt);
        $this->assertStringNotContainsString('chiave libera, in snake_case', $prompt);
    }

    /**
     * US-043 AC2: the AI must report value/unit exactly as read from the
     * text; unit conversion is explicitly forbidden and left to the
     * application (AttributeUnitConverter).
     */
    public function test_payload_forbids_unit_conversion_and_requires_values_as_read(): void
    {
        $products = Product::factory()->count(1)->create();

        $payload = (new ClassificationPromptBuilder)->build($products, new TaxonomyCatalog, new AttributeVocabulary, 'claude-test-model');

        $prompt = $payload['messages'][0]['content'];

        $this->assertStringContainsString('ESATTAMENTE come letti nel testo del prodotto', $prompt);
        $this->assertStringContainsString('NON convertire mai il valore', $prompt);
    }
}
