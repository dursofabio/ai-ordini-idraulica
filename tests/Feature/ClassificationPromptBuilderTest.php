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
            'key' => 'potenza_kw',
            'value_num' => 1.5,
            'value_text' => null,
            'unit' => 'kW',
            'source' => 'regex',
        ]);

        $products = Product::query()->with('attributes')->whereKey($product->id)->get();

        $payload = (new ClassificationPromptBuilder)->build($products, new TaxonomyCatalog, 'claude-test-model');

        $prompt = $payload['messages'][0]['content'];

        $this->assertStringContainsString('potenza_kw', $prompt);
        $this->assertStringContainsString('1.500', $prompt);
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
        $this->assertStringContainsString('"value_num"', $prompt);
        $this->assertStringContainsString('"value_text"', $prompt);
        $this->assertStringContainsString('"confidence"', $prompt);
    }
}
