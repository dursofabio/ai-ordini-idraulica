<?php

namespace Tests\Feature;

use App\Jobs\DeepEnrichProductJob;
use App\Models\AttributeDefinition;
use App\Models\EnrichmentLog;
use App\Models\EnrichmentProposal;
use App\Models\Product;
use App\Services\Ai\AttributeVocabulary;
use App\Services\Ai\ClaudeClient;
use App\Services\Ai\DeepEnrichmentPromptBuilder;
use App\Services\Ai\DeepEnrichmentResponseValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-051 acceptance criteria — deep enrichment job:
 *  - A valid response records a `pending` proposal for `descrizione_estesa`,
 *    one for `product_type` when the AI proposes one, and one per proposed
 *    attribute, plus one `enrichment_logs` row with token usage and
 *    confidence.
 *  - Once the run's AI spend cap is already exceeded, no HTTP call is made
 *    and the product is marked `needs_review`.
 *  - Two consecutive invalid responses mark the product `needs_review`
 *    without touching its existing descrizione_estesa/attributes.
 *  - A low overall confidence is propagated onto the logged/proposed
 *    confidence, not silently upgraded.
 *  - A key absent from the attribute registry becomes an
 *    `attribute_definition` proposal instead of an `attribute` one.
 *  - An exception raised by the AI client leaves existing product data
 *    intact and still resolves to `needs_review` (via the job's failed()
 *    lifecycle).
 *
 * Runs against in-memory SQLite via RequiresDatabase.
 */
class DeepEnrichProductJobTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

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

    public function test_valid_response_records_pending_proposals_and_logs_tokens(): void
    {
        AttributeDefinition::factory()->numeric()->create(['key' => 'potenza', 'canonical_unit' => 'kW']);

        $product = Product::factory()->create(['codice_articolo' => 'ABC-001']);

        Http::fake([
            '*' => Http::response($this->anthropicBody(
                descrizioneEstesa: "# Caldaia\n\nScheda tecnica dettagliata.",
                confidence: 88,
                attributes: [['key' => 'potenza', 'value' => '25', 'unit' => 'kW', 'confidence' => 90]],
                tipoProdotto: 'Caldaia a condensazione',
            )),
        ]);

        $this->runJob($product);

        $log = EnrichmentLog::query()->where('product_id', $product->id)->where('step', 'ai_deep_enrichment')->first();
        $this->assertNotNull($log);
        $this->assertSame('claude-smart-test', $log->model);
        $this->assertSame(88, $log->confidence);
        $this->assertSame(100, $log->tokens_in);
        $this->assertSame(50, $log->tokens_out);
        $this->assertSame('claude-smart-test', $log->request_payload['model']);
        $this->assertStringContainsString('ABC-001', $log->request_payload['messages'][0]['content']);
        $this->assertSame("# Caldaia\n\nScheda tecnica dettagliata.", json_decode($log->response_payload['content'][0]['text'], true)['descrizione_estesa']);

        $descriptionProposal = EnrichmentProposal::query()->where('product_id', $product->id)->where('field', 'descrizione_estesa')->first();
        $this->assertNotNull($descriptionProposal);
        $this->assertSame('pending', $descriptionProposal->status);
        $this->assertSame("# Caldaia\n\nScheda tecnica dettagliata.", $descriptionProposal->value);
        $this->assertSame('ai', $descriptionProposal->origin);

        $productTypeProposal = EnrichmentProposal::query()->where('product_id', $product->id)->where('field', 'product_type')->first();
        $this->assertNotNull($productTypeProposal);
        $this->assertSame('pending', $productTypeProposal->status);
        $this->assertSame('Caldaia a condensazione', $productTypeProposal->value);
        $this->assertSame('ai', $productTypeProposal->origin);
        $this->assertSame(88, $productTypeProposal->confidence);

        $attributeProposal = EnrichmentProposal::query()->where('product_id', $product->id)->where('field', 'attribute')->first();
        $this->assertNotNull($attributeProposal);
        $this->assertSame('potenza', $attributeProposal->attribute_key);
        $this->assertSame('pending', $attributeProposal->status);

        $this->assertSame('pending', $product->fresh()->enrichment_status ?? 'pending');
        Http::assertSentCount(1);
    }

    public function test_spend_cap_already_exceeded_skips_the_call_and_marks_needs_review(): void
    {
        config()->set('services.anthropic.batch_cost_cap', 1.0);

        $product = Product::factory()->create(['enrichment_status' => 'enriched']);

        Cache::put('enrichment:spend:'.'shared-run', 999.0, 3600);

        Http::fake();

        (new DeepEnrichProductJob($product->id, 'shared-run'))->handle(
            app(ClaudeClient::class),
            app(DeepEnrichmentPromptBuilder::class),
            app(DeepEnrichmentResponseValidator::class),
            app(AttributeVocabulary::class),
        );

        Http::assertNothingSent();
        $this->assertSame('needs_review', $product->fresh()->enrichment_status);
        $this->assertSame(0, EnrichmentProposal::query()->where('product_id', $product->id)->count());

        $log = EnrichmentLog::query()->where('product_id', $product->id)->first();
        $this->assertNotNull($log);
        $this->assertNull($log->confidence);
    }

    /**
     * The default runId (no caller-supplied override, as on the real
     * "Arricchisci con AI" dispatch path) must be shared across separate
     * manual dispatches on the same day, not a fresh UUID per job instance —
     * otherwise every call would start its own spend counter at zero and the
     * configured cap could never actually trip in practice.
     */
    public function test_default_run_id_shares_spend_across_separate_manual_dispatches(): void
    {
        config()->set('services.anthropic.batch_cost_cap', 0.001);

        $firstProduct = Product::factory()->create();
        $secondProduct = Product::factory()->create();

        Http::fake([
            '*' => Http::response($this->anthropicBody(
                descrizioneEstesa: 'Descrizione',
                confidence: 80,
                attributes: [],
            )),
        ]);

        $this->runJob($firstProduct);
        $this->runJob($secondProduct);

        Http::assertSentCount(1);
        $this->assertSame('needs_review', $secondProduct->fresh()->enrichment_status);
    }

    public function test_two_invalid_responses_mark_needs_review_and_leave_existing_data_intact(): void
    {
        $product = Product::factory()->create([
            'descrizione_estesa' => 'Testo esistente da preservare',
            'enrichment_status' => 'enriched',
        ]);

        Http::fake([
            '*' => Http::response($this->anthropicRawBody('non valido {{{')),
        ]);

        $this->runJob($product);

        Http::assertSentCount(2);

        $product->refresh();
        $this->assertSame('needs_review', $product->enrichment_status);
        $this->assertSame('Testo esistente da preservare', $product->descrizione_estesa);
        $this->assertSame(0, EnrichmentProposal::query()->where('product_id', $product->id)->count());

        $log = EnrichmentLog::query()->where('product_id', $product->id)->first();
        $this->assertNotNull($log->request_payload);
        $this->assertSame('non valido {{{', $log->response_payload['content'][0]['text']);
    }

    public function test_low_confidence_is_propagated_not_upgraded(): void
    {
        $product = Product::factory()->create();

        Http::fake([
            '*' => Http::response($this->anthropicBody(
                descrizioneEstesa: 'Descrizione con pochi dati',
                confidence: 25,
                attributes: [],
            )),
        ]);

        $this->runJob($product);

        $log = EnrichmentLog::query()->where('product_id', $product->id)->first();
        $this->assertSame(25, $log->confidence);

        $proposal = EnrichmentProposal::query()->where('product_id', $product->id)->where('field', 'descrizione_estesa')->first();
        $this->assertSame(25, $proposal->confidence);
    }

    /**
     * A null `tipo_prodotto` (e.g. minimal starting data, same
     * anti-hallucination gate as the extended description) must not create a
     * `product_type` proposal at all.
     */
    public function test_null_product_type_creates_no_product_type_proposal(): void
    {
        $product = Product::factory()->create();

        Http::fake([
            '*' => Http::response($this->anthropicBody(
                descrizioneEstesa: 'Descrizione',
                confidence: 80,
                attributes: [],
            )),
        ]);

        $this->runJob($product);

        $this->assertSame(0, EnrichmentProposal::query()->where('product_id', $product->id)->where('field', 'product_type')->count());
    }

    public function test_key_outside_the_registry_becomes_both_an_attribute_proposal_and_a_definition_proposal(): void
    {
        $product = Product::factory()->create();

        Http::fake([
            '*' => Http::response($this->anthropicBody(
                descrizioneEstesa: 'Descrizione',
                confidence: 80,
                attributes: [['key' => 'chiave_non_registrata', 'value' => 'valore', 'confidence' => 70]],
            )),
        ]);

        $this->runJob($product);

        $attributeProposal = EnrichmentProposal::query()->where('product_id', $product->id)->where('field', 'attribute')->first();
        $this->assertNotNull($attributeProposal);
        $this->assertSame('chiave_non_registrata', $attributeProposal->attribute_key);
        $this->assertSame('valore', $attributeProposal->value);
        $this->assertSame('pending', $attributeProposal->status);

        $definitionProposal = EnrichmentProposal::query()->where('product_id', $product->id)->where('field', 'attribute_definition')->first();
        $this->assertNotNull($definitionProposal);
        $this->assertSame('chiave_non_registrata', $definitionProposal->attribute_key);
        $this->assertSame('pending', $definitionProposal->status);
    }

    /**
     * A client-level exception (e.g. a network failure) is caught directly
     * inside handle() — not left to bubble up and rely on the queue's
     * failed() lifecycle — so the outcome is the same regardless of the
     * active queue driver, including `sync`, where an uncaught exception
     * would otherwise abort the very request that dispatched this job.
     */
    public function test_client_exception_leaves_product_data_intact_and_resolves_needs_review(): void
    {
        $product = Product::factory()->create([
            'descrizione_estesa' => 'Testo esistente',
            'enrichment_status' => 'enriched',
        ]);

        Http::fake(function (): void {
            throw new ConnectionException('Errore di rete simulato');
        });

        $this->runJob($product);

        $product->refresh();
        $this->assertSame('needs_review', $product->enrichment_status);
        $this->assertSame('Testo esistente', $product->descrizione_estesa);
        $this->assertSame(0, EnrichmentProposal::query()->where('product_id', $product->id)->count());

        $log = EnrichmentLog::query()->where('product_id', $product->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('Errore di rete simulato', $log->output['error'] ?? null);
    }

    /**
     * {@see DeepEnrichProductJob::failed()} is a defensive backstop for a
     * failure occurring outside handle()'s own try/catch: it must still
     * resolve to needs_review on its own.
     */
    public function test_failed_lifecycle_hook_also_resolves_needs_review(): void
    {
        $product = Product::factory()->create([
            'descrizione_estesa' => 'Testo esistente',
            'enrichment_status' => 'enriched',
        ]);

        (new DeepEnrichProductJob($product->id))->failed(new ConnectionException('Errore imprevisto simulato'));

        $product->refresh();
        $this->assertSame('needs_review', $product->enrichment_status);
        $this->assertSame('Testo esistente', $product->descrizione_estesa);
        $this->assertSame(0, EnrichmentProposal::query()->where('product_id', $product->id)->count());
    }

    private function runJob(Product $product): void
    {
        (new DeepEnrichProductJob($product->id))->handle(
            app(ClaudeClient::class),
            app(DeepEnrichmentPromptBuilder::class),
            app(DeepEnrichmentResponseValidator::class),
            app(AttributeVocabulary::class),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $attributes
     * @return array<string, mixed>
     */
    private function anthropicBody(string $descrizioneEstesa, int $confidence, array $attributes, ?string $tipoProdotto = null): array
    {
        return $this->anthropicRawBody(json_encode([
            'descrizione_estesa' => $descrizioneEstesa,
            'tipo_prodotto' => $tipoProdotto,
            'confidence' => $confidence,
            'attributes' => $attributes,
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string, mixed>
     */
    private function anthropicRawBody(string $text): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $text]],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
        ];
    }
}
