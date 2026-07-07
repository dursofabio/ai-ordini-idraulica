<?php

namespace App\Filament\Resources\EnrichmentLogs\Tables;

use App\Models\EnrichmentLog;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class EnrichmentLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Data')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('product.codice_articolo')
                    ->label('Codice articolo')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('step')
                    ->label('Step')
                    ->badge(),
                TextColumn::make('model')
                    ->label('Modello')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('confidence')
                    ->label('Confidenza')
                    ->suffix('%')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('tokens_in')
                    ->label('Token input')
                    ->numeric()
                    ->placeholder('—'),
                TextColumn::make('tokens_out')
                    ->label('Token output')
                    ->numeric()
                    ->placeholder('—'),
                TextColumn::make('cost')
                    ->label('Costo')
                    ->numeric(6)
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('step')
                    ->label('Step')
                    ->options(fn (): array => EnrichmentLog::query()
                        ->distinct()
                        ->orderBy('step')
                        ->pluck('step', 'step')
                        ->all()),
                SelectFilter::make('model')
                    ->label('Modello')
                    ->options(fn (): array => EnrichmentLog::query()
                        ->whereNotNull('model')
                        ->distinct()
                        ->orderBy('model')
                        ->pluck('model', 'model')
                        ->all()),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                //
            ]);
    }
}
