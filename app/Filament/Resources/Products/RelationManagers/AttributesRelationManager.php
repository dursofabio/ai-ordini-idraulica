<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Filament\Pages\ReviewQueue;
use App\Filament\Pages\ReviewQueueDetail;
use App\Models\ProductAttribute;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only: technical attributes are populated exclusively by the
 * enrichment pipeline (or the correction form on
 * {@see ReviewQueueDetail}), never edited directly from
 * the product page.
 */
class AttributesRelationManager extends RelationManager
{
    protected static string $relationship = 'attributes';

    protected static ?string $title = 'Attributi tecnici';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('key')
            ->columns([
                TextColumn::make('key')
                    ->label('Chiave')
                    ->searchable(),
                TextColumn::make('value')
                    ->label('Valore')
                    ->state(function (ProductAttribute $record): string {
                        $value = $record->value_text ?? rtrim(rtrim((string) $record->value_num, '0'), '.');
                        $unit = filled($record->unit) ? ' '.$record->unit : '';

                        return "{$value}{$unit}";
                    }),
                TextColumn::make('source')
                    ->label('Origine')
                    ->formatStateUsing(fn (?string $state): string => ReviewQueue::originLabel($state)),
                TextColumn::make('confidence')
                    ->label('Confidenza')
                    ->badge()
                    ->formatStateUsing(fn (?int $state): string => $state === null ? 'N/D' : "{$state}%")
                    ->color(fn (?int $state): string => match (true) {
                        $state === null => 'gray',
                        $state < 60 => 'danger',
                        $state < 85 => 'warning',
                        default => 'success',
                    }),
            ])
            ->defaultSort('key')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
