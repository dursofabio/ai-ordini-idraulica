<?php

namespace App\Filament\Widgets;

use App\Models\EnrichmentLog;
use App\Services\Enrichment\AiSpendGuard;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * US-025 AC3 — shows the estimated AI cost of the last classification batch.
 *
 * There is no `run_id`/batch column persisted on `enrichment_logs` (the
 * dispatcher's `runId` only lives in cache via {@see AiSpendGuard}). Rows
 * belonging to the same `ClassifyProductsBatchJob::logResults()` call are
 * inserted in bulk sharing the same `created_at` (a single `Carbon::now()`
 * captured once per call), so "last batch" is defined here as the group of
 * `enrichment_logs` rows with the maximum `created_at` timestamp. Tokens are
 * summed per `model` within that group and priced via
 * {@see AiSpendGuard::estimateCost()}.
 *
 * **Known limitation**: `enrichment_logs.created_at` is a `timestamp(0)`
 * column (Laravel's default `timestamps()` precision), so it is truncated to
 * whole-second precision on write in production (PostgreSQL), even though
 * `Carbon::now()` captures microseconds in PHP. Two distinct batches that
 * complete within the same wall-clock second (plausible with parallel
 * Horizon workers) would be merged into a single "last batch" group here,
 * skewing the reported cost. This is an accepted trade-off for the MVP scope
 * of this spec (3 points) — a robust fix requires a persisted `run_id`
 * column on `enrichment_logs`, which is out of scope. SQLite (used by this
 * widget's test suite and by the Dusk e2e stack) does not reproduce this
 * truncation, so the collision scenario itself is not covered by automated
 * tests against the production database engine.
 */
class AiCostWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = '15s';

    protected function getStats(): array
    {
        $guard = app(AiSpendGuard::class);

        $lastBatchTimestamp = EnrichmentLog::query()->max('created_at');

        if ($lastBatchTimestamp === null) {
            return [
                Stat::make('Costo AI ultimo batch', '$0.00'),
            ];
        }

        $tokensByModel = EnrichmentLog::query()
            ->where('created_at', $lastBatchTimestamp)
            ->whereNotNull('model')
            ->selectRaw('model, sum(tokens_in) as tokens_in, sum(tokens_out) as tokens_out')
            ->groupBy('model')
            ->get();

        $cost = $tokensByModel->sum(
            fn (EnrichmentLog $row) => $guard->estimateCost($row->model, (int) $row->tokens_in, (int) $row->tokens_out)
        );

        return [
            Stat::make('Costo AI ultimo batch', '$'.number_format($cost, 4)),
        ];
    }
}
