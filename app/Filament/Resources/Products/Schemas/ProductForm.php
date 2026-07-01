<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Models\Product;
use App\Models\Subfamily;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('codice_articolo')
                    ->disabled(),
                Textarea::make('description_raw')
                    ->disabled(),
                Select::make('brand_id')
                    ->label('Marca')
                    ->relationship('brand', 'name')
                    ->searchable()
                    ->preload()
                    ->helperText(fn (?Product $record): string => $record?->brand_source === 'manual' ? '🔒 Impostato manualmente' : 'Origine: '.($record?->brand_source ?? '—')),
                Select::make('family_id')
                    ->label('Famiglia')
                    ->relationship('family', 'name')
                    ->searchable()
                    ->preload()
                    ->live()
                    ->helperText(fn (?Product $record): string => $record?->family_source === 'manual' ? '🔒 Impostato manualmente' : 'Origine: '.($record?->family_source ?? '—')),
                Select::make('subfamily_id')
                    ->label('Sottofamiglia')
                    ->options(fn (Get $get) => Subfamily::query()
                        ->where('family_id', $get('family_id'))
                        ->pluck('name', 'id'))
                    ->searchable()
                    ->helperText(fn (?Product $record): string => $record?->subfamily_source === 'manual' ? '🔒 Impostato manualmente' : 'Origine: '.($record?->subfamily_source ?? '—')),
            ]);
    }
}
