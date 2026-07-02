<?php

namespace App\Filament\Resources\ImportBatches\Tables;

use App\Enums\ImportBatchStatus;
use App\Models\ImportBatch;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ImportBatchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('filename')
                    ->label('File'),
                TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->color(fn (ImportBatch $record): string => match ($record->status) {
                        ImportBatchStatus::Completed => 'success',
                        ImportBatchStatus::Failed => 'danger',
                        ImportBatchStatus::Uploaded => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('total_rows')
                    ->label('Righe totali')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('rows_new')
                    ->label('Nuove')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('rows_updated')
                    ->label('Aggiornate')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('error_rows')
                    ->label('In errore')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color(fn (ImportBatch $record): string => $record->error_rows > 0 ? 'danger' : 'gray'),
                TextColumn::make('skipped_rows')
                    ->label('Scartate')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('started_at')
                    ->label('Avviato il')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('finished_at')
                    ->label('Completato il')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Stato')
                    ->options(collect(ImportBatchStatus::cases())->mapWithKeys(
                        fn (ImportBatchStatus $status): array => [$status->value => ucfirst($status->value)]
                    )),
            ])
            ->recordActions([
                //
            ])
            ->toolbarActions([
                //
            ]);
    }
}
