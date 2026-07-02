<?php

namespace App\Filament\Resources\Subfamilies\Pages;

use App\Filament\Resources\Subfamilies\SubfamilyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSubfamilies extends ListRecords
{
    protected static string $resource = SubfamilyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
