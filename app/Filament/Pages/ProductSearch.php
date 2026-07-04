<?php

namespace App\Filament\Pages;

use App\Models\Product;
use App\Models\Subfamily;
use App\Services\Search\AppliedAttributeFilter;
use App\Services\Search\MatchOutcome;
use App\Services\Search\MatchOutcomeResolver;
use App\Services\Search\NaturalLanguageSearchService;
use App\Services\Search\QueryParser;
use App\Services\Search\SearchResult;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * US-033: read-only search page over the hybrid search engine
 * ({@see SearchService}, US-019). Hosts the search form (free text +
 * brand/family/subfamily + a fixed set of numeric technical attribute
 * filters) and a results table backed by `NaturalLanguageSearchService::paginate()`
 * (US-048) via Filament's custom-data `->records()` (results aren't an
 * Eloquent query, they're a paginated `Collection<SearchResult>`).
 *
 * US-048: the free-text query is parsed by an AI model into recognized text
 * + hard attribute filters before the hybrid search runs. `$recognizedText`/
 * `$appliedFilterLabels` expose that parse to the view so it can show back
 * what was understood. They're computed once in {@see self::search()} (not
 * re-derived from the table's own query, whose `records()` resolver runs
 * later in the Blade render — after the banner would need the value) and
 * kept as plain Livewire-synced scalars rather than the richer
 * `ParsedSearchQuery` DTO, which Livewire's property hydration doesn't
 * support directly. Being real public properties, they also survive
 * subsequent requests that don't re-run `search()` (e.g. a table pagination
 * click), unlike a value recomputed only as a render side effect.
 *
 * @property-read Schema $form
 */
class ProductSearch extends Page implements HasSchemas, HasTable
{
    use InteractsWithSchemas;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static ?string $navigationLabel = 'Ricerca prodotti';

    protected static ?string $title = 'Ricerca prodotti';

    protected string $view = 'filament.pages.product-search';

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    /**
     * Distinguishes "page just loaded, never searched" from "searched but
     * zero rows matched", so the table empty state can guide the user in the
     * first case and confirm a genuine no-results outcome in the second.
     */
    public bool $hasSearched = false;

    /**
     * The recognized (residual) descriptive text from the last executed
     * search's natural-language parse (US-048), or `null` before any search
     * has run.
     */
    public ?string $recognizedText = null;

    /**
     * One human-readable label per attribute filter the last search's
     * natural-language parse turned into a hard filter (US-048), rendered
     * as chips in the interpretation banner. Empty when no attribute was
     * recognized.
     *
     * @var array<int, string>
     */
    public array $appliedFilterLabels = [];

    /**
     * The tri-state confidence outcome (US-049) of the last executed
     * search — automatic match, disambiguation, or no results — or `null`
     * before any search has run. Drives the status badge in the
     * interpretation banner.
     */
    public ?MatchOutcome $matchOutcome = null;

    /**
     * The confidence margin behind `$matchOutcome`, or `null` when a margin
     * isn't meaningful (see {@see MatchOutcomeResolver}).
     */
    public ?float $matchMargin = null;

