<?php

namespace App\Filament\Resources\Products\Tables;

use App\Filament\Resources\Products\Actions\ProductEnrichmentActions;
use App\Models\Product;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('codice_articolo')
                    ->searchable(),
                TextColumn::make('description_raw'),
                TextColumn::make('brand.name')
                    ->label('Marca')
                    ->badge()
                    ->color(fn (Product $record): string => $record->brand_source === 'manual' ? 'info' : 'gray')
                    ->icon(fn (Product $record): ?Heroicon => $record->brand_source === 'manual' ? Heroicon::OutlinedLockClosed : null),
                TextColumn::make('family.name')
                    ->label('Famiglia')
                    ->badge()
                    ->color(fn (Product $record): string => $record->family_source === 'manual' ? 'info' : 'gray')
                    ->icon(fn (Product $record): ?Heroicon => $record->family_source === 'manual' ? Heroicon::OutlinedLockClosed : null),
                TextColumn::make('enrichment_status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'enriched' => 'success',
                        'needs_review' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('confidence'),
            ])
            ->filters([
                SelectFilter::make('enrichment_status')
                    ->options([
                        'pending' => 'In attesa',
                        'enriched' => 'Arricchito',
                        'needs_review' => 'Da rivedere',
                    ]),
                SelectFilter::make('brand')
                    ->relationship('brand', 'name'),
                SelectFilter::make('family')
                    ->relationship('family', 'name'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                ProductEnrichmentActions::relaunchDeterministicEnrichment(),
                ProductEnrichmentActions::relaunchAiClassification(),
                ProductEnrichmentActions::regenerateEmbedding(),
            ]);
    }
}
