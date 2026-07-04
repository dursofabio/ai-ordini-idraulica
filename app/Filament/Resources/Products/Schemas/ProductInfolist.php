<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Models\Product;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ProductInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Prodotto')
                    ->schema([
                        TextEntry::make('codice_articolo'),
                        TextEntry::make('description_raw')
                            ->label('Descrizione'),
                        TextEntry::make('brand.name')
                            ->label('Marca')
                            ->badge()
                            ->color(fn (Product $record): string => $record->brand_source === 'manual' ? 'info' : 'gray')
                            ->icon(fn (Product $record): ?Heroicon => $record->brand_source === 'manual' ? Heroicon::OutlinedLockClosed : null),
                        TextEntry::make('family.name')
                            ->label('Famiglia')
                            ->badge()
                            ->color(fn (Product $record): string => $record->family_source === 'manual' ? 'info' : 'gray')
                            ->icon(fn (Product $record): ?Heroicon => $record->family_source === 'manual' ? Heroicon::OutlinedLockClosed : null),
                        TextEntry::make('subfamily.name')
                            ->label('Sottofamiglia')
                            ->badge()
                            ->color(fn (Product $record): string => $record->subfamily_source === 'manual' ? 'info' : 'gray')
                            ->icon(fn (Product $record): ?Heroicon => $record->subfamily_source === 'manual' ? Heroicon::OutlinedLockClosed : null),
                    ]),
                Section::make('Stato Embedding')
                    ->schema([
                        TextEntry::make('embedding_status')
                            ->label('Stato')
                            ->state(fn (Product $record): string => $record->embedding ? 'Generato' : 'Assente')
                            ->badge()
                            ->color(fn (Product $record): string => $record->embedding ? 'success' : 'gray'),
                        TextEntry::make('embedding.model')
                            ->label('Modello')
                            ->placeholder('—'),
                        TextEntry::make('embedding.created_at')
                            ->label('Generato il')
                            ->dateTime()
                            ->placeholder('—'),
                    ]),
                Section::make('Storico Arricchimento')
                    ->schema([
                        RepeatableEntry::make('enrichmentLogs')
                            ->hiddenLabel()
                            // Order by created_at explicitly instead of adding a
                            // default ordering to Product::enrichmentLogs(), which
                            // other callers (e.g. the enrichment pipeline) rely on
                            // returning entries in natural insertion order.
                            ->state(fn (Product $record) => $record->enrichmentLogs()->orderBy('created_at')->get())
                            ->table([
                                TableColumn::make('Step'),
                                TableColumn::make('Confidenza'),
                                TableColumn::make('Modello'),
                                TableColumn::make('Token Input'),
                                TableColumn::make('Token Output'),
                            ])
                            ->schema([
                                TextEntry::make('step'),
                                TextEntry::make('confidence')
                                    ->suffix('%')
                                    ->placeholder('—'),
                                TextEntry::make('model')
                                    ->placeholder('—'),
                                TextEntry::make('tokens_in')
                                    ->numeric()
                                    ->placeholder('—'),
                                TextEntry::make('tokens_out')
                                    ->numeric()
                                    ->placeholder('—'),
                            ])
                            ->visible(fn (Product $record): bool => $record->enrichmentLogs()->exists()),
                        TextEntry::make('enrichment_empty_state')
                            ->hiddenLabel()
                            ->state('Nessun arricchimento eseguito')
                            ->visible(fn (Product $record): bool => $record->enrichmentLogs()->doesntExist()),
                    ]),
            ]);
    }
}
