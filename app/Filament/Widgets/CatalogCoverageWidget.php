<?php

namespace App\Filament\Widgets;

use App\Models\Product;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * US-025 AC1 — shows the percentage of products with brand, family, and
 * subfamily valorized, so an admin can monitor catalog enrichment coverage
 * at a glance without querying the database manually.
 */
class CatalogCoverageWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = '15s';

    protected function getStats(): array
    {
        // Single aggregate query (COUNT + conditional COUNT) instead of 4
        // separate round-trips, per the plan's "query aggregate singole"
        // guidance. COUNT(column) already ignores NULLs.
        $counts = Product::query()
            ->selectRaw('count(*) as total, count(brand_id) as with_brand, count(family_id) as with_family, count(subfamily_id) as with_subfamily')
            ->first();

        $total = (int) $counts->total;

        return [
            Stat::make('Copertura marca', $this->percentage((int) $counts->with_brand, $total)),
            Stat::make('Copertura famiglia', $this->percentage((int) $counts->with_family, $total)),
            Stat::make('Copertura sottofamiglia', $this->percentage((int) $counts->with_subfamily, $total)),
        ];
    }

    private function percentage(int $count, int $total): string
    {
        if ($total === 0) {
            return '0%';
        }

        return round(($count / $total) * 100, 1).'%';
    }
}
