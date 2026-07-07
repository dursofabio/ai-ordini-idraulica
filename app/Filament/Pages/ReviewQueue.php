<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Products\Actions\EnrichmentProposalTriageActions;
use App\Models\Brand;
use App\Models\EnrichmentProposal;
use App\Models\Family;
use App\Models\Subfamily;
use App\Services\Enrichment\EnrichmentApplier;
use App\Services\Enrichment\EnrichmentProposalTriage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * US-041: work queue for individual pending proposals from the
 * `enrichment_proposals` register (US-040), so an admin triages exactly the
 * proposal that needs a decision (e.g. a single uncertain attribute) instead
 * of the whole product, even when the rest of the product's taxonomy is
 * already certain. One row = one pending {@see EnrichmentProposal}; a
 * product with several pending proposals appears once per proposal.
 *
 *  - Confirm: writes the proposed value to the product (brand/family/
 *    subfamily field + its own `*_source`, or the technical attribute row)
 *    with the field's source set to the proposal's `origin`, and marks the
 *    proposal `applied`. This is the exact same effect as the automatic
 *    high-confidence application performed by
 *    {@see EnrichmentApplier}.
 *  - Correct: opens an inline form — a taxonomy `Select` or attribute value
 *    inputs, depending on the proposal's field — prevalorized with the
 *    proposal's current value, and saves the submitted value with
 *    `source = 'manual'`, marking the proposal `applied`.
 *  - Discard: marks the proposal `discarded` without touching the product at
 *    all, since the pending value was never applied in the first place.
 *
 * The per-row actions come from {@see EnrichmentProposalTriageActions} and
 * the write semantics live in {@see EnrichmentProposalTriage}, both shared
 * with the product view page's proposals list so the two surfaces can never
 * diverge.
 */
