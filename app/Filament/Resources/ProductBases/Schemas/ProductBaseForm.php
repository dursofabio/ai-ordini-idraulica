<?php

namespace App\Filament\Resources\ProductBases\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ProductBaseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->label('Titolo')
                    ->required(),
                Textarea::make('description_ai')
                    ->label('Descrizione AI')
                    ->rows(4),
            ]);
    }
}
