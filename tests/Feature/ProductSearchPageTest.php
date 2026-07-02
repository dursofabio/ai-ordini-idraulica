<?php

namespace Tests\Feature;

use App\Filament\Pages\ProductSearch;
use App\Models\Brand;
use App\Models\Family;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductBase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * US-033 acceptance criteria for the read-only "Ricerca prodotti" page:
 *  - AC3: submitting the form (free text and/or filters) runs the hybrid
 *    search and shows the expected product-bases in the results table.
 *  - AC2: the fixed numeric technical attribute filters (potenza_kw,
 *    diametro_nominale, pressione_nominale) narrow the results to
 *    product-bases with a matching variant.
 *  - AC1/AC3: brand/family/subfamily filters narrow the results.
 *  - AC4: with no text and no filter set, submitting never calls the search
 *    engine (no embedding HTTP call) and the table shows the guided empty
 *    state, distinct from a genuine "no results" outcome.
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

        Http::fake([
            '*' => Http::response(['embedding' => [0.1, 0.2, 0.3, 0.4]]),
        ]);
    }

    public function test_free_text_search_returns_only_relevant_product_bases(): void
    {
        $this->actingAs(User::factory()->create());

        // Strong lexical match on the query terms (fts_score dominates since
        // neither base has a stored embedding, so vector_score is 0 for
        // both — see SearchService::applyRanking()).
        $relevant = ProductBase::factory()->create([
            'title' => 'Scaldabagno a pompa di calore',
            'description_ai' => 'Scaldabagno a pompa di calore per uso domestico',
        ]);
        // Neither lexically nor semantically related to the query: excluded
        // by SearchService's relevance cutoff instead of merely ranked last.
        $irrelevant = ProductBase::factory()->create([
            'title' => 'Valvola a sfera in ottone',
            'description_ai' => 'Valvola a sfera in ottone per impianti idraulici',
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

        $matching = ProductBase::factory()->create([
            'brand_id' => $brandA->id,
            'family_id' => $familyA->id,
        ]);
        $nonMatching = ProductBase::factory()->create([
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

    public function test_numeric_attribute_filter_returns_only_product_bases_with_variant_in_range(): void
    {
        $this->actingAs(User::factory()->create());

        $inRange = ProductBase::factory()->create();
        $inRangeProduct = Product::factory()->create(['product_base_id' => $inRange->id]);
        ProductAttribute::factory()->create([
            'product_id' => $inRangeProduct->id,
            'key' => 'potenza_kw',
            'value_num' => 2.5,
        ]);

        $outOfRange = ProductBase::factory()->create();
        $outOfRangeProduct = Product::factory()->create(['product_base_id' => $outOfRange->id]);
        ProductAttribute::factory()->create([
            'product_id' => $outOfRangeProduct->id,
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

        ProductBase::factory()->create();

        Livewire::test(ProductSearch::class)
            ->call('search')
            ->assertSee(self::GUIDED_EMPTY_STATE_HEADING)
            ->assertDontSee(self::NO_RESULTS_EMPTY_STATE_HEADING);

        Http::assertNothingSent();
    }

    public function test_submitting_filters_that_match_nothing_shows_distinct_no_results_empty_state(): void
    {
        $this->actingAs(User::factory()->create());

        $unrelatedBrand = Brand::factory()->create();
        ProductBase::factory()->create();

        Livewire::test(ProductSearch::class)
            ->fillForm(['brand_id' => $unrelatedBrand->id])
            ->call('search')
            ->assertSee(self::NO_RESULTS_EMPTY_STATE_HEADING)
            ->assertDontSee(self::GUIDED_EMPTY_STATE_HEADING);
    }
}
