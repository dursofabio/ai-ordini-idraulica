<?php

namespace App\Filament\Resources\ImportBatches\Schemas;

use App\Models\ImportBatch;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ImportBatchInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('filename')
                    ->label('File'),
                TextEntry::make('status')
                    ->label('Stato')
                    ->badge(),
                TextEntry::make('total_rows')
                    ->label('Righe totali')
                    ->numeric(),
                TextEntry::make('processed_rows')
                    ->label('Righe elaborate')
                    ->numeric(),
                TextEntry::make('rows_new')
                    ->label('Righe nuove')
                    ->numeric(),
                TextEntry::make('rows_updated')
                    ->label('Righe aggiornate')
                    ->numeric(),
                TextEntry::make('error_rows')
                    ->label('Righe in errore')
                    ->numeric()
                    ->badge()
                    ->color(fn (ImportBatch $record): string => $record->error_rows > 0 ? 'danger' : 'gray'),
                TextEntry::make('skipped_rows')
                    ->label('Righe scartate')
                    ->numeric(),
                TextEntry::make('started_at')
                    ->label('Avviato il')
                    ->dateTime(),
                TextEntry::make('finished_at')
                    ->label('Completato il')
                    ->dateTime(),
            ]);
    }
}
