<?php

namespace App\Filament\Widgets;

use App\Models\Product;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * US-025 AC4 — shows the count of inactive articles (`is_active = false`),
 * so an admin can spot catalog items that dropped out of circulation.
 */
class InactiveProductsWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = '15s';

    protected function getStats(): array
    {
        return [
            Stat::make('Articoli inattivi', (string) Product::query()->where('is_active', false)->count()),
        ];
    }
}
