<?php

namespace Tests\Browser;

use App\Models\Brand;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Hash;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * US-022 demo scenario (spec "Dimostra"):
 * an admin opens the Brand list from the backoffice, edits 'VAILLANT',
 * adds 'VAILL' as an alias via the tags input, and saves; the alias is
 * persisted and still shown after reloading the edit form.
 *
 * The guarantee that BrandResolver actually picks up the newly added alias
 * on the next deterministic enrichment pass is backend logic already
 * covered by tests/Feature/BrandAliasBackofficeResolverTest.php; this
 * browser test only demonstrates the human-visible half of the scenario:
 * the admin adds the alias from the backoffice UI and sees it saved.
 *
 * Per-step screenshots are stored in docs/test-results/US-022/ as the visual
 * artifact of the run (Dusk does not record video). Actions are paced with
 * explicit visibility assertions and short holds so the artifacts are
 * readable by a non-technical reviewer.
 */
class BrandAliasManagementTest extends DuskTestCase
{
    use DatabaseMigrations;

    protected const ARTIFACT_DIR = __DIR__.'/../../docs/test-results/US-022';

    public function test_admin_adds_brand_alias_from_backoffice(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin',
            'email' => AdminUserSeeder::DEFAULT_EMAIL,
            'password' => Hash::make(AdminUserSeeder::DEFAULT_PASSWORD),
        ]);

        $brand = Brand::factory()->create([
            'name' => 'VAILLANT',
            'slug' => 'vaillant',
            'aliases' => [],
        ]);

        $this->browse(function (Browser $browser) use ($admin, $brand) {
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

            // 2. The admin opens the Brand list and sees VAILLANT with no
            // aliases yet.
            $browser->visit('/admin/brands')
                ->waitForText('VAILLANT')
                ->assertSee('VAILLANT')
                ->pause(400)
                ->screenshot('03-list-before');

            // 3. The admin opens the brand's edit page.
            $browser->visit('/admin/brands/'.$brand->id.'/edit')
                ->waitFor('#form\\.name')
                ->assertInputValue('#form\\.name', 'VAILLANT')
                ->pause(400)
                ->screenshot('04-edit-before');

            // 4. The admin types the new alias into the tags input and
            // confirms it.
            $browser->click('#form\\.aliases')
                ->pause(400)
                ->type('#form\\.aliases', 'VAILL')
                ->pause(400)
                ->keys('#form\\.aliases', '{enter}')
                ->pause(400)
                ->assertSee('VAILL')
                ->screenshot('05-alias-added');

            // 5. The admin saves the form.
            $browser->press('Save changes')
                ->waitForText('Saved')
                ->pause(400)
                ->screenshot('06-saved');

            // 6. Reloading the edit form still shows the persisted alias.
            $browser->visit('/admin/brands/'.$brand->id.'/edit')
                ->waitFor('#form\\.name')
                ->waitForText('VAILL')
                ->assertSee('VAILL')
                ->pause(1500)
                ->screenshot('07-alias-persisted-after-reload');
        });

        $brand->refresh();

        $this->assertSame(['VAILL'], $brand->aliases);
    }
}
