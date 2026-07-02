<?php

namespace App\Services\Enrichment;

use App\Jobs\ClassifyProductsBatchJob;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Selects the products still missing a brand or family and dispatches one
 * {@see ClassifyProductsBatchJob} per batch of 20-50 product IDs, so the
 * costly AI classification call is amortized across many products instead
 * of one call per product.
 *
 * All jobs dispatched by a single {@see self::dispatch()} call share one
 * generated `runId`, so {@see AiSpendGuard} can
 * track and cap AI spend across the whole run instead of per job (US-016).
 */
class ClassificationBatchDispatcher
{
    /**
     * Default/target batch size dispatched per job, within the accepted
     * 20-50 range.
     */
    public const DEFAULT_BATCH_SIZE = 40;

    /**
     * Minimum accepted batch size. A naive fixed-size chunk() would leave a
     * short trailing batch (e.g. 85 products at size 40 => 40/40/5), which
     * violates the acceptance criterion that every call covers 20-50
     * products. Batches are rebalanced instead so each one falls in range,
     * except when fewer than this many eligible products exist in total, in
     * which case a single undersized batch is unavoidable and accepted.
     */
    public const MIN_BATCH_SIZE = 20;

    /**
     * Select eligible products (missing brand_id or family_id, and
     * enrichment_status = 'pending') and dispatch one job per rebalanced
     * batch of product IDs, each sized between {@see self::MIN_BATCH_SIZE}
     * and `$targetBatchSize` (unless too few products exist overall).
     * Returns the number of jobs dispatched.
     */
    public function dispatch(int $targetBatchSize = self::DEFAULT_BATCH_SIZE): int
    {
        $ids = Product::query()
            ->where('enrichment_status', 'pending')
            ->where(function ($query): void {
                $query->whereNull('brand_id')->orWhereNull('family_id');
            })
            ->orderBy('id')
            ->pluck('id');

        $dispatched = 0;
        $runId = (string) Str::uuid();

        foreach ($this->balancedBatches($ids, $targetBatchSize) as $batch) {
            ClassifyProductsBatchJob::dispatch($batch, $runId);
            $dispatched++;
        }

        return $dispatched;
    }

    /**
     * Splits `$ids` into batches that each fall within
     * [{@see self::MIN_BATCH_SIZE}, `$targetBatchSize`] whenever the total
     * count allows it: the number of batches is chosen so the IDs divide as
     * evenly as possible, instead of chunking to a fixed size and risking an
     * undersized trailing batch.
     *
     * @param  Collection<int, int>  $ids
     * @return array<int, array<int, int>>
     */
    private function balancedBatches(Collection $ids, int $targetBatchSize): array
    {
        $total = $ids->count();

        if ($total === 0) {
            return [];
        }

        if ($total <= $targetBatchSize) {
            return [$ids->values()->all()];
        }

        $batchCount = (int) ceil($total / $targetBatchSize);
        $baseSize = intdiv($total, $batchCount);
        $remainder = $total % $batchCount;

        $batches = [];
        $offset = 0;

        for ($i = 0; $i < $batchCount; $i++) {
            $size = $baseSize + ($i < $remainder ? 1 : 0);
            $batches[] = $ids->slice($offset, $size)->values()->all();
            $offset += $size;
        }

        return $batches;
    }
}
