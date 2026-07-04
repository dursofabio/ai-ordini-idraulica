<?php

namespace App\Filament\Pages;

use App\Models\AttributeDefinition;
use App\Models\Brand;
use App\Models\EnrichmentProposal;
use App\Models\Family;
use App\Models\Subfamily;
use App\Services\Enrichment\EnrichmentApplier;
use App\Services\Enrichment\SimilarAttributeKeyFinder;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Text;
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
                $this->confirmAction(),
                $this->correctAction(),
                $this->discardAction(),
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
     * Promotes the pending proposal as-is (AC2), the same effect as the
     * automatic high-confidence application: writes the proposed value with
     * the proposal's own `origin` as the field's source, and marks the
     * proposal `applied`.
     */
    private function confirmAction(): Action
    {
        return Action::make('confirm')
            ->label('Conferma')
            ->color('success')
            ->icon(Heroicon::OutlinedCheck)
            ->action(function (EnrichmentProposal $record): void {
                $this->applyConfirm($record);

                Notification::make()
                    ->title('Proposta confermata')
                    ->success()
                    ->send();
            });
    }

    /**
     * US-037/US-041: bulk counterpart of {@see confirmAction()}. Filament
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
                $records->each(fn (EnrichmentProposal $record) => $this->applyConfirm($record));

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
     * AC3: inline correction form, whose schema depends on the proposal's
     * `field` — a taxonomy `Select` prevalorized with the proposal's
     * `value_id` for brand/family/subfamily, or the raw value inputs
     * prevalorized with `value_text`/`value_num`/`unit` for a technical
     * attribute. The submitted value is always treated as a manual override,
     * since the admin explicitly reviewed and submitted it.
     */
    private function correctAction(): Action
    {
        return Action::make('correct')
            ->label('Correggi')
            ->color('primary')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->schema(fn (EnrichmentProposal $record): array => match ($record->field) {
                'brand' => [
                    Select::make('value_id')
                        ->label('Marca')
                        ->options(fn (): array => Brand::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable(),
                ],
                'family' => [
                    Select::make('value_id')
                        ->label('Famiglia')
                        ->options(fn (): array => Family::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable(),
                ],
                'subfamily' => [
                    Select::make('value_id')
                        ->label('Sottofamiglia')
                        ->options(fn (): array => Subfamily::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable(),
                ],
                'attribute_definition' => [
                    TextInput::make('attribute_key')
                        ->label('Chiave')
                        ->required(),
                    Select::make('data_type')
                        ->label('Tipo')
                        ->options([
                            'numeric' => 'Numerico',
                            'text' => 'Testo',
                        ])
                        ->required(),
                    TextInput::make('unit')
                        ->label('Unità canonica'),
                    TextInput::make('value_text')
                        ->label('Descrizione'),
                    Text::make('Chiavi esistenti più simili: '.self::similarKeysSummary($record))
                        ->color('gray'),
                ],
                default => [
                    TextInput::make('value_text')
                        ->label('Valore testuale'),
                    TextInput::make('value_num')
                        ->label('Valore numerico')
                        ->numeric(),
                    TextInput::make('unit')
                        ->label('Unità'),
                ],
            })
            ->fillForm(fn (EnrichmentProposal $record): array => match ($record->field) {
                'attribute', 'product_type' => [
                    'value_text' => $record->value_text,
                    'value_num' => $record->value_num,
                    'unit' => $record->unit,
                ],
                'attribute_definition' => [
                    'attribute_key' => $record->attribute_key,
                    'data_type' => $record->data_type,
                    'unit' => $record->unit,
                    'value_text' => $record->value_text,
                ],
                default => ['value_id' => $record->value_id],
            })
            ->action(function (array $data, EnrichmentProposal $record): void {
                $this->applyCorrection($record, $data);

                Notification::make()
                    ->title('Correzione salvata')
                    ->success()
                    ->send();
            });
    }

    /**
     * AC4: discards the pending proposal without touching the product — the
     * proposed value was never applied in the first place, so there is
     * nothing to roll back on the product itself.
     */
    private function discardAction(): Action
    {
        return Action::make('discard')
            ->label('Scarta')
            ->color('danger')
            ->icon(Heroicon::OutlinedTrash)
            ->requiresConfirmation()
            ->action(function (EnrichmentProposal $record): void {
                $this->applyDiscard($record);

                Notification::make()
                    ->title('Proposta scartata')
                    ->success()
                    ->send();
            });
    }

    /**
     * US-037/US-041: bulk counterpart of {@see discardAction()}.
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
                $records->each(fn (EnrichmentProposal $record) => $this->applyDiscard($record));

                Notification::make()
                    ->title("{$records->count()} proposte scartate")
                    ->success()
                    ->send();
            });
    }

    /**
     * AC2: writes the proposed value to the product with the proposal's own
     * `origin` as the field's source (taxonomy fields) or the attribute
     * row's `source` (technical attributes), then marks the proposal
     * `applied`. Shared by the single {@see confirmAction()} and the bulk
     * {@see confirmSelectedBulkAction()} so both variants can never diverge.
     */
    private function applyConfirm(EnrichmentProposal $proposal): void
    {
        $this->writeProposalValue($proposal, $proposal->origin, [
            'value_id' => $proposal->value_id,
            'value_text' => $proposal->value_text,
            'value_num' => $proposal->value_num,
            'unit' => $proposal->unit,
            'attribute_key' => $proposal->attribute_key,
            'data_type' => $proposal->data_type,
        ], $proposal->confidence);

        $proposal->update(['status' => 'applied']);
    }

    /**
     * AC3: writes the admin-submitted value to the product with
     * `source = 'manual'`, then marks the proposal `applied`.
     */
    private function applyCorrection(EnrichmentProposal $proposal, array $data): void
    {
        $this->writeProposalValue($proposal, 'manual', [
            'value_id' => $data['value_id'] ?? null,
            'value_text' => $data['value_text'] ?? null,
            'value_num' => $data['value_num'] ?? null,
            'unit' => $data['unit'] ?? null,
            'attribute_key' => $data['attribute_key'] ?? null,
            'data_type' => $data['data_type'] ?? null,
        ]);

        $proposal->update(['status' => 'applied']);
    }

    /**
     * Writes `$values` to the proposal's underlying product: for brand/
     * family/subfamily, sets `{field}_id` and `{field}_source = $source`;
     * for a technical attribute, creates or updates the
     * `product_attributes` row for `attribute_key` with `source = $source`
     * (and `confidence = $confidence`, mirroring
     * {@see EnrichmentApplier::writeAttribute()} —
     * only passed by {@see applyConfirm()}, since a manual correction has no
     * meaningful confidence score to carry over). `product_type` (US-045)
     * has no `_id`/`_source` columns: it writes the plain text value
     * directly onto the `product_type` column instead. `attribute_definition`
     * (US-044 AC3) doesn't touch the product at all: it creates the new
     * `AttributeDefinition` registry row instead, via `firstOrCreate` on the
     * key so an already-registered key (e.g. approved from a concurrent
     * duplicate proposal) is not re-created or errored on.
     *
     * @param  array{value_id: ?int, value_text: ?string, value_num: ?float, unit: ?string, attribute_key?: ?string, data_type?: ?string}  $values
     */
    private function writeProposalValue(EnrichmentProposal $proposal, string $source, array $values, ?int $confidence = null): void
    {
        if ($proposal->field === 'attribute_definition') {
            AttributeDefinition::query()->firstOrCreate(
                ['key' => $values['attribute_key']],
                [
                    'data_type' => $values['data_type'],
                    'canonical_unit' => $values['unit'],
                    'description' => $values['value_text'],
                ],
            );

            return;
        }

        if ($proposal->field === 'attribute') {
            $proposal->product->attributes()->updateOrCreate(
                ['key' => $proposal->attribute_key],
                [
                    'value_text' => $values['value_text'],
                    'value_num' => $values['value_num'],
                    'unit' => $values['unit'],
                    'source' => $source,
                    'confidence' => $confidence,
                ],
            );

            return;
        }

        if ($proposal->field === 'product_type') {
            $proposal->product->update([
                'product_type' => $values['value_text'],
            ]);

            return;
        }

        $proposal->product->update([
            "{$proposal->field}_id" => $values['value_id'],
            "{$proposal->field}_source" => $source,
        ]);
    }

    /**
     * AC4: discards the proposal without touching the product. Shared by the
     * single {@see discardAction()} and the bulk
     * {@see discardSelectedBulkAction()} so both variants can never diverge.
     */
    private function applyDiscard(EnrichmentProposal $proposal): void
    {
        $proposal->update(['status' => 'discarded']);
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
            'product_type' => $proposal->value_text ?? '—',
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
     * AC2: human-readable summary of the existing registry keys closest to
     * the proposal's `attribute_key`, shown as a read-only hint inside
     * {@see correctAction()}'s form so the reviewer can catch a
     * near-duplicate before registering a new definition.
     */
    private static function similarKeysSummary(EnrichmentProposal $proposal): string
    {
        $similar = (new SimilarAttributeKeyFinder)->find($proposal->attribute_key);

        if ($similar->isEmpty()) {
            return 'Nessuna chiave simile trovata nel registro.';
        }

        return $similar
            ->map(function (array $definition): string {
                $unit = filled($definition['canonical_unit']) ? ", {$definition['canonical_unit']}" : '';

                return "{$definition['key']} ({$definition['data_type']}{$unit})";
            })
            ->implode('; ');
    }

    /**
     * Formats an attribute proposal's value the same way as the technical
     * attributes shown elsewhere (e.g. {@see ReviewQueueDetail}): the text
     * value when present, otherwise the trimmed numeric value, plus the unit
     * when present.
     */
    private static function attributeValueLabel(EnrichmentProposal $proposal): string
    {
        $value = $proposal->value_text ?? rtrim(rtrim((string) $proposal->value_num, '0'), '.');
        $unit = filled($proposal->unit) ? ' '.$proposal->unit : '';

        return "{$value}{$unit}";
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
