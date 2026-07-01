<?php

namespace Tests\Feature;

use App\Jobs\ClassifyProductsBatchJob;
use App\Models\Brand;
use App\Models\EnrichmentLog;
use App\Models\Product;
use App\Services\Ai\ClassificationPromptBuilder;
use App\Services\Ai\ClassificationResponseValidator;
use App\Services\Ai\ClaudeClient;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-014 acceptance criteria — batch classification job:
 *  - A valid response on the first attempt (all confidence >= 60) creates
 *    one EnrichmentLog per product with model = model_fast, tokens divided
 *    across the batch, and never calls the smart model.
 *  - Two consecutive invalid-JSON responses mark every product in the batch
 *    `needs_review`, log the validation error per product, and only 2 HTTP
 *    calls are made (one retry), without throwing.
 *  - A result with confidence < 60 triggers one additional per-product call
 *    using model_smart, and the final log for that product reports
 *    model_smart.
 *
 * US-015 acceptance criteria — confidence-gated enrichment write-back,
 * exercised end-to-end through the job:
 *  - confidence >= 85 populates brand_id and marks the product 'enriched'
 *    with source 'ai'.
 *  - confidence 60-84 populates brand_id but marks the product
 *    'needs_review'.
 *  - confidence < 60, even after escalation to the smart model, leaves
 *    brand_id/family_id null and the product 'needs_review'.
 *
 * Runs against in-memory SQLite via RequiresDatabase.
 */
class ClassifyProductsBatchJobTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.anthropic', [
            'api_key' => 'test-api-key',
            'model' => 'claude-test-model',
            'model_fast' => 'claude-fast-test',
            'model_smart' => 'claude-smart-test',
            'version' => '2023-06-01',
            'base_url' => 'https://api.anthropic.test',
            'timeout' => 120,
            'retry_times' => 1,
            'retry_delay_ms' => 1,
        ]);
    }

    public function test_successful_classification_logs_one_entry_per_product_with_fast_model(): void
    {
        Brand::factory()->create(['name' => 'Grohe']);

        $products = Product::factory()->count(2)->create(['enrichment_status' => 'pending']);

        Http::fake([
            '*' => Http::response($this->anthropicBody($products, confidence: 90)),
        ]);

        (new ClassifyProductsBatchJob($products->pluck('id')->all()))->handle(
            app(ClaudeClient::class),
            app(ClassificationPromptBuilder::class),
            app(ClassificationResponseValidator::class),
        );

        $this->assertSame(2, EnrichmentLog::query()->where('step', 'ai_classification')->count());

        foreach ($products as $product) {
            $log = EnrichmentLog::query()->where('product_id', $product->id)->first();

            $this->assertNotNull($log);
            $this->assertSame('claude-fast-test', $log->model);
            $this->assertSame(90, $log->confidence);
        }

        Http::assertSentCount(1);
    }

    public function test_two_invalid_json_responses_mark_batch_needs_review_and_stop_after_retry(): void
    {
        $products = Product::factory()->count(2)->create(['enrichment_status' => 'pending']);

        Http::fake([
            '*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'not valid json {{{']],
                'usage' => ['input_tokens' => 5, 'output_tokens' => 5],
            ]),
        ]);

        (new ClassifyProductsBatchJob($products->pluck('id')->all()))->handle(
            app(ClaudeClient::class),
            app(ClassificationPromptBuilder::class),
            app(ClassificationResponseValidator::class),
        );

        Http::assertSentCount(2);

        foreach ($products as $product) {
            $this->assertSame('needs_review', $product->fresh()->enrichment_status);

            $log = EnrichmentLog::query()->where('product_id', $product->id)->first();
            $this->assertNotNull($log);
            $this->assertNull($log->confidence);
        }
    }

    public function test_low_confidence_result_escalates_to_smart_model_for_that_product_only(): void
    {
        Brand::factory()->create(['name' => 'Grohe']);

        $lowConfidenceProduct = Product::factory()->create(['enrichment_status' => 'pending']);
        $highConfidenceProduct = Product::factory()->create(['enrichment_status' => 'pending']);

        $products = collect([$lowConfidenceProduct, $highConfidenceProduct]);

        $batchBody = [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'results' => [
                        $this->resultFor($lowConfidenceProduct, confidence: 40),
                        $this->resultFor($highConfidenceProduct, confidence: 95),
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ]],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 40],
        ];

        $escalationBody = [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'results' => [$this->resultFor($lowConfidenceProduct, confidence: 85)],
                ], JSON_UNESCAPED_UNICODE),
            ]],
            'usage' => ['input_tokens' => 30, 'output_tokens' => 10],
        ];

        Http::fake([
            '*' => Http::sequence()
                ->push($batchBody)
                ->push($escalationBody),
        ]);

        (new ClassifyProductsBatchJob($products->pluck('id')->all()))->handle(
            app(ClaudeClient::class),
            app(ClassificationPromptBuilder::class),
            app(ClassificationResponseValidator::class),
        );

        Http::assertSentCount(2);

        Http::assertSent(function ($request): bool {
            return ($request->data()['model'] ?? null) === 'claude-smart-test';
        });

        $escalatedLog = EnrichmentLog::query()->where('product_id', $lowConfidenceProduct->id)->first();
        $this->assertNotNull($escalatedLog);
        $this->assertSame('claude-smart-test', $escalatedLog->model);
        $this->assertSame(85, $escalatedLog->confidence);

        $normalLog = EnrichmentLog::query()->where('product_id', $highConfidenceProduct->id)->first();
        $this->assertNotNull($normalLog);
        $this->assertSame('claude-fast-test', $normalLog->model);
        $this->assertSame(95, $normalLog->confidence);
    }

    public function test_high_confidence_classification_enriches_the_product(): void
    {
        Brand::factory()->create(['name' => 'Grohe']);

        $products = Product::factory()->count(2)->create(['enrichment_status' => 'pending']);

        Http::fake([
            '*' => Http::response($this->anthropicBody($products, confidence: 90)),
        ]);

        (new ClassifyProductsBatchJob($products->pluck('id')->all()))->handle(
            app(ClaudeClient::class),
            app(ClassificationPromptBuilder::class),
            app(ClassificationResponseValidator::class),
        );

        foreach ($products as $product) {
            $fresh = $product->fresh();
            $this->assertNotNull($fresh->brand_id);
            $this->assertSame('enriched', $fresh->enrichment_status);
            $this->assertSame('ai', $fresh->source);
            $this->assertSame(90, $fresh->confidence);
        }
    }

    public function test_medium_confidence_classification_applies_values_but_needs_review(): void
    {
        Brand::factory()->create(['name' => 'Grohe']);

        $products = Product::factory()->count(2)->create(['enrichment_status' => 'pending']);

        Http::fake([
            '*' => Http::response($this->anthropicBody($products, confidence: 70)),
        ]);

        (new ClassifyProductsBatchJob($products->pluck('id')->all()))->handle(
            app(ClaudeClient::class),
            app(ClassificationPromptBuilder::class),
            app(ClassificationResponseValidator::class),
        );

        foreach ($products as $product) {
            $fresh = $product->fresh();
            $this->assertNotNull($fresh->brand_id);
            $this->assertSame('needs_review', $fresh->enrichment_status);
            $this->assertSame('ai', $fresh->source);
            $this->assertSame(70, $fresh->confidence);
        }
    }

    public function test_low_confidence_classification_after_escalation_applies_no_values(): void
    {
        Brand::factory()->create(['name' => 'Grohe']);

        $product = Product::factory()->create(['enrichment_status' => 'pending']);

        $batchBody = [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'results' => [$this->resultFor($product, confidence: 30)],
                ], JSON_UNESCAPED_UNICODE),
            ]],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 40],
        ];

        $escalationBody = [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'results' => [$this->resultFor($product, confidence: 45)],
                ], JSON_UNESCAPED_UNICODE),
            ]],
            'usage' => ['input_tokens' => 30, 'output_tokens' => 10],
        ];

        Http::fake([
            '*' => Http::sequence()
                ->push($batchBody)
                ->push($escalationBody),
        ]);

        (new ClassifyProductsBatchJob([$product->id]))->handle(
            app(ClaudeClient::class),
            app(ClassificationPromptBuilder::class),
            app(ClassificationResponseValidator::class),
        );

        Http::assertSentCount(2);

        $fresh = $product->fresh();
        $this->assertNull($fresh->brand_id);
        $this->assertNull($fresh->family_id);
        $this->assertSame('needs_review', $fresh->enrichment_status);
        $this->assertNull($fresh->source);
        $this->assertNull($fresh->confidence);
    }

    /**
     * @param  EloquentCollection<int, Product>  $products
     * @return array<string, mixed>
     */
    private function anthropicBody(EloquentCollection $products, int $confidence): array
    {
        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'results' => $products->map(fn (Product $product) => $this->resultFor($product, $confidence))->all(),
                ], JSON_UNESCAPED_UNICODE),
            ]],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 40],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resultFor(Product $product, int $confidence): array
    {
        return [
            'codice_articolo' => $product->codice_articolo,
            'brand' => 'Grohe',
            'family' => null,
            'subfamily' => null,
            'product_type' => 'Miscelatore',
            'enriched_description' => 'Descrizione arricchita',
            'confidence' => $confidence,
        ];
    }
}
