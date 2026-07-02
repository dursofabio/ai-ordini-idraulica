<?php

namespace App\Filament\Resources\Subfamilies\Pages;

use App\Filament\Resources\Subfamilies\SubfamilyResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSubfamily extends EditRecord
{
    protected static string $resource = SubfamilyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
