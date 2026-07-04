<?php

namespace Tests\Feature;

use App\Filament\Pages\ProductSearch;
use App\Models\AttributeDefinition;
use App\Models\Brand;
use App\Models\Family;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * US-033 acceptance criteria for the read-only "Ricerca prodotti" page:
 *  - AC3: submitting the form (free text and/or filters) runs the hybrid
 *    search and shows the expected products in the results table.
 *  - AC2: the fixed numeric technical attribute filters (potenza_kw,
 *    diametro_nominale, pressione_nominale) narrow the results to products
 *    with a matching attribute.
 *  - AC1/AC3: brand/family/subfamily filters narrow the results.
 *  - AC4: with no text and no filter set, submitting never calls the search
 *    engine (no embedding HTTP call) and the table shows the guided empty
 *    state, distinct from a genuine "no results" outcome.
 *
 * US-047 flattens the results table onto a single `Product` row per SKU (no
 * more grouping/variants).
 *
 * US-048 acceptance criteria for the natural-language query parser:
 *  - A query with an explicit technical attribute mention shows the
 *    interpretation banner (recognized text + applied filter chip) and
 *    narrows the results table to the hard-filtered attribute.
 *  - When the AI parse call fails, the page degrades to plain full-text
 *    search with no interpretation chip, instead of breaking the page.
 */
class ProductSearchPageTest extends TestCase
{
    use RefreshDatabase;

    private const GUIDED_EMPTY_STATE_HEADING = 'Inserisci un testo o un filtro per iniziare la ricerca';

    private const NO_RESULTS_EMPTY_STATE_HEADING = 'Nessun prodotto trovato';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        config()->set('services.embedding', [
            'base_url' => 'https://embedding.test',
            'model' => 'bge-m3',
            'api_key' => null,
            'dimensions' => 4,
            'timeout' => 120,
            'retry_times' => 2,
            'retry_delay_ms' => 1,
        ]);

