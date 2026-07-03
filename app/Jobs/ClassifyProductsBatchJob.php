<?php

namespace App\Jobs;

use App\Exceptions\InvalidClassificationResponseException;
use App\Models\EnrichmentLog;
use App\Models\Product;
use App\Services\Ai\AiClient;
use App\Services\Ai\ClassificationPromptBuilder;
use App\Services\Ai\ClassificationResponseValidator;
use App\Services\Ai\ClassifiedProduct;
use App\Services\Ai\TaxonomyCatalog;
use App\Services\Ai\ValidatedClassification;
use App\Services\Enrichment\AiSpendGuard;
use App\Services\Enrichment\ClassificationBatchDispatcher;
use App\Services\Enrichment\EnrichmentApplier;
use App\Services\Enrichment\EnrichmentCache;
use DateTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Classifies a batch of products (brand, family, subfamily, product type,
 * enriched description) via the Anthropic Messages API, using the fast
 * model for the bulk call and escalating to the smart model per-product
 * when confidence is low.
 *
 * Flow: partition the batch into cache hits ({@see EnrichmentCache}) and
 * cache misses (also deduplicated by `description_raw` hash within the
 * batch) → build one prompt for the miss representatives → call model_fast
 * → validate. If validation fails, retry once with the same prompt/model; a
 * second failure marks every non-cached product in the batch `needs_review`
 * and logs the validation error per product, without throwing. On a valid
 * response, any result with confidence < {@see self::LOW_CONFIDENCE_THRESHOLD}
 * is re-classified individually with model_smart before logging, and every
 * new result is written back into {@see EnrichmentCache} so later batches or
 * reimports with the same description skip the API call entirely (US-016).
 * Each product gets exactly one `enrichment_logs` row (step
 * `ai_classification`) with the batch's token usage divided evenly across
 * its non-cached products. Before any call, {@see AiSpendGuard} is consulted
 * against the run's configured cost cap; if already exceeded, no call is
 * made and the event is logged (US-016). After logging, {@see EnrichmentApplier}
 * writes the classification result back onto the product according to its
 * confidence band (US-015).
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

    /**
     * Worst-case output tokens assumed when estimating the cost of a call
     * before it is made, matching the `max_tokens` requested by
     * {@see ClassificationPromptBuilder}.
     */
    private const MAX_TOKENS = 8192;

    /**
     * Superseded by {@see self::retryUntil()} for actual retry accounting
     * (Laravel gives retryUntil() precedence when both are defined); kept at
     * 1 so a job that has never been released by {@see self::middleware()}
     * still reads, at a glance, as "no job-level retry by default".
     */
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
     * codice_articolo (of a duplicate, non-representative product) =>
     * codice_articolo of the representative product that was actually sent
     * to the AI for the same `description_raw` hash, so
     * {@see self::logResults()} can replicate the representative's result
     * onto every duplicate (US-016 AC4).
     *
     * @var array<string, string>
     */
    private array $representativeCodiceByDuplicate = [];

    /**
     * Message of the most recent {@see InvalidClassificationResponseException}
     * caught during {@see self::classifyWithRetry()}, kept so the
     * `needs_review` audit log can record the actual rejection reason
     * instead of a generic message when both attempts fail.
     */
    private ?string $lastValidationError = null;

    /**
     * Whether the spend cap event has already been logged for this job
     * instance, so a cap exceeded mid-escalation logs only once even though
     * multiple per-product escalation calls could each observe the cap.
     */
    private bool $capExceededLogged = false;

    /**
     * Shared cost-tracking identifier for all jobs dispatched by the same
     * {@see ClassificationBatchDispatcher} run. Set
     * in the constructor body (rather than promoted with a default) because
     * the default value — a freshly generated UUID — cannot be expressed as
     * a constant constructor-promotion default.
     */
    public string $runId;

    /**
     * @param  array<int, int>  $productIds
     * @param  string|null  $runId  Defaults to a freshly generated UUID when
     *                              omitted, so the job remains usable standalone (e.g. in tests)
     *                              without a caller-supplied run.
     */
    public function __construct(
        public array $productIds,
        ?string $runId = null,
    ) {
        $this->runId = $runId ?? (string) Str::uuid();
        $this->onQueue('enrich');
    }

    /**
     * Pauses this job class (shared across every queued instance, since
     * they all hit the same downstream AI provider) after 2 consecutive
     * {@see RequestException}s — e.g. HTTP 429 from a rate-limited
     * free-tier model — instead of letting each one fail permanently into
     * `failed_jobs`. Other exceptions (validation bugs, etc.) are not
     * throttled and surface immediately as before.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new ThrottlesExceptions(2, 90))
                ->when(fn (Throwable $exception): bool => $exception instanceof RequestException)
                ->backoff(15),
        ];
    }

    /**
     * Keeps a throttled job eligible for retry for up to 8 hours (an
     * overnight run) instead of the effectively-single-attempt behavior
     * {@see self::$tries} would otherwise imply; Laravel gives this
     * precedence over $tries once both are defined.
     */
    public function retryUntil(): DateTime
    {
        return Carbon::now()->addHours(8);
    }

    /**
     * Fallback delay for any release not already paced by
     * {@see self::middleware()} (e.g. a worker restart mid-attempt), so a
     * persistent failure doesn't hammer the provider in a tight loop for
     * the remainder of the {@see self::retryUntil()} window.
     */
    public function backoff(): int
    {
        return 30;
    }

    public function handle(
        AiClient $client,
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
        $modelFast = $client->modelFast();
        $cache = new EnrichmentCache;
        $spendGuard = new AiSpendGuard;

        [$cached, $toClassify, $notCached] = $this->partitionByCache($products, $cache);

        // Apply cache hits immediately; nothing to classify for them.
        $this->applyCachedResults($cached, $taxonomy, new EnrichmentApplier);

        if ($toClassify->isEmpty()) {
            return;
        }

        if ($spendGuard->capExceeded($this->runId)) {
            // Worst-case estimate (MAX_TOKENS output) for this batch, logged
            // alongside the already-spent/cap figures so the warning shows
            // what this batch would have cost had it been allowed to run.
            $estimatedCost = $spendGuard->estimateCost($modelFast, $this->promptInputTokenEstimate($toClassify), self::MAX_TOKENS);

            $this->handleCapExceeded($notCached, $spendGuard, $modelFast, $estimatedCost);

            return;
        }

        $expectedCodici = $toClassify->pluck('codice_articolo');

        $validated = $this->classifyWithRetry($client, $promptBuilder, $validator, $toClassify, $taxonomy, $modelFast, $expectedCodici);

        if ($validated === null) {
            $this->markNeedsReview($notCached, $this->lastValidationError ?? 'Risposta AI non valida dopo un retry.', $modelFast);

            return;
        }

        [$classification, $tokensIn, $tokensOut] = $validated;

        $spendGuard->spend($this->runId, $spendGuard->estimateCost($modelFast, $tokensIn, $tokensOut));

        $this->escalateLowConfidenceResults($client, $promptBuilder, $validator, $toClassify, $taxonomy, $classification, $spendGuard);

        $this->logResults($notCached, $toClassify->count(), $classification, $taxonomy, $modelFast, $tokensIn, $tokensOut, $cache);
    }

    /**
     * Splits `$products` into cache hits (resolved via {@see EnrichmentCache}),
     * the deduplicated representatives that still need classification (one
     * per distinct `description_raw` hash within the batch, per US-016 AC4),
     * and every non-cached product (representatives plus duplicates) for
     * bookkeeping (audit logging, `needs_review` marking) that must cover
     * duplicates too. Duplicate codici are recorded in
     * {@see self::$representativeCodiceByDuplicate} so {@see self::logResults()}
     * can replicate the representative's result onto them.
     *
     * @param  EloquentCollection<int, Product>  $products
     * @return array{0: SupportCollection<int, array{product: Product, result: ClassifiedProduct}>, 1: EloquentCollection<int, Product>, 2: EloquentCollection<int, Product>}
     */
    private function partitionByCache(EloquentCollection $products, EnrichmentCache $cache): array
    {
        $cachedEntries = collect();
        $toClassify = [];
        $notCached = [];
        $representativeByHash = [];

        foreach ($products as $product) {
            $cachedResult = $cache->get($product);

            if ($cachedResult !== null) {
                $cachedEntries->push(['product' => $product, 'result' => $this->rekeyed($cachedResult, $product)]);

                continue;
            }

            $notCached[] = $product;

            $hash = $this->descriptionHash($product);

            if (! isset($representativeByHash[$hash])) {
                $representativeByHash[$hash] = $product->codice_articolo;
                $toClassify[] = $product;

                continue;
            }

            // Another product in this batch already represents this
            // description hash; skip sending this one to the AI and
            // replicate the representative's result onto it once the
            // classification completes (US-016 AC4).
            $this->representativeCodiceByDuplicate[$product->codice_articolo] = $representativeByHash[$hash];
        }

        return [$cachedEntries, new EloquentCollection($toClassify), new EloquentCollection($notCached)];
    }

    /**
     * Applies every cache-hit result directly, without calling the AI or
     * writing a new `enrichment_logs` row (the result was already logged
     * when it was first classified).
     *
     * @param  SupportCollection<int, array{product: Product, result: ClassifiedProduct}>  $cached
     */
    private function applyCachedResults(SupportCollection $cached, TaxonomyCatalog $taxonomy, EnrichmentApplier $applier): void
    {
        foreach ($cached as $entry) {
            $applier->apply($entry['product'], $entry['result'], $taxonomy);
        }
    }

    /**
     * A cached {@see ClassifiedProduct} was stored under a different
     * product's `codice_articolo`; rekey it to the current product so
     * {@see EnrichmentApplier} and any downstream code see a consistent
     * identity.
     */
    private function rekeyed(ClassifiedProduct $result, Product $product): ClassifiedProduct
    {
        if ($result->codiceArticolo === $product->codice_articolo) {
            return $result;
        }

        return new ClassifiedProduct(
            codiceArticolo: $product->codice_articolo,
            brand: $result->brand,
            family: $result->family,
            subfamily: $result->subfamily,
            productType: $result->productType,
            enrichedDescription: $result->enrichedDescription,
            confidence: $result->confidence,
        );
    }

    private function descriptionHash(Product $product): string
    {
        return hash('sha256', trim((string) $product->description_raw));
    }

    /**
     * Rough worst-case input token estimate for a batch, used only for the
     * pre-call spend check; real spend after the call uses the API's
     * reported usage instead. One token ~= 4 characters is a coarse but
     * conservative approximation of the prompt built by
     * {@see ClassificationPromptBuilder}.
     *
     * @param  EloquentCollection<int, Product>  $products
     */
    private function promptInputTokenEstimate(EloquentCollection $products): int
    {
        $chars = $products->sum(fn (Product $product): int => strlen((string) ($product->description_clean ?? $product->description_raw)) + 64);

        return (int) ceil($chars / 4);
    }

    /**
     * Marks every product not resolved from cache as `needs_review` because
     * the run's spend cap is already exceeded (or would be exceeded by this
     * batch), without making any API call, and logs the event once per job
     * instance.
     *
     * @param  EloquentCollection<int, Product>  $products
     */
    private function handleCapExceeded(EloquentCollection $products, AiSpendGuard $spendGuard, string $modelFast, ?float $estimatedCost = null): void
    {
        $this->markNeedsReview($products, 'Tetto di spesa AI raggiunto per questo run.', $modelFast);

        if (! $this->capExceededLogged) {
            $this->capExceededLogged = true;

            Log::warning('Tetto di spesa AI raggiunto: elaborazione interrotta', [
                'run_id' => $this->runId,
                'estimated_cost' => $estimatedCost,
                'batch_cost_cap' => config('services.anthropic.batch_cost_cap'),
                'remaining_budget' => $spendGuard->remainingBudget($this->runId),
            ]);
        }
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
        AiClient $client,
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
     * Re-checks the spend cap before each per-product call; once exceeded,
     * remaining products keep their fast-model result and the cap event is
     * logged exactly once (US-016).
     *
     * @param  EloquentCollection<int, Product>  $products
     */
    private function escalateLowConfidenceResults(
        AiClient $client,
        ClassificationPromptBuilder $promptBuilder,
        ClassificationResponseValidator $validator,
        EloquentCollection $products,
        TaxonomyCatalog $taxonomy,
        ValidatedClassification $classification,
        AiSpendGuard $spendGuard,
    ): void {
        $modelSmart = $client->modelSmart();

        foreach ($products as $product) {
            $result = $classification->for($product->codice_articolo);

            if ($result === null || $result->confidence === null || $result->confidence >= self::LOW_CONFIDENCE_THRESHOLD) {
                continue;
            }

            if ($spendGuard->capExceeded($this->runId)) {
                if (! $this->capExceededLogged) {
                    $this->capExceededLogged = true;

                    Log::warning('Tetto di spesa AI raggiunto durante l\'escalation: prodotti rimanenti mantengono il risultato fast-model', [
                        'run_id' => $this->runId,
                        'estimated_cost' => null,
                        'batch_cost_cap' => config('services.anthropic.batch_cost_cap'),
                        'remaining_budget' => $spendGuard->remainingBudget($this->runId),
                    ]);
                }

                break;
            }

            $singleBatch = collect([$product]);
            $payload = $promptBuilder->build($singleBatch, $taxonomy, $modelSmart);
            $response = $client->messages($payload);

            $spendGuard->spend($this->runId, $spendGuard->estimateCost($modelSmart, $response->tokensIn, $response->tokensOut));

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
     * Divides the batch's token usage evenly across its distinct
     * (deduplicated) representatives — `$representativeCount` — via integer
     * division: for counts that don't divide evenly, up to
     * `$representativeCount - 1` tokens are dropped from the audit trail
     * rather than attributed to any single product. Acceptable for the
     * confidence/model audit purpose of `enrichment_logs`; revisit if exact
     * per-product cost accounting is needed later.
     *
     * `$notCached` covers every non-cached product in the batch, including
     * duplicates that were never sent to the AI: a duplicate's result is
     * resolved from its representative's entry in `$classification` via
     * {@see self::resolveResult()}, so it gets the same brand/family/etc. and
     * its own `enrichment_logs` row (US-016 AC4), but is not charged its own
     * token share since no call was made for it.
     *
     * Every classified product's result — representatives and duplicates
     * alike — is written into {@see EnrichmentCache} so later batches, jobs,
     * or reimports sharing the same `description_raw` resolve from cache
     * instead of calling the AI again (US-016).
     *
     * @param  EloquentCollection<int, Product>  $notCached
     */
    private function logResults(
        EloquentCollection $notCached,
        int $representativeCount,
        ValidatedClassification $classification,
        TaxonomyCatalog $taxonomy,
        string $modelFast,
        int $batchTokensIn,
        int $batchTokensOut,
        EnrichmentCache $cache,
    ): void {
        $count = max($representativeCount, 1);
        $shareIn = intdiv($batchTokensIn, $count);
        $shareOut = intdiv($batchTokensOut, $count);
        $now = Carbon::now();
        $applier = new EnrichmentApplier;

        $rows = [];

        foreach ($notCached as $product) {
            [$result, $isDuplicate] = $this->resolveResult($product, $classification);

            if ($result === null) {
                continue;
            }

            // A duplicate's model must reflect whichever model actually
            // produced its (borrowed) result: look the escalation up under
            // the representative's codice_articolo, not the duplicate's own
            // (which never made its own API call and so is never a key in
            // $escalatedModels). Token share is still 0 for duplicates since
            // no call was made on their behalf.
            $escalationCodice = $isDuplicate
                ? ($this->representativeCodiceByDuplicate[$product->codice_articolo] ?? $product->codice_articolo)
                : $product->codice_articolo;
            $escalation = $this->escalatedModels[$escalationCodice] ?? null;

            $rows[] = [
                'product_id' => $product->id,
                'step' => 'ai_classification',
                'input' => json_encode(['codice_articolo' => $product->codice_articolo], JSON_UNESCAPED_UNICODE),
                'output' => json_encode($this->resultToArray($result), JSON_UNESCAPED_UNICODE),
                'confidence' => $result->confidence,
                'model' => $escalation[0] ?? $modelFast,
                'tokens_in' => $isDuplicate ? 0 : ($escalation[1] ?? $shareIn),
                'tokens_out' => $isDuplicate ? 0 : ($escalation[2] ?? $shareOut),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $applier->apply($product, $result, $taxonomy);
            $cache->put($product, $result);
        }

        if ($rows !== []) {
            EnrichmentLog::query()->insert($rows);
        }
    }

    /**
     * Resolves the classification result to apply to `$product`: its own
     * entry in `$classification` when it was sent to the AI directly, or its
     * representative's result (rekeyed to this product's codice_articolo)
     * when it was deduplicated onto another product with the same
     * `description_raw` (US-016 AC4).
     *
     * @return array{0: ClassifiedProduct|null, 1: bool} The result (or null
     *                                                   if unresolvable) and whether it was borrowed from a representative.
     */
    private function resolveResult(Product $product, ValidatedClassification $classification): array
    {
        $direct = $classification->for($product->codice_articolo);

        if ($direct !== null) {
            return [$direct, false];
        }

        $representativeCodice = $this->representativeCodiceByDuplicate[$product->codice_articolo] ?? null;
        $representativeResult = $representativeCodice !== null ? $classification->for($representativeCodice) : null;

        if ($representativeResult === null) {
            return [null, false];
        }

        return [$this->rekeyed($representativeResult, $product), true];
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
    private function markNeedsReview(EloquentCollection $products, string $reason, string $modelFast): void
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
                'model' => $modelFast,
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
