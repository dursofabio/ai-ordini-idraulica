<?php

namespace Tests\Browser;

use App\Models\Product;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Hash;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * US-050 demo scenario (spec "Dimostra"):
 * the operator opens a product's edit page, fills the "Descrizione estesa"
 * markdown editor with formatted text, saves, then opens the product detail
 * page and sees the description rendered as HTML (not raw markdown) in its
 * dedicated section.
 *
 * A second, non-demo scenario checks the empty-field contract (AC4): a
 * product without an extended description shows only the placeholder in the
 * section — no fake content, no errors.
 *
 * Per-step screenshots are stored in docs/test-results/US-050/ as the visual
 * artifact of the run (Dusk does not record video). Actions are paced with
 * explicit visibility assertions and short holds so the artifacts are
 * readable by a non-technical reviewer.
 */
class ProductExtendedDescriptionTest extends DuskTestCase
{
    use DatabaseMigrations;

    protected const ARTIFACT_DIR = __DIR__.'/../../docs/test-results/US-050';

    public function test_operator_edits_and_views_the_extended_markdown_description(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin',
            'email' => AdminUserSeeder::DEFAULT_EMAIL,
            'password' => Hash::make(AdminUserSeeder::DEFAULT_PASSWORD),
        ]);

        $product = Product::factory()->create(['descrizione_estesa' => null]);

        $this->browse(function (Browser $browser) use ($admin, $product) {
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
                ->pause(400);

            // 2. The operator opens the product's edit page, where the
            // markdown editor is still empty.
            $browser->visit('/admin/products/'.$product->id.'/edit')
                ->waitForText('Edit Product')
                ->assertSee('Descrizione estesa')
                ->waitFor('.fi-fo-markdown-editor .CodeMirror')
                ->pause(400)
                ->screenshot('02-edit-page-empty-editor');

            // 3. The operator writes formatted markdown in the editor. The
            // editor is EasyMDE/CodeMirror: clicking it focuses a hidden
            // textarea, so keystrokes are sent to the focused element instead
            // of typed into a visible input.
            $browser->click('.fi-fo-markdown-editor .CodeMirror')
                ->pause(400);

            $browser->driver->getKeyboard()->sendKeys(
                "# Scheda tecnica\n\nDescrizione **ricca** del prodotto."
            );

            $browser->pause(600)
                ->assertSee('Scheda tecnica')
                ->screenshot('03-editor-filled');

            // 4. The operator saves the form.
            $browser->press('Save changes')
                ->waitForText('Saved')
                ->pause(400)
                ->screenshot('04-saved');

            // 5. On the product detail page, the dedicated section shows the
            // description rendered as HTML: the heading and bold text are
            // visible, while the raw markdown markers are not.
            $browser->visit('/admin/products/'.$product->id)
                ->waitForText('Descrizione estesa')
                ->assertSee('Scheda tecnica')
                ->assertDontSee('**ricca**')
                ->assertSourceHas('<strong>ricca</strong>')
                ->pause(1500)
                ->screenshot('05-view-rendered-markdown');
        });

        $this->assertSame(
            "# Scheda tecnica\n\nDescrizione **ricca** del prodotto.",
            $product->refresh()->descrizione_estesa,
        );
    }

    public function test_view_page_shows_only_the_placeholder_when_description_is_empty(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin',
            'email' => AdminUserSeeder::DEFAULT_EMAIL,
            'password' => Hash::make(AdminUserSeeder::DEFAULT_PASSWORD),
        ]);

        $product = Product::factory()->create(['descrizione_estesa' => null]);

        $this->browse(function (Browser $browser) use ($admin, $product) {
            $browser->visit('/admin/login')
                ->waitFor('#form\\.email')
                ->type('#form\\.email', $admin->email)
                ->type('#form\\.password', AdminUserSeeder::DEFAULT_PASSWORD)
                ->click('button[type="submit"]')
                ->waitForLocation('/admin');

            $browser->visit('/admin/products/'.$product->id)
                ->waitForText('Descrizione estesa')
                ->assertSee('—')
                ->assertDontSee('Scheda tecnica')
                ->pause(400)
                ->screenshot('06-view-empty-placeholder');
        });
    }
}
