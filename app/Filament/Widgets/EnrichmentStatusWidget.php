<?php

namespace App\Filament\Widgets;

use App\Models\Product;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Str;

/**
 * US-025 AC2 — shows how many articles fall into each `enrichment_status`
 * value currently present in the data. `enrichment_status` is a free-form
 * string column (not a PHP Enum), so the widget enumerates the distinct
 * values actually in use via a `group by` instead of assuming a fixed set —
 * this avoids hardcoded '0' stats for statuses never used, and stays correct
 * if new statuses are introduced later.
 */
class EnrichmentStatusWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = '15s';

    protected function getStats(): array
    {
        $counts = Product::query()
            ->selectRaw('enrichment_status, count(*) as total')
            ->groupBy('enrichment_status')
            ->pluck('total', 'enrichment_status');

        return $counts
            ->map(fn (int $total, string $status) => Stat::make(Str::headline($status), (string) $total))
            ->values()
            ->all();
    }
}
