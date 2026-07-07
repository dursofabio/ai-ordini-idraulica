<?php

namespace App\Filament\Resources\EnrichmentLogs\Schemas;

use App\Models\EnrichmentLog;
use Filament\Infolists\Components\CodeEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EnrichmentLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Group::make()
                    ->schema([
                        Section::make('Riepilogo strutturato')
                            ->collapsible()
                            ->schema([
                                self::jsonEntry('input', 'Input'),
                                self::jsonEntry('output', 'Output'),
                            ]),
                        Section::make('Richiesta e risposta complete')
                            ->description('Il payload esatto inviato all\'AI e la risposta grezza ricevuta, così come scambiati con il provider.')
                            ->collapsible()
                            ->collapsed()
                            ->schema([
                                self::jsonEntry('request_payload', 'Richiesta inviata all\'AI'),
                                self::jsonEntry('response_payload', 'Risposta ricevuta dall\'AI'),
                            ]),

                    ])
                    ->columnSpan(2),
                Section::make('Riepilogo')
                    ->columns(1)
                    ->schema([
                        TextEntry::make('product.codice_articolo')
                            ->label('Codice articolo'),
                        TextEntry::make('product.description_raw')
                            ->label('Descrizione'),
                        TextEntry::make('step')
                            ->label('Step')
                            ->badge(),
                        TextEntry::make('created_at')
                            ->label('Data')
                            ->dateTime(),
                        TextEntry::make('model')
                            ->label('Modello')
                            ->placeholder('—'),
                        TextEntry::make('confidence')
                            ->label('Confidenza')
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
                        TextEntry::make('cost')
                            ->label('Costo')
                            ->numeric(6)
                            ->placeholder('—'),
                    ]),
            ])
            ->columns(3);
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
}
