<?php

namespace Tests\Feature;

use App\Jobs\ClassifyProductsBatchJob;
use App\Models\Brand;
use App\Models\EnrichmentLog;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Services\Ai\AiClient;
use App\Services\Ai\ClassificationPromptBuilder;
use App\Services\Ai\ClassificationResponseValidator;
use App\Services\Ai\ClassifiedProduct;
use App\Services\Ai\ClaudeClient;
use App\Services\Enrichment\AiSpendGuard;
use App\Services\Enrichment\EnrichmentCache;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
 * US-034 acceptance criteria — provider abstraction:
 *  - The job resolves whichever AiClient the container binds
 *    (config('services.ai_provider')) and produces the same structured
 *    EnrichmentLog output (brand/family/subfamily/product_type/
 *    enriched_description/confidence) whether that binding resolves to
 *    ClaudeClient or OpenRouterClient, differing only in the logged model.
 *
 * Rate-limit resilience (added after an OpenRouter free-tier model started
 * returning HTTP 429 for a real overnight run): a persistent
 * RequestException must not permanently fail the job into `failed_jobs` —
 * {@see ClassifyProductsBatchJob::middleware()} throttles it back onto the
 * queue instead, and {@see ClassifyProductsBatchJob::retryUntil()} keeps it
 * eligible for retry across the whole run.
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

        config()->set('cache.default', 'array');

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
            'pricing' => [
                'model_fast' => ['input_per_million' => 0.8, 'output_per_million' => 4.0],
                'model_smart' => ['input_per_million' => 3.0, 'output_per_million' => 15.0],
            ],
            'batch_cost_cap' => null,
            'enrichment_cache_ttl' => null,
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

    public function test_product_with_precached_description_does_not_call_the_api(): void
    {
        Brand::factory()->create(['name' => 'Grohe']);

        $cachedProduct = Product::factory()->create([
            'enrichment_status' => 'pending',
            'description_raw' => 'Miscelatore da cucina Grohe cromato',
        ]);

        (new EnrichmentCache)->put($cachedProduct, new ClassifiedProduct(
            codiceArticolo: $cachedProduct->codice_articolo,
            brand: 'Grohe',
            family: null,
            subfamily: null,
            productType: 'Miscelatore',
            enrichedDescription: 'Descrizione arricchita cacheata',
            confidence: 92,
        ));

        Http::fake();

        (new ClassifyProductsBatchJob([$cachedProduct->id]))->handle(
            app(ClaudeClient::class),
            app(ClassificationPromptBuilder::class),
            app(ClassificationResponseValidator::class),
        );

        Http::assertNothingSent();

        $fresh = $cachedProduct->fresh();
        $this->assertNotNull($fresh->brand_id);
        $this->assertSame('enriched', $fresh->enrichment_status);
        $this->assertSame('ai', $fresh->source);
        $this->assertSame(92, $fresh->confidence);
    }

    public function test_two_products_with_identical_description_in_the_same_batch_generate_one_call_and_share_the_result(): void
    {
        Brand::factory()->create(['name' => 'Grohe']);

        $sharedDescription = 'Valvola a sfera 1/2 pollice ottone';

        $first = Product::factory()->create(['enrichment_status' => 'pending', 'description_raw' => $sharedDescription]);
        $second = Product::factory()->create(['enrichment_status' => 'pending', 'description_raw' => $sharedDescription]);

        Http::fake([
            '*' => Http::response($this->anthropicBody(new EloquentCollection([$first]), confidence: 90)),
        ]);

        (new ClassifyProductsBatchJob([$first->id, $second->id]))->handle(
            app(ClaudeClient::class),
            app(ClassificationPromptBuilder::class),
            app(ClassificationResponseValidator::class),
        );

        Http::assertSentCount(1);

        foreach ([$first, $second] as $product) {
            $fresh = $product->fresh();
            $this->assertNotNull($fresh->brand_id, "Product {$product->codice_articolo} should have been enriched.");
            $this->assertSame('enriched', $fresh->enrichment_status);
            $this->assertSame(90, $fresh->confidence);
        }
    }

    public function test_duplicate_of_an_escalated_representative_logs_the_smart_model_it_actually_received(): void
    {
        Brand::factory()->create(['name' => 'Grohe']);

        $sharedDescription = 'Miscelatore monocomando lavabo bianco';

        $representative = Product::factory()->create(['enrichment_status' => 'pending', 'description_raw' => $sharedDescription]);
        $duplicate = Product::factory()->create(['enrichment_status' => 'pending', 'description_raw' => $sharedDescription]);

        $batchBody = [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'results' => [$this->resultFor($representative, confidence: 40)],
                ], JSON_UNESCAPED_UNICODE),
            ]],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 40],
        ];

        $escalationBody = [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'results' => [$this->resultFor($representative, confidence: 88)],
                ], JSON_UNESCAPED_UNICODE),
            ]],
            'usage' => ['input_tokens' => 30, 'output_tokens' => 10],
        ];

        Http::fake([
            '*' => Http::sequence()
                ->push($batchBody)
                ->push($escalationBody),
        ]);

        (new ClassifyProductsBatchJob([$representative->id, $duplicate->id]))->handle(
            app(ClaudeClient::class),
            app(ClassificationPromptBuilder::class),
            app(ClassificationResponseValidator::class),
        );

        Http::assertSentCount(2);

        // Both rows must report the smart model, since the duplicate's
        // applied result was borrowed from the representative's escalated
        // (smart-model) classification, not from the fast-model batch call.
        $representativeLog = EnrichmentLog::query()->where('product_id', $representative->id)->first();
        $this->assertNotNull($representativeLog);
        $this->assertSame('claude-smart-test', $representativeLog->model);
        $this->assertSame(88, $representativeLog->confidence);

        $duplicateLog = EnrichmentLog::query()->where('product_id', $duplicate->id)->first();
        $this->assertNotNull($duplicateLog);
        $this->assertSame('claude-smart-test', $duplicateLog->model);
        $this->assertSame(88, $duplicateLog->confidence);
        $this->assertSame(0, $duplicateLog->tokens_in);
        $this->assertSame(0, $duplicateLog->tokens_out);
    }

    public function test_batch_fully_resolved_from_cache_sends_no_http_request(): void
    {
        Brand::factory()->create(['name' => 'Grohe']);

        $products = Product::factory()->count(2)->create(['enrichment_status' => 'pending']);
        $cache = new EnrichmentCache;

        foreach ($products as $product) {
            $cache->put($product, new ClassifiedProduct(
                codiceArticolo: $product->codice_articolo,
                brand: 'Grohe',
                family: null,
                subfamily: null,
                productType: 'Miscelatore',
                enrichedDescription: 'Descrizione arricchita cacheata',
                confidence: 90,
            ));
        }

        Http::fake();

        (new ClassifyProductsBatchJob($products->pluck('id')->all()))->handle(
            app(ClaudeClient::class),
            app(ClassificationPromptBuilder::class),
            app(ClassificationResponseValidator::class),
        );

        Http::assertNothingSent();
    }

    public function test_successful_classification_populates_cache_and_a_later_reimport_skips_the_api_call(): void
    {
        Brand::factory()->create(['name' => 'Grohe']);

        $originalProduct = Product::factory()->create([
            'enrichment_status' => 'pending',
            'description_raw' => 'Rubinetto a sfera 3/4 pollice',
        ]);

        // Simulate a reimport: a second product row with the same
        // description_raw, created upfront (like the original) so both
        // rows' ProductBase-creation side effects (embedding dispatch) run
        // before Http::fake() is armed below and are not counted as
        // classification calls.
        $reimportedProduct = Product::factory()->create([
            'enrichment_status' => 'pending',
            'description_raw' => 'Rubinetto a sfera 3/4 pollice',
        ]);

        Http::fake([
            '*' => Http::response($this->anthropicBody(new EloquentCollection([$originalProduct]), confidence: 90)),
        ]);

        (new ClassifyProductsBatchJob([$originalProduct->id]))->handle(
            app(ClaudeClient::class),
            app(ClassificationPromptBuilder::class),
            app(ClassificationResponseValidator::class),
        );

        Http::assertSentCount(1);

        $cached = (new EnrichmentCache)->get($originalProduct->fresh());
        $this->assertNotNull($cached);

        (new ClassifyProductsBatchJob([$reimportedProduct->id]))->handle(
            app(ClaudeClient::class),
            app(ClassificationPromptBuilder::class),
            app(ClassificationResponseValidator::class),
        );

        // Still only the one call made for the original product; the
        // reimported product resolved entirely from cache.
        Http::assertSentCount(1);

        $fresh = $reimportedProduct->fresh();
        $this->assertNotNull($fresh->brand_id);
        $this->assertSame('enriched', $fresh->enrichment_status);
    }

    public function test_job_stops_and_logs_when_spend_cap_already_exceeded(): void
    {
        config()->set('services.anthropic.batch_cost_cap', 1.0);

        $products = Product::factory()->count(2)->create(['enrichment_status' => 'pending']);
        $job = new ClassifyProductsBatchJob($products->pluck('id')->all(), 'run-cap-exceeded');

        (new AiSpendGuard)->spend('run-cap-exceeded', 5.0);

        Http::fake();

        Log::shouldReceive('warning')
            ->once()
            ->with('Tetto di spesa AI raggiunto: elaborazione interrotta', \Mockery::on(
                static fn (array $context): bool => $context['run_id'] === 'run-cap-exceeded'
                    && $context['batch_cost_cap'] === 1.0
            ));

        $job->handle(
            app(ClaudeClient::class),
            app(ClassificationPromptBuilder::class),
            app(ClassificationResponseValidator::class),
        );

        Http::assertNothingSent();

        foreach ($products as $product) {
            $this->assertSame('needs_review', $product->fresh()->enrichment_status);
        }
    }

    public function test_cap_exceeded_mid_escalation_keeps_fast_model_results_and_logs_once(): void
    {
        Brand::factory()->create(['name' => 'Grohe']);

        // Pricing from setUp(): model_smart costs $3.0/$15.0 per million
        // input/output tokens. Each escalation call below reports
        // 1,000,000 in / 1,000,000 out => costs exactly $18.0. With the cap
        // set to $18.0 and nothing else spent yet, the first escalation call
        // is allowed (pre-call check passes at exactly-at-cap spend of 0)
        // and its $18.0 spend then exactly reaches the cap, so the second
        // escalation call's pre-call check sees capExceeded() = true and is
        // skipped.
        config()->set('services.anthropic.batch_cost_cap', 18.0);

        $firstLowConfidence = Product::factory()->create(['enrichment_status' => 'pending']);
        $secondLowConfidence = Product::factory()->create(['enrichment_status' => 'pending']);

        $products = collect([$firstLowConfidence, $secondLowConfidence]);

        $batchBody = [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'results' => [
                        $this->resultFor($firstLowConfidence, confidence: 40),
                        $this->resultFor($secondLowConfidence, confidence: 45),
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ]],
            // Negligible usage so the initial batch call itself does not
            // make a dent in the $18.0 cap.
            'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
        ];

        $escalationBody = [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'results' => [$this->resultFor($firstLowConfidence, confidence: 85)],
                ], JSON_UNESCAPED_UNICODE),
            ]],
            'usage' => ['input_tokens' => 1_000_000, 'output_tokens' => 1_000_000],
        ];

        Http::fake([
            '*' => Http::sequence()
                ->push($batchBody)
                ->push($escalationBody),
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->with('Tetto di spesa AI raggiunto durante l\'escalation: prodotti rimanenti mantengono il risultato fast-model', \Mockery::type('array'));

        (new ClassifyProductsBatchJob($products->pluck('id')->all(), 'run-cap-mid-escalation'))->handle(
            app(ClaudeClient::class),
            app(ClassificationPromptBuilder::class),
            app(ClassificationResponseValidator::class),
        );

        // Initial batch call + exactly one escalation call (for the first
        // low-confidence product); the second low-confidence product's
        // escalation is skipped because the cap was reached by the first
        // escalation's recorded spend.
        Http::assertSentCount(2);

        $firstLog = EnrichmentLog::query()->where('product_id', $firstLowConfidence->id)->first();
        $this->assertNotNull($firstLog);
        $this->assertSame('claude-smart-test', $firstLog->model);
        $this->assertSame(85, $firstLog->confidence);

        // The second low-confidence product never got its escalation call,
        // so it keeps its original fast-model result.
        $secondLog = EnrichmentLog::query()->where('product_id', $secondLowConfidence->id)->first();
        $this->assertNotNull($secondLog);
        $this->assertSame('claude-fast-test', $secondLog->model);
        $this->assertSame(45, $secondLog->confidence);
    }

    public function test_job_produces_structured_output_with_the_anthropic_provider(): void
    {
        Brand::factory()->create(['name' => 'Grohe']);
        config()->set('services.ai_provider', 'anthropic');

        $product = Product::factory()->create(['enrichment_status' => 'pending']);

        Http::fake([
            '*' => Http::response($this->anthropicBody(new EloquentCollection([$product]), confidence: 90)),
        ]);

        (new ClassifyProductsBatchJob([$product->id]))->handle(
            app(AiClient::class),
            app(ClassificationPromptBuilder::class),
            app(ClassificationResponseValidator::class),
        );

        $log = EnrichmentLog::query()->where('product_id', $product->id)->first();

        $this->assertNotNull($log);
        $this->assertSame('claude-fast-test', $log->model);
        $this->assertSame(90, $log->confidence);
        $this->assertSame($this->expectedStructuredOutput(), $log->output);
    }

    public function test_job_produces_the_same_structured_output_with_the_openrouter_provider(): void
    {
        Brand::factory()->create(['name' => 'Grohe']);

        config()->set('services.openrouter', [
            'api_key' => 'test-openrouter-key',
            'model' => 'openrouter-test-model',
            'base_url' => 'https://openrouter.test/api/v1',
            'timeout' => 120,
            'retry_times' => 1,
            'retry_delay_ms' => 1,
        ]);
        config()->set('services.ai_provider', 'openrouter');

        $product = Product::factory()->create(['enrichment_status' => 'pending']);

        Http::fake([
            '*' => Http::response($this->openRouterBody(new EloquentCollection([$product]), confidence: 90)),
        ]);

        (new ClassifyProductsBatchJob([$product->id]))->handle(
            app(AiClient::class),
            app(ClassificationPromptBuilder::class),
            app(ClassificationResponseValidator::class),
        );

        $log = EnrichmentLog::query()->where('product_id', $product->id)->first();

        $this->assertNotNull($log);
        $this->assertSame('openrouter-test-model', $log->model);
        $this->assertSame(90, $log->confidence);
        $this->assertSame($this->expectedStructuredOutput(), $log->output);
    }

    public function test_job_throttles_on_repeated_request_exceptions_instead_of_failing_permanently(): void
    {
        $job = new ClassifyProductsBatchJob([1]);

        $middleware = $job->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(ThrottlesExceptions::class, $middleware[0]);
        $this->assertTrue(Carbon::now()->addHours(7)->lt($job->retryUntil()));
    }

    /**
     * The structured classification fields expected regardless of which AI
     * provider produced them (US-034): only `model` is allowed to differ
     * between the Anthropic and OpenRouter runs.
     *
     * @return array<string, mixed>
     */
    private function expectedStructuredOutput(): array
    {
        return [
            'brand' => 'Grohe',
            'family' => null,
            'subfamily' => null,
            'product_type' => 'Miscelatore',
            'enriched_description' => 'Descrizione arricchita',
        ];
    }

    /**
     * @param  EloquentCollection<int, Product>  $products
     * @return array<string, mixed>
     */
    private function openRouterBody(EloquentCollection $products, int $confidence): array
    {
        return [
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'results' => $products->map(fn (Product $product) => $this->resultFor($product, $confidence))->all(),
                    ], JSON_UNESCAPED_UNICODE),
                ],
            ]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 40],
        ];
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
     * @param  list<array<string, mixed>>  $attributes
     * @return array<string, mixed>
     */
    private function resultFor(Product $product, int $confidence, array $attributes = []): array
    {
        return [
            'codice_articolo' => $product->codice_articolo,
            'brand' => 'Grohe',
            'family' => null,
            'subfamily' => null,
            'product_type' => 'Miscelatore',
            'enriched_description' => 'Descrizione arricchita',
            'confidence' => $confidence,
            'attributes' => $attributes,
        ];
    }

    public function test_batch_classification_populates_product_attributes_from_ai_response(): void
    {
        Brand::factory()->create(['name' => 'Grohe']);

        $product = Product::factory()->create(['enrichment_status' => 'pending']);
        ProductAttribute::factory()->for($product)->create([
            'key' => 'potenza_kw',
            'value_num' => 1.0,
            'value_text' => null,
            'unit' => 'kW',
            'source' => 'regex',
            'confidence' => null,
        ]);

        $batchBody = [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'results' => [
                        $this->resultFor($product, confidence: 90, attributes: [
                            ['key' => 'potenza_kw', 'value_num' => 1.5, 'unit' => 'kW', 'confidence' => 92],
                            ['key' => 'portata_lmin', 'value_num' => 12.0, 'unit' => 'L/min', 'confidence' => 88],
                        ]),
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ]],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 40],
        ];

        Http::fake(['*' => Http::response($batchBody)]);

        (new ClassifyProductsBatchJob([$product->id]))->handle(
            app(ClaudeClient::class),
            app(ClassificationPromptBuilder::class),
            app(ClassificationResponseValidator::class),
        );

        $potenza = $product->attributes()->where('key', 'potenza_kw')->first();
        $this->assertNotNull($potenza);
        $this->assertEquals(1.5, $potenza->value_num);
        $this->assertSame('ai', $potenza->source);
        $this->assertSame(92, $potenza->confidence);

        $portata = $product->attributes()->where('key', 'portata_lmin')->first();
        $this->assertNotNull($portata);
        $this->assertEquals(12.0, $portata->value_num);
        $this->assertSame('ai', $portata->source);
        $this->assertSame(88, $portata->confidence);
    }

    public function test_cache_hit_carries_through_previously_proposed_attributes(): void
    {
        Brand::factory()->create(['name' => 'Grohe']);

        $cachedProduct = Product::factory()->create([
            'enrichment_status' => 'pending',
            'description_raw' => 'Miscelatore da cucina Grohe cromato con portata regolabile',
        ]);

        (new EnrichmentCache)->put($cachedProduct, new ClassifiedProduct(
            codiceArticolo: $cachedProduct->codice_articolo,
            brand: 'Grohe',
            family: null,
            subfamily: null,
            productType: 'Miscelatore',
            enrichedDescription: 'Descrizione arricchita cacheata',
            confidence: 92,
            attributes: [
                'portata_lmin' => ['value_num' => 10.0, 'unit' => 'L/min', 'confidence' => 90],
            ],
        ));

        Http::fake();

        (new ClassifyProductsBatchJob([$cachedProduct->id]))->handle(
            app(ClaudeClient::class),
            app(ClassificationPromptBuilder::class),
            app(ClassificationResponseValidator::class),
        );

        Http::assertNothingSent();

        $attribute = $cachedProduct->attributes()->where('key', 'portata_lmin')->first();
        $this->assertNotNull($attribute);
        $this->assertEquals(10.0, $attribute->value_num);
        $this->assertSame('ai', $attribute->source);
        $this->assertSame(90, $attribute->confidence);
    }
}
