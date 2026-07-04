<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Filament\Pages\ReviewQueue;
use App\Filament\Resources\Products\Actions\EnrichmentProposalTriageActions;
use App\Models\EnrichmentProposal;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Lists every proposal (applied/discarded ones included, as an audit trail)
 * and exposes the same per-proposal triage actions as {@see ReviewQueue} —
 * confirm / correct / discard, shared via
 * {@see EnrichmentProposalTriageActions} — on the rows still `pending`, so
 * an admin looking at a product can resolve its open proposals without
 * leaving the page.
 */
class EnrichmentProposalsRelationManager extends RelationManager
{
    protected static string $relationship = 'enrichmentProposals';

    protected static ?string $title = 'Proposte di arricchimento';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('field')
            ->columns([
                TextColumn::make('field')
                    ->label('Campo')
                    ->formatStateUsing(fn (EnrichmentProposal $record): string => match ($record->field) {
                        'brand' => 'Marca',
                        'family' => 'Famiglia',
                        'subfamily' => 'Sottofamiglia',
                        'attribute' => "Attributo: {$record->attribute_key}",
                        'product_type' => 'Tipo prodotto',
                        'attribute_definition' => 'Nuova chiave attributo',
                        default => $record->field,
                    }),
                TextColumn::make('value')
                    ->label('Valore proposto')
                    ->state(function (EnrichmentProposal $record): string {
                        $value = $record->value_text ?? rtrim(rtrim((string) $record->value_num, '0'), '.');
                        $unit = filled($record->unit) ? ' '.$record->unit : '';

                        return "{$value}{$unit}";
                    }),
                TextColumn::make('data_type')
                    ->label('Tipo dato')
                    ->placeholder('—'),
                TextColumn::make('origin')
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
                TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => 'In attesa',
                        'applied' => 'Applicata',
                        'discarded' => 'Scartata',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'applied' => 'success',
                        'discarded' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')
                    ->label('Data')
                    ->dateTime(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->recordActions([
                EnrichmentProposalTriageActions::confirm(),
                EnrichmentProposalTriageActions::correct(),
                EnrichmentProposalTriageActions::discard(),
            ])
            ->toolbarActions([]);
    }
}
