<?php

namespace Tests\Browser;

use App\Jobs\DeepEnrichProductJob;
use App\Models\AttributeDefinition;
use App\Models\EnrichmentProposal;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Hash;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use Tests\Feature\DeepEnrichProductJobTest;

/**
 * US-051 demo scenario (spec "Dimostra"): the operator presses "Arricchisci
 * con AI" on a product's view page, the job is queued (confirmed by a
 * notification), and — once the AI proposals exist in the review queue — the
 * operator confirms the extended-description proposal and sees it rendered
 * on the product page.
 *
 * Scenario 1 exercises the real button/job-dispatch path end to end: the
 * underlying {@see DeepEnrichProductJob} runs under
 * QUEUE_CONNECTION=sync in this standalone Dusk stack, and
 * ANTHROPIC_BASE_URL is pointed at a connection-refused host (see
 * .env.dusk.local) so the call fails fast and deterministically without a
 * real network dependency; the job's own error handling still resolves this
 * to `needs_review` without ever surfacing as a page error, so only the
 * "queued" notification is asserted here — proving the button, the action,
 * and the job dispatch wiring, not a specific AI response.
 *
 * Scenario 2 seeds the AI proposals directly (bypassing the AI call itself,
 * already covered by {@see DeepEnrichProductJobTest}) to
 * exercise the confirm → product page rendering path through the existing
 * review queue, shared with every other proposal type.
 *
 * Per-step screenshots are stored in docs/test-results/US-051/ as the visual
 * artifact of the run (Dusk does not record video, same convention as
 * US-041/US-050). Actions are paced with explicit visibility assertions and
 * short holds so the artifacts are readable by a non-technical reviewer.
 */
class ProductDeepEnrichmentTest extends DuskTestCase
{
    use DatabaseMigrations;

    protected const ARTIFACT_DIR = __DIR__.'/../../docs/test-results/US-051';

    public function test_operator_queues_deep_enrichment_from_the_product_page(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin',
            'email' => AdminUserSeeder::DEFAULT_EMAIL,
            'password' => Hash::make(AdminUserSeeder::DEFAULT_PASSWORD),
        ]);

        $product = Product::factory()->create([
            'codice_articolo' => 'DEEP-001',
            'description_raw' => 'CALDAIA A CONDENSAZIONE 25KW PARETE',
        ]);

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

            // 2. The operator opens the product's view page and sees the
            // "Arricchisci con AI" action alongside the other reprocessing
            // actions.
            $browser->visit('/admin/products/'.$product->id)
                ->waitForText('Arricchisci con AI')
                ->pause(400)
                ->screenshot('02-product-page-before-click');

            // 3. Pressing the action queues DeepEnrichProductJob and shows a
            // confirmation notification (AC1).
            $browser->press('Arricchisci con AI')
                ->waitForText('Arricchimento AI accodato')
                ->pause(1500)
                ->screenshot('03-queued-notification');
        });
    }

    public function test_admin_confirms_deep_enrichment_proposals_and_sees_them_on_the_product_page(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin',
            'email' => AdminUserSeeder::DEFAULT_EMAIL,
            'password' => Hash::make(AdminUserSeeder::DEFAULT_PASSWORD),
        ]);

        AttributeDefinition::factory()->numeric()->create([
            'key' => 'potenza_kw',
            'canonical_unit' => 'kW',
        ]);

        $product = Product::factory()->create([
            'codice_articolo' => 'DEEP-002',
            'description_raw' => 'CALDAIA A CONDENSAZIONE 25KW PARETE',
            'descrizione_estesa' => null,
        ]);

        $descriptionProposal = EnrichmentProposal::factory()->create([
            'product_id' => $product->id,
            'field' => 'descrizione_estesa',
            'attribute_key' => null,
            'value_id' => null,
            'value' => "# Caldaia a condensazione\n\nModello da parete con potenza **25 kW**, adatto a impianti residenziali.",
            'unit' => null,
            'origin' => 'ai',
            'confidence' => 82,
            'status' => 'pending',
        ]);

        $attributeProposal = EnrichmentProposal::factory()->create([
            'product_id' => $product->id,
            'field' => 'attribute',
            'attribute_key' => 'potenza_kw',
            'value_id' => null,
            'value' => '25',
            'unit' => 'kW',
            'origin' => 'ai',
            'confidence' => 88,
            'status' => 'pending',
        ]);

        $this->browse(function (Browser $browser) use ($admin, $product, $descriptionProposal, $attributeProposal) {
            // 1. The admin logs in to the Filament backoffice.
            $browser->visit('/admin/login')
                ->waitFor('#form\\.email')
                ->type('#form\\.email', $admin->email)
                ->pause(400)
                ->type('#form\\.password', AdminUserSeeder::DEFAULT_PASSWORD)
                ->pause(400)
                ->click('button[type="submit"]')
                ->waitForLocation('/admin')
                ->pause(400);

            // 2. The admin opens the "Da revisionare" review queue and sees
            // both AI proposals for the deeply-enriched product.
            $browser->visit('/admin/review-queue')
                ->waitForText('articoli da revisionare')
                ->assertSee('2 articoli da revisionare')
                ->assertSee('Descrizione estesa')
                ->assertSee('Attributo: potenza_kw')
                ->pause(400)
                ->screenshot('04-review-queue-before-confirm');

            // 3. The admin confirms the extended-description proposal; its
            // row disappears from the queue and the counter decreases, while
            // the attribute proposal for the same product remains.
            $browser->with($this->rowSelector($descriptionProposal), function (Browser $row) {
                $row->press('Conferma');
            });

            $browser->pause(800)
                ->waitUntilMissing($this->rowSelector($descriptionProposal))
                ->assertSee('1 articoli da revisionare')
                ->assertPresent($this->rowSelector($attributeProposal))
                ->screenshot('05-review-queue-after-confirm');

            // 4. On the product page, the confirmed extended description is
            // rendered as HTML in its dedicated section (AC3).
            $browser->visit('/admin/products/'.$product->id)
                ->waitForText('Descrizione estesa')
                ->assertSee('Caldaia a condensazione')
                ->assertDontSee('**25 kW**')
                ->assertSourceHas('<strong>25 kW</strong>')
                ->pause(1500)
                ->screenshot('06-product-page-with-confirmed-description');
        });

        $this->assertSame(
            "# Caldaia a condensazione\n\nModello da parete con potenza **25 kW**, adatto a impianti residenziali.",
            $product->refresh()->descrizione_estesa,
        );
        $this->assertSame('applied', $descriptionProposal->fresh()->status);
        $this->assertSame('pending', $attributeProposal->fresh()->status);
    }

    private function rowSelector(EnrichmentProposal $proposal): string
    {
        return 'tr[wire\\:key$=".table.records.'.$proposal->getKey().'"]';
    }
}
