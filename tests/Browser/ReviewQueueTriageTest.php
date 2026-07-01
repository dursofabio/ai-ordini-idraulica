<?php

namespace Tests\Browser;

use App\Models\Brand;
use App\Models\Family;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Hash;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * US-023 demo scenario (spec "Dimostra"):
 * an admin opens the "Da revisionare" review queue and sees the products
 * with `enrichment_status='needs_review'` ordered by ascending confidence;
 * clicking "Conferma" promotes the AI proposal and the record disappears
 * from the queue; clicking "Correggi" opens an inline form to enter
 * corrected values; clicking "Scarta" (after confirming) clears the AI
 * proposal and keeps the record in the queue for future re-processing.
 *
 * Per-step screenshots are stored in docs/test-results/US-023/ as the visual
 * artifact of the run (Dusk does not record video). Actions are paced with
 * explicit visibility assertions and short holds so the artifacts are
 * readable by a non-technical reviewer.
 */
class ReviewQueueTriageTest extends DuskTestCase
{
    use DatabaseMigrations;

    protected const ARTIFACT_DIR = __DIR__.'/../../docs/test-results/US-023';

    public function test_admin_triages_the_review_queue_via_confirm_correct_and_discard(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin',
            'email' => AdminUserSeeder::DEFAULT_EMAIL,
            'password' => Hash::make(AdminUserSeeder::DEFAULT_PASSWORD),
        ]);

        $aiBrand = Brand::factory()->create(['name' => 'Marca AI']);
        $aiFamily = Family::factory()->create(['name' => 'Famiglia AI']);
        $correctedBrand = Brand::factory()->create(['name' => 'Marca Corretta']);
        $correctedFamily = Family::factory()->create(['name' => 'Famiglia Corretta']);

        $toConfirm = Product::factory()->create([
            'codice_articolo' => 'CONFIRM-001',
            'description_raw' => 'Valvola a sfera 1 pollice da confermare',
            'enrichment_status' => 'needs_review',
            'brand_id' => $aiBrand->id,
            'family_id' => $aiFamily->id,
            'brand_source' => 'ai',
            'family_source' => 'ai',
            'source' => 'ai',
            'confidence' => 70,
        ]);

        $toCorrect = Product::factory()->create([
            'codice_articolo' => 'CORRECT-002',
            'description_raw' => 'Raccordo a T da correggere',
            'enrichment_status' => 'needs_review',
            'brand_id' => $aiBrand->id,
            'family_id' => $aiFamily->id,
            'brand_source' => 'ai',
            'family_source' => 'ai',
            'source' => 'ai',
            'confidence' => 50,
        ]);

        $toDiscard = Product::factory()->create([
            'codice_articolo' => 'DISCARD-003',
            'description_raw' => 'Tubo flessibile da scartare',
            'enrichment_status' => 'needs_review',
            'brand_id' => $aiBrand->id,
            'family_id' => $aiFamily->id,
            'brand_source' => 'ai',
            'family_source' => 'ai',
            'source' => 'ai',
            'confidence' => 30,
        ]);

        $this->browse(function (Browser $browser) use ($admin, $toConfirm, $toCorrect, $toDiscard) {
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

            // 2. The admin opens the "Da revisionare" review queue and sees
            // the queue counter and the three products awaiting triage.
            $browser->visit('/admin/review-queue')
                ->waitForText('articoli da revisionare')
                ->assertSee('3 articoli da revisionare')
                ->assertSee($toConfirm->description_raw)
                ->assertSee($toCorrect->description_raw)
                ->assertSee($toDiscard->description_raw)
                ->pause(400)
                ->screenshot('03-queue-before');

            // 3. The admin confirms the AI proposal for the first product;
            // it disappears from the queue and the counter decreases.
            $browser->with($this->rowSelector($toConfirm), function (Browser $row) {
                $row->press('Conferma');
            });

            $browser->pause(800)
                ->waitUntilMissingText($toConfirm->description_raw)
                ->assertSee('2 articoli da revisionare')
                ->screenshot('04-after-confirm');

            // 4. The admin opens "Correggi" on the second product, picks a
            // corrected brand and family from the inline form, and saves.
            $browser->with($this->rowSelector($toCorrect), function (Browser $row) {
                $row->press('Correggi');
            });

            $browser->pause(500)
                ->waitForText('Correggi')
                ->screenshot('05-correct-modal-open');

            $browser->waitFor('.fi-fo-select-wrp:has(label[for="mountedActions.0.data.brand_id"])')
                ->click('.fi-fo-select-wrp:has(label[for="mountedActions.0.data.brand_id"]) .fi-select-input-btn')
                ->pause(400)
                ->type('.fi-fo-select-wrp:has(label[for="mountedActions.0.data.brand_id"]) .fi-select-input-search-ctn input', 'Marca Corretta')
                ->pause(800)
                ->screenshot('06-correct-brand-search');

            $browser->click('.fi-fo-select-wrp:has(label[for="mountedActions.0.data.brand_id"]) li.fi-select-input-option')
                ->pause(400)
                ->assertSee('Marca Corretta')
                ->screenshot('07-correct-brand-selected');

            $browser->click('.fi-fo-select-wrp:has(label[for="mountedActions.0.data.family_id"]) .fi-select-input-btn')
                ->pause(400)
                ->type('.fi-fo-select-wrp:has(label[for="mountedActions.0.data.family_id"]) .fi-select-input-search-ctn input', 'Famiglia Corretta')
                ->pause(800)
                ->click('.fi-fo-select-wrp:has(label[for="mountedActions.0.data.family_id"]) li.fi-select-input-option')
                ->pause(400)
                ->assertSee('Famiglia Corretta')
                ->screenshot('08-correct-family-selected');

            $browser->press('Correggi')
                ->pause(800)
                ->waitUntilMissingText($toCorrect->description_raw)
                ->assertSee('1 articoli da revisionare')
                ->screenshot('09-after-correct');

            // 5. The admin discards the AI proposal on the third product;
            // a confirmation modal appears before the discard is applied,
            // and the record stays in the queue (still needs_review).
            $browser->with($this->rowSelector($toDiscard), function (Browser $row) {
                $row->press('Scarta');
            });

            $browser->pause(500)
                ->waitForText('Scarta')
                ->screenshot('10-discard-confirmation-modal');

            $browser->press('Scarta')
                ->pause(800)
                ->assertSee($toDiscard->description_raw)
                ->assertSee('1 articoli da revisionare')
                ->pause(1500)
                ->screenshot('11-after-discard');
        });

        $toConfirm->refresh();
        $toCorrect->refresh();
        $toDiscard->refresh();

        $this->assertSame('enriched', $toConfirm->enrichment_status);

        $this->assertSame($correctedBrand->id, $toCorrect->brand_id);
        $this->assertSame($correctedFamily->id, $toCorrect->family_id);
        $this->assertSame('manual', $toCorrect->brand_source);
        $this->assertSame('manual', $toCorrect->family_source);
        $this->assertSame('enriched', $toCorrect->enrichment_status);

        $this->assertNull($toDiscard->brand_id);
        $this->assertNull($toDiscard->family_id);
        $this->assertSame('needs_review', $toDiscard->enrichment_status);
    }

    /**
     * Builds a CSS selector for the table row of the given product, so each
     * action button click is scoped to the correct record instead of
     * matching the first "Conferma"/"Correggi"/"Scarta" button on the page.
     * Filament keys every table row with a `wire:key` ending in
     * `table.records.{recordKey}`, which is a stable, unique attribute to
     * scope on (Dusk's resolver only supports CSS selectors, not XPath).
     */
    private function rowSelector(Product $product): string
    {
        return 'tr[wire\\:key$=".table.records.'.$product->getKey().'"]';
    }
}
