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
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Locked;

/**
 * US-038: standalone detail page for a single review-queue record, reached
 * via the "Dettagli" link on {@see ReviewQueue}. Gives an admin the full
 * picture (raw file data, AI proposals with origin, technical attributes,
 * confidence) plus an editable form for brand/family/subfamily and technical
 * attributes, for corrections that need more room than the compact
 * "Correggi" modal.
 *
 * Not registered in navigation: only reachable via the per-row link on the
 * queue. `save()` applies the exact same field semantics as
 * {@see ReviewQueue}'s `correctAction()` (`source = 'manual'`,
 * `confidence = 100`, `enrichment_status = 'enriched'`) and redirects back
 * to the queue.
 */
class ReviewQueueDetail extends Page implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $slug = 'review-queue/{record}';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.review-queue-detail';

    // Named differently from the `record` route/mount parameter below: a
    // public property literally named `$record` would make Livewire hydrate
    // it directly from the raw route-key scalar (as a plain property),
    // bypassing the typed `mount(Product $record)` parameter's automatic
    // route-model-binding resolution and crashing with a type error.
    #[Locked]
    public ?Product $product = null;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(Product $record): void
    {
        $this->product = $record;

        $this->form->fill([
            'brand_id' => $record->brand_id,
            'family_id' => $record->family_id,
            'subfamily_id' => $record->subfamily_id,
        ]);
    }

    public function getTitle(): string
    {
        return "Dettaglio articolo: {$this->product->codice_articolo}";
    }

    /**
     * AC2: read-only view of every piece of information available for the
     * record — raw file data, the AI proposals with their origin, the
     * technical attributes with their origin, and the overall confidence.
     */
    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->record($this->product)
            ->components([
                Section::make('Dati da file')
                    ->schema([
                        TextEntry::make('codice_articolo')
                            ->label('Codice articolo'),
                        TextEntry::make('description_raw')
                            ->label('Descrizione originale'),
                        TextEntry::make('descrizione_marca')
                            ->label('Marca da file')
                            ->placeholder('—'),
                        TextEntry::make('fam_descrizione')
                            ->label('Famiglia da file')
                            ->placeholder('—'),
                        TextEntry::make('subfam_descrizione')
                            ->label('Sottofamiglia da file')
                            ->placeholder('—'),
                        TextEntry::make('costo')
                            ->label('Costo')
                            ->money('EUR'),
                        TextEntry::make('giacenza')
                            ->label('Giacenza')
                            ->numeric(),
                    ])
                    ->columns(2),
                Section::make('Proposte e confidenza')
                    ->schema([
                        TextEntry::make('brand.name')
                            ->label('Marca proposta (AI)')
                            ->placeholder('—')
                            ->helperText(fn (Product $record): string => 'Origine: '.ReviewQueue::originLabel($record->brand_source)),
                        TextEntry::make('family.name')
                            ->label('Famiglia proposta (AI)')
                            ->placeholder('—')
                            ->helperText(fn (Product $record): string => 'Origine: '.ReviewQueue::originLabel($record->family_source)),
                        TextEntry::make('subfamily.name')
                            ->label('Sottofamiglia proposta (AI)')
                            ->placeholder('—')
                            ->helperText(fn (Product $record): string => 'Origine: '.ReviewQueue::originLabel($record->subfamily_source)),
                        TextEntry::make('confidence')
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
                    ->columns(2),
                Section::make('Attributi tecnici')
                    ->schema([
                        TextEntry::make('attributes')
                            ->hiddenLabel()
                            ->listWithLineBreaks()
                            ->placeholder('Nessun attributo tecnico')
                            ->state(function (Product $record): array {
                                return $record->attributes
                                    ->map(function (ProductAttribute $attribute): string {
                                        $value = $attribute->value_text ?? rtrim(rtrim((string) $attribute->value_num, '0'), '.');
                                        $unit = filled($attribute->unit) ? ' '.$attribute->unit : '';
                                        $origin = ReviewQueue::originLabel($attribute->source);

                                        return "{$attribute->key}: {$value}{$unit} · {$origin}";
                                    })
                                    ->all();
                            }),
                    ]),
            ]);
    }

    /**
     * AC3: editable form for brand/family/subfamily (same pattern as
     * {@see ProductForm} /
     * `ReviewQueue::correctAction()`), precompiled in {@see mount()}, plus a
     * repeater to edit the technical attributes in place.
     */
    public function form(Schema $schema): Schema
    {
        return $schema
            ->record($this->product)
            ->statePath('data')
            ->components([
                Section::make('Correggi marca, famiglia e sottofamiglia')
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
                    ->columns(3),
                Section::make('Correggi attributi tecnici')
                    ->schema([
                        Repeater::make('attributes')
                            ->relationship()
                            ->hiddenLabel()
                            ->schema([
                                TextInput::make('key')
                                    ->label('Chiave')
                                    ->required(),
                                TextInput::make('value_text')
                                    ->label('Valore testuale'),
                                TextInput::make('value_num')
                                    ->label('Valore numerico')
                                    ->numeric(),
                                TextInput::make('unit')
                                    ->label('Unità'),
                            ])
                            ->columns(4)
                            ->addActionLabel('Aggiungi attributo')
                            // AC4: a brand-new row is a manual override by
                            // definition (it didn't exist before this save).
                            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => [
                                ...$data,
                                'source' => 'manual',
                            ])
                            // AC4: only rows the admin actually edited become
                            // manual overrides. Every existing attribute
                            // round-trips through this hook on every save
                            // (the repeater loads the full relationship), so
                            // unconditionally forcing `source = 'manual'`
                            // here would silently overwrite the AI/regex/
                            // dictionary/file provenance of every untouched
                            // attribute just because the admin corrected an
                            // unrelated field (e.g. brand). Loose (`!=`)
                            // comparison mirrors EditProduct's dirty-check,
                            // since value_num round-trips as a numeric string
                            // (e.g. "1.5" submitted vs "1.500" stored).
                            ->mutateRelationshipDataBeforeSaveUsing(function (array $data, ProductAttribute $record): array {
                                $isChanged = collect(['key', 'value_text', 'value_num', 'unit'])
                                    ->contains(fn (string $field): bool => ($data[$field] ?? null) != $record->{$field});

                                $data['source'] = $isChanged ? 'manual' : $record->source;

                                return $data;
                            }),
                    ]),
            ]);
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToQueue')
                ->label('Torna alla coda')
                ->color('gray')
                ->icon(Heroicon::OutlinedArrowLeft)
                ->url(ReviewQueue::getUrl()),
        ];
    }

    /**
     * AC4: applies the exact same field semantics as
     * `ReviewQueue::correctAction()` — every submitted brand/family/subfamily
     * value becomes a manual override, plus persists the attribute repeater
     * (also forced to `source = 'manual'` per row above) via the automatic
     * relationship-save that `Schema::getState()` performs for an
     * already-existing record — then redirects back to the queue.
     */
    public function save(): void
    {
        $data = $this->form->getState();

        $this->product->update([
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

        $this->redirect(ReviewQueue::getUrl());
    }
}
