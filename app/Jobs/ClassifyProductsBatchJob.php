<?php

namespace App\Jobs;

use App\Exceptions\InvalidClassificationResponseException;
use App\Models\EnrichmentLog;
use App\Models\Product;
use App\Services\Ai\ClassificationPromptBuilder;
use App\Services\Ai\ClassificationResponseValidator;
use App\Services\Ai\ClassifiedProduct;
use App\Services\Ai\ClaudeClient;
use App\Services\Ai\TaxonomyCatalog;
use App\Services\Ai\ValidatedClassification;
use App\Services\Enrichment\EnrichmentApplier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Classifies a batch of products (brand, family, subfamily, product type,
 * enriched description) via the Anthropic Messages API, using the fast
 * model for the bulk call and escalating to the smart model per-product
 * when confidence is low.
 *
 * Flow: build one prompt for the whole batch → call model_fast → validate.
 * If validation fails, retry once with the same prompt/model; a second
 * failure marks every product in the batch `needs_review` and logs the
 * validation error per product, without throwing. On a valid response, any
 * result with confidence < {@see self::LOW_CONFIDENCE_THRESHOLD} is
 * re-classified individually with model_smart before logging. Each product
 * gets exactly one `enrichment_logs` row (step `ai_classification`) with the
 * batch's token usage divided evenly across its products. After logging,
 * {@see EnrichmentApplier} writes the classification result back onto the
 * product according to its confidence band (US-015).
 */
class ClassifyProductsBatchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Confidence below this threshold (0-100) triggers a per-product
     * escalation to the smart model.
     */
    private const LOW_CONFIDENCE_THRESHOLD = 60;

    public int $tries = 1;

    /**
     * The batch call plus one escalation call per low-confidence product
     * (up to 50 in a batch) can chain many sequential Anthropic requests;
     * the default 60s worker timeout is too short for that worst case.
     */
    public int $timeout = 600;

    /**
     * codice_articolo => [model, tokensIn, tokensOut] for products that were
     * escalated to the smart model, so logResults() can attribute the
     * correct model/tokens instead of the batch's fast-model figures.
     *
     * @var array<string, array{0: string, 1: int, 2: int}>
     */
    private array $escalatedModels = [];

    /**
     * Message of the most recent {@see InvalidClassificationResponseException}
     * caught during {@see self::classifyWithRetry()}, kept so the
     * `needs_review` audit log can record the actual rejection reason
     * instead of a generic message when both attempts fail.
     */
    private ?string $lastValidationError = null;

    /**
     * @param  array<int, int>  $productIds
     */
    public function __construct(
        public array $productIds,
    ) {
        $this->onQueue('enrich');
    }

    public function handle(
        ClaudeClient $client,
        ClassificationPromptBuilder $promptBuilder,
        ClassificationResponseValidator $validator,
    ): void {
        $products = Product::query()
            ->whereIn('id', $this->productIds)
            ->where('enrichment_status', 'pending')
            ->get();

        if ($products->isEmpty()) {
            return;
        }

        $taxonomy = new TaxonomyCatalog;
        $modelFast = (string) config('services.anthropic.model_fast');
        $expectedCodici = $products->pluck('codice_articolo');

        $validated = $this->classifyWithRetry($client, $promptBuilder, $validator, $products, $taxonomy, $modelFast, $expectedCodici);

        if ($validated === null) {
            $this->markNeedsReview($products, $this->lastValidationError ?? 'Risposta AI non valida dopo un retry.');

            return;
        }

        [$classification, $tokensIn, $tokensOut] = $validated;

        $this->escalateLowConfidenceResults($client, $promptBuilder, $validator, $products, $taxonomy, $classification);

        $this->logResults($products, $classification, $taxonomy, $modelFast, $tokensIn, $tokensOut);
    }

    /**
     * Calls the fast model once, retries once more on validation failure,
     * and returns null when both attempts fail.
     *
     * @param  EloquentCollection<int, Product>  $products
     * @param  SupportCollection<int, string>  $expectedCodici
     * @return array{0: ValidatedClassification, 1: int, 2: int}|null
     */
    private function classifyWithRetry(
        ClaudeClient $client,
        ClassificationPromptBuilder $promptBuilder,
        ClassificationResponseValidator $validator,
        EloquentCollection $products,
        TaxonomyCatalog $taxonomy,
        string $model,
        SupportCollection $expectedCodici,
    ): ?array {
        $payload = $promptBuilder->build($products, $taxonomy, $model);

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $response = $client->messages($payload);

            try {
                $classification = $validator->validate($response, $expectedCodici, $taxonomy);

                return [$classification, $response->tokensIn, $response->tokensOut];
            } catch (InvalidClassificationResponseException $exception) {
                $this->lastValidationError = $exception->getMessage();

                continue;
            }
        }

        return null;
    }

    /**
     * Re-classifies, one product at a time with the smart model, every
     * result whose confidence is below the escalation threshold, replacing
     * it in place within `$classification`'s underlying results collection.
     *
     * @param  EloquentCollection<int, Product>  $products
     */
    private function escalateLowConfidenceResults(
        ClaudeClient $client,
        ClassificationPromptBuilder $promptBuilder,
        ClassificationResponseValidator $validator,
        EloquentCollection $products,
        TaxonomyCatalog $taxonomy,
        ValidatedClassification $classification,
    ): void {
        $modelSmart = (string) config('services.anthropic.model_smart');

        foreach ($products as $product) {
            $result = $classification->for($product->codice_articolo);

            if ($result === null || $result->confidence === null || $result->confidence >= self::LOW_CONFIDENCE_THRESHOLD) {
                continue;
            }

            $singleBatch = collect([$product]);
            $payload = $promptBuilder->build($singleBatch, $taxonomy, $modelSmart);
            $response = $client->messages($payload);

            try {
                $escalated = $validator->validate($response, collect([$product->codice_articolo]), $taxonomy);
            } catch (InvalidClassificationResponseException) {
                continue;
            }

            $escalatedResult = $escalated->for($product->codice_articolo);

            if ($escalatedResult !== null) {
                $classification->results->put($product->codice_articolo, $escalatedResult);
                $this->escalatedModels[$product->codice_articolo] = [$modelSmart, $response->tokensIn, $response->tokensOut];
            }
        }
    }

    /**
     * Divides the batch's token usage evenly across its products via integer
     * division: for counts that don't divide evenly, up to `$count - 1`
     * tokens are dropped from the audit trail rather than attributed to any
     * single product. Acceptable for the confidence/model audit purpose of
     * `enrichment_logs`; revisit if exact per-product cost accounting is
     * needed later.
     *
     * @param  EloquentCollection<int, Product>  $products
     */
    private function logResults(
        EloquentCollection $products,
        ValidatedClassification $classification,
        TaxonomyCatalog $taxonomy,
        string $modelFast,
        int $batchTokensIn,
        int $batchTokensOut,
    ): void {
        $count = max($products->count(), 1);
        $shareIn = intdiv($batchTokensIn, $count);
        $shareOut = intdiv($batchTokensOut, $count);
        $now = Carbon::now();
        $applier = new EnrichmentApplier;

        $rows = [];

        foreach ($products as $product) {
            $result = $classification->for($product->codice_articolo);

            if ($result === null) {
                continue;
            }

            $escalation = $this->escalatedModels[$product->codice_articolo] ?? null;

            $rows[] = [
                'product_id' => $product->id,
                'step' => 'ai_classification',
                'input' => json_encode(['codice_articolo' => $product->codice_articolo], JSON_UNESCAPED_UNICODE),
                'output' => json_encode($this->resultToArray($result), JSON_UNESCAPED_UNICODE),
                'confidence' => $result->confidence,
                'model' => $escalation[0] ?? $modelFast,
                'tokens_in' => $escalation[1] ?? $shareIn,
                'tokens_out' => $escalation[2] ?? $shareOut,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $applier->apply($product, $result, $taxonomy);
        }

        if ($rows !== []) {
            EnrichmentLog::query()->insert($rows);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function resultToArray(ClassifiedProduct $result): array
    {
        return [
            'brand' => $result->brand,
            'family' => $result->family,
            'subfamily' => $result->subfamily,
            'product_type' => $result->productType,
            'enriched_description' => $result->enrichedDescription,
        ];
    }

    /**
     * Marks every product in the batch as needing manual review and logs
     * one error EnrichmentLog per product, used when both classification
     * attempts fail validation. The logged error is the last validator
     * rejection reason, so a human reviewer can see why without re-running
     * the batch.
     *
     * @param  EloquentCollection<int, Product>  $products
     */
    private function markNeedsReview(EloquentCollection $products, string $reason): void
    {
        $now = Carbon::now();
        $rows = [];

        foreach ($products as $product) {
            $rows[] = [
                'product_id' => $product->id,
                'step' => 'ai_classification',
                'input' => json_encode(['codice_articolo' => $product->codice_articolo], JSON_UNESCAPED_UNICODE),
                'output' => json_encode(['error' => $reason], JSON_UNESCAPED_UNICODE),
                'confidence' => null,
                'model' => (string) config('services.anthropic.model_fast'),
                'tokens_in' => null,
                'tokens_out' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            EnrichmentLog::query()->insert($rows);
        }

        Product::query()
            ->whereIn('id', $products->pluck('id'))
            ->update(['enrichment_status' => 'needs_review']);
    }
}
