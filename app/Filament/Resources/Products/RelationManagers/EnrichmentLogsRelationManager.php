<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Models\EnrichmentLog;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only: enrichment logs are written exclusively by the enrichment
 * pipeline, never edited from the product page.
 */
class EnrichmentLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'enrichmentLogs';

    protected static ?string $title = 'Storico arricchimento';

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('step'),
                TextEntry::make('model')
                    ->placeholder('—'),
                TextEntry::make('confidence')
                    ->suffix('%')
                    ->placeholder('—'),
                TextEntry::make('tokens_in')
                    ->label('Token input')
                    ->numeric()
                    ->placeholder('—'),
                TextEntry::make('tokens_out')
                    ->label('Token output')
                    ->numeric()
                    ->placeholder('—'),
                TextEntry::make('input')
                    ->state(fn (EnrichmentLog $record): string => json_encode($record->input, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '—')
                    ->extraAttributes(['class' => 'whitespace-pre-wrap font-mono text-xs'])
                    ->columnSpanFull(),
                TextEntry::make('output')
                    ->state(fn (EnrichmentLog $record): string => json_encode($record->output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '—')
                    ->extraAttributes(['class' => 'whitespace-pre-wrap font-mono text-xs'])
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('step')
            ->columns([
                TextColumn::make('step')
                    ->label('Step'),
                TextColumn::make('confidence')
                    ->label('Confidenza')
                    ->suffix('%')
                    ->placeholder('—'),
                TextColumn::make('model')
                    ->label('Modello')
                    ->placeholder('—'),
                TextColumn::make('tokens_in')
                    ->label('Token input')
                    ->numeric()
                    ->placeholder('—'),
                TextColumn::make('tokens_out')
                    ->label('Token output')
                    ->numeric()
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Data')
                    ->dateTime(),
            ])
            ->defaultSort('created_at')
            ->headerActions([])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
