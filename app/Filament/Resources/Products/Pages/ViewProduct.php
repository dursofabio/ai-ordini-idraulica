<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\Actions\ProductEnrichmentActions;
use App\Filament\Resources\Products\ProductResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewProduct extends ViewRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * Exposes the same manual reprocessing actions as the products table row
     * (US-031 AC4), reusing {@see ProductEnrichmentActions} so both surfaces
     * behave identically.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            ProductEnrichmentActions::relaunchDeterministicEnrichment(),
            ProductEnrichmentActions::relaunchAiClassification(),
            ProductEnrichmentActions::deepEnrichWithAi(),
            ProductEnrichmentActions::regenerateEmbedding(),
        ];
    }
}