        // Scoped to the embedding host (not a blanket '*') so tests that also
        // fake the Anthropic host (US-048) can layer a more specific stub
        // without this one matching first — Http::fake() stubs are resolved
        // in registration order, first match wins.
        Http::fake([
            'https://embedding.test/*' => Http::response(['embedding' => [0.1, 0.2, 0.3, 0.4]]),
        ]);
    }

    public function test_free_text_search_returns_only_relevant_products(): void
    {
        $this->actingAs(User::factory()->create());

        // Strong lexical match on the query terms (fts_score dominates since
        // neither product has a stored embedding, so vector_score is 0 for
        // both — see SearchService::applyRanking()).
        $relevant = Product::factory()->create([
            'product_type' => 'Scaldabagno a pompa di calore',
            'description_clean' => 'Scaldabagno a pompa di calore per uso domestico',
        ]);
        // Neither lexically nor semantically related to the query: excluded
        // by SearchService's relevance cutoff instead of merely ranked last.
        $irrelevant = Product::factory()->create([
            'product_type' => 'Valvola a sfera in ottone',
            'description_clean' => 'Valvola a sfera in ottone per impianti idraulici',
        ]);

        Livewire::test(ProductSearch::class)
            ->fillForm(['query' => 'scaldabagno pompa calore'])
            ->call('search')
            ->assertCanSeeTableRecords([$relevant])
            ->assertCanNotSeeTableRecords([$irrelevant]);
    }

    public function test_free_text_with_brand_and_family_filter_narrows_results(): void
    {
        $this->actingAs(User::factory()->create());

        $brandA = Brand::factory()->create();
        $familyA = Family::factory()->create();
        $brandB = Brand::factory()->create();
        $familyB = Family::factory()->create();

        $matching = Product::factory()->create([
            'brand_id' => $brandA->id,
            'family_id' => $familyA->id,
        ]);
        $nonMatching = Product::factory()->create([
            'brand_id' => $brandB->id,
            'family_id' => $familyB->id,
        ]);

        Livewire::test(ProductSearch::class)
            ->fillForm([
                'query' => 'prodotto idraulico',
                'brand_id' => $brandA->id,
                'family_id' => $familyA->id,
            ])
            ->call('search')
            ->assertCanSeeTableRecords([$matching])
            ->assertCanNotSeeTableRecords([$nonMatching]);
    }

    public function test_numeric_attribute_filter_returns_only_products_with_attribute_in_range(): void
    {
        $this->actingAs(User::factory()->create());

        $inRange = Product::factory()->create();
        ProductAttribute::factory()->create([
            'product_id' => $inRange->id,
            'key' => 'potenza_kw',
            'value_num' => 2.5,
        ]);

        $outOfRange = Product::factory()->create();
        ProductAttribute::factory()->create([
            'product_id' => $outOfRange->id,
            'key' => 'potenza_kw',
            'value_num' => 10,
        ]);

        Livewire::test(ProductSearch::class)
            ->fillForm([
                'potenza_kw_min' => 1,
                'potenza_kw_max' => 3,
            ])
            ->call('search')
            ->assertCanSeeTableRecords([$inRange])
            ->assertCanNotSeeTableRecords([$outOfRange]);
    }

    public function test_submitting_with_no_text_and_no_filters_never_calls_search_and_shows_guided_empty_state(): void
    {
        $this->actingAs(User::factory()->create());

        Product::factory()->create();

        Livewire::test(ProductSearch::class)
            ->call('search')
            ->assertSee(self::GUIDED_EMPTY_STATE_HEADING)
            ->assertDontSee(self::NO_RESULTS_EMPTY_STATE_HEADING);

        Http::assertNothingSent();
    }

    /**
     * US-049 AC: a single matching product with no competing candidate
     * resolves to an automatic match, shown as a distinct status badge in
     * the interpretation banner.
     */
    public function test_query_with_single_confident_candidate_shows_auto_match_badge(): void
    {
        $this->actingAs(User::factory()->create());

        Product::factory()->create([
            'product_type' => 'Scaldabagno a pompa di calore',
            'description_clean' => 'Scaldabagno a pompa di calore per uso domestico',
        ]);

        Livewire::test(ProductSearch::class)
            ->fillForm(['query' => 'scaldabagno pompa calore'])
            ->call('search')
            ->assertSee('Corrispondenza automatica')
            ->assertDontSee('Prodotti candidati da verificare');
    }

    /**
     * US-049 AC: two products both matching the free-text query (neither
     * embedded, so their vector scores tie at 0 — see
     * MatchOutcomeResolver's cautious null/non-positive top-score rule)
     * resolve to disambiguation, shown with both candidates in the results
     * table.
     */
    public function test_ambiguous_query_shows_disambiguation_badge_with_candidates_listed(): void
    {
        $this->actingAs(User::factory()->create());

        $first = Product::factory()->create([
            'product_type' => 'Scaldabagno a pompa di calore modello A',
            'description_clean' => 'Scaldabagno a pompa di calore modello A per uso domestico',
        ]);
        $second = Product::factory()->create([
            'product_type' => 'Scaldabagno a pompa di calore modello B',
            'description_clean' => 'Scaldabagno a pompa di calore modello B per uso domestico',
        ]);

        Livewire::test(ProductSearch::class)
            ->fillForm(['query' => 'scaldabagno pompa calore'])
            ->call('search')
            ->assertSee('Prodotti candidati da verificare')
            ->assertDontSee('Corrispondenza automatica')
            ->assertCanSeeTableRecords([$first, $second]);
    }

    public function test_submitting_filters_that_match_nothing_shows_distinct_no_results_empty_state(): void
    {
        $this->actingAs(User::factory()->create());

        $unrelatedBrand = Brand::factory()->create();
        Product::factory()->create();

        Livewire::test(ProductSearch::class)
            ->fillForm(['brand_id' => $unrelatedBrand->id])
            ->call('search')
            ->assertSee(self::NO_RESULTS_EMPTY_STATE_HEADING)
            ->assertDontSee(self::GUIDED_EMPTY_STATE_HEADING);
    }

    /**
     * US-048 AC4/AC1: a query with an explicit technical attribute mention
     * shows the interpretation banner (recognized text + a chip per applied
     * filter) and the hard filter narrows the results table, excluding a
     * product with near-identical text but a different attribute value.
     */
    public function test_query_with_explicit_attribute_shows_interpretation_banner_and_narrows_results(): void
    {
        $this->actingAs(User::factory()->create());

        $this->fakeAnthropicConfig();

        AttributeDefinition::query()->updateOrCreate(['key' => 'attacco_pollici'], [
            'data_type' => 'numeric',
            'canonical_unit' => '"',
            'accepted_units' => ['"' => 1, 'POLLICI' => 1, 'IN' => 1],
            'description' => 'Dimensione dell\'attacco filettato, espressa in pollici (").',
        ]);

        $matching = Product::factory()->create([
            'product_type' => 'Tubo inox',
            'description_clean' => 'Tubo inox per impianti idraulici',
        ]);
        ProductAttribute::factory()->create([
            'product_id' => $matching->id,
            'key' => 'attacco_pollici',
            'value_num' => 1,
        ]);

        $differentValue = Product::factory()->create([
            'product_type' => 'Tubo inox',
            'description_clean' => 'Tubo inox per impianti idraulici',
        ]);
        ProductAttribute::factory()->create([
            'product_id' => $differentValue->id,
            'key' => 'attacco_pollici',
            'value_num' => 2,
        ]);

        Http::fake([
            'https://embedding.test/*' => Http::response(['embedding' => [0.1, 0.2, 0.3, 0.4]]),
            'https://api.anthropic.test/*' => Http::response($this->anthropicParseBody('tubo inox', [
                ['key' => 'attacco_pollici', 'value_num' => 1, 'unit' => 'pollici'],
            ])),
        ]);

        Livewire::test(ProductSearch::class)
            ->fillForm(['query' => 'tubo inox 1 pollice'])
            ->call('search')
            ->assertSee('Tipo riconosciuto:')
            ->assertSee('tubo inox')
            ->assertSee('attacco filettato')
            ->assertCanSeeTableRecords([$matching])
            ->assertCanNotSeeTableRecords([$differentValue]);
    }

    /**
     * US-048: when the AI query-parse call fails, the page degrades to
     * plain full-text search (the banner still shows, with the original
     * query as plain text and no attribute chip) instead of breaking.
     */
    public function test_ai_parse_failure_degrades_to_full_text_search(): void
    {
        $this->actingAs(User::factory()->create());

        $this->fakeAnthropicConfig();

        $relevant = Product::factory()->create([
            'product_type' => 'Scaldabagno a pompa di calore',
            'description_clean' => 'Scaldabagno a pompa di calore per uso domestico',
        ]);

        Http::fake([
            'https://embedding.test/*' => Http::response(['embedding' => [0.1, 0.2, 0.3, 0.4]]),
            'https://api.anthropic.test/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'not valid json {{{']],
                'usage' => ['input_tokens' => 5, 'output_tokens' => 5],
            ]),
        ]);

        Livewire::test(ProductSearch::class)
            ->fillForm(['query' => 'scaldabagno pompa calore'])
            ->call('search')
            ->assertSee('Tipo riconosciuto:')
            ->assertSee('scaldabagno pompa calore')
            ->assertCanSeeTableRecords([$relevant]);
    }

    private function fakeAnthropicConfig(): void
    {
        config()->set('services.ai_provider', 'anthropic');

        config()->set('services.anthropic', [
            'api_key' => 'test-api-key',
            'model' => 'claude-test-model',
            'model_fast' => 'claude-fast-test',
            'model_smart' => 'claude-smart-test',
            'version' => '2023-06-01',
            'base_url' => 'https://api.anthropic.test',
            'timeout' => 120,
            'retry_times' => 0,
            'retry_delay_ms' => 1,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $attributes
     * @return array<string, mixed>
     */
    private function anthropicParseBody(string $recognizedText, array $attributes): array
    {
        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'recognized_text' => $recognizedText,
                    'attributes' => $attributes,
                ], JSON_UNESCAPED_UNICODE),
            ]],
            'usage' => ['input_tokens' => 50, 'output_tokens' => 20],
        ];
    }
}
