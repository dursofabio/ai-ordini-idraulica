<?php

namespace Tests\Browser;

use App\Models\Brand;
use App\Models\Family;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductBase;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Hash;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * US-033 demo scenario (spec "Dimostra"):
 * an operator opens "Ricerca prodotti", types free text plus a family
 * filter, clicks "Cerca", and sees a results table of candidate
 * product-bases with brand, family, variant count and power range; a
 * second scenario shows that filters matching nothing render a distinct
 * "no results" empty state instead of the initial guided one.
 *
 * Dusk runs against SQLite (see `.env.dusk.local`), so `SearchService`
 * takes its non-PostgreSQL fallback ranking path (plain `LIKE` on
 * title/description_ai, no vector fusion) and never calls the embedding
 * provider — no HTTP faking is needed for this browser test.
 *
 * Per-step screenshots are stored in docs/test-results/US-033/ as the visual
 * artifact of the run (Dusk does not record video). Actions are paced with
 * explicit visibility assertions and short holds so the artifacts are
 * readable by a non-technical reviewer.
 */
class ProductSearchTest extends DuskTestCase
{
    use DatabaseMigrations;

    protected const ARTIFACT_DIR = __DIR__.'/../../docs/test-results/US-033';

    private const GUIDED_EMPTY_STATE_HEADING = 'Inserisci un testo o un filtro per iniziare la ricerca';

    private const NO_RESULTS_EMPTY_STATE_HEADING = 'Nessun prodotto trovato';

