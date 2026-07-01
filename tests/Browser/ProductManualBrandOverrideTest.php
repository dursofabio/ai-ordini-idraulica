<?php

namespace Tests\Browser;

use App\Models\Brand;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Hash;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * US-021 demo scenario (spec "Dimostra"):
 * an admin opens a product from the backoffice, changes its assigned brand,
 * and saves; the product then shows `brand_source='manual'` (rendered as the
 * "🔒 Impostato manualmente" helper text on the edit form and as a blue/info
 * badge with a lock icon on the product list) and `confidence=100`.
 *
 * The guarantee that the AI will never again overwrite a manually-set value
 * on subsequent reimports is backend logic already covered by
 * tests/Feature/ProductResourceTest.php; this browser test only demonstrates
 * the human-visible half of the scenario: the admin edits the brand, saves,
 * and sees the manual indicator.
 *
 * Per-step screenshots are stored in docs/test-results/US-021/ as the visual
 * artifact of the run (Dusk does not record video). Actions are paced with
 * explicit visibility assertions and short holds so the artifacts are
 * readable by a non-technical reviewer.
 */
class ProductManualBrandOverrideTest extends DuskTestCase
{
    use DatabaseMigrations;

    protected const ARTIFACT_DIR = __DIR__.'/../../docs/test-results/US-021';

    public function test_admin_manually_overrides_product_brand_and_sees_manual_indicator(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin',
            'email' => AdminUserSeeder::DEFAULT_EMAIL,
            'password' => Hash::make(AdminUserSeeder::DEFAULT_PASSWORD),
        ]);

        $originalBrand = Brand::factory()->create(['name' => 'Marca Originale']);
        $correctBrand = Brand::factory()->create(['name' => 'Marca Corretta']);

        $product = Product::factory()->create([
            'brand_id' => $originalBrand->id,
            'brand_source' => 'ai',
            'source' => 'ai',
            'confidence' => 40,
        ]);

        // Scopes every Select interaction to the "Marca" field specifically,
        // since the edit form renders multiple Filament Select components
        // (brand, family, subfamily) with the same internal markup.
        $brandFieldSelector = '.fi-fo-select-wrp:has(label[for="form.brand_id"])';

        $this->browse(function (Browser $browser) use ($admin, $product, $brandFieldSelector) {
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
                ->screenshot('02-dashboard');

            // 2. The admin opens the product list and sees the current,
            // non-manual (gray, no-lock) brand badge.
            $browser->visit('/admin/products')
                ->waitForText($product->codice_articolo)
                ->assertSee('Marca Originale')
                ->pause(400)
                ->screenshot('03-list-before');

            // 3. The admin opens the product's edit page.
            $browser->visit('/admin/products/'.$product->id.'/edit')
                ->waitForText('Edit Product')
                ->assertSee('Origine: ai')
                ->pause(400)
                ->screenshot('04-edit-before');

            // 4. The admin opens the "Marca" select and searches for the
            // corrected brand.
            $browser->click($brandFieldSelector.' .fi-select-input-btn')
                ->pause(400)
                ->screenshot('05-brand-dropdown-open')
                ->type($brandFieldSelector.' .fi-select-input-search-ctn input', 'Marca Corretta')
                ->pause(1200)
                ->screenshot('06-brand-search-results');

            // 5. The admin picks the corrected brand from the filtered
            // dropdown results.
            $browser->click($brandFieldSelector.' li.fi-select-input-option')
                ->pause(400)
                ->assertSee('Marca Corretta')
                ->screenshot('07-brand-selected');

            // 6. The admin saves the form.
            $browser->press('Save changes')
                ->waitForText('Saved')
                ->pause(400)
                ->assertSee('🔒 Impostato manualmente')
                ->screenshot('08-saved-manual-indicator');

            // 7. Back on the list, the product now shows the info-colored,
            // lock-icon badge with the corrected brand and confidence 100.
            $browser->visit('/admin/products')
                ->waitForText($product->codice_articolo)
                ->assertSee('Marca Corretta')
                ->assertPresent('svg.fi-icon')
                ->pause(1500)
                ->screenshot('09-list-after');
        });

        $product->refresh();

        $this->assertSame($correctBrand->id, $product->brand_id);
        $this->assertSame('manual', $product->brand_source);
        $this->assertSame('manual', $product->source);
        $this->assertSame(100, $product->confidence);
    }
}
