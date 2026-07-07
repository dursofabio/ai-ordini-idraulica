<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductAttribute;
use App\Services\Ai\DeepEnrichmentPromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-051 acceptance criteria — deep enrichment prompt payload:
 *  - The generated Messages API payload includes the product's own data
 *    (codice_articolo, description, known attributes).
 *  - The prompt uses the given model, instructs a unit-free attribute key
 *    naming convention, and imposes the anti-hallucination instruction for
 *    minimal starting data.
 *  - The requested max_tokens is generous enough for a rich markdown
 *    description plus a full attribute list.
 *
 * Runs against in-memory SQLite via RequiresDatabase.
 */
class DeepEnrichmentPromptBuilderTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_payload_includes_product_codice_articolo_and_description(): void
    {
        $product = Product::factory()->create([
            'codice_articolo' => 'ART-123',
            'description_raw' => 'CALDAIA A CONDENSAZIONE 25KW',
        ]);

        $payload = (new DeepEnrichmentPromptBuilder)->build($product, 'claude-smart-test');

        $prompt = $payload['messages'][0]['content'];

        $this->assertStringContainsString('ART-123', $prompt);
        $this->assertStringContainsString('CALDAIA A CONDENSAZIONE 25KW', $prompt);
    }

    public function test_payload_uses_the_given_model(): void
    {
        $product = Product::factory()->create();

        $payload = (new DeepEnrichmentPromptBuilder)->build($product, 'claude-smart-test');

        $this->assertSame('claude-smart-test', $payload['model']);
        $this->assertSame('user', $payload['messages'][0]['role']);
    }

    public function test_payload_includes_known_attributes_as_product_context(): void
    {
        $product = Product::factory()->create();

        ProductAttribute::factory()->for($product)->create([
            'key' => 'potenza',
            'value' => '25',
            'unit' => 'kW',
            'source' => 'ai',
        ]);

        $product->load('attributes');

        $payload = (new DeepEnrichmentPromptBuilder)->build($product, 'claude-smart-test');

        $prompt = $payload['messages'][0]['content'];

        $this->assertStringContainsString('potenza', $prompt);
        $this->assertStringContainsString('kW', $prompt);
    }

    public function test_payload_instructs_a_unit_free_key_naming_convention(): void
    {
        $product = Product::factory()->create();

        $payload = (new DeepEnrichmentPromptBuilder)->build($product, 'claude-smart-test');

        $prompt = $payload['messages'][0]['content'];

        $this->assertStringContainsString('SENZA includere l\'unità di misura nel nome', $prompt);
        $this->assertStringContainsString('MAI "potenza_kw"', $prompt);
    }

    /**
     * The AI must never propose the product's own article code, description,
     * or product type as a technical attribute — {@see DeepEnrichmentResponseValidator}
     * also enforces this as a hard filter, this only checks the prompt's own
     * instruction.
     */
    public function test_payload_forbids_proposing_the_products_own_identity_fields_as_attributes(): void
    {
        $product = Product::factory()->create();

        $payload = (new DeepEnrichmentPromptBuilder)->build($product, 'claude-smart-test');

        $prompt = $payload['messages'][0]['content'];

        $this->assertStringContainsString('NON includere MAI tra gli attributi il codice articolo', $prompt);
    }

    public function test_payload_instructs_a_strict_decimal_number_format(): void
    {
        $product = Product::factory()->create();

        $payload = (new DeepEnrichmentPromptBuilder)->build($product, 'claude-smart-test');

        $prompt = $payload['messages'][0]['content'];

        $this->assertStringContainsString('punto (.) come separatore decimale', $prompt);
        $this->assertStringContainsString('MAI un separatore delle migliaia', $prompt);
    }

    public function test_payload_instructs_against_inventing_attributes_with_minimal_data(): void
    {
        $product = Product::factory()->create();

        $payload = (new DeepEnrichmentPromptBuilder)->build($product, 'claude-smart-test');

        $prompt = $payload['messages'][0]['content'];

        $this->assertStringContainsString('NON inventare caratteristiche tecniche', $prompt);
        $this->assertStringContainsString('confidenza complessiva', $prompt);
    }

    public function test_payload_forbids_unit_conversion_and_requires_values_as_read(): void
    {
        $product = Product::factory()->create();

        $payload = (new DeepEnrichmentPromptBuilder)->build($product, 'claude-smart-test');

        $prompt = $payload['messages'][0]['content'];

        $this->assertStringContainsString('ESATTAMENTE come letta nel testo del prodotto', $prompt);
        $this->assertStringContainsString('NON convertire mai il valore', $prompt);
    }

    public function test_max_tokens_is_generous_for_a_rich_extended_description(): void
    {
        $product = Product::factory()->create();

        $payload = (new DeepEnrichmentPromptBuilder)->build($product, 'claude-smart-test');

        $this->assertGreaterThanOrEqual(2048, $payload['max_tokens']);
    }

    /**
     * The AI must also propose a product type, restricted to the product's
     * own name/type (never the brand, family, subfamily, or an attribute
     * value) — same restriction as {@see ClassificationPromptBuilder}'s
     * `product_type` field.
     */
    public function test_payload_instructs_the_ai_to_propose_a_product_type(): void
    {
        $product = Product::factory()->create();

        $payload = (new DeepEnrichmentPromptBuilder)->build($product, 'claude-smart-test');

        $prompt = $payload['messages'][0]['content'];

        $this->assertStringContainsString('tipo_prodotto', $prompt);
        $this->assertStringContainsString('MAI la marca, la famiglia, la sottofamiglia', $prompt);
    }

    public function test_payload_includes_the_products_known_type_as_context(): void
    {
        $product = Product::factory()->create(['product_type' => 'Caldaia a condensazione']);

        $payload = (new DeepEnrichmentPromptBuilder)->build($product, 'claude-smart-test');

        $prompt = $payload['messages'][0]['content'];

        $this->assertStringContainsString('tipo prodotto: Caldaia a condensazione', $prompt);
    }
}