    public function test_operator_searches_with_free_text_and_family_filter_and_sees_ranked_results(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin',
            'email' => AdminUserSeeder::DEFAULT_EMAIL,
            'password' => Hash::make(AdminUserSeeder::DEFAULT_PASSWORD),
        ]);

        $family = Family::factory()->create(['name' => 'Compressori']);
        $brand = Brand::factory()->create(['name' => 'Marca Compressori']);
        $otherFamily = Family::factory()->create(['name' => 'Valvole']);
        $otherBrand = Brand::factory()->create(['name' => 'Marca Valvole']);

        $matching = ProductBase::factory()->create([
            'title' => 'Compressore a pistone 2CV',
            'description_ai' => 'Compressore a pistone 2CV per uso officina',
            'brand_id' => $brand->id,
            'family_id' => $family->id,
            'subfamily_id' => null,
        ]);

        $variantLow = Product::factory()->create(['product_base_id' => $matching->id]);
        ProductAttribute::factory()->create([
            'product_id' => $variantLow->id,
            'key' => 'potenza_kw',
            'value_num' => 2.5,
        ]);

        $variantHigh = Product::factory()->create(['product_base_id' => $matching->id]);
        ProductAttribute::factory()->create([
            'product_id' => $variantHigh->id,
            'key' => 'potenza_kw',
            'value_num' => 4,
        ]);

        $nonMatching = ProductBase::factory()->create([
            'title' => 'Valvola a sfera in ottone',
            'description_ai' => 'Valvola a sfera in ottone per impianti idraulici',
            'brand_id' => $otherBrand->id,
            'family_id' => $otherFamily->id,
            'subfamily_id' => null,
        ]);

        $this->browse(function (Browser $browser) use ($admin, $family, $matching, $nonMatching) {
            // 1. The operator logs in to the Filament backoffice.
            $browser->visit('/admin/login')
                ->waitFor('#form\\.email')
                ->type('#form\\.email', $admin->email)
                ->pause(400)
                ->type('#form\\.password', AdminUserSeeder::DEFAULT_PASSWORD)
                ->pause(400)
                ->screenshot('01-login-page')
                ->click('button[type="submit"]')
                ->waitForLocation('/admin')
                ->pause(400)
                ->screenshot('02-dashboard');

            // 2. The operator opens "Ricerca prodotti" and sees the initial
            // guided empty state (no search has run yet).
            $browser->visit('/admin/product-search')
                ->waitForText(self::GUIDED_EMPTY_STATE_HEADING)
                ->assertSee(self::GUIDED_EMPTY_STATE_HEADING)
                ->assertDontSee(self::NO_RESULTS_EMPTY_STATE_HEADING)
                ->pause(400)
                ->screenshot('03-guided-empty-state');

            // 3. The operator types free text and picks the "Famiglia"
            // filter.
            $browser->type('#form\\.query', 'compressore')
                ->pause(400)
                ->screenshot('04-query-typed');

            $browser->click('.fi-fo-select-wrp:has(label[for="form.family_id"]) .fi-select-input-btn')
                ->pause(400)
                ->type('.fi-fo-select-wrp:has(label[for="form.family_id"]) .fi-select-input-search-ctn input', $family->name)
                ->pause(800)
                ->screenshot('05-family-search');

            $browser->click('.fi-fo-select-wrp:has(label[for="form.family_id"]) li.fi-select-input-option')
                ->pause(400)
                ->assertSee($family->name)
                ->screenshot('06-family-selected');

            // 4. The operator clicks "Cerca" and sees the ranked results
            // table with the candidate product-base's brand, family, variant
            // count and power range — and not the non-matching product-base.
            $browser->press('Cerca')
                ->waitUntilMissingText(self::GUIDED_EMPTY_STATE_HEADING)
                ->waitForText($matching->title)
                ->assertSee($matching->title)
                ->assertSee('Marca Compressori')
                ->assertSee('Compressori')
                ->assertDontSee($nonMatching->title)
                ->pause(400)
                ->screenshot('07-results-table');

            $browser->with($this->rowSelector($matching), function (Browser $row) {
                $row->assertSee('2')
                    ->assertSee('2.5–4 kW');
            });

            $browser->pause(1500)
                ->screenshot('08-results-detail');
        });
    }

    public function test_operator_searches_with_out_of_range_filter_and_sees_distinct_no_results_state(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin',
            'email' => AdminUserSeeder::DEFAULT_EMAIL,
            'password' => Hash::make(AdminUserSeeder::DEFAULT_PASSWORD),
        ]);

        $productBase = ProductBase::factory()->create([
            'title' => 'Pompa sommersa',
            'description_ai' => 'Pompa sommersa per pozzi',
            'subfamily_id' => null,
        ]);

        $variant = Product::factory()->create(['product_base_id' => $productBase->id]);
        ProductAttribute::factory()->create([
            'product_id' => $variant->id,
            'key' => 'potenza_kw',
            'value_num' => 5,
        ]);

        $this->browse(function (Browser $browser) use ($admin, $productBase) {
            // 1. The operator logs in to the Filament backoffice.
            $browser->visit('/admin/login')
                ->waitFor('#form\\.email')
                ->type('#form\\.email', $admin->email)
                ->pause(400)
                ->type('#form\\.password', AdminUserSeeder::DEFAULT_PASSWORD)
                ->pause(400)
                ->click('button[type="submit"]')
                ->waitForLocation('/admin')
                ->pause(400);

            // 2. The operator opens "Ricerca prodotti" and sees the initial
            // guided empty state.
            $browser->visit('/admin/product-search')
                ->waitForText(self::GUIDED_EMPTY_STATE_HEADING)
                ->assertSee(self::GUIDED_EMPTY_STATE_HEADING)
                ->pause(400)
                ->screenshot('09-guided-empty-state');

            // 3. The operator sets a "Potenza (kW)" range that no seeded
            // variant falls into (the only variant has potenza_kw = 5).
            $browser->type('#form\\.potenza_kw_min', '500')
                ->pause(300)
                ->type('#form\\.potenza_kw_max', '600')
                ->pause(400)
                ->screenshot('10-out-of-range-filter');

            // 4. The operator clicks "Cerca" and sees the distinct
            // "no results" message, not the initial guided state, and not
            // the unrelated product-base.
            $browser->press('Cerca')
                ->waitForText(self::NO_RESULTS_EMPTY_STATE_HEADING)
                ->assertSee(self::NO_RESULTS_EMPTY_STATE_HEADING)
                ->assertDontSee(self::GUIDED_EMPTY_STATE_HEADING)
                ->assertDontSee($productBase->title)
                ->pause(1500)
                ->screenshot('11-no-results-state');
        });
    }

    /**
     * Builds a CSS selector for the results-table row of the given
     * product-base, so assertions on that row's cells (variant count, power
     * range) are scoped to the correct record instead of matching the first
     * occurrence on the page. Filament keys every custom-data table row with
     * a `wire:key` ending in `table.records.{recordKey}`, and `ProductSearch`
     * keys each row by `productBase->id` (see `ProductSearch::searchResults()`).
     */
    private function rowSelector(ProductBase $productBase): string
    {
        return 'tr[wire\\:key$=".table.records.'.$productBase->getKey().'"]';
    }
}
