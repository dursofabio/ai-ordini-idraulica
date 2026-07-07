<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\Pages\ViewProduct;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\RelationManagers\AttributesRelationManager;
use App\Filament\Resources\Products\RelationManagers\EnrichmentLogsRelationManager;
use App\Filament\Resources\Products\RelationManagers\EnrichmentProposalsRelationManager;
use App\Jobs\ClassifyProductsBatchJob;
use App\Jobs\DeepEnrichProductJob;
use App\Jobs\GenerateProductEmbeddingJob;
use App\Jobs\RunDeterministicEnrichmentJob;
use App\Models\Brand;
use App\Models\EnrichmentLog;
use App\Models\EnrichmentProposal;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductEmbedding;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-030 acceptance criteria for the product view page:
 *  - AC1: the list exposes a dedicated view action, and the view page loads.
 *  - AC2/AC3: the enrichment history is shown, via the enrichment logs
 *    relation manager, in chronological order with step/confidence/model/
 *    tokens.
 *  - AC4: the product's own embedding status (generated/missing, model,
 *    date) is shown (US-046: embedding lives per-product, not per-base).
 *  - AC5: manually-set brand/family stay visually distinct, as in the
 *    existing list/form.
 *
 * The technical attributes, enrichment logs, and enrichment proposals lists
 * are each rendered by a dedicated {@see RelationManager}
 * underneath the infolist, rather than being embedded in it.
 *
 * US-050 adds the 'Descrizione estesa' section rendering the markdown field
 * (AC2), with a placeholder and no errors when the field is empty (AC4);
 * updating only that field must not re-dispatch the embedding job.
 */
class ProductResourceViewTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // ProductObserver auto-dispatches GenerateProductEmbeddingJob
        // synchronously (QUEUE_CONNECTION=sync) whenever a Product with a
        // product_type/description_clean/brand_id is created via factory.
        // Pointing the provider at an unreachable host makes that job fail
        // harmlessly instead of hitting the real Ollama service and creating
        // an unwanted embedding row.
        config()->set('services.embedding.base_url', 'https://embedding.test');
    }

    public function test_list_exposes_the_view_action(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create();

        Livewire::test(ListProducts::class)
            ->assertTableActionExists(ViewAction::class, record: $product);
    }

    public function test_view_page_loads_for_an_existing_product(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create();

        Livewire::test(ViewProduct::class, ['record' => $product->getRouteKey()])
            ->assertOk();
    }

    public function test_manual_brand_and_family_are_visually_distinct_in_view_page(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create([
            'brand_source' => 'manual',
            'family_source' => 'ai',
        ]);

        $component = Livewire::test(ViewProduct::class, ['record' => $product->getRouteKey()]);

        $component->assertSchemaComponentExists(
            'brand.name',
            checkComponentUsing: fn (TextEntry $component): bool => $component->getColor(null) === 'info'
                && $component->getIcon(null) === Heroicon::OutlinedLockClosed,
        );

        $component->assertSchemaComponentExists(
            'family.name',
            checkComponentUsing: fn (TextEntry $component): bool => $component->getColor(null) === 'gray'
                && $component->getIcon(null) === null,
        );
    }

    public function test_view_page_shows_generated_embedding_status(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create();
        ProductEmbedding::factory()->create(['product_id' => $product->id, 'model' => 'text-embedding-3-small']);

        Livewire::test(ViewProduct::class, ['record' => $product->getRouteKey()])
            ->assertSchemaStateSet([
                'embedding_status' => 'Generato',
                'embedding.model' => 'text-embedding-3-small',
            ]);
    }

    public function test_view_page_shows_missing_embedding_status_when_product_has_no_embedding(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create();

        Livewire::test(ViewProduct::class, ['record' => $product->getRouteKey()])
            ->assertSchemaStateSet(['embedding_status' => 'Assente']);
    }

    public function test_view_page_shows_all_product_fields_in_the_infolist(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create([
            'product_type' => 'caldaia',
            'fam_codice' => 'FAM01',
            'subfam_codice' => 'SUB01',
            'marca_codice' => 'MRC01',
            'costo' => 199.90,
            'giacenza' => 12,
            'is_active' => true,
            'enrichment_status' => 'enriched',
            'source' => 'ai',
            'confidence' => 92,
        ]);

        Livewire::test(ViewProduct::class, ['record' => $product->getRouteKey()])
            ->assertSchemaStateSet([
                'product_type' => 'caldaia',
                'fam_codice' => 'FAM01',
                'subfam_codice' => 'SUB01',
                'marca_codice' => 'MRC01',
                'giacenza' => '12.00',
                'enrichment_status' => 'enriched',
                'source' => 'ai',
                'confidence' => 92,
                'is_active' => true,
            ]);
    }

    public function test_view_page_registers_all_relation_managers(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create();

        // Only the first relation manager tab renders eagerly on the initial
        // page load; the others are lazy-loaded once their tab is selected.
        // Each tab's actual content is covered directly by the relation
        // manager component tests below.
        Livewire::test(ViewProduct::class, ['record' => $product->getRouteKey()])
            ->assertSeeLivewire(AttributesRelationManager::class);

        $this->assertSame(
            [
                AttributesRelationManager::class,
                EnrichmentLogsRelationManager::class,
                EnrichmentProposalsRelationManager::class,
            ],
            ProductResource::getRelations(),
        );
    }

    public function test_enrichment_logs_relation_manager_lists_logs_in_chronological_order(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create();

        $first = EnrichmentLog::factory()->create([
            'product_id' => $product->id,
            'step' => 'deterministic',
            'confidence' => 60,
            'created_at' => now()->subMinutes(10),
        ]);
        $second = EnrichmentLog::factory()->create([
            'product_id' => $product->id,
            'step' => 'ai',
            'confidence' => 92,
            'created_at' => now(),
        ]);

        Livewire::test(EnrichmentLogsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => ViewProduct::class,
        ])
            ->assertCanSeeTableRecords([$first, $second], inOrder: true);
    }

    public function test_attributes_relation_manager_lists_technical_attributes(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create();
        $attribute = ProductAttribute::factory()->create([
            'product_id' => $product->id,
            'key' => 'potenza_kw',
            'value' => '25',
            'unit' => 'kW',
        ]);

        Livewire::test(AttributesRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => ViewProduct::class,
        ])
            ->assertCanSeeTableRecords([$attribute]);
    }

    public function test_enrichment_proposals_relation_manager_lists_proposals(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create();
        $proposal = EnrichmentProposal::factory()->create([
            'product_id' => $product->id,
            'field' => 'attribute',
            'attribute_key' => 'potenza_kw',
            'status' => 'pending',
        ]);

        Livewire::test(EnrichmentProposalsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => ViewProduct::class,
        ])
            ->assertCanSeeTableRecords([$proposal]);
    }

    /**
     * The proposals list exposes the same triage actions as the review queue
     * (shared via EnrichmentProposalTriageActions), but only on rows still
     * `pending`: applied/discarded proposals are a closed audit trail.
     */
    public function test_enrichment_proposals_relation_manager_shows_triage_actions_only_for_pending_proposals(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create();
        $pending = EnrichmentProposal::factory()->create([
            'product_id' => $product->id,
            'status' => 'pending',
        ]);
        $applied = EnrichmentProposal::factory()->create([
            'product_id' => $product->id,
            'status' => 'applied',
        ]);
        $discarded = EnrichmentProposal::factory()->create([
            'product_id' => $product->id,
            'status' => 'discarded',
        ]);

        $component = Livewire::test(EnrichmentProposalsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => ViewProduct::class,
        ]);

        foreach (['confirm', 'correct', 'discard'] as $action) {
            $component
                ->assertActionVisible(TestAction::make($action)->table($pending))
                ->assertActionHidden(TestAction::make($action)->table($applied))
                ->assertActionHidden(TestAction::make($action)->table($discarded));
        }
    }

    /**
     * Confirming from the product page writes the proposed value to the
     * product with the proposal's own `origin` as the field's source and
     * marks the proposal `applied` — the exact same semantics as the review
     * queue's confirm action.
     */
    public function test_enrichment_proposals_relation_manager_confirm_action_applies_the_proposal(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $brand = Brand::factory()->create();
        $product = Product::factory()->create();
        $proposal = EnrichmentProposal::factory()->create([
            'product_id' => $product->id,
            'field' => 'brand',
            'value_id' => $brand->id,
            'origin' => 'ai',
            'status' => 'pending',
        ]);

        Livewire::test(EnrichmentProposalsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => ViewProduct::class,
        ])
            ->callTableAction('confirm', $proposal)
            ->assertNotified('Proposta confermata');

        $product->refresh();
        $proposal->refresh();

        $this->assertSame($brand->id, $product->brand_id);
        $this->assertSame('ai', $product->brand_source);
        $this->assertSame('applied', $proposal->status);
    }

    /**
     * Correcting from the product page prevalorizes the form with the
     * proposal's current value and saves the submitted value with
     * `source = 'manual'`, marking the proposal `applied`.
     */
    public function test_enrichment_proposals_relation_manager_correct_action_saves_submitted_value_as_manual(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create();
        $proposal = EnrichmentProposal::factory()->create([
            'product_id' => $product->id,
            'field' => 'attribute',
            'attribute_key' => 'potenza_kw',
            'value' => '1.5',
            'unit' => 'kW',
            'origin' => 'ai',
            'status' => 'pending',
        ]);

        Livewire::test(EnrichmentProposalsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => ViewProduct::class,
        ])
            ->mountTableAction('correct', $proposal)
            ->assertTableActionDataSet([
                'value' => '1.5',
                'unit' => 'kW',
            ])
            ->setTableActionData(['value' => '3.2'])
            ->callMountedTableAction()
            ->assertNotified('Correzione salvata');

        $proposal->refresh();
        $attribute = $product->attributes()->where('key', 'potenza_kw')->first();

        $this->assertNotNull($attribute);
        $this->assertSame('3.2', $attribute->value);
        $this->assertSame('manual', $attribute->source);
        $this->assertSame('applied', $proposal->status);
    }

    /**
     * Discarding from the product page only marks the proposal `discarded`;
     * the product is never touched, since the pending value was never
     * applied in the first place.
     */
    public function test_enrichment_proposals_relation_manager_discard_action_marks_the_proposal_discarded(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create();
        $proposal = EnrichmentProposal::factory()->create([
            'product_id' => $product->id,
            'field' => 'attribute',
            'attribute_key' => 'potenza_kw',
            'value' => '1.5',
            'unit' => 'kW',
            'status' => 'pending',
        ]);

        Livewire::test(EnrichmentProposalsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => ViewProduct::class,
        ])
            ->callTableAction('discard', $proposal)
            ->assertNotified('Proposta scartata');

        $proposal->refresh();

        $this->assertSame('discarded', $proposal->status);
        $this->assertSame(0, $product->attributes()->count());
    }

    /**
     * US-051: the `descrizione_estesa` proposal's value is markdown, so the
     * "Valore proposto" column must render it (not the raw `#`/`**` source)
     * — other proposal types keep showing their plain value + unit.
     */
    public function test_enrichment_proposals_relation_manager_renders_descrizione_estesa_value_as_markdown(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create();
        EnrichmentProposal::factory()->create([
            'product_id' => $product->id,
            'field' => 'descrizione_estesa',
            'value' => 'Descrizione **ricca** del prodotto.',
            'status' => 'pending',
        ]);

        Livewire::test(EnrichmentProposalsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => ViewProduct::class,
        ])
            ->assertSee('<strong>ricca</strong>', escape: false);
    }

    /**
     * A `descrizione_estesa` value longer than the column's preview budget
     * is truncated in the table — the full text is only reachable via the
     * "Visualizza" modal action.
     */
    public function test_enrichment_proposals_relation_manager_truncates_a_long_descrizione_estesa_value(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create();
        $longValue = str_repeat('Parola ', 60);
        EnrichmentProposal::factory()->create([
            'product_id' => $product->id,
            'field' => 'descrizione_estesa',
            'value' => $longValue,
            'status' => 'pending',
        ]);

        $html = Livewire::test(EnrichmentProposalsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => ViewProduct::class,
        ])->html();

        $this->assertStringNotContainsString(trim($longValue), $html);
    }

    /**
     * "Visualizza" is only offered on a `descrizione_estesa` proposal —
     * viewing a plain attribute value in a modal would add nothing.
     */
    public function test_enrichment_proposals_relation_manager_view_action_only_visible_for_descrizione_estesa(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create();
        $descriptionProposal = EnrichmentProposal::factory()->create([
            'product_id' => $product->id,
            'field' => 'descrizione_estesa',
            'value' => 'Testo',
            'status' => 'pending',
        ]);
        $attributeProposal = EnrichmentProposal::factory()->create([
            'product_id' => $product->id,
            'field' => 'attribute',
            'attribute_key' => 'potenza_kw',
            'status' => 'pending',
        ]);

        Livewire::test(EnrichmentProposalsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => ViewProduct::class,
        ])
            ->assertActionVisible(TestAction::make('viewDescription')->table($descriptionProposal))
            ->assertActionHidden(TestAction::make('viewDescription')->table($attributeProposal));
    }

    /**
     * The "Visualizza" modal's content is built from the full, untruncated
     * value — {@see EnrichmentProposalsRelationManager::renderDescriptionMarkdown()}
     * converts markdown to sanitized HTML, extracted out of the action's
     * `->modalContent()` closure specifically so this can be asserted
     * directly: Livewire renders a table action's modal content lazily on
     * the client, so it never appears in a component's server-rendered test
     * HTML.
     */
    public function test_render_description_markdown_converts_the_full_untruncated_value(): void
    {
        $longValue = str_repeat('Parola ', 60).'FINE-TESTO';

        $html = EnrichmentProposalsRelationManager::renderDescriptionMarkdown("# Titolo\n\n**{$longValue}**")->toHtml();

        $this->assertStringContainsString('<h1>Titolo</h1>', $html);
        $this->assertStringContainsString("<strong>{$longValue}</strong>", $html);
    }

    /**
     * US-031 AC4: the same manual reprocessing actions available from the
     * products table row must also be available (and behave the same) from
     * the product view page header.
     */
    public function test_view_page_relaunch_deterministic_enrichment_dispatches_job(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create();

        Queue::fake();

        Livewire::test(ViewProduct::class, ['record' => $product->getRouteKey()])
            ->callAction('relaunchDeterministicEnrichment')
            ->assertNotified();

        Queue::assertPushed(
            RunDeterministicEnrichmentJob::class,
            static fn (RunDeterministicEnrichmentJob $job): bool => $job->productId === $product->id,
        );
    }

    public function test_view_page_relaunch_ai_classification_dispatches_job_regardless_of_current_status(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create([
            'description_raw' => 'CALDAIA A CONDENSAZIONE 25KW',
            'enrichment_status' => 'enriched',
        ]);

        Queue::fake();

        Livewire::test(ViewProduct::class, ['record' => $product->getRouteKey()])
            ->callAction('relaunchAiClassification')
            ->assertNotified();

        Queue::assertPushed(
            ClassifyProductsBatchJob::class,
            static fn (ClassifyProductsBatchJob $job): bool => $job->productIds === [$product->id],
        );
    }

    public function test_view_page_relaunch_ai_classification_is_disabled_when_description_is_empty(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create([
            'description_raw' => '',
            'description_clean' => null,
        ]);

        Queue::fake();

        Livewire::test(ViewProduct::class, ['record' => $product->getRouteKey()])
            ->assertActionDisabled('relaunchAiClassification');

        Queue::assertNotPushed(ClassifyProductsBatchJob::class);
    }

    public function test_view_page_deep_enrich_with_ai_dispatches_job(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create([
            'description_raw' => 'CALDAIA A CONDENSAZIONE 25KW',
        ]);

        Queue::fake();

        Livewire::test(ViewProduct::class, ['record' => $product->getRouteKey()])
            ->callAction('deepEnrichWithAi')
            ->assertNotified();

        Queue::assertPushed(
            DeepEnrichProductJob::class,
            static fn (DeepEnrichProductJob $job): bool => $job->productId === $product->id,
        );
    }

    public function test_view_page_deep_enrich_with_ai_is_disabled_when_description_is_empty(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create([
            'description_raw' => '',
            'description_clean' => null,
        ]);

        Queue::fake();

        Livewire::test(ViewProduct::class, ['record' => $product->getRouteKey()])
            ->assertActionDisabled('deepEnrichWithAi');

        Queue::assertNotPushed(DeepEnrichProductJob::class);
    }

    public function test_view_page_regenerate_embedding_dispatches_job(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create();

        Queue::fake();

        Livewire::test(ViewProduct::class, ['record' => $product->getRouteKey()])
            ->callAction('regenerateEmbedding')
            ->assertNotified();

        Queue::assertPushed(
            GenerateProductEmbeddingJob::class,
            static fn (GenerateProductEmbeddingJob $job): bool => $job->productId === $product->id,
        );
    }

    public function test_view_page_renders_descrizione_estesa_as_markdown(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create([
            'descrizione_estesa' => "# Scheda tecnica\n\nDescrizione **ricca** del prodotto.",
        ]);

        Livewire::test(ViewProduct::class, ['record' => $product->getRouteKey()])
            ->assertOk()
            ->assertSee('Descrizione estesa')
            ->assertSee('<strong>ricca</strong>', escape: false);
    }

    public function test_view_page_shows_placeholder_when_descrizione_estesa_is_empty(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create(['descrizione_estesa' => null]);

        Livewire::test(ViewProduct::class, ['record' => $product->getRouteKey()])
            ->assertOk()
            ->assertSchemaStateSet(['descrizione_estesa' => null]);
    }

    public function test_updating_descrizione_estesa_does_not_dispatch_embedding_regeneration(): void
    {
        $product = Product::factory()->create();

        Queue::fake();

        $product->update(['descrizione_estesa' => 'Testo descrittivo aggiornato']);

        Queue::assertNotPushed(GenerateProductEmbeddingJob::class);
    }
}
