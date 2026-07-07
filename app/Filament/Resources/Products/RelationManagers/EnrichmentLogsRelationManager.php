<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Models\EnrichmentLog;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\CodeEntry;
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
                self::jsonEntry('input', 'Input'),
                self::jsonEntry('output', 'Output'),
                self::jsonEntry('request_payload', 'Richiesta completa inviata all\'AI'),
                self::jsonEntry('response_payload', 'Risposta completa dell\'AI'),
            ]);
    }

    /**
     * A JSON column rendered with syntax highlighting (Phiki auto-detects
     * `Grammar::Json` for array state, which is how `input`/`output`/
     * `request_payload`/`response_payload` are cast on {@see EnrichmentLog}).
     * Phiki's `<pre>` has no `white-space` override, so the browser default
     * (`pre`) would force a horizontal scrollbar on long lines; wrap it
     * instead since prompts routinely exceed the available width.
     */
    private static function jsonEntry(string $name, string $label): CodeEntry
    {
        return CodeEntry::make($name)
            ->label($label)
            ->copyable()
            ->placeholder('—')
            ->extraAttributes(['class' => '[&_pre]:whitespace-pre-wrap [&_pre]:break-words'])
            ->columnSpanFull();
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
