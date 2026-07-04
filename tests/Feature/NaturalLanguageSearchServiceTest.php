<?php

namespace Tests\Feature;

use App\Models\AttributeDefinition;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Services\Search\NaturalLanguageSearchService;
use App\Services\Search\SearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * US-048 TASK-11 — NaturalLanguageSearchService: hard filter + ranking on
 * residual text + filter fusion + graceful degradation:
 *  - An attribute extracted from the query excludes products with a
 *    different value even when the text is otherwise near-identical.
 *  - `interpretation` exposes the recognized text and a display label per
 *    applied filter.
 *  - Explicit page filters and query-extracted filters combine in AND.
 *  - With the parser disabled or the AI call failing, behavior degrades to
 *    plain SearchService full-text search — no regression vs US-047.
 */
class NaturalLanguageSearchServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.embedding', [
            'base_url' => 'https://embedding.test',
            'model' => 'bge-m3',
            'api_key' => null,
            'dimensions' => 4,
            'timeout' => 120,
            'retry_times' => 1,
            'retry_delay_ms' => 1,
        ]);

        config()->set('services.ai_provider', 'anthropic');

        config()->set('services.anthropic', [
            'api_key' => 'test-api-key',
            'model' => 'claude-test-model',
            'model_fast' => 'claude-fast-test',
            'model_smart' => 'claude-smart-test',
            'version' => '2023-06-01',
            'base_url' => 'https://api.anthropic.test',
            'timeout' => 120,
            'retry_times' => 0,
            'retry_delay_ms' => 1,
        ]);

        config()->set('search.natural_language', [
            'enabled' => true,
            'cache_ttl' => 3600,
        ]);

        AttributeDefinition::query()->updateOrCreate(['key' => 'attacco_pollici'], [
            'data_type' => 'numeric',
            'canonical_unit' => '"',
            'accepted_units' => ['"' => 1, 'POLLICI' => 1, 'IN' => 1],
            'description' => 'Dimensione dell\'attacco filettato, espressa in pollici (").',
        ]);
    }

    public function test_query_attribute_filter_excludes_products_with_different_value_despite_similar_text(): void
    {
        $matching = Product::factory()->create([
            'product_type' => 'Tubo inox',
            'description_clean' => 'Tubo inox per impianti idraulici',
        ]);
        ProductAttribute::factory()->create([
            'product_id' => $matching->id,
            'key' => 'attacco_pollici',
            'value_num' => 1,
        ]);

        $differentValue = Product::factory()->create([
            'product_type' => 'Tubo inox',
            'description_clean' => 'Tubo inox per impianti idraulici',
        ]);
        ProductAttribute::factory()->create([
            'product_id' => $differentValue->id,
            'key' => 'attacco_pollici',
            'value_num' => 2,
        ]);

        $this->fakeAiParse('tubo inox', [
            ['key' => 'attacco_pollici', 'value_num' => 1, 'unit' => 'pollici'],
        ]);

        $result = app(NaturalLanguageSearchService::class)->search('tubo inox 1 pollice');

        $productIds = $result->results->map(fn ($r) => $r->product->id);

        $this->assertTrue($productIds->contains($matching->id));
        $this->assertFalse($productIds->contains($differentValue->id));
    }

    public function test_interpretation_exposes_recognized_text_and_filter_display_label(): void
    {
        $product = Product::factory()->create([
            'product_type' => 'Tubo inox',
            'description_clean' => 'Tubo inox per impianti idraulici',
        ]);
        ProductAttribute::factory()->create([
            'product_id' => $product->id,
            'key' => 'attacco_pollici',
            'value_num' => 1,
        ]);

        $this->fakeAiParse('tubo inox', [
            ['key' => 'attacco_pollici', 'value_num' => 1, 'unit' => 'pollici'],
        ]);

        $result = app(NaturalLanguageSearchService::class)->search('tubo inox 1 pollice');

        $this->assertSame('tubo inox', $result->interpretation->recognizedText);
        $this->assertCount(1, $result->interpretation->appliedFilters);
        $this->assertStringContainsString('attacco filettato', $result->interpretation->appliedFilters[0]->toDisplayLabel());
    }

    public function test_explicit_page_filters_and_query_extracted_filters_combine_in_and(): void
    {
        $brandA = Brand::factory()->create();
        $brandB = Brand::factory()->create();

        $matching = Product::factory()->create([
            'brand_id' => $brandA->id,
            'product_type' => 'Tubo inox',
            'description_clean' => 'Tubo inox per impianti idraulici',
        ]);
        ProductAttribute::factory()->create([
            'product_id' => $matching->id,
            'key' => 'attacco_pollici',
            'value_num' => 1,
        ]);

        // Same attribute value, but wrong brand: excluded by the explicit
        // page filter even though the query-extracted attribute matches.
        $wrongBrand = Product::factory()->create([
            'brand_id' => $brandB->id,
            'product_type' => 'Tubo inox',
            'description_clean' => 'Tubo inox per impianti idraulici',
        ]);
        ProductAttribute::factory()->create([
            'product_id' => $wrongBrand->id,
            'key' => 'attacco_pollici',
            'value_num' => 1,
        ]);

        $this->fakeAiParse('tubo inox', [
            ['key' => 'attacco_pollici', 'value_num' => 1, 'unit' => 'pollici'],
        ]);

        $result = app(NaturalLanguageSearchService::class)->search('tubo inox 1 pollice', ['brand_id' => $brandA->id]);

        $productIds = $result->results->map(fn ($r) => $r->product->id);

        $this->assertTrue($productIds->contains($matching->id));
        $this->assertFalse($productIds->contains($wrongBrand->id));
    }

    public function test_disabled_parser_degrades_to_plain_full_text_search_matching_search_service(): void
    {
        config()->set('search.natural_language.enabled', false);

        $product = Product::factory()->create([
            'product_type' => 'Scaldabagno a pompa di calore',
            'description_clean' => 'Scaldabagno a pompa di calore per uso domestico',
        ]);

        Http::fake([
            'https://embedding.test/*' => Http::response(['embedding' => [0.1, 0.2, 0.3, 0.4]]),
        ]);

        $nlResult = app(NaturalLanguageSearchService::class)->search('scaldabagno pompa calore');
        $directResult = app(SearchService::class)->search('scaldabagno pompa calore');

        $this->assertSame(
            $directResult->map(fn ($r) => $r->product->id)->all(),
            $nlResult->results->map(fn ($r) => $r->product->id)->all(),
        );

        $this->assertSame('scaldabagno pompa calore', $nlResult->interpretation->recognizedText);
        $this->assertSame([], $nlResult->interpretation->appliedFilters);

        // No AI call at all: only the embedding provider was hit (once for
        // each identical service call, since query-embedding cache keys are
        // shared but each service instance here is freshly resolved).
        Http::assertSent(fn ($request): bool => str_starts_with((string) $request->url(), 'https://embedding.test'));
        Http::assertNotSent(fn ($request): bool => str_starts_with((string) $request->url(), 'https://api.anthropic.test'));
    }

    public function test_ai_parse_failure_degrades_to_plain_full_text_search(): void
    {
        $product = Product::factory()->create([
            'product_type' => 'Scaldabagno a pompa di calore',
            'description_clean' => 'Scaldabagno a pompa di calore per uso domestico',
        ]);

        Http::fake([
            'https://embedding.test/*' => Http::response(['embedding' => [0.1, 0.2, 0.3, 0.4]]),
            'https://api.anthropic.test/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'not valid json {{{']],
                'usage' => ['input_tokens' => 5, 'output_tokens' => 5],
            ]),
        ]);

        $result = app(NaturalLanguageSearchService::class)->search('scaldabagno pompa calore');

        $this->assertSame('scaldabagno pompa calore', $result->interpretation->recognizedText);
        $this->assertSame([], $result->interpretation->appliedFilters);
        $this->assertTrue($result->results->map(fn ($r) => $r->product->id)->contains($product->id));
    }

    /**
     * @param  array<int, array<string, mixed>>  $attributes
     */
    private function fakeAiParse(string $recognizedText, array $attributes): void
    {
        Http::fake([
            'https://embedding.test/*' => Http::response(['embedding' => [0.1, 0.2, 0.3, 0.4]]),
            'https://api.anthropic.test/*' => Http::response([
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode([
                        'recognized_text' => $recognizedText,
                        'attributes' => $attributes,
                    ], JSON_UNESCAPED_UNICODE),
                ]],
                'usage' => ['input_tokens' => 50, 'output_tokens' => 20],
            ]),
        ]);
    }
}
