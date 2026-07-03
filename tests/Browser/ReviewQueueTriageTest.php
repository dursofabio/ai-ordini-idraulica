<?php

namespace Tests\Browser;

use App\Models\Brand;
use App\Models\Family;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Subfamily;
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
        $aiSubfamily = Subfamily::factory()->create(['name' => 'Sottofamiglia AI', 'family_id' => $aiFamily->id]);
        $correctedBrand = Brand::factory()->create(['name' => 'Marca Corretta']);
        $correctedFamily = Family::factory()->create(['name' => 'Famiglia Corretta']);

        // US-035: this product carries a proposed subfamily plus a deduced
        // technical attribute, so the new "Sottofamiglia proposta (AI)" and
        // "Attributi tecnici" columns have real content to show on screen.
        // US-036: it also carries the raw file-imported taxonomy plus
        // costo/giacenza, so the additional read-only columns have real
        // content to show on screen.
        $toConfirm = Product::factory()->create([
            'codice_articolo' => 'CONFIRM-001',
            'description_raw' => 'Valvola a sfera 1 pollice da confermare',
            'descrizione_marca' => 'Marca da file SPA',
            'fam_descrizione' => 'Famiglia da file',
            'subfam_descrizione' => 'Sottofamiglia da file',
            'costo' => 42.5,
            'giacenza' => 17,
            'enrichment_status' => 'needs_review',
            'brand_id' => $aiBrand->id,
            'family_id' => $aiFamily->id,
            'subfamily_id' => $aiSubfamily->id,
            'brand_source' => 'ai',
            'family_source' => 'ai',
            'subfamily_source' => 'ai',
            'source' => 'ai',
            'confidence' => 70,
        ]);

        ProductAttribute::factory()->create([
            'product_id' => $toConfirm->id,
            'key' => 'kW',
            'value_num' => 1.5,
            'value_text' => null,
            'unit' => 'kW',
            'source' => 'regex',
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

        // US-035: null confidence, so the "N/D" confidence badge rendering is
        // visible in the queue. `discard()` always nulls the confidence
        // anyway, so this doesn't change the outcome of the discard flow.
        $toDiscard = Product::factory()->create([
            'codice_articolo' => 'DISCARD-003',
            'description_raw' => 'Tubo flessibile da scartare',
            'enrichment_status' => 'needs_review',
            'brand_id' => $aiBrand->id,
            'family_id' => $aiFamily->id,
            'brand_source' => 'ai',
            'family_source' => 'ai',
            'source' => 'ai',
            'confidence' => null,
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

            // 2b. (US-035) The admin also inspects the enriched columns:
            // the proposed subfamily and its origin, the deduced technical
            // attributes, and the "N/D" badge for the product with no
            // confidence score yet.
            $browser->waitForText('Sottofamiglia AI')
                ->assertSee('Sottofamiglia AI')
                ->assertSee('Origine: AI')
                ->assertSee('kW')
                ->assertSee('Dedotta')
                ->assertSee('N/D')
                ->pause(400)
                ->screenshot('03b-queue-details');

            // 2c. (US-036) The admin also sees the additional read-only
            // columns: codice articolo, the raw file-imported taxonomy
            // (marca/famiglia/sottofamiglia "da file") next to the AI
            // proposals, and costo/giacenza.
            $browser->assertSee($toConfirm->codice_articolo)
                ->assertSee('Marca da file SPA')
                ->assertSee('Famiglia da file')
                ->assertSee('Sottofamiglia da file')
                ->pause(400)
                ->screenshot('03c-queue-additional-columns');

            // 2d. (US-036) The admin orders the queue by clicking the
            // "Confidenza" header, which becomes marked as actively sorted.
            $browser->click('.fi-ta-header-cell-sort-btn[aria-label="Confidenza"]')
                ->pause(500)
                ->assertPresent('th.fi-ta-header-cell-sorted')
                ->screenshot('03d-queue-sorted-by-confidence');

            // 2e. (US-036) The admin filters the queue by confidence band
            // ("Bassa (<60)"): only the product with confidence 50 remains
            // visible, the others are hidden.
            $browser->click('.fi-ta-filters-dropdown button[aria-label="Filter"]')
                ->pause(400)
                ->waitFor('#tableFiltersForm\.confidence_band\.value')
                ->select('#tableFiltersForm\.confidence_band\.value', 'bassa')
                ->pause(300)
                ->press('Apply filters')
                ->pause(600)
                ->waitUntilMissingText($toConfirm->description_raw)
                ->assertSee($toCorrect->description_raw)
                ->assertDontSee($toConfirm->description_raw)
                ->assertDontSee($toDiscard->description_raw)
                // Closes the filters dropdown panel (via the non-sortable
                // "Codice articolo" header cell, an uninvolved element safely
                // below the sticky topbar) so the resulting filtered table is
                // fully visible in the screenshot instead of being covered by
                // the still-open panel.
                ->click('.fi-ta-header-cell-codice-articolo')
                ->pause(400)
                ->screenshot('03e-queue-filtered-by-confidence-band');

            // The confidence-band filter is reset (via its active-filter
            // badge) before continuing with the triage actions below, which
            // act on all three seeded products.
            $browser->click('button[wire\\:click="removeTableFilter(\'confidence_band\')"]')
                ->pause(600)
                ->waitForText($toConfirm->description_raw)
                ->assertSee($toConfirm->description_raw)
                ->assertSee($toCorrect->description_raw)
                ->assertSee($toDiscard->description_raw);

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

            $browser->waitFor('.fi-fo-select-wrp:has(label[for="mountedActionSchema0.brand_id"])')
                ->click('.fi-fo-select-wrp:has(label[for="mountedActionSchema0.brand_id"]) .fi-select-input-btn')
                ->pause(400)
                ->type('.fi-fo-select-wrp:has(label[for="mountedActionSchema0.brand_id"]) .fi-select-input-search-ctn input', 'Marca Corretta')
                ->pause(1500)
                ->screenshot('06-correct-brand-search');

            $browser->click('.fi-fo-select-wrp:has(label[for="mountedActionSchema0.brand_id"]) li.fi-select-input-option')
                ->pause(400)
                ->assertSee('Marca Corretta')
                ->screenshot('07-correct-brand-selected');

            $browser->click('.fi-fo-select-wrp:has(label[for="mountedActionSchema0.family_id"]) .fi-select-input-btn')
                ->pause(400)
                ->type('.fi-fo-select-wrp:has(label[for="mountedActionSchema0.family_id"]) .fi-select-input-search-ctn input', 'Famiglia Corretta')
                ->pause(1500)
                ->click('.fi-fo-select-wrp:has(label[for="mountedActionSchema0.family_id"]) li.fi-select-input-option')
                ->pause(400)
                ->assertSee('Famiglia Corretta')
                ->screenshot('08-correct-family-selected');

            // Scoped to the open modal: the table still has other rows with
            // their own "Correggi" link underneath the modal overlay, so an
            // unscoped press() could match the wrong (background) button.
            // The modal's own submit button uses Filament's default "Submit"
            // label (the action's own "Correggi" label is only used for the
            // modal heading and the row trigger, not the submit button).
            $browser->with('.fi-modal-open', function (Browser $modal) {
                $modal->press('Submit');
            });

            $browser->pause(800)
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

            // Scoped to the open modal for the same reason as the Correggi
            // submit above: the remaining row still shows its own "Scarta"
            // link underneath the confirmation overlay. A confirmation-only
            // action (no form) uses Filament's default "Confirm" label for
            // its button, not the action's own "Scarta" label.
            $browser->with('.fi-modal-open', function (Browser $modal) {
                $modal->press('Confirm');
            });

            $browser->pause(800)
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
