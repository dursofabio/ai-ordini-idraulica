<?php

namespace App\Filament\Resources\ProductBases\Pages;

use App\Filament\Resources\ProductBases\ProductBaseResource;
use Filament\Resources\Pages\ListRecords;

class ListProductBases extends ListRecords
{
    protected static string $resource = ProductBaseResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
