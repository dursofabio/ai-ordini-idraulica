<?php

namespace App\Filament\Resources\Brands\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only: technical attributes are populated exclusively by the
 * enrichment pipeline (or the correction form on
 * {@see ReviewQueueDetail}), never edited directly from
 * the product page.
 */
class ProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'products';

    protected static ?string $title = 'Prodotti';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('codice_articolo')
            ->columns([
                TextColumn::make('codice_articolo')
                    ->label('Codice')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('description_raw')
                    ->label('Descrizione')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('family.name')
                    ->label('Famiglia')
                    ->searchable()
                    ->sortable()
                    ->badge(),
                TextColumn::make('subfamily.name')
                    ->label('Sottofamiglia')
                    ->searchable()
                    ->sortable()
                    ->badge(),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
