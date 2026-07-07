<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Filament\Pages\ReviewQueue;
use App\Filament\Resources\Products\Actions\EnrichmentProposalTriageActions;
use App\Models\EnrichmentProposal;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Lists every proposal (applied/discarded ones included, as an audit trail)
 * and exposes the same per-proposal triage actions as {@see ReviewQueue} —
 * confirm / correct / discard, shared via
 * {@see EnrichmentProposalTriageActions} — on the rows still `pending`, so
 * an admin looking at a product can resolve its open proposals without
 * leaving the page.
 *
 * The `descrizione_estesa` proposal's value is markdown (US-051): the
 * "Valore proposto" column renders it (rather than the raw source) and
 * truncates it to {@see self::DESCRIPTION_PREVIEW_LENGTH} characters, with
 * {@see self::viewDescriptionAction()} opening a modal with the full
 * rendered text for rows too long to skim in the table.
 */
class EnrichmentProposalsRelationManager extends RelationManager
{
    protected static string $relationship = 'enrichmentProposals';

    protected static ?string $title = 'Proposte di arricchimento';

    /**
     * Character budget for the "Valore proposto" column preview of a
     * `descrizione_estesa` proposal, truncated before markdown conversion so
     * the table row stays scannable — the full text remains one click away
     * via {@see self::viewDescriptionAction()}.
     */
    private const DESCRIPTION_PREVIEW_LENGTH = 150;

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
                        'descrizione_estesa' => 'Descrizione',
                        'attribute_definition' => 'Nuova chiave attributo',
                        default => $record->field,
                    }),
                TextColumn::make('value')
                    ->label('Valore proposto')
                    ->markdown(fn (EnrichmentProposal $record): bool => $record->field === 'descrizione_estesa')
                    ->state(function (EnrichmentProposal $record): string {
                        if ($record->field === 'descrizione_estesa') {
                            return Str::limit((string) $record->value, self::DESCRIPTION_PREVIEW_LENGTH);
                        }

                        $unit = filled($record->unit) ? ' '.$record->unit : '';

                        return "{$record->value}{$unit}";
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
                self::viewDescriptionAction(),
                EnrichmentProposalTriageActions::confirm(),
                EnrichmentProposalTriageActions::correct(),
                EnrichmentProposalTriageActions::discard(),
            ])
            ->toolbarActions([]);
    }

    /**
     * Opens a read-only modal with the full rendered markdown of a
     * `descrizione_estesa` proposal — the "Valore proposto" column only
     * shows a truncated preview.
     */
    private static function viewDescriptionAction(): Action
    {
        return Action::make('viewDescription')
            ->label('Visualizza')
            ->color('gray')
            ->icon(Heroicon::OutlinedEye)
            ->visible(fn (EnrichmentProposal $record): bool => $record->field === 'descrizione_estesa' && filled($record->value))
            ->modalHeading('Descrizione estesa proposta')
            ->modalContent(fn (EnrichmentProposal $record): HtmlString => self::renderDescriptionMarkdown($record->value))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Chiudi');
    }

    /**
     * Converts a proposal's full, untruncated markdown value to sanitized
     * HTML for {@see self::viewDescriptionAction()}'s modal. Extracted as
     * its own method (rather than inlined in the action's `->modalContent()`
     * closure) so it can be unit-tested directly — Livewire's table-action
     * modal content is rendered lazily on the client and isn't present in a
     * component's server-rendered test HTML.
     */
    public static function renderDescriptionMarkdown(?string $value): HtmlString
    {
        return new HtmlString(Str::sanitizeHtml(Str::markdown((string) $value)));
    }
}
