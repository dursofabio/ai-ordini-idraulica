<?php

namespace App\Services\Enrichment;

use App\Jobs\ClassifyProductsBatchJob;
use Illuminate\Support\Facades\Cache;

/**
 * Tracks and caps the estimated USD cost of AI classification calls across
 * all {@see ClassifyProductsBatchJob} instances belonging to the
 * same {@see ClassificationBatchDispatcher} run, identified by a shared
 * `runId` (US-016). Pricing is read from `config('services.anthropic.pricing')`
 * and the cap from `config('services.anthropic.batch_cost_cap')` (null =
 * unlimited).
 *
 * The running total for a run is stored in the cache under
 * `enrichment:spend:{runId}` with a 24h TTL — long enough to outlive every
 * job in a run, short enough not to leak indefinitely — and incremented
 * atomically via `Cache::lock()` so concurrent jobs on the same run never
 * race on the read-modify-write.
 */
class AiSpendGuard
{
    /**
     * TTL (seconds) for the per-run spend counter: long enough to cover a
     * full dispatcher run's worth of queued jobs.
     */
    private const SPEND_TTL_SECONDS = 86400;

    /**
     * Seconds to wait for the spend counter lock before giving up.
     */
    private const LOCK_WAIT_SECONDS = 10;

    /**
     * Estimate the USD cost of a call given its model and token counts,
     * using the per-million-token pricing configured for that model's role
     * (`model_fast`/`model_smart`). Returns 0.0 if the model doesn't match
     * either configured role.
     */
    public function estimateCost(string $model, int $tokensIn, int $tokensOut): float
    {
        $pricing = $this->pricingFor($model);

        if ($pricing === null) {
            return 0.0;
        }

        $costIn = ($tokensIn / 1_000_000) * (float) $pricing['input_per_million'];
        $costOut = ($tokensOut / 1_000_000) * (float) $pricing['output_per_million'];

        return $costIn + $costOut;
    }

    /**
     * Atomically add `$cost` to the run's running total and return the new
     * total.
     */
    public function spend(string $runId, float $cost): float
    {
        $lock = Cache::lock('enrichment:spend-lock:'.$runId, self::LOCK_WAIT_SECONDS);

        return $lock->block(self::LOCK_WAIT_SECONDS, function () use ($runId, $cost): float {
            $key = $this->spendKey($runId);
            $total = (float) Cache::get($key, 0.0) + $cost;

            Cache::put($key, $total, self::SPEND_TTL_SECONDS);

            return $total;
        });
    }

    /**
     * Remaining budget for the run, or null when no cap is configured.
     */
    public function remainingBudget(string $runId): ?float
    {
        $cap = config('services.anthropic.batch_cost_cap');

        if ($cap === null) {
            return null;
        }

        $spent = (float) Cache::get($this->spendKey($runId), 0.0);

        return (float) $cap - $spent;
    }

    /**
     * Whether the run's spend has already reached or exceeded the
     * configured cap. Always false when no cap is configured.
     */
    public function capExceeded(string $runId): bool
    {
        $remaining = $this->remainingBudget($runId);

        return $remaining !== null && $remaining <= 0.0;
    }

    /**
     * @return array{input_per_million: float, output_per_million: float}|null
     */
    private function pricingFor(string $model): ?array
    {
        $pricing = config('services.anthropic.pricing', []);

        foreach (['model_fast', 'model_smart'] as $role) {
            if ($model === config("services.anthropic.{$role}")) {
                return $pricing[$role] ?? null;
            }
        }

        return null;
    }

    private function spendKey(string $runId): string
    {
        return 'enrichment:spend:'.$runId;
    }
}