    /**
     * The three fixed technical attribute keys exposed as min/max filter
     * pairs on the form (US-028); populated exclusively via AI classification
     * anchored to the attribute registry since US-043.
     *
     * @var array<int, string>
     */
    private const ATTRIBUTE_KEYS = ['potenza_kw', 'diametro_nominale', 'pressione_nominale'];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            // No backing record (this form only captures filter state), but
            // `->relationship()` selects below still need a model class to
            // resolve `brand()`/`family()` against.
            ->model(Product::class)
            ->components([
                Form::make([
                    TextInput::make('query')
                        ->label('Testo di ricerca')
                        ->columnSpanFull(),
                    Grid::make(3)
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
                        ]),
                    Grid::make(6)
                        ->schema([
                            TextInput::make('potenza_kw_min')
                                ->label('Potenza (kW) min')
                                ->numeric(),
                            TextInput::make('potenza_kw_max')
                                ->label('Potenza (kW) max')
                                ->numeric(),
                            TextInput::make('diametro_nominale_min')
                                ->label('DN min')
                                ->numeric(),
                            TextInput::make('diametro_nominale_max')
                                ->label('DN max')
                                ->numeric(),
                            TextInput::make('pressione_nominale_min')
                                ->label('PN min')
                                ->numeric(),
                            TextInput::make('pressione_nominale_max')
                                ->label('PN max')
                                ->numeric(),
                        ]),
                ])
                    ->livewireSubmitHandler('search')
                    ->footer([
                        Actions::make([
                            Action::make('search')
                                ->label('Cerca')
                                ->submit('search'),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * Captures the submitted filter state. `$this->hasSearched` only flips to
     * true when the submission actually carries a query or a filter (AC4): a
     * "Cerca" click with every field blank must still show the guided empty
     * state, not "no results found", since no real search ran.
     *
     * US-048: also parses the free-text query here (not lazily inside the
     * table's `records()` resolver) so `$recognizedText`/`$appliedFilterLabels`
     * are ready for the very same render's interpretation banner, which
     * Blade evaluates before the results table below it.
     */
    public function search(): void
    {
        $this->data = $this->form->getState();
        $this->hasSearched = $this->hasSearchCriteria();

        $this->refreshInterpretation();
        $this->refreshMatchOutcome();
    }

    /**
     * Updates `$recognizedText`/`$appliedFilterLabels` from a fresh
     * natural-language parse of the submitted query, or clears them when no
     * search actually ran.
     */
    private function refreshInterpretation(): void
    {
        if (! $this->hasSearched) {
            $this->recognizedText = null;
            $this->appliedFilterLabels = [];

            return;
        }

        $query = (string) ($this->data['query'] ?? '');
        $parsed = app(QueryParser::class)->parse($query);

        $this->recognizedText = $parsed->recognizedText;
        $this->appliedFilterLabels = array_map(
            fn (AppliedAttributeFilter $filter): string => $filter->toDisplayLabel(),
            $parsed->appliedFilters,
        );
    }

    /**
     * Updates `$matchOutcome`/`$matchMargin` from a fresh confidence
     * resolution (US-049) of the submitted query/filters, or clears them
     * when no search actually ran.
     */
    private function refreshMatchOutcome(): void
    {
        if (! $this->hasSearched) {
            $this->matchOutcome = null;
            $this->matchMargin = null;

            return;
        }

        $query = (string) ($this->data['query'] ?? '');
        $outcome = app(NaturalLanguageSearchService::class)->matchOutcome($query, $this->buildSearchFilters());

        $this->matchOutcome = $outcome->outcome;
        $this->matchMargin = $outcome->margin;
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (int $page, int $recordsPerPage): LengthAwarePaginator => $this->searchResults($page, $recordsPerPage))
            ->columns([
                TextColumn::make('title')
                    ->label('Titolo'),
                TextColumn::make('brand_name')
                    ->label('Marca'),
                TextColumn::make('family_name')
                    ->label('Famiglia / Sottofamiglia'),
            ])
            ->emptyStateHeading(fn (): string => $this->hasSearched
                ? 'Nessun prodotto trovato'
                : 'Inserisci un testo o un filtro per iniziare la ricerca')
            ->emptyStateDescription(fn (): string => $this->hasSearched
                ? 'Nessun prodotto corrisponde ai criteri inseriti. Prova a modificare il testo o i filtri.'
                : 'Usa il campo di testo libero e/o i filtri per cercare tra i prodotti a catalogo.');
    }

    /**
     * AC4: runs the actual search only once the user submitted at least a
     * free-text query or one filter — never on the initial page load, and
     * never on a submit that leaves every field empty — so the embedding
     * provider is not called needlessly.
     *
     * Paginated at the SQL level via {@see NaturalLanguageSearchService::paginate()}
     * (instead of fetching every match and slicing in PHP) so page loads
     * stay fast regardless of how many products a broad query matches.
     * US-048: also parses the free-text query into hard attribute filters
     * before ranking (the interpretation shown to the user is computed
     * separately, in {@see self::search()} — see that method's docblock).
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function searchResults(int $page, int $recordsPerPage): LengthAwarePaginator
    {
        if (! $this->hasSearchCriteria()) {
            return new LengthAwarePaginator(collect(), 0, $recordsPerPage, $page);
        }

        $query = (string) ($this->data['query'] ?? '');

        $naturalLanguageResult = app(NaturalLanguageSearchService::class)
            ->paginate($query, $this->buildSearchFilters(), $recordsPerPage, $page);

        $paginator = $naturalLanguageResult->results;

        // The results' `Product` models come from `SearchService` without
        // brand/family/subfamily loaded; batch-load them here (instead of in
        // the service) so lazy access in `toTableRow()` below doesn't N+1.
        EloquentCollection::make($paginator->getCollection()->map(fn (SearchResult $result): Product => $result->product))
            ->loadMissing(['brand', 'family', 'subfamily']);

        return $paginator->setCollection(
            $paginator->getCollection()->map(fn (SearchResult $result): array => $this->toTableRow($result)),
        );
    }

    /**
     * True when the submitted state has a non-blank query or at least one
     * non-blank filter field (brand/family/subfamily or an attribute
     * min/max).
     */
    private function hasSearchCriteria(): bool
    {
        if (blank($this->data)) {
            return false;
        }

        if (filled($this->data['query'] ?? null)) {
            return true;
        }

        $fields = ['brand_id', 'family_id', 'subfamily_id'];

        foreach (self::ATTRIBUTE_KEYS as $key) {
            $fields[] = "{$key}_min";
            $fields[] = "{$key}_max";
        }

        foreach ($fields as $field) {
            if (filled($this->data[$field] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Maps the submitted form state into the `filters` shape expected by
     * {@see SearchService::search()}, omitting any key whose value wasn't
     * provided rather than sending it as `null`.
     *
     * @return array{brand_id?: int, family_id?: int, subfamily_id?: int, attributes?: array<int, array{key: string, min?: float, max?: float}>}
     */
    private function buildSearchFilters(): array
    {
        $filters = [];

        foreach (['brand_id', 'family_id', 'subfamily_id'] as $field) {
            if (filled($this->data[$field] ?? null)) {
                $filters[$field] = (int) $this->data[$field];
            }
        }

        $attributes = [];

        foreach (self::ATTRIBUTE_KEYS as $key) {
            $min = $this->data["{$key}_min"] ?? null;
            $max = $this->data["{$key}_max"] ?? null;

            if (blank($min) && blank($max)) {
                continue;
            }

            $attribute = ['key' => $key];

            if (filled($min)) {
                $attribute['min'] = (float) $min;
            }

            if (filled($max)) {
                $attribute['max'] = (float) $max;
            }

            $attributes[] = $attribute;
        }

        if ($attributes !== []) {
            $filters['attributes'] = $attributes;
        }

        return $filters;
    }

    /**
     * Builds a single results-table row from a `SearchResult` (AC3: titolo,
     * marca, famiglia/sottofamiglia). Flat list, one row per product — no
     * grouping/deduplication (US-047).
     *
     * @return array<string, mixed>
     */
    private function toTableRow(SearchResult $result): array
    {
        $product = $result->product;
        $familyName = $product->family?->name;
        $subfamilyName = $product->subfamily?->name;

        $title = filled($product->product_type) ? $product->product_type : $product->description_clean;

        return [
            'title' => $title,
            'brand_name' => $product->brand?->name,
            'family_name' => $subfamilyName !== null
                ? "{$familyName} / {$subfamilyName}"
                : $familyName,
        ];
    }
}
