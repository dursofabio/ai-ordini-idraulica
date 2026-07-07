<?php

namespace App\Filament\Resources\EnrichmentLogs\Pages;

use App\Filament\Resources\EnrichmentLogs\EnrichmentLogResource;
use Filament\Resources\Pages\ListRecords;

class ListEnrichmentLogs extends ListRecords
{
    protected static string $resource = EnrichmentLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            //
        ];
    }
}
