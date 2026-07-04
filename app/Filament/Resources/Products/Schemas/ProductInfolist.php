<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Models\Product;
use Filament\Infolists\Components\IconEntry;
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
                        TextEntry::make('description_clean')
                            ->label('Descrizione normalizzata')
                            ->placeholder('—'),
                        TextEntry::make('product_type')
                            ->label('Tipo prodotto')
                            ->placeholder('—'),
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
                    ])
                    ->columns(2),
                Section::make('Dati da file')
                    ->schema([
                        TextEntry::make('descrizione_marca')
                            ->label('Marca da file')
                            ->placeholder('—'),
                        TextEntry::make('marca_codice')
                            ->label('Codice marca')
                            ->placeholder('—'),
                        TextEntry::make('fam_descrizione')
                            ->label('Famiglia da file')
                            ->placeholder('—'),
                        TextEntry::make('fam_codice')
                            ->label('Codice famiglia')
                            ->placeholder('—'),
                        TextEntry::make('subfam_descrizione')
                            ->label('Sottofamiglia da file')
                            ->placeholder('—'),
                        TextEntry::make('subfam_codice')
                            ->label('Codice sottofamiglia')
                            ->placeholder('—'),
                        TextEntry::make('costo')
                            ->label('Costo')
                            ->money('EUR'),
                        TextEntry::make('giacenza')
                            ->label('Giacenza')
                            ->numeric(),
                    ])
                    ->columns(2),
                Section::make('Stato arricchimento')
                    ->schema([
                        TextEntry::make('enrichment_status')
                            ->label('Stato')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'enriched' => 'success',
                                'needs_review' => 'warning',
                                default => 'gray',
                            }),
                        TextEntry::make('source')
                            ->label('Origine')
                            ->placeholder('—'),
                        TextEntry::make('confidence')
                            ->label('Confidenza')
                            ->badge()
                            ->formatStateUsing(fn (?int $state): string => $state === null ? 'N/D' : "{$state}%")
                            ->color(fn (?int $state): string => match (true) {
                                $state === null => 'gray',
                                $state < 60 => 'danger',
                                $state < 85 => 'warning',
                                default => 'success',
                            }),
                        IconEntry::make('is_active')
                            ->label('Attivo')
                            ->boolean(),
                    ])
                    ->columns(4),
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
            ]);
    }
}
