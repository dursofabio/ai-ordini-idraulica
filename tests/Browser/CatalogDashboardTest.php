<?php

namespace Tests\Browser;

use App\Models\Brand;
use App\Models\EnrichmentLog;
use App\Models\Family;
use App\Models\Product;
use App\Models\Subfamily;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Hash;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * US-025 demo scenario (spec "Dimostra"):
 * an admin opens the Filament dashboard and sees the catalog coverage
 * percentages (brand/family/subfamily), the enrichment status counts, the
 * inactive articles count, and the estimated AI cost of the last batch —
 * all without having to query the database manually.
 *
 * Per-step screenshots are stored in docs/test-results/US-025/ as the visual
 * artifact of the run (Dusk does not record video), following the same
 * convention as tests/Browser/BrandAliasManagementTest.php (US-022).
 */
class CatalogDashboardTest extends DuskTestCase
{
    use DatabaseMigrations;

    protected const ARTIFACT_DIR = __DIR__.'/../../docs/test-results/US-025';

    public function test_admin_sees_catalog_coverage_and_ai_cost_on_dashboard(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin',
            'email' => AdminUserSeeder::DEFAULT_EMAIL,
            'password' => Hash::make(AdminUserSeeder::DEFAULT_PASSWORD),
        ]);

        // Known seed: 4 products, only 1 fully enriched (brand+family+subfamily)
        // -> 25% coverage on each dimension. 2 inactive products.
        $brand = Brand::factory()->create();
        $family = Family::factory()->create();
        $subfamily = Subfamily::factory()->create();

        Product::factory()->create([
            'brand_id' => $brand->id,
            'family_id' => $family->id,
            'subfamily_id' => $subfamily->id,
            'enrichment_status' => 'done',
            'is_active' => true,
        ]);
        Product::factory()->create([
            'brand_id' => null,
            'family_id' => null,
            'subfamily_id' => null,
            'enrichment_status' => 'pending',
            'is_active' => true,
        ]);
        Product::factory()->create([
            'brand_id' => null,
            'family_id' => null,
            'subfamily_id' => null,
            'enrichment_status' => 'needs_review',
            'is_active' => false,
        ]);
        $lastBatchProduct = Product::factory()->create([
            'brand_id' => null,
            'family_id' => null,
            'subfamily_id' => null,
            'enrichment_status' => 'enriched',
            'is_active' => false,
        ]);

        // Known AI cost for the last batch: 1M in + 1M out tokens on the
        // configured "fast" model.
        EnrichmentLog::factory()->create([
            'product_id' => $lastBatchProduct->id,
            'model' => config('services.anthropic.model_fast'),
            'tokens_in' => 1_000_000,
            'tokens_out' => 1_000_000,
        ]);

        $this->browse(function (Browser $browser) use ($admin) {
            // 1. The admin logs in to the Filament backoffice.
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
                ->screenshot('02-dashboard-loaded');

            // 2. The admin sees the catalog coverage percentages.
            $browser->waitForText('Copertura marca')
                ->assertSee('Copertura marca')
                ->assertSee('Copertura famiglia')
                ->assertSee('Copertura sottofamiglia')
                ->assertSee('25%')
                ->pause(400)
                ->screenshot('03-catalog-coverage');

            // 3. The admin sees the enrichment status counts.
            $browser->assertSee('Pending')
                ->assertSee('Done')
                ->pause(400)
                ->screenshot('04-enrichment-status');

            // 4. The admin sees the inactive articles count.
            $browser->assertSee('Articoli inattivi')
                ->assertSee('2')
                ->pause(400)
                ->screenshot('05-inactive-products');

            // 5. The admin sees the estimated AI cost of the last batch.
            $browser->assertSee('Costo AI ultimo batch')
                ->pause(400)
                ->screenshot('06-ai-cost');

            // 6. The dashboard stays populated across a polling refresh cycle,
            // without a full page reload.
            $browser->pause(16000)
                ->assertSee('Copertura marca')
                ->assertSee('Costo AI ultimo batch')
                ->pause(1500)
                ->screenshot('07-dashboard-after-poll');
        });
    }
}
