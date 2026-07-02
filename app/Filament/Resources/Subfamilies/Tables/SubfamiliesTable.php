<?php

namespace App\Filament\Resources\Subfamilies\Tables;

use App\Models\Subfamily;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SubfamiliesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable(),
                TextColumn::make('slug')
                    ->label('Slug'),
                TextColumn::make('aliases')
                    ->label('N. alias')
                    ->state(fn (Subfamily $record): int => count($record->aliases ?? [])),
                TextColumn::make('family.name')
                    ->label('Famiglia'),
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
