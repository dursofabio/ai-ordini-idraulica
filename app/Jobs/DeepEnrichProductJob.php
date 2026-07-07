<?php

namespace App\Jobs;

use App\Exceptions\InvalidDeepEnrichmentResponseException;
use App\Models\AttributeDefinition;
use App\Models\EnrichmentLog;
use App\Models\EnrichmentProposal;
use App\Models\Product;
use App\Services\Ai\AiClient;
use App\Services\Ai\AttributeVocabulary;
use App\Services\Ai\DeepEnrichedProduct;
use App\Services\Ai\DeepEnrichmentPromptBuilder;
use App\Services\Ai\DeepEnrichmentResponseValidator;
use App\Services\Enrichment\AiSpendGuard;
use App\Services\Enrichment\EnrichmentProposalRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Deeply enriches a single product on demand (US-051): calls the AI's smart
 * model via {@see DeepEnrichmentPromptBuilder} for a markdown extended
 * description plus a full technical fact sheet, anchored to the closed
 * attribute registry ({@see AttributeVocabulary}), and records every result
 * as a `pending` {@see EnrichmentProposal} via
 * {@see EnrichmentProposalRecorder} — nothing is written directly onto the
 * product; a human reviewer must confirm the proposals from the existing
 * review queue.
 *
 * On any failure (the spend cap already exceeded, two consecutive invalid
 * responses, or an exception raised by the AI client — caught directly
 * inside {@see self::handle()} so the outcome doesn't depend on the active
 * queue driver, with {@see self::failed()} as a defensive backstop for
 * anything that fails outside that boundary): `enrichment_status` is set to
 * `needs_review`, one error `enrichment_logs` row is written, and no product
 * data or proposal is created.
 */
class DeepEnrichProductJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * A single deep-enrichment call plus one retry can take longer than the
     * default 60s worker timeout for a rich markdown response.
     */
    public int $timeout = 300;

    /**
     * Shared cost-tracking identifier for {@see AiSpendGuard}. Unlike
     * {@see ClassifyProductsBatchJob}, which shares one dispatcher-minted
     * UUID across every job of the same batch run, this job has no natural
     * batch boundary: it is dispatched one product at a time from the
     * "Arricchisci con AI" action, with no caller-supplied run to join.
     * Defaulting to a day-scoped key (rather than a fresh UUID per
     * execution) means every manual dispatch on the same day shares the same
     * spend counter, so the configured cap can actually accumulate and trip
     * across repeated clicks — a fresh UUID per call would reset the budget
     * to zero every time and make the cap unenforceable in practice.
     */
    public string $runId;

    public function __construct(
        public int $productId,
        ?string $runId = null,
    ) {
        $this->runId = $runId ?? 'deep-enrichment-'.now()->format('Y-m-d');
        $this->onQueue('enrich');
    }

    public function handle(
        AiClient $client,
        DeepEnrichmentPromptBuilder $promptBuilder,
        DeepEnrichmentResponseValidator $validator,
        AttributeVocabulary $vocabulary,
    ): void {
        $product = Product::query()->find($this->productId);

        if ($product === null) {
            return;
        }

        $spendGuard = new AiSpendGuard;
        $model = $client->modelSmart();

        if ($spendGuard->capExceeded($this->runId)) {
            $this->markNeedsReview($product, 'Tetto di spesa AI raggiunto per questo run.', $model);

            return;
        }

        $product->loadMissing('attributes');

        $payload = $promptBuilder->build($product, $model);

        try {
            [$result, $tokensIn, $tokensOut, $error, $responseRaw, $cost] = $this->enrichWithRetry($client, $validator, $payload, $product, $vocabulary, $model);
        } catch (Throwable $exception) {
            // Caught here (rather than left to bubble to self::failed()) so a
            // client-level failure (e.g. RequestException) resolves to
            // needs_review immediately regardless of the active queue driver
            // — including `sync`, where an uncaught exception would otherwise
            // abort the very request that triggered this job.
            $this->markNeedsReview($product, $exception->getMessage(), $model, $payload, null);

            return;
        }

        if ($result === null) {
            $this->markNeedsReview($product, $error ?? 'Risposta AI non valida dopo un retry.', $model, $payload, $responseRaw, $cost);

            return;
        }

        $spendGuard->spend($this->runId, $spendGuard->estimateCost($model, $tokensIn, $tokensOut));

        $this->recordResults($product, $result, $vocabulary, $model, $tokensIn, $tokensOut, $payload, $responseRaw, $cost);
    }

    /**
     * Calls the smart model once, retries once more on validation failure,
     * and returns a null result when both attempts fail. The raw response
     * body of the last attempt is always returned so callers can log it
     * even on failure.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: DeepEnrichedProduct|null, 1: int, 2: int, 3: string|null, 4: array<string, mixed>|null, 5: float|null}
     */
    private function enrichWithRetry(
        AiClient $client,
        DeepEnrichmentResponseValidator $validator,
        array $payload,
        Product $product,
        AttributeVocabulary $vocabulary,
        string $model,
    ): array {
        $lastError = null;
        $lastResponseRaw = null;
        $lastCost = null;

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $response = $client->messages($payload);
            $lastResponseRaw = $response->raw;
            $lastCost = $response->cost;

            try {
                $result = $validator->validate($response, $product->codice_articolo);

                return [$result, $response->tokensIn, $response->tokensOut, null, $lastResponseRaw, $response->cost];
            } catch (InvalidDeepEnrichmentResponseException $exception) {
                $lastError = $exception->getMessage();

                continue;
            }
        }

        return [null, 0, 0, $lastError, $lastResponseRaw, $lastCost];
    }

    /**
     * Logs the successful call and records every result as a `pending`
     * proposal: the extended description under the `descrizione_estesa`
     * field, the proposed product type (if any) under `product_type`, and
     * every attribute — whether its key matches a registered
     * {@see AttributeDefinition} or not — under `attribute`, so no value the
     * AI reports is ever lost. A key absent from the registry additionally
     * gets its own `attribute_definition` proposal (mirroring
     * {@see ClassifyProductsBatchJob}'s use of
     * {@see EnrichmentProposalRecorder::recordAttributeDefinitionProposal()})
     * so a reviewer can promote it into the registry.
     */
    private function recordResults(
        Product $product,
        DeepEnrichedProduct $result,
        AttributeVocabulary $vocabulary,
        string $model,
        int $tokensIn,
        int $tokensOut,
        array $requestPayload,
        ?array $responsePayload,
        ?float $cost = null,
    ): void {
        EnrichmentLog::query()->create([
            'product_id' => $product->id,
            'step' => 'ai_deep_enrichment',
            'input' => ['codice_articolo' => $product->codice_articolo],
            'output' => [
                'descrizione_estesa' => $result->extendedDescription,
                'tipo_prodotto' => $result->productType,
                'attributes' => $result->attributes,
            ],
            'request_payload' => $requestPayload,
            'response_payload' => $responsePayload,
            'confidence' => $result->confidence,
            'model' => $model,
            'tokens_in' => $tokensIn,
            'tokens_out' => $tokensOut,
            'cost' => $cost,
        ]);

        $recorder = app(EnrichmentProposalRecorder::class);

        if ($result->extendedDescription !== null) {
            $recorder->record(
                product: $product,
                field: 'descrizione_estesa',
                origin: 'ai',
                status: 'pending',
                confidence: $result->confidence,
                value: $result->extendedDescription,
            );
        }

        if ($result->productType !== null) {
            $recorder->record(
                product: $product,
                field: 'product_type',
                origin: 'ai',
                status: 'pending',
                confidence: $result->confidence,
                value: $result->productType,
            );
        }

        foreach ($result->attributes as $key => $attribute) {
            $recorder->record(
                product: $product,
                field: 'attribute',
                origin: 'ai',
                status: 'pending',
                confidence: $attribute['confidence'] ?? null,
                attributeKey: $key,
                value: $attribute['value'] ?? null,
                unit: $attribute['unit'] ?? null,
            );

            if ($vocabulary->definitionFor($key) === null) {
                $recorder->recordAttributeDefinitionProposal($product, $key, $attribute);
            }
        }
    }

    /**
     * Marks the product as needing manual review and logs one error
     * EnrichmentLog, without touching any product data or proposal.
     */
    private function markNeedsReview(Product $product, string $reason, ?string $model, ?array $requestPayload = null, ?array $responsePayload = null, ?float $cost = null): void
    {
        EnrichmentLog::query()->create([
            'product_id' => $product->id,
            'step' => 'ai_deep_enrichment',
            'input' => ['codice_articolo' => $product->codice_articolo],
            'output' => ['error' => $reason],
            'request_payload' => $requestPayload,
            'response_payload' => $responsePayload,
            'confidence' => null,
            'model' => $model,
            'tokens_in' => null,
            'tokens_out' => null,
            'cost' => $cost,
        ]);

        $product->update(['enrichment_status' => 'needs_review']);
    }

    /**
     * Defensive backstop for a failure that occurs outside
     * {@see self::handle()}'s own try/catch (e.g. during job deserialization
     * on a real async queue): still resolves to `needs_review` plus an error
     * log instead of silently landing in `failed_jobs` with no trace on the
     * product. In the common case — an exception raised by the AI client
     * during {@see self::handle()} — that method's own catch already handles
     * it, making this a no-op rerun of the same outcome.
     */
    public function failed(?Throwable $exception): void
    {
        $product = Product::query()->find($this->productId);

        if ($product === null) {
            return;
        }

        $model = rescue(fn (): string => app(AiClient::class)->modelSmart(), rescue: null, report: false);

        $this->markNeedsReview($product, $exception?->getMessage() ?? 'Errore imprevisto durante l\'arricchimento AI.', $model);
    }
}
