<?php

namespace App\Filament\Resources\AttributeDefinitions\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AttributeDefinitionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')
                    ->label('Chiave')
                    ->searchable(),
                TextColumn::make('data_type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'numeric' ? 'Numerico' : 'Testo')
                    ->color(fn (string $state): string => $state === 'numeric' ? 'info' : 'gray'),
                TextColumn::make('canonical_unit')
                    ->label('Unità canonica')
                    ->placeholder('—'),
                TextColumn::make('description')
                    ->label('Descrizione')
                    ->limit(60)
                    ->searchable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