class ReviewQueue extends Page implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'Da revisionare';

    protected static ?string $title = 'Da revisionare';

    protected string $view = 'filament.pages.review-queue';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                EnrichmentProposal::query()
                    ->where('status', 'pending')
                    ->with('product')
            )
            // US-036/US-041: kept as a `->defaultSort()` closure (applied by
            // Filament *after* any explicit column sort chosen via
            // `->sortable()`) so clicking a sortable header actually
            // overrides the ordering, while this confidence-ascending/
            // nulls-first order still applies as a tie-breaker and as the
            // initial queue order.
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByRaw('confidence IS NOT NULL')
                ->orderBy('confidence'))
            ->heading(fn (): string => $this->queueHeading())
            ->columns([
                TextColumn::make('product.codice_articolo')
                    ->label('Codice articolo'),
                TextColumn::make('product.description_raw')
                    ->label('Descrizione originale')
                    ->wrap(),
                TextColumn::make('field')
                    ->label('Campo')
                    ->formatStateUsing(fn (EnrichmentProposal $record): string => self::fieldLabel($record)),
                TextColumn::make('proposed_value')
                    ->label('Valore proposto')
                    ->state(fn (EnrichmentProposal $record): string => self::proposedValueLabel($record)),
                TextColumn::make('origin')
                    ->label('Origine')
                    ->formatStateUsing(fn (?string $state): string => self::originLabel($state)),
                TextColumn::make('confidence')
                    ->label('Confidenza')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (?int $state): string => $state === null ? 'N/D' : "{$state}%")
                    ->color(fn (?int $state): string => match (true) {
                        $state === null => 'gray',
                        $state < 60 => 'danger',
                        $state < 85 => 'warning',
                        default => 'success',
                    }),
            ])
            ->filters([
                SelectFilter::make('field')
                    ->label('Campo')
                    ->options([
                        'brand' => 'Marca',
                        'family' => 'Famiglia',
                        'subfamily' => 'Sottofamiglia',
                        'attribute' => 'Attributo',
                        'product_type' => 'Tipo prodotto',
                        'descrizione_estesa' => 'Descrizione estesa',
                        'attribute_definition' => 'Nuova chiave attributo',
                    ]),
                SelectFilter::make('confidence_band')
                    ->label('Fascia di confidenza')
                    ->options([
                        'bassa' => 'Bassa (<60)',
                        'media' => 'Media (60-84)',
                        'alta' => 'Alta (≥85)',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'bassa' => $query->where('confidence', '<', 60),
                            'media' => $query->whereBetween('confidence', [60, 84]),
                            'alta' => $query->where('confidence', '>=', 85),
                            default => $query,
                        };
                    }),
            ])
            ->recordActions([
                $this->viewDetailAction(),
                EnrichmentProposalTriageActions::confirm(),
                EnrichmentProposalTriageActions::correct(),
                EnrichmentProposalTriageActions::discard(),
            ])
            ->toolbarActions([
                $this->confirmSelectedBulkAction(),
                $this->discardSelectedBulkAction(),
            ]);
    }

    /**
     * Computes the "N articoli da revisionare" heading on every render, so it
     * always reflects the current queue size even right after an action
     * removes or keeps a proposal in the queue.
     */
    private function queueHeading(): string
    {
        $count = EnrichmentProposal::query()->where('status', 'pending')->count();

        return "{$count} articoli da revisionare";
    }

    /**
     * US-037/US-041: bulk counterpart of the shared per-row confirm action
     * ({@see EnrichmentProposalTriageActions::confirm()}). Filament
     * automatically enables the row-selection checkbox column as soon as the
     * table defines at least one bulk action here.
     */
    private function confirmSelectedBulkAction(): BulkAction
    {
        return BulkAction::make('confirmSelected')
            ->label('Conferma selezionati')
            ->color('success')
            ->icon(Heroicon::OutlinedCheck)
            ->action(function (Collection $records): void {
                $triage = app(EnrichmentProposalTriage::class);

                $records->each(fn (EnrichmentProposal $record) => $triage->confirm($record));

                Notification::make()
                    ->title("{$records->count()} proposte confermate")
                    ->success()
                    ->send();
            });
    }

    /**
     * US-038/US-041: real navigation link (not a modal) to the standalone
     * {@see ReviewQueueDetail} page for the proposal's underlying product,
     * positioned before the quick-triage actions.
     */
    private function viewDetailAction(): Action
    {
        return Action::make('viewDetail')
            ->label('Dettagli')
            ->color('gray')
            ->icon(Heroicon::OutlinedEye)
            ->url(fn (EnrichmentProposal $record): string => ReviewQueueDetail::getUrl(['record' => $record->product]));
    }

    /**
     * US-037/US-041: bulk counterpart of the shared per-row discard action
     * ({@see EnrichmentProposalTriageActions::discard()}).
     * `->requiresConfirmation()` on the bulk action itself shows a single
     * confirmation modal for the whole selection, not one per record.
     */
    private function discardSelectedBulkAction(): BulkAction
    {
        return BulkAction::make('discardSelected')
            ->label('Scarta selezionati')
            ->color('danger')
            ->icon(Heroicon::OutlinedTrash)
            ->requiresConfirmation()
            ->action(function (Collection $records): void {
                $triage = app(EnrichmentProposalTriage::class);

                $records->each(fn (EnrichmentProposal $record) => $triage->discard($record));

                Notification::make()
                    ->title("{$records->count()} proposte scartate")
                    ->success()
                    ->send();
            });
    }

    /**
     * Human-readable label for the proposal's field, appending the
     * attribute key for `attribute` proposals so multiple pending attribute
     * proposals on the same product remain distinguishable in the queue.
     */
    private static function fieldLabel(EnrichmentProposal $proposal): string
    {
        return match ($proposal->field) {
            'brand' => 'Marca',
            'family' => 'Famiglia',
            'subfamily' => 'Sottofamiglia',
            'attribute' => "Attributo: {$proposal->attribute_key}",
            'product_type' => 'Tipo prodotto',
            'descrizione_estesa' => 'Descrizione estesa',
            'attribute_definition' => 'Nuova chiave attributo',
            default => $proposal->field,
        };
    }

    /**
     * Resolves the human-readable proposed value: the taxonomy record's name
     * for brand/family/subfamily proposals, or the formatted value/unit for
     * attribute proposals.
     */
    private static function proposedValueLabel(EnrichmentProposal $proposal): string
    {
        return match ($proposal->field) {
            'brand' => Brand::query()->find($proposal->value_id)?->name ?? '—',
            'family' => Family::query()->find($proposal->value_id)?->name ?? '—',
            'subfamily' => Subfamily::query()->find($proposal->value_id)?->name ?? '—',
            'attribute' => self::attributeValueLabel($proposal),
            'product_type' => $proposal->value ?? '—',
            'descrizione_estesa' => Str::limit($proposal->value ?? '—', 80),
            'attribute_definition' => self::attributeDefinitionValueLabel($proposal),
            default => '—',
        };
    }

    /**
     * US-044: summarizes a proposed new registry definition as
     * "key (data_type, unit)" (e.g. "portata_lmin (numeric, l/min)"), or
     * without the unit part when the AI reported none.
     */
    private static function attributeDefinitionValueLabel(EnrichmentProposal $proposal): string
    {
        $unit = filled($proposal->unit) ? ", {$proposal->unit}" : '';

        return "{$proposal->attribute_key} ({$proposal->data_type}{$unit})";
    }

    /**
     * Formats an attribute proposal's value the same way as the technical
     * attributes shown elsewhere (e.g. {@see ReviewQueueDetail}): the value
     * plus the unit when present.
     */
    private static function attributeValueLabel(EnrichmentProposal $proposal): string
    {
        $unit = filled($proposal->unit) ? ' '.$proposal->unit : '';

        return "{$proposal->value}{$unit}";
    }

    /**
     * Maps a `*_source`/`origin` literal to the human-readable origin label
     * shown alongside each proposal. Public so {@see ReviewQueueDetail} can
     * reuse the exact same mapping (US-038) instead of duplicating it.
     */
    public static function originLabel(?string $source): string
    {
        return match ($source) {
            'ai' => 'AI',
            'regex', 'dictionary', 'propagated' => 'Dedotta',
            'file' => 'Da file',
            'manual' => 'Manuale',
            default => '—',
        };
    }
}
