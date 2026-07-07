<?php

namespace Tests\Browser;

use App\Models\Brand;
use App\Models\EnrichmentProposal;
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
 * US-038 demo scenario (spec "Dimostra"): from the "Da revisionare" review
 * queue, a per-row "Dettagli" link opens a dedicated page (own URL) showing
 * every available product information (raw file data, AI proposals with
 * their origin, technical attributes, confidence) plus a form to correct
 * brand/family/subfamily and technical attributes. Saving applies the same
 * logic as the existing "Correggi" action and redirects back to the queue.
 *
 * Per-step screenshots are stored in docs/test-results/US-038/ as the visual
 * artifact of the run (Dusk does not record video, same convention as
 * US-023's ReviewQueueTriageTest).
 */
class ReviewQueueDetailPageTest extends DuskTestCase
{
    use DatabaseMigrations;

    protected const ARTIFACT_DIR = __DIR__.'/../../docs/test-results/US-038';

    public function test_admin_opens_the_detail_page_corrects_it_and_is_redirected_to_the_queue(): void
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
        $correctedSubfamily = Subfamily::factory()->create(['name' => 'Sottofamiglia Corretta', 'family_id' => $correctedFamily->id]);

        $product = Product::factory()->create([
            'codice_articolo' => 'DETAIL-001',
            'description_raw' => 'Valvola a sfera 1 pollice da dettagliare',
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

        $attribute = ProductAttribute::factory()->create([
            'product_id' => $product->id,
            'key' => 'kW',
            'value' => '1.5',
            'unit' => 'kW',
            'source' => 'regex',
        ]);

        // US-041: the "Da revisionare" queue now lists individual pending
        // {@see EnrichmentProposal} rows instead of whole products, so a
        // product with no proposal rows at all would no longer appear in the
        // queue. These three pending proposals (matching the AI-sourced
        // brand/family/subfamily already set on the product above) are what
        // makes this product show up — as three rows — before the detail
        // page correction below resolves them all in one save.
        $brandProposal = EnrichmentProposal::factory()->create([
            'product_id' => $product->id,
            'field' => 'brand',
            'value_id' => $aiBrand->id,
            'origin' => 'ai',
            'confidence' => 70,
            'status' => 'pending',
        ]);
        EnrichmentProposal::factory()->create([
            'product_id' => $product->id,
            'field' => 'family',
            'value_id' => $aiFamily->id,
            'origin' => 'ai',
            'confidence' => 70,
            'status' => 'pending',
        ]);
        EnrichmentProposal::factory()->create([
            'product_id' => $product->id,
            'field' => 'subfamily',
            'value_id' => $aiSubfamily->id,
            'origin' => 'ai',
            'confidence' => 70,
            'status' => 'pending',
        ]);

        $this->browse(function (Browser $browser) use (
            $admin,
            $product,
            $attribute,
            $brandProposal,
        ) {
            // 1. The admin logs in to the Filament backoffice.
            $browser->visit('/admin/login')
                ->waitFor('#form\\.email')
                ->type('#form\\.email', $admin->email)
                ->pause(400)
                ->type('#form\\.password', AdminUserSeeder::DEFAULT_PASSWORD)
                ->pause(400)
                ->click('button[type="submit"]')
                ->waitForLocation('/admin')
                ->pause(400)
                ->screenshot('01-dashboard');

            // 2. The admin opens the "Da revisionare" review queue and sees
            // the seeded product awaiting triage.
            $browser->visit('/admin/review-queue')
                ->waitForText('articoli da revisionare')
                ->assertSee('3 articoli da revisionare')
                ->assertSee($product->description_raw)
                ->pause(400)
                ->screenshot('02-queue-before');

            // 3. The admin clicks "Dettagli" on one of the product's three
            // proposal rows (any of them link to the same product detail
            // page): this is a real navigation link to a dedicated URL, not
            // a modal.
            $browser->with($this->rowSelector($brandProposal), function (Browser $row) {
                $row->clickLink('Dettagli');
            });

            $browser->waitForLocation('/admin/review-queue/'.$product->getKey())
                ->assertPathIs('/admin/review-queue/'.$product->getKey())
                ->pause(400)
                ->screenshot('03-detail-page-opened');

            // 4. The admin sees every available piece of information about
            // the product: raw file data, AI proposals with their origin,
            // the technical attribute with its origin, and the confidence.
            $browser->waitForText('DETAIL-001')
                ->assertSee('DETAIL-001')
                ->assertSee($product->description_raw)
                ->assertSee('Marca da file SPA')
                ->assertSee('Famiglia da file')
                ->assertSee('Sottofamiglia da file')
                ->assertSee('Marca AI')
                ->assertSee('Famiglia AI')
                ->assertSee('Sottofamiglia AI')
                ->assertSee('Origine: AI')
                ->assertSee('70%')
                ->assertSee('kW: 1.5 kW')
                ->pause(400)
                ->screenshot('04-detail-page-information');

            // 5. The admin corrects the brand from the editable form.
            //
            // Note: the schema's `id`/`for` attributes are always prefixed
            // with the schema's registration key ("form", from the
            // `form(Schema $schema)` method below), not the `->statePath()`
            // configured inside it ("data") — the latter only drives
            // `wire:model` bindings. Mirrors {@see ReviewQueueTriageTest}'s
            // `mountedActionSchema0.*` convention (also not literally the
            // form's own field name).
            $browser->click('.fi-fo-select-wrp:has(label[for="form.brand_id"]) .fi-select-input-btn')
                ->pause(400)
                ->type('.fi-fo-select-wrp:has(label[for="form.brand_id"]) .fi-select-input-search-ctn input', 'Marca Corretta')
                ->pause(1500)
                ->click('.fi-fo-select-wrp:has(label[for="form.brand_id"]) li.fi-select-input-option')
                ->pause(400)
                ->assertSee('Marca Corretta')
                ->screenshot('05-brand-corrected');

            // 6. The admin corrects the family, which resets the subfamily
            // options to the new family's children.
            $browser->click('.fi-fo-select-wrp:has(label[for="form.family_id"]) .fi-select-input-btn')
                ->pause(400)
                ->type('.fi-fo-select-wrp:has(label[for="form.family_id"]) .fi-select-input-search-ctn input', 'Famiglia Corretta')
                ->pause(1500)
                ->click('.fi-fo-select-wrp:has(label[for="form.family_id"]) li.fi-select-input-option')
                ->pause(400)
                ->assertSee('Famiglia Corretta')
                ->screenshot('06-family-corrected');

            // 7. The admin corrects the subfamily.
            $browser->click('.fi-fo-select-wrp:has(label[for="form.subfamily_id"]) .fi-select-input-btn')
                ->pause(400)
                ->type('.fi-fo-select-wrp:has(label[for="form.subfamily_id"]) .fi-select-input-search-ctn input', 'Sottofamiglia Corretta')
                ->pause(1500)
                ->click('.fi-fo-select-wrp:has(label[for="form.subfamily_id"]) li.fi-select-input-option')
                ->pause(400)
                ->assertSee('Sottofamiglia Corretta')
                ->screenshot('07-subfamily-corrected');

            // 8. The admin corrects the value of the technical attribute.
            $valueFieldId = 'form.attributes.record-'.$attribute->id.'.value';
            $browser->clear('input[id="'.$valueFieldId.'"]')
                ->type('input[id="'.$valueFieldId.'"]', '3.2')
                ->pause(400)
                ->screenshot('08-attribute-corrected');

            // 9. The admin saves: the same manual-correction logic as
            // "Correggi" applies, and the admin is redirected back to the
            // queue where the product (now enriched/manual) is gone.
            $browser->press('Salva correzione');

            $browser->pause(800)
                ->waitForLocation('/admin/review-queue')
                ->assertPathIs('/admin/review-queue')
                ->waitUntilMissingText($product->description_raw)
                ->assertSee('0 articoli da revisionare')
                ->pause(1500)
                ->screenshot('09-back-to-queue-after-save');
        });

        $product->refresh();
        $attribute->refresh();

        $this->assertSame($correctedBrand->id, $product->brand_id);
        $this->assertSame($correctedFamily->id, $product->family_id);
        $this->assertSame($correctedSubfamily->id, $product->subfamily_id);
        $this->assertSame('manual', $product->brand_source);
        $this->assertSame('manual', $product->family_source);
        $this->assertSame('manual', $product->subfamily_source);
        $this->assertSame('manual', $product->source);
        $this->assertSame(100, $product->confidence);
        $this->assertSame('enriched', $product->enrichment_status);

        $this->assertSame('3.2', $attribute->value);
        $this->assertSame('manual', $attribute->source);
    }

    /**
     * Builds a CSS selector for the table row of the given proposal, so the
     * "Dettagli" link click is scoped to a known row instead of matching the
     * first row on the page. Filament keys every table row with a `wire:key`
     * ending in `table.records.{recordKey}` — under US-041 the table's
     * record is the {@see EnrichmentProposal}, not the product, which is a
     * stable, unique attribute to scope on (Dusk's resolver only supports CSS
     * selectors, not XPath). Mirrors {@see ReviewQueueTriageTest::rowSelector()}.
     */
    private function rowSelector(EnrichmentProposal $proposal): string
    {
        return 'tr[wire\\:key$=".table.records.'.$proposal->getKey().'"]';
    }
}
