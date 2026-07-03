<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Subfamily;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * US-023: work queue for products flagged `enrichment_status = 'needs_review'`
 * (low AI confidence), so an admin can triage the AI proposal per record
 * without opening the full product edit page:
 *  - Confirm: promotes the current AI proposal as-is (`enrichment_status =
 *    'enriched'`), leaving brand_id/family_id/subfamily_id/*_source/source
 *    untouched.
 *  - Correct: opens an inline form (same brand/family/subfamily pattern as
 *    {@see ProductForm}) and saves
 *    the submitted values with `*_source = 'manual'`, `source = 'manual'`,
 *    `confidence = 100`, `enrichment_status = 'enriched'`.
 *  - Discard: clears any non-manual AI proposal (brand/family/subfamily and
 *    their `*_source`) plus `source`/`confidence`, and keeps the record in
 *    `needs_review` for a future re-classification pass. Fields already
 *    `*_source = 'manual'` are preserved.
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
                Product::query()
                    ->where('enrichment_status', 'needs_review')
                    ->with('attributes')
                    ->orderByRaw('confidence IS NOT NULL')
                    ->orderBy('confidence')
            )
            ->heading(fn (): string => $this->queueHeading())
            ->columns([
                TextColumn::make('description_raw')
                    ->label('Descrizione originale')
                    ->wrap(),
                TextColumn::make('brand.name')
                    ->label('Marca proposta (AI)')
                    ->description(fn (Product $record): string => 'Origine: '.self::originLabel($record->brand_source)),
                TextColumn::make('family.name')
                    ->label('Famiglia proposta (AI)')
                    ->description(fn (Product $record): string => 'Origine: '.self::originLabel($record->family_source)),
                TextColumn::make('subfamily.name')
                    ->label('Sottofamiglia proposta (AI)')
                    ->description(fn (Product $record): string => 'Origine: '.self::originLabel($record->subfamily_source)),
                TextColumn::make('attributes')
                    ->label('Attributi tecnici')
                    ->listWithLineBreaks()
                    ->placeholder('—')
                    ->state(function (Product $record): array {
                        return $record->attributes
                            ->map(function (ProductAttribute $attribute): string {
                                $value = $attribute->value_text ?? rtrim(rtrim((string) $attribute->value_num, '0'), '.');
                                $unit = filled($attribute->unit) ? ' '.$attribute->unit : '';
                                $origin = self::originLabel($attribute->source);

                                // product_attributes has no confidence column: this is a
                                // fixed label, not a placeholder to be filled in later.
                                return "{$attribute->key}: {$value}{$unit} · {$origin} · Confidenza: N/D";
                            })
                            ->all();
                    }),
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
            ])
            ->recordActions([
                $this->confirmAction(),
                $this->correctAction(),
                $this->discardAction(),
            ]);
    }

    /**
     * Computes the "N articoli da revisionare" heading on every render, so it
     * always reflects the current queue size (AC5) even right after an
     * action removes or keeps a record in the queue.
     */
    private function queueHeading(): string
    {
        $count = Product::query()->where('enrichment_status', 'needs_review')->count();

        return "{$count} articoli da revisionare";
    }

    /**
     * AC2: promotes the AI proposal as-is, without touching
     * brand_id/family_id/subfamily_id or any *_source/source field.
     */
    private function confirmAction(): Action
    {
        return Action::make('confirm')
            ->label('Conferma')
            ->color('success')
            ->icon(Heroicon::OutlinedCheck)
            ->action(function (Product $record): void {
                $record->update(['enrichment_status' => 'enriched']);

                Notification::make()
                    ->title('Proposta confermata')
                    ->success()
                    ->send();
            });
    }

    /**
     * AC3: inline correction form, precompiled with the record's current
     * brand/family/subfamily (pattern from ProductForm, US-021). Every
     * submitted field is treated as a manual override regardless of whether
     * it changed, since the admin explicitly reviewed and submitted it.
     */
    private function correctAction(): Action
    {
        return Action::make('correct')
            ->label('Correggi')
            ->color('primary')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->schema([
                Select::make('brand_id')
                    ->label('Marca')
                    ->relationship('brand', 'name')
                    ->searchable()
                    ->preload(),
                Select::make('family_id')
                    ->label('Famiglia')
                    ->relationship('family', 'name')
                    ->searchable()
                    ->preload()
                    ->live(),
                Select::make('subfamily_id')
                    ->label('Sottofamiglia')
                    ->options(fn (Get $get) => Subfamily::query()
                        ->where('family_id', $get('family_id'))
                        ->pluck('name', 'id'))
                    ->searchable(),
            ])
            ->fillForm(fn (Product $record): array => [
                'brand_id' => $record->brand_id,
                'family_id' => $record->family_id,
                'subfamily_id' => $record->subfamily_id,
            ])
            ->action(function (array $data, Product $record): void {
                $record->update([
                    'brand_id' => $data['brand_id'],
                    'family_id' => $data['family_id'],
                    'subfamily_id' => $data['subfamily_id'],
                    'brand_source' => 'manual',
                    'family_source' => 'manual',
                    'subfamily_source' => 'manual',
                    'source' => 'manual',
                    'confidence' => 100,
                    'enrichment_status' => 'enriched',
                ]);

                Notification::make()
                    ->title('Correzione salvata')
                    ->success()
                    ->send();
            });
    }

    /**
     * AC4: discards the AI proposal. Only fields that are not already
     * `*_source = 'manual'` are cleared, so a prior manual correction on one
     * field (e.g. brand) survives a discard triggered by a bad AI value on
     * another field (e.g. family). The record explicitly stays
     * `needs_review` for a future re-classification pass.
     */
    private function discardAction(): Action
    {
        return Action::make('discard')
            ->label('Scarta')
            ->color('danger')
            ->icon(Heroicon::OutlinedTrash)
            ->requiresConfirmation()
            ->action(function (Product $record): void {
                $attributes = ['enrichment_status' => 'needs_review'];

                foreach (['brand', 'family', 'subfamily'] as $field) {
                    if ($record->{"{$field}_source"} !== 'manual') {
                        $attributes["{$field}_id"] = null;
                        $attributes["{$field}_source"] = null;
                    }
                }

                $attributes['source'] = null;
                $attributes['confidence'] = null;

                $record->update($attributes);

                Notification::make()
                    ->title('Proposta scartata')
                    ->success()
                    ->send();
            });
    }

    /**
     * Maps a `*_source` literal to the human-readable origin label shown
     * alongside each AI-proposed field (brand/family/subfamily/attributes).
     */
    private static function originLabel(?string $source): string
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
