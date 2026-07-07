<?php

namespace Tests\Browser;

use App\Models\AttributeDefinition;
use App\Models\Brand;
use App\Models\EnrichmentProposal;
use App\Models\Family;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Hash;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * US-041 demo scenario (spec "Dimostra"): the "Da revisionare" review queue
 * now lists individual pending {@see EnrichmentProposal} rows (US-040
 * register) instead of whole products, so a product with several pending
 * proposals appears once per proposal. An admin opens the queue, confirms one
 * proposal, corrects another, and discards a third — and, crucially, a
 * product carrying two pending proposals keeps showing its second proposal
 * once the first one is resolved, proving the proposals are triaged
 * independently of each other.
 *
 * US-044 extends this same scenario with the "Nuova chiave attributo"
 * proposal type: the admin approves one after checking the existing
 * registry keys suggested as similar, and discards a second one that turns
 * out to be a near-duplicate of an already registered key.
 *
 * Per-step screenshots are stored in docs/test-results/US-041/ as the visual
 * artifact of the run (Dusk does not record video, same convention as
 * US-023's original version of this test).
 */
class ReviewQueueTriageTest extends DuskTestCase
{
    use DatabaseMigrations;

    protected const ARTIFACT_DIR = __DIR__.'/../../docs/test-results/US-041';

    public function test_admin_triages_individual_proposals_and_a_products_other_pending_proposal_survives(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin',
            'email' => AdminUserSeeder::DEFAULT_EMAIL,
            'password' => Hash::make(AdminUserSeeder::DEFAULT_PASSWORD),
        ]);

        $aiBrand = Brand::factory()->create(['name' => 'Marca AI']);
        $aiFamily = Family::factory()->create(['name' => 'Famiglia AI']);
        $correctedFamily = Family::factory()->create(['name' => 'Famiglia Corretta']);

        // Single pending BRAND proposal: confirmed as-is below.
        $toConfirm = Product::factory()->create([
            'codice_articolo' => 'CONFIRM-001',
            'description_raw' => 'Valvola a sfera 1 pollice da confermare',
        ]);
        $brandProposal = EnrichmentProposal::factory()->create([
            'product_id' => $toConfirm->id,
            'field' => 'brand',
            'attribute_key' => null,
            'value_id' => $aiBrand->id,
            'unit' => null,
            'origin' => 'ai',
            'confidence' => 70,
            'status' => 'pending',
        ]);

        // US-041 cardinal scenario: this product carries TWO pending
        // proposals (a family taxonomy proposal and a technical attribute
        // proposal). Only the family one is corrected below — the attribute
        // one must remain visible in the queue afterwards, proving proposals
        // are triaged independently rather than the whole product being
        // resolved at once.
        $toCorrect = Product::factory()->create([
            'codice_articolo' => 'CORRECT-002',
            'description_raw' => 'Raccordo a T da correggere, con due proposte in sospeso',
        ]);
        $familyProposal = EnrichmentProposal::factory()->create([
            'product_id' => $toCorrect->id,
            'field' => 'family',
            'attribute_key' => null,
            'value_id' => $aiFamily->id,
            'unit' => null,
            'origin' => 'ai',
            'confidence' => 50,
            'status' => 'pending',
        ]);
        $attributeProposalOnToCorrect = EnrichmentProposal::factory()->create([
            'product_id' => $toCorrect->id,
            'field' => 'attribute',
            'attribute_key' => 'kW',
            'value_id' => null,
            'value' => '1.5',
            'unit' => 'kW',
            'origin' => 'regex',
            'confidence' => 40,
            'status' => 'pending',
        ]);

        // Single pending ATTRIBUTE proposal: discarded below.
        $toDiscard = Product::factory()->create([
            'codice_articolo' => 'DISCARD-003',
            'description_raw' => 'Tubo flessibile da scartare',
        ]);
        $discardProposal = EnrichmentProposal::factory()->create([
            'product_id' => $toDiscard->id,
            'field' => 'attribute',
            'attribute_key' => 'Materiale',
            'value_id' => null,
            'value' => 'Ottone',
            'unit' => null,
            'origin' => 'dictionary',
            'confidence' => 20,
            'status' => 'pending',
        ]);

        // The four products for the bulk triage steps are created by
        // reference *inside* the closure (after the single-record flow
        // asserts the queue is down to the one surviving proposal), so they
        // don't inflate the initial "4 articoli da revisionare" assertion.
        /** @var EnrichmentProposal|null $bulkConfirmFirstProposal */
        $bulkConfirmFirstProposal = null;
        /** @var EnrichmentProposal|null $bulkConfirmSecondProposal */
        $bulkConfirmSecondProposal = null;
        /** @var EnrichmentProposal|null $bulkDiscardFirstProposal */
        $bulkDiscardFirstProposal = null;
        /** @var EnrichmentProposal|null $bulkDiscardSecondProposal */
        $bulkDiscardSecondProposal = null;

        // US-044: same reasoning as the bulk products above — created by
        // reference inside the closure so they don't inflate the earlier
        // queue-count assertions.
        /** @var EnrichmentProposal|null $definitionApproveProposal */
        $definitionApproveProposal = null;
        /** @var EnrichmentProposal|null $definitionDiscardProposal */
        $definitionDiscardProposal = null;

        $this->browse(function (Browser $browser) use (
            $admin,
            $toConfirm,
            $toCorrect,
            $toDiscard,
            $aiBrand,
            $brandProposal,
            $familyProposal,
            $attributeProposalOnToCorrect,
            $discardProposal,
            &$bulkConfirmFirstProposal,
            &$bulkConfirmSecondProposal,
            &$bulkDiscardFirstProposal,
            &$bulkDiscardSecondProposal,
            &$definitionApproveProposal,
            &$definitionDiscardProposal,
        ) {
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
            // the queue counter (4 pending proposals across 3 products, since
            // $toCorrect carries two of them) and the proposal rows.
            $browser->visit('/admin/review-queue')
                ->waitForText('articoli da revisionare')
                ->assertSee('4 articoli da revisionare')
                ->assertSee($toConfirm->description_raw)
                ->assertSee($toCorrect->description_raw)
                ->assertSee($toDiscard->description_raw)
                ->assertSee('Famiglia')
                ->assertSee('Attributo: kW')
                ->pause(400)
                ->screenshot('03-queue-before');

            // 3. The admin confirms the brand proposal for the first product;
            // its row disappears from the queue and the counter decreases.
            $browser->with($this->rowSelector($brandProposal), function (Browser $row) {
                $row->press('Conferma');
            });

            $browser->pause(800)
                ->waitUntilMissing($this->rowSelector($brandProposal))
                ->assertSee('3 articoli da revisionare')
                ->screenshot('04-after-confirm');

            // 4. The admin opens "Correggi" on the FAMILY proposal of the
            // two-proposal product and picks a different family.
            $browser->with($this->rowSelector($familyProposal), function (Browser $row) {
                $row->press('Correggi');
            });

            $browser->pause(500)
                ->waitForText('Correggi')
                ->screenshot('05-correct-modal-open');

            $browser->waitFor('.fi-fo-select-wrp:has(label[for="mountedActionSchema0.value_id"])')
                ->click('.fi-fo-select-wrp:has(label[for="mountedActionSchema0.value_id"]) .fi-select-input-btn')
                ->pause(400)
                ->type('.fi-fo-select-wrp:has(label[for="mountedActionSchema0.value_id"]) .fi-select-input-search-ctn input', 'Famiglia Corretta')
                ->pause(1500)
                ->screenshot('06-correct-family-search');

            $browser->click('.fi-fo-select-wrp:has(label[for="mountedActionSchema0.value_id"]) li.fi-select-input-option')
                ->pause(400)
                ->assertSee('Famiglia Corretta')
                ->screenshot('07-correct-family-selected');

            // Scoped to the open modal: the table still has other rows with
            // their own "Correggi" link underneath the modal overlay, so an
            // unscoped press() could match the wrong (background) button.
            $browser->with('.fi-modal-open', function (Browser $modal) {
                $modal->press('Submit');
            });

            // 5. The family proposal's row disappears, but the attribute
            // proposal for the SAME product must still be visible: proposals
            // are triaged independently, so resolving one never removes the
            // product's other pending proposals wholesale.
            $browser->pause(800)
                ->waitUntilMissing($this->rowSelector($familyProposal))
                ->assertSee('2 articoli da revisionare')
                ->assertPresent($this->rowSelector($attributeProposalOnToCorrect))
                ->assertSee($toCorrect->description_raw)
                ->assertSee('Attributo: kW')
                ->screenshot('08-after-correct-other-proposal-still-visible');

            // 6. The admin discards the attribute proposal on the third
            // product; a confirmation modal appears before the discard is
            // applied, and — unlike confirm/correct — the product itself is
            // never touched, since the pending value was never applied.
            $browser->with($this->rowSelector($discardProposal), function (Browser $row) {
                $row->press('Scarta');
            });

            $browser->pause(500)
                ->waitFor('.fi-modal-open')
                ->screenshot('09-discard-confirmation-modal');

            $browser->with('.fi-modal-open', function (Browser $modal) {
                $modal->press('Confirm');
            });

            // The one remaining pending proposal (the attribute proposal on
            // $toCorrect) is still visible: it was never touched by any of
            // the three actions above, so the queue count settles back to 1.
            $browser->pause(800)
                ->waitUntilMissing($this->rowSelector($discardProposal))
                ->assertSee('1 articoli da revisionare')
                ->assertPresent($this->rowSelector($attributeProposalOnToCorrect))
                ->screenshot('10-after-discard');

            // 7. (bulk coverage) Two more products enter the queue for the
            // bulk "Conferma selezionati" step and two more for the bulk
            // "Scarta selezionati" step below.
            $bulkConfirmFirstProduct = Product::factory()->create([
                'codice_articolo' => 'BULK-CONFIRM-004',
                'description_raw' => 'Miscelatore da confermare in blocco (1 di 2)',
            ]);
            $bulkConfirmFirstProposal = EnrichmentProposal::factory()->create([
                'product_id' => $bulkConfirmFirstProduct->id,
                'field' => 'brand',
                'attribute_key' => null,
                'value_id' => $aiBrand->id,
                'unit' => null,
                'origin' => 'ai',
                'confidence' => 65,
                'status' => 'pending',
            ]);
            $bulkConfirmSecondProduct = Product::factory()->create([
                'codice_articolo' => 'BULK-CONFIRM-005',
                'description_raw' => 'Miscelatore da confermare in blocco (2 di 2)',
            ]);
            $bulkConfirmSecondProposal = EnrichmentProposal::factory()->create([
                'product_id' => $bulkConfirmSecondProduct->id,
                'field' => 'brand',
                'attribute_key' => null,
                'value_id' => $aiBrand->id,
                'unit' => null,
                'origin' => 'file',
                'confidence' => 68,
                'status' => 'pending',
            ]);
            $bulkDiscardFirstProduct = Product::factory()->create([
                'codice_articolo' => 'BULK-DISCARD-006',
                'description_raw' => 'Rubinetto da scartare in blocco (1 di 2)',
            ]);
            $bulkDiscardFirstProposal = EnrichmentProposal::factory()->create([
                'product_id' => $bulkDiscardFirstProduct->id,
                'field' => 'attribute',
                'attribute_key' => 'DN',
                'value_id' => null,
                'value' => '25',
                'unit' => 'mm',
                'origin' => 'regex',
                'confidence' => 25,
                'status' => 'pending',
            ]);
            $bulkDiscardSecondProduct = Product::factory()->create([
                'codice_articolo' => 'BULK-DISCARD-007',
                'description_raw' => 'Rubinetto da scartare in blocco (2 di 2)',
            ]);
            $bulkDiscardSecondProposal = EnrichmentProposal::factory()->create([
                'product_id' => $bulkDiscardSecondProduct->id,
                'field' => 'attribute',
                'attribute_key' => 'DN',
                'value_id' => null,
                'value' => '32',
                'unit' => 'mm',
                'origin' => 'regex',
                'confidence' => 35,
                'status' => 'pending',
            ]);

            $browser->visit('/admin/review-queue')
                ->waitForText('articoli da revisionare')
                ->assertSee('5 articoli da revisionare')
                ->assertSee($bulkConfirmFirstProduct->description_raw)
                ->assertSee($bulkConfirmSecondProduct->description_raw)
                ->assertSee($bulkDiscardFirstProduct->description_raw)
                ->assertSee($bulkDiscardSecondProduct->description_raw)
                ->pause(400)
                ->screenshot('11-bulk-queue-before');

            // 8. The admin selects the two brand proposals via their row
            // checkboxes; the bulk actions become visible in the toolbar as
            // soon as a row is selected.
            $browser->with($this->rowSelector($bulkConfirmFirstProposal), function (Browser $row) {
                $row->click('input.fi-ta-record-checkbox');
            });

            $browser->pause(300);

            $browser->with($this->rowSelector($bulkConfirmSecondProposal), function (Browser $row) {
                $row->click('input.fi-ta-record-checkbox');
            });

            $browser->pause(400)
                ->assertSee('Conferma selezionati')
                ->screenshot('12-bulk-confirm-rows-selected');

            // 9. Clicking "Conferma selezionati" applies the confirm logic to
            // both selected proposals in one go. The toolbar bulk-action
            // buttons stick right below the fixed topbar, so WebDriver's
            // native click sometimes reports them as obscured by it; a
            // direct DOM click sidesteps that false-positive interception.
            $this->clickButtonByLabel($browser, 'Conferma selezionati');

            $browser->pause(800)
                ->waitUntilMissing($this->rowSelector($bulkConfirmFirstProposal))
                ->assertMissing($this->rowSelector($bulkConfirmSecondProposal))
                ->assertSee('3 articoli da revisionare')
                ->screenshot('13-after-bulk-confirm');

            // 10. The admin selects the remaining two attribute proposals and
            // uses "Scarta selezionati", which shows a single confirmation
            // modal for the whole selection (not one per record).
            $browser->with($this->rowSelector($bulkDiscardFirstProposal), function (Browser $row) {
                $row->click('input.fi-ta-record-checkbox');
            });

            $browser->pause(300);

            $browser->with($this->rowSelector($bulkDiscardSecondProposal), function (Browser $row) {
                $row->click('input.fi-ta-record-checkbox');
            });

            $browser->pause(400)
                ->assertSee('Scarta selezionati')
                ->screenshot('14-bulk-discard-rows-selected');

            $this->clickButtonByLabel($browser, 'Scarta selezionati');

            $browser->pause(500)
                ->waitFor('.fi-modal-open')
                ->screenshot('15-bulk-discard-confirmation-modal');

            $browser->with('.fi-modal-open', function (Browser $modal) {
                $modal->press('Confirm');
            });

            // The queue settles back down to exactly the one proposal that
            // was never touched throughout the whole scenario: the attribute
            // proposal that survived the "Correggi" of its sibling family
            // proposal on the same product.
            $browser->pause(800)
                ->waitUntilMissing($this->rowSelector($bulkDiscardFirstProposal))
                ->assertMissing($this->rowSelector($bulkDiscardSecondProposal))
                ->assertSee('1 articoli da revisionare')
                ->assertPresent($this->rowSelector($attributeProposalOnToCorrect))
                ->pause(1500)
                ->screenshot('16-after-bulk-discard');

            // 11. US-044: an already-registered key ('portata_l_min') is
            // seeded so the "similar keys" suggestion has something genuine
            // to surface, then two more products enter the queue carrying a
            // "Nuova chiave attributo" proposal each — one to approve, one
            // to discard as a near-duplicate.
            AttributeDefinition::factory()->create([
                'key' => 'portata_l_min',
                'data_type' => 'numeric',
                'canonical_unit' => 'l/min',
                'description' => 'Portata nominale',
            ]);

            $definitionApproveProduct = Product::factory()->create([
                'codice_articolo' => 'DEF-APPROVE-008',
                'description_raw' => 'Miscelatore con nuova chiave attributo da approvare',
            ]);
            $definitionApproveProposal = EnrichmentProposal::factory()->attributeDefinition()->create([
                'product_id' => $definitionApproveProduct->id,
                'attribute_key' => 'pressione_bar',
                'data_type' => 'numeric',
                'unit' => 'bar',
                'confidence' => 55,
            ]);

            $definitionDiscardProduct = Product::factory()->create([
                'codice_articolo' => 'DEF-DISCARD-009',
                'description_raw' => 'Rubinetto con chiave attributo duplicata da scartare',
            ]);
            $definitionDiscardProposal = EnrichmentProposal::factory()->attributeDefinition()->create([
                'product_id' => $definitionDiscardProduct->id,
                'attribute_key' => 'portata_lmin',
                'data_type' => 'numeric',
                'unit' => 'l/min',
                'confidence' => 45,
            ]);

            $browser->visit('/admin/review-queue')
                ->waitForText('articoli da revisionare')
                ->assertSee('3 articoli da revisionare')
                ->assertSee('Nuova chiave attributo')
                ->pause(400)
                ->screenshot('17-attribute-definition-queue-before');

            // 12. The admin opens "Correggi" on the genuinely new key: the
            // similar-keys hint has nothing close enough to flag, so the
            // admin fills in a description and approves it.
            $browser->with($this->rowSelector($definitionApproveProposal), function (Browser $row) {
                $row->press('Correggi');
            });

            $browser->pause(500)
                ->waitForText('Correggi')
                ->screenshot('18-attribute-definition-correct-modal-open');

            $browser->waitFor('#mountedActionSchema0\\.value')
                ->type('#mountedActionSchema0\\.value', 'Pressione di esercizio nominale')
                ->pause(400)
                ->screenshot('19-attribute-definition-description-filled');

            $browser->with('.fi-modal-open', function (Browser $modal) {
                $modal->press('Submit');
            });

            $browser->pause(800)
                ->waitUntilMissing($this->rowSelector($definitionApproveProposal))
                ->assertSee('2 articoli da revisionare')
                ->screenshot('20-after-attribute-definition-approve');

            // 13. The admin opens the second proposal: its key
            // ('portata_lmin') is a near-duplicate of the already registered
            // 'portata_l_min', surfaced by the similar-keys hint, so the
            // admin discards it instead of approving a fragmenting variant.
            $browser->with($this->rowSelector($definitionDiscardProposal), function (Browser $row) {
                $row->press('Correggi');
            });

            $browser->pause(500)
                ->waitForText('Correggi')
                ->assertSee('portata_l_min')
                ->screenshot('21-attribute-definition-duplicate-suggested');

            $browser->with('.fi-modal-open', function (Browser $modal) {
                $modal->press('Cancel');
            });

            $browser->pause(400);

            $browser->with($this->rowSelector($definitionDiscardProposal), function (Browser $row) {
                $row->press('Scarta');
            });

            $browser->pause(500)
                ->waitFor('.fi-modal-open')
                ->screenshot('22-attribute-definition-discard-confirmation');

            $browser->with('.fi-modal-open', function (Browser $modal) {
                $modal->press('Confirm');
            });

            $browser->pause(800)
                ->waitUntilMissing($this->rowSelector($definitionDiscardProposal))
                ->assertSee('1 articoli da revisionare')
                ->assertPresent($this->rowSelector($attributeProposalOnToCorrect))
                ->pause(1500)
                ->screenshot('23-after-attribute-definition-discard');
        });

        $toConfirm->refresh();
        $brandProposal->refresh();

        $this->assertSame($aiBrand->id, $toConfirm->brand_id);
        $this->assertSame('ai', $toConfirm->brand_source);
        $this->assertSame('applied', $brandProposal->status);

        $toCorrect->refresh();
        $familyProposal->refresh();
        $attributeProposalOnToCorrect->refresh();

        $this->assertSame($correctedFamily->id, $toCorrect->family_id);
        $this->assertSame('manual', $toCorrect->family_source);
        $this->assertSame('applied', $familyProposal->status);

        // The attribute proposal on the same product was never confirmed,
        // corrected, or discarded, so it is still pending and its value was
        // never written to the product.
        $this->assertSame('pending', $attributeProposalOnToCorrect->status);
        $this->assertSame(0, $toCorrect->attributes()->count());

        $toDiscard->refresh();
        $discardProposal->refresh();

        $this->assertSame('discarded', $discardProposal->status);
        $this->assertNull($toDiscard->brand_id);
        $this->assertNull($toDiscard->family_id);
        $this->assertSame(0, $toDiscard->attributes()->count());

        // Assigned by reference inside the `browse()` closure above (not
        // before it, so their creation doesn't inflate the earlier "N
        // articoli da revisionare" assertions) — asserted non-null here so
        // static analysis can narrow them from their initial `null` type
        // before the property/method access below.
        $this->assertNotNull($bulkConfirmFirstProposal);
        $this->assertNotNull($bulkConfirmSecondProposal);
        $this->assertNotNull($bulkDiscardFirstProposal);
        $this->assertNotNull($bulkDiscardSecondProposal);

        $bulkConfirmFirstProposal->refresh();
        $bulkConfirmSecondProposal->refresh();

        $this->assertSame('applied', $bulkConfirmFirstProposal->status);
        $this->assertSame($aiBrand->id, $bulkConfirmFirstProposal->product->brand_id);
        $this->assertSame('ai', $bulkConfirmFirstProposal->product->brand_source);

        $this->assertSame('applied', $bulkConfirmSecondProposal->status);
        $this->assertSame($aiBrand->id, $bulkConfirmSecondProposal->product->brand_id);
        $this->assertSame('file', $bulkConfirmSecondProposal->product->brand_source);

        $bulkDiscardFirstProposal->refresh();
        $bulkDiscardSecondProposal->refresh();

        $this->assertSame('discarded', $bulkDiscardFirstProposal->status);
        $this->assertSame(0, $bulkDiscardFirstProposal->product->attributes()->count());

        $this->assertSame('discarded', $bulkDiscardSecondProposal->status);
        $this->assertSame(0, $bulkDiscardSecondProposal->product->attributes()->count());

        // US-044: the approved definition proposal registered its key with
        // the admin-filled description; the discarded one never touched the
        // registry, so the near-duplicate key was never added.
        $this->assertNotNull($definitionApproveProposal);
        $this->assertNotNull($definitionDiscardProposal);

        $definitionApproveProposal->refresh();
        $definitionDiscardProposal->refresh();

        $this->assertSame('applied', $definitionApproveProposal->status);
        $this->assertDatabaseHas('attribute_definitions', [
            'key' => 'pressione_bar',
            'data_type' => 'numeric',
            'canonical_unit' => 'bar',
            'description' => 'Pressione di esercizio nominale',
        ]);

        $this->assertSame('discarded', $definitionDiscardProposal->status);
        $this->assertDatabaseMissing('attribute_definitions', ['key' => 'portata_lmin']);

        // Sanity check: across the whole scenario, exactly one proposal is
        // still pending — the attribute proposal that survived its sibling
        // being corrected.
        $this->assertSame(1, EnrichmentProposal::query()->where('status', 'pending')->count());
        $this->assertSame($attributeProposalOnToCorrect->id, EnrichmentProposal::query()->where('status', 'pending')->first()->id);
    }

    /**
     * Builds a CSS selector for the table row of the given proposal, so each
     * action button click is scoped to the correct record instead of
     * matching the first "Conferma"/"Correggi"/"Scarta" button on the page.
     * Filament keys every table row with a `wire:key` ending in
     * `table.records.{recordKey}` — under US-041 the table's record is the
     * {@see EnrichmentProposal}, not the product, so this is now keyed by the
     * proposal's own id (Dusk's resolver only supports CSS selectors, not
     * XPath).
     */
    private function rowSelector(EnrichmentProposal $proposal): string
    {
        return 'tr[wire\\:key$=".table.records.'.$proposal->getKey().'"]';
    }

    /**
     * Clicks a button by its exact visible label via a direct DOM `.click()`
     * call instead of Dusk's native `->press()`. Used for the sticky toolbar
     * bulk-action buttons, which WebDriver can report as obscured by the
     * app's fixed topbar even though they are visually reachable. A DOM
     * `.click()` still dispatches a real "click" event, which is all
     * Alpine's `x-on:click="mountAction(...)"` listens for.
     */
    private function clickButtonByLabel(Browser $browser, string $label): void
    {
        $browser->script(sprintf(
            <<<'JS'
            Array.from(document.querySelectorAll('button')).find(
                (button) => button.textContent.trim() === %s
            )?.click();
            JS,
            json_encode($label)
        ));
    }
}
