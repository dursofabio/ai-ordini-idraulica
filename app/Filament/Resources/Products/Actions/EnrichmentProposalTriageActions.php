<?php

namespace App\Filament\Resources\Products\Actions;

use App\Filament\Pages\ReviewQueue;
use App\Filament\Resources\Products\RelationManagers\EnrichmentProposalsRelationManager;
use App\Models\Brand;
use App\Models\EnrichmentProposal;
use App\Models\Family;
use App\Models\Subfamily;
use App\Services\Enrichment\EnrichmentProposalTriage;
use App\Services\Enrichment\SimilarAttributeKeyFinder;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;

/**
 * Per-proposal triage actions (confirm / correct / discard), shared between
 * the "Da revisionare" queue ({@see ReviewQueue}) and the product view
 * page's proposals list
 * ({@see EnrichmentProposalsRelationManager}), so
 * both surfaces expose exactly the same behavior. Every action is only
 * visible on a `pending` proposal — already-applied/discarded proposals are
 * a closed audit trail; the write semantics themselves live in
 * {@see EnrichmentProposalTriage}.
 */
class EnrichmentProposalTriageActions
{
    /**
     * US-041 AC2: promotes the pending proposal as-is, the same effect as
     * the automatic high-confidence application.
     */
    public static function confirm(): Action
    {
        return Action::make('confirm')
            ->label('Conferma')
            ->color('success')
            ->icon(Heroicon::OutlinedCheck)
            ->visible(fn (EnrichmentProposal $record): bool => $record->status === 'pending')
            ->action(function (EnrichmentProposal $record): void {
                app(EnrichmentProposalTriage::class)->confirm($record);

                Notification::make()
                    ->title('Proposta confermata')
                    ->success()
                    ->send();
            });
    }

    /**
     * US-041 AC3: inline correction form, whose schema depends on the
     * proposal's `field` — a taxonomy `Select` prevalorized with the
     * proposal's `value_id` for brand/family/subfamily, or the raw value
     * inputs prevalorized with `value_text`/`value_num`/`unit` for a
     * technical attribute. The submitted value is always treated as a manual
     * override, since the admin explicitly reviewed and submitted it.
     */
    public static function correct(): Action
    {
        return Action::make('correct')
            ->label('Correggi')
            ->color('primary')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->visible(fn (EnrichmentProposal $record): bool => $record->status === 'pending')
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
                app(EnrichmentProposalTriage::class)->correct($record, $data);

                Notification::make()
                    ->title('Correzione salvata')
                    ->success()
                    ->send();
            });
    }

    /**
     * US-041 AC4: discards the pending proposal without touching the
     * product — the proposed value was never applied in the first place, so
     * there is nothing to roll back on the product itself.
     */
    public static function discard(): Action
    {
        return Action::make('discard')
            ->label('Scarta')
            ->color('danger')
            ->icon(Heroicon::OutlinedTrash)
            ->visible(fn (EnrichmentProposal $record): bool => $record->status === 'pending')
            ->requiresConfirmation()
            ->action(function (EnrichmentProposal $record): void {
                app(EnrichmentProposalTriage::class)->discard($record);

                Notification::make()
                    ->title('Proposta scartata')
                    ->success()
                    ->send();
            });
    }

    /**
     * US-044 AC2: human-readable summary of the existing registry keys
     * closest to the proposal's `attribute_key`, shown as a read-only hint
     * inside {@see correct()}'s form so the reviewer can catch a
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
}
