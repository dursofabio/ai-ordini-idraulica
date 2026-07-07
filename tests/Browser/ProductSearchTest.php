<?php

namespace Tests\Browser;

use App\Models\Brand;
use App\Models\Family;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\User;
use App\Services\Search\MatchOutcomeResolver;
use App\Services\Search\SearchResult;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Hash;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * US-033 demo scenario (spec "Dimostra"):
 * an operator opens "Ricerca prodotti", types free text plus a family
 * filter, clicks "Cerca", and sees a results table of candidate products
 * with brand and family; a second scenario shows that filters matching
 * nothing render a distinct "no results" empty state instead of the initial
 * guided one.
 *
 * US-047 flattens the results table onto a single `Product` row per SKU (no
 * more grouping/variants/power range).
 *
 * Dusk runs against SQLite (see `.env.dusk.local`), so `SearchService`
 * takes its non-PostgreSQL fallback ranking path (plain `LIKE` on
 * product_type/description_clean, no vector fusion) and never calls the
 * embedding provider — no HTTP faking is needed for this browser test.
 *
 * Per-step screenshots are stored in docs/test-results/US-033/ as the visual
 * artifact of the run (Dusk does not record video). Actions are paced with
 * explicit visibility assertions and short holds so the artifacts are
 * readable by a non-technical reviewer.
 *
 * US-048: `.env.dusk.local` also sets `SEARCH_NL_PARSING_ENABLED=false`
 * (this standalone stack has no AI credentials, same reason `DB_CONNECTION`
 * is SQLite here instead of Postgres), so every scenario in this class
 * already exercises `NaturalLanguageSearchService` with the parser
 * disabled. {@see self::test_operator_searches_with_nl_parsing_disabled_and_sees_plain_interpretation_banner()}
 * makes that degraded path explicit and asserts the interpretation banner
 * shows plain, unfiltered text with no invented attribute chip. The path
 * with the AI parser actually enabled (recognized text + attribute chips,
 * hard-filtered results) is covered by `ProductSearchPageTest`, which fakes
 * the HTTP call in-process — Dusk never makes a real AI call.
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

        $matching = Product::factory()->create([
            'product_type' => 'Compressore a pistone 2CV',
            'description_clean' => 'Compressore a pistone 2CV per uso officina',
            'brand_id' => $brand->id,
            'family_id' => $family->id,
            'subfamily_id' => null,
        ]);
        ProductAttribute::factory()->create([
            'product_id' => $matching->id,
            'key' => 'potenza',
            'value' => '2.5',
        ]);

        $nonMatching = Product::factory()->create([
            'product_type' => 'Valvola a sfera in ottone',
            'description_clean' => 'Valvola a sfera in ottone per impianti idraulici',
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
            // table with the candidate product's brand and family — and not
            // the non-matching product.
            $browser->press('Cerca')
                ->waitUntilMissingText(self::GUIDED_EMPTY_STATE_HEADING)
                ->waitForText($matching->product_type)
                ->assertSee($matching->product_type)
                ->assertSee('Marca Compressori')
                ->assertSee('Compressori')
                ->assertDontSee($nonMatching->product_type)
                ->pause(400)
                ->screenshot('07-results-table');

            $browser->pause(1500)
                ->screenshot('08-results-detail');
        });
    }

    public function test_operator_searches_with_non_matching_family_filter_and_sees_distinct_no_results_state(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin',
            'email' => AdminUserSeeder::DEFAULT_EMAIL,
            'password' => Hash::make(AdminUserSeeder::DEFAULT_PASSWORD),
        ]);

        $product = Product::factory()->create([
            'product_type' => 'Pompa sommersa',
            'description_clean' => 'Pompa sommersa per pozzi',
            'subfamily_id' => null,
        ]);
        $unrelatedFamily = Family::factory()->create(['name' => 'Valvole']);

        $this->browse(function (Browser $browser) use ($admin, $product, $unrelatedFamily) {
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

            // 3. The operator picks a "Famiglia" filter that the only seeded
            // product doesn't belong to.
            $browser->click('.fi-fo-select-wrp:has(label[for="form.family_id"]) .fi-select-input-btn')
                ->pause(400)
                ->type('.fi-fo-select-wrp:has(label[for="form.family_id"]) .fi-select-input-search-ctn input', $unrelatedFamily->name)
                ->pause(800)
                ->screenshot('10-out-of-range-filter');

            $browser->click('.fi-fo-select-wrp:has(label[for="form.family_id"]) li.fi-select-input-option')
                ->pause(400);

            // 4. The operator clicks "Cerca" and sees the distinct
            // "no results" message, not the initial guided state, and not
            // the unrelated product.
            $browser->press('Cerca')
                ->waitForText(self::NO_RESULTS_EMPTY_STATE_HEADING)
                ->assertSee(self::NO_RESULTS_EMPTY_STATE_HEADING)
                ->assertDontSee(self::GUIDED_EMPTY_STATE_HEADING)
                ->assertDontSee($product->product_type)
                ->pause(1500)
                ->screenshot('11-no-results-state');
        });
    }

    /**
     * US-048 demo scenario, degraded path: with
     * `SEARCH_NL_PARSING_ENABLED=false` (the standing setting for this whole
     * standalone Dusk stack, see the class docblock), the page must keep
     * working end-to-end with zero AI calls, and the interpretation banner
     * must show the query back verbatim with no attribute chip — proving
     * "no invented filter" holds even when the parser never runs.
     *
     * Screenshots for this scenario are stored separately, under
     * docs/test-results/US-048/, instead of the class's default US-033
     * directory.
     */
    public function test_operator_searches_with_nl_parsing_disabled_and_sees_plain_interpretation_banner(): void
    {
        $artifactDir = __DIR__.'/../../docs/test-results/US-048';

        if (! is_dir($artifactDir)) {
            mkdir($artifactDir, 0755, true);
        }

        $previousScreenshotDir = Browser::$storeScreenshotsAt;
        Browser::$storeScreenshotsAt = $artifactDir;

        try {
            $admin = User::factory()->create([
                'name' => 'Admin',
                'email' => AdminUserSeeder::DEFAULT_EMAIL,
                'password' => Hash::make(AdminUserSeeder::DEFAULT_PASSWORD),
            ]);

            $matching = Product::factory()->create([
                'product_type' => 'Compressore a pistone 2CV',
                'description_clean' => 'Compressore a pistone 2CV per uso officina',
            ]);

            $this->browse(function (Browser $browser) use ($admin, $matching) {
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

                // 2. The operator opens "Ricerca prodotti" and types free
                // text — no attribute-looking mention, so this scenario
                // stays valid regardless of the parser being on or off.
                $browser->visit('/admin/product-search')
                    ->waitForText(self::GUIDED_EMPTY_STATE_HEADING)
                    ->type('#form\\.query', 'compressore')
                    ->pause(400)
                    ->screenshot('01-query-typed');

                // 3. The operator clicks "Cerca" and sees the matching
                // product, with zero AI call: the interpretation banner
                // shows the query back verbatim, with no attribute chip.
                $browser->press('Cerca')
                    ->waitUntilMissingText(self::GUIDED_EMPTY_STATE_HEADING)
                    ->waitForText($matching->product_type)
                    ->assertSee($matching->product_type)
                    ->assertSee('Tipo riconosciuto:')
                    ->assertSee('compressore')
                    ->pause(1500)
                    ->screenshot('02-plain-interpretation-banner');
            });
        } finally {
            Browser::$storeScreenshotsAt = $previousScreenshotDir;
        }
    }

    /**
     * US-049 non-regression scenario: Dusk runs against SQLite (see the
     * class docblock), so `SearchService` takes its non-pgsql fallback
     * ranking path, which never selects `vector_score` — every
     * {@see SearchResult} in this environment has a
     * `null` vectorScore. Two products both matching the free-text query
     * means {@see MatchOutcomeResolver} sees more than
     * one candidate with a null top vector score, so it must degrade to the
     * cautious "disambiguation" outcome rather than either crash or —
     * worse — falsely declare an automatic match it can't actually
     * substantiate without a real vector signal.
     *
     * Screenshots for this scenario are stored under
     * docs/test-results/US-049/.
     */
    public function test_operator_searches_ambiguous_free_text_and_never_sees_a_false_auto_match_badge(): void
    {
        $artifactDir = __DIR__.'/../../docs/test-results/US-049';

        if (! is_dir($artifactDir)) {
            mkdir($artifactDir, 0755, true);
        }

        $previousScreenshotDir = Browser::$storeScreenshotsAt;
        Browser::$storeScreenshotsAt = $artifactDir;

        try {
            $admin = User::factory()->create([
                'name' => 'Admin',
                'email' => AdminUserSeeder::DEFAULT_EMAIL,
                'password' => Hash::make(AdminUserSeeder::DEFAULT_PASSWORD),
            ]);

            $first = Product::factory()->create([
                'product_type' => 'Scaldabagno a pompa di calore modello A',
                'description_clean' => 'Scaldabagno a pompa di calore modello A per uso domestico',
            ]);
            $second = Product::factory()->create([
                'product_type' => 'Scaldabagno a pompa di calore modello B',
                'description_clean' => 'Scaldabagno a pompa di calore modello B per uso domestico',
            ]);

            $this->browse(function (Browser $browser) use ($admin, $first, $second) {
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

                // 2. The operator opens "Ricerca prodotti" and types free
                // text that matches two near-identical products — genuinely
                // ambiguous, with no embedding available in this environment
                // to break the tie.
                $browser->visit('/admin/product-search')
                    ->waitForText(self::GUIDED_EMPTY_STATE_HEADING)
                    ->type('#form\\.query', 'scaldabagno pompa calore')
                    ->pause(400)
                    ->screenshot('01-ambiguous-query-typed');

                // 3. The operator clicks "Cerca" and sees both candidates
                // plus the disambiguation badge — never an automatic-match
                // badge, which would be a false positive here.
                $browser->press('Cerca')
                    ->waitUntilMissingText(self::GUIDED_EMPTY_STATE_HEADING)
                    ->waitForText('Prodotti candidati da verificare')
                    ->assertSee('Prodotti candidati da verificare')
                    ->assertDontSee('Corrispondenza automatica')
                    ->assertSee($first->product_type)
                    ->assertSee($second->product_type)
                    ->pause(1500)
                    ->screenshot('02-disambiguation-badge');
            });
        } finally {
            Browser::$storeScreenshotsAt = $previousScreenshotDir;
        }
    }

    /**
     * US-049 non-regression scenario, exact-code exception: even on the
     * SQLite fallback path (no vector scores at all), a query matching a
     * product's `codice_articolo` exactly is still unambiguous by
     * construction and must show the automatic-match badge — the one case
     * where an automatic match is expected in this environment.
     *
     * Screenshots for this scenario are stored under
     * docs/test-results/US-049/.
     */
    public function test_operator_searches_exact_product_code_and_sees_auto_match_badge_despite_sqlite_fallback(): void
    {
        $artifactDir = __DIR__.'/../../docs/test-results/US-049';

        if (! is_dir($artifactDir)) {
            mkdir($artifactDir, 0755, true);
        }

        $previousScreenshotDir = Browser::$storeScreenshotsAt;
        Browser::$storeScreenshotsAt = $artifactDir;

        try {
            $admin = User::factory()->create([
                'name' => 'Admin',
                'email' => AdminUserSeeder::DEFAULT_EMAIL,
                'password' => Hash::make(AdminUserSeeder::DEFAULT_PASSWORD),
            ]);

            $exactMatch = Product::factory()->create([
                'product_type' => 'Valvola a sfera in ottone',
                'description_clean' => 'Valvola a sfera in ottone per impianti idraulici',
                'codice_articolo' => 'ABC-12345',
            ]);
            Product::factory()->create([
                'product_type' => 'Valvola a sfera in acciaio',
                'description_clean' => 'Valvola a sfera in acciaio per impianti idraulici',
            ]);

            $this->browse(function (Browser $browser) use ($admin, $exactMatch) {
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

                // 2. The operator opens "Ricerca prodotti" and types the
                // exact article code of one product.
                $browser->visit('/admin/product-search')
                    ->waitForText(self::GUIDED_EMPTY_STATE_HEADING)
                    ->type('#form\\.query', $exactMatch->codice_articolo)
                    ->pause(400)
                    ->screenshot('03-exact-code-typed');

                // 3. The operator clicks "Cerca" and sees the automatic
                // match badge, not the disambiguation one.
                $browser->press('Cerca')
                    ->waitUntilMissingText(self::GUIDED_EMPTY_STATE_HEADING)
                    ->waitForText('Corrispondenza automatica')
                    ->assertSee('Corrispondenza automatica')
                    ->assertDontSee('Prodotti candidati da verificare')
                    ->assertSee($exactMatch->product_type)
                    ->pause(1500)
                    ->screenshot('04-auto-match-badge');
            });
        } finally {
            Browser::$storeScreenshotsAt = $previousScreenshotDir;
        }
    }
}
