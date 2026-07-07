<?php

namespace Tests\Feature;

use App\Filament\Pages\ReviewQueue;
use App\Filament\Pages\ReviewQueueDetail;
use App\Models\AttributeDefinition;
use App\Models\Brand;
use App\Models\EnrichmentProposal;
use App\Models\Family;
use App\Models\Product;
use App\Models\Subfamily;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Tables\Columns\Column;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-041 acceptance criteria for the proposal-level "Da revisionare" review
 * queue: the queue now lists individual pending {@see EnrichmentProposal}
 * rows (from the US-040 register) instead of whole products, so a product
 * appears once per pending proposal rather than once overall.
 *
 *  - AC1: the queue lists only `status = 'pending'` proposals, ordered by
 *    ascending confidence (NULL first), showing the underlying product's
 *    codice_articolo/description_raw plus the proposal's own field/proposed
 *    value/origin/confidence.
 *  - AC2: "Confirm" writes the proposed value to the product (the specific
 *    field/attribute only) with the proposal's own `origin` as the source,
 *    and marks the proposal `applied` — without touching the product's
 *    overall source/confidence/enrichment_status.
 *  - AC3: "Correct" saves the admin-submitted value with `source = 'manual'`
 *    for the specific field/attribute, with the form precompiled from the
 *    proposal's current value, and marks the proposal `applied`.
 *  - AC4: "Discard" only marks the proposal `discarded`, without touching the
 *    product at all.
 *  - AC5: a product whose other fields are already resolved keeps only its
 *    remaining pending proposals in the queue (one row per proposal, not one
 *    per product).
 */
class ReviewQueueTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_queue_only_lists_pending_proposals(): void
    {
        $admin = User::factory()->create();
        $pending = EnrichmentProposal::factory()->create(['status' => 'pending']);
        $applied = EnrichmentProposal::factory()->create(['status' => 'applied']);
        $discarded = EnrichmentProposal::factory()->create(['status' => 'discarded']);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$applied, $discarded]);
    }

    public function test_queue_is_ordered_by_ascending_confidence_with_null_first(): void
    {
        $admin = User::factory()->create();
        $highConfidence = EnrichmentProposal::factory()->create(['status' => 'pending', 'confidence' => 80]);
        $lowConfidence = EnrichmentProposal::factory()->create(['status' => 'pending', 'confidence' => 20]);
        $noConfidence = EnrichmentProposal::factory()->create(['status' => 'pending', 'confidence' => null]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->assertCanSeeTableRecords([$noConfidence, $lowConfidence, $highConfidence], inOrder: true);
    }

    /**
     * US-041: a product with several pending proposals must appear once per
     * proposal, not once overall.
     */
    public function test_product_with_multiple_pending_proposals_appears_once_per_proposal(): void
    {
        $admin = User::factory()->create();
        $product = Product::factory()->create();
        $familyProposal = EnrichmentProposal::factory()->create([
            'product_id' => $product->id,
            'field' => 'family',
            'status' => 'pending',
        ]);
        $attributeProposal = EnrichmentProposal::factory()->create([
            'product_id' => $product->id,
            'field' => 'attribute',
            'attribute_key' => 'kW',
            'status' => 'pending',
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->assertCanSeeTableRecords([$familyProposal, $attributeProposal])
            ->assertCountTableRecords(2);
    }

    /**
     * US-041 AC5 cardinal scenario: a product whose brand is already applied
     * from file (no pending brand proposal) but has exactly one pending
     * low-confidence attribute proposal must show up in the queue with
     * exactly one row — not a row for the whole product, and not extra rows
     * for family/subfamily since those have no pending proposals either.
     */
    public function test_product_with_resolved_brand_and_one_pending_attribute_shows_exactly_one_row(): void
    {
        $admin = User::factory()->create();
        $brand = Brand::factory()->create();
        $product = Product::factory()->create([
            'brand_id' => $brand->id,
            'brand_source' => 'file',
        ]);
        $pendingAttribute = EnrichmentProposal::factory()->create([
            'product_id' => $product->id,
            'field' => 'attribute',
            'attribute_key' => 'kW',
            'value' => '1.5',
            'unit' => 'kW',
            'origin' => 'regex',
            'confidence' => 40,
            'status' => 'pending',
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->assertCanSeeTableRecords([$pendingAttribute])
            ->assertCountTableRecords(1);
    }

    public function test_queue_columns_show_the_underlying_products_codice_articolo_and_description(): void
    {
        $admin = User::factory()->create();
        $product = Product::factory()->create([
            'codice_articolo' => 'ART-12345',
            'description_raw' => 'Raccordo a T da 3/4 pollici',
        ]);
        $proposal = EnrichmentProposal::factory()->create([
            'product_id' => $product->id,
            'status' => 'pending',
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->assertTableColumnStateSet('product.codice_articolo', 'ART-12345', $proposal)
            ->assertTableColumnStateSet('product.description_raw', 'Raccordo a T da 3/4 pollici', $proposal);
    }

    public function test_field_column_shows_label_including_attribute_key_for_attribute_proposals(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $brandProposal = EnrichmentProposal::factory()->create(['field' => 'brand', 'status' => 'pending']);
        $familyProposal = EnrichmentProposal::factory()->create(['field' => 'family', 'status' => 'pending']);
        $subfamilyProposal = EnrichmentProposal::factory()->create(['field' => 'subfamily', 'status' => 'pending']);
        $attributeProposal = EnrichmentProposal::factory()->create([
            'field' => 'attribute',
            'attribute_key' => 'kW',
            'status' => 'pending',
        ]);

        $component = Livewire::test(ReviewQueue::class);

        $this->assertSame('Marca', $this->formattedColumnState($component, 'field', $brandProposal));
        $this->assertSame('Famiglia', $this->formattedColumnState($component, 'field', $familyProposal));
        $this->assertSame('Sottofamiglia', $this->formattedColumnState($component, 'field', $subfamilyProposal));
        $this->assertSame('Attributo: kW', $this->formattedColumnState($component, 'field', $attributeProposal));
    }

    public function test_proposed_value_column_resolves_taxonomy_names_for_brand_family_and_subfamily(): void
    {
        $admin = User::factory()->create();
        $brand = Brand::factory()->create(['name' => 'Marca AI']);
        $family = Family::factory()->create(['name' => 'Famiglia AI']);
        $subfamily = Subfamily::factory()->create(['name' => 'Sottofamiglia AI', 'family_id' => $family->id]);

        $brandProposal = EnrichmentProposal::factory()->create(['field' => 'brand', 'value_id' => $brand->id, 'status' => 'pending']);
        $familyProposal = EnrichmentProposal::factory()->create(['field' => 'family', 'value_id' => $family->id, 'status' => 'pending']);
        $subfamilyProposal = EnrichmentProposal::factory()->create(['field' => 'subfamily', 'value_id' => $subfamily->id, 'status' => 'pending']);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->assertTableColumnStateSet('proposed_value', 'Marca AI', $brandProposal)
            ->assertTableColumnStateSet('proposed_value', 'Famiglia AI', $familyProposal)
            ->assertTableColumnStateSet('proposed_value', 'Sottofamiglia AI', $subfamilyProposal);
    }

    public function test_proposed_value_column_shows_dash_when_taxonomy_value_id_is_not_found(): void
    {
        $admin = User::factory()->create();
        $proposal = EnrichmentProposal::factory()->create([
            'field' => 'brand',
            'value_id' => 999999,
            'status' => 'pending',
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->assertTableColumnStateSet('proposed_value', '—', $proposal);
    }

    public function test_proposed_value_column_formats_attribute_values_with_text_numeric_and_unit(): void
    {
        $admin = User::factory()->create();
        $textProposal = EnrichmentProposal::factory()->create([
            'field' => 'attribute',
            'attribute_key' => 'Materiale',
            'value' => 'Ottone',
            'unit' => null,
            'status' => 'pending',
        ]);
        $numericProposal = EnrichmentProposal::factory()->create([
            'field' => 'attribute',
            'attribute_key' => 'kW',
            'value' => '1.5',
            'unit' => 'kW',
            'status' => 'pending',
        ]);
        $wholeNumberProposal = EnrichmentProposal::factory()->create([
            'field' => 'attribute',
            'attribute_key' => 'DN',
            'value' => '100',
            'unit' => null,
            'status' => 'pending',
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->assertTableColumnStateSet('proposed_value', 'Ottone', $textProposal)
            ->assertTableColumnStateSet('proposed_value', '1.5 kW', $numericProposal)
            ->assertTableColumnStateSet('proposed_value', '100', $wholeNumberProposal);
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    public static function originSourceProvider(): array
    {
        return [
            'ai' => ['ai', 'AI'],
            'regex' => ['regex', 'Dedotta'],
            'dictionary' => ['dictionary', 'Dedotta'],
            'propagated' => ['propagated', 'Dedotta'],
            'file' => ['file', 'Da file'],
            'manual' => ['manual', 'Manuale'],
        ];
    }

    public function test_origin_column_maps_each_origin_value_to_expected_label(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $component = Livewire::test(ReviewQueue::class);

        foreach (self::originSourceProvider() as [$origin, $expectedLabel]) {
            $proposal = EnrichmentProposal::factory()->create(['origin' => $origin, 'status' => 'pending']);

            $this->assertSame(
                $expectedLabel,
                $this->formattedColumnState($component, 'origin', $proposal),
                "Failed asserting origin label for origin [{$origin}]."
            );
        }
    }

    /**
     * `enrichment_proposals.origin` is NOT NULL at the database level (unlike
     * the product's own `*_source`/attribute `source` columns, which reuse
     * this same {@see ReviewQueue::originLabel()} mapping and can be null),
     * so the unknown/null branch is exercised directly against the shared
     * static mapping instead of through a persisted proposal.
     */
    public function test_origin_label_maps_null_and_unknown_values_to_dash(): void
    {
        $this->assertSame('—', ReviewQueue::originLabel(null));
        $this->assertSame('—', ReviewQueue::originLabel('unknown'));
    }

    public function test_confidence_column_formats_null_state_as_nd_and_keeps_percentage_for_values(): void
    {
        $admin = User::factory()->create();
        $withoutConfidence = EnrichmentProposal::factory()->create(['confidence' => null, 'status' => 'pending']);
        $withConfidence = EnrichmentProposal::factory()->create(['confidence' => 45, 'status' => 'pending']);

        $this->actingAs($admin);

        $component = Livewire::test(ReviewQueue::class);

        $this->assertSame('N/D', $this->formattedColumnState($component, 'confidence', $withoutConfidence));
        $this->assertSame('45%', $this->formattedColumnState($component, 'confidence', $withConfidence));
    }

    public function test_confidence_column_color_reflects_thresholds(): void
    {
        $admin = User::factory()->create();
        $none = EnrichmentProposal::factory()->create(['confidence' => null, 'status' => 'pending']);
        $danger = EnrichmentProposal::factory()->create(['confidence' => 59, 'status' => 'pending']);
        $warning = EnrichmentProposal::factory()->create(['confidence' => 84, 'status' => 'pending']);
        $success = EnrichmentProposal::factory()->create(['confidence' => 85, 'status' => 'pending']);

        $this->actingAs($admin);

        $component = Livewire::test(ReviewQueue::class);

        // `getColumn()` returns the same cached Column instance on every
        // call, so each color must be read immediately after binding its
        // record — resolving all four records first and reading their
        // colors afterwards would make every assertion read the color of
        // whichever record was bound last.
        $this->assertSame('gray', $this->confidenceColumnColor($component, $none));
        $this->assertSame('danger', $this->confidenceColumnColor($component, $danger));
        $this->assertSame('warning', $this->confidenceColumnColor($component, $warning));
        $this->assertSame('success', $this->confidenceColumnColor($component, $success));
    }

    private function confidenceColumnColor(Testable $component, EnrichmentProposal $record): string|array|null
    {
        $column = $this->resolveColumn($component, 'confidence', $record);

        return $column->getColor($column->getState());
    }

    public function test_confidence_column_is_sortable(): void
    {
        $admin = User::factory()->create();
        $low = EnrichmentProposal::factory()->create(['confidence' => 20, 'status' => 'pending']);
        $high = EnrichmentProposal::factory()->create(['confidence' => 80, 'status' => 'pending']);

        $this->actingAs($admin);

        $component = Livewire::test(ReviewQueue::class);

        $component->sortTable('confidence')
            ->assertCanSeeTableRecords([$low, $high], inOrder: true);
        $component->sortTable('confidence', 'desc')
            ->assertCanSeeTableRecords([$high, $low], inOrder: true);
    }

    /**
     * US-041: `field` is a plain column filter (no relationship), matching
     * the `enrichment_status` filter pattern already used elsewhere.
     */
    public function test_field_filter_narrows_the_queue(): void
    {
        $admin = User::factory()->create();
        $brandProposal = EnrichmentProposal::factory()->create(['field' => 'brand', 'status' => 'pending']);
        $attributeProposal = EnrichmentProposal::factory()->create([
            'field' => 'attribute',
            'attribute_key' => 'kW',
            'status' => 'pending',
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->filterTable('field', 'brand')
            ->assertCanSeeTableRecords([$brandProposal])
            ->assertCanNotSeeTableRecords([$attributeProposal]);
    }

    /**
     * The `confidence_band` filter applies the bassa (<60), media (60-84) and
     * alta (>=85) thresholds, with edge cases exactly on the 59/60 and 84/85
     * boundaries and a null-confidence proposal excluded from every band.
     */
    public function test_confidence_band_filter_applies_low_medium_and_high_thresholds(): void
    {
        $admin = User::factory()->create();
        $justBelowLow = EnrichmentProposal::factory()->create(['status' => 'pending', 'confidence' => 59]);
        $justAtMedium = EnrichmentProposal::factory()->create(['status' => 'pending', 'confidence' => 60]);
        $justAtMediumEnd = EnrichmentProposal::factory()->create(['status' => 'pending', 'confidence' => 84]);
        $justAtHigh = EnrichmentProposal::factory()->create(['status' => 'pending', 'confidence' => 85]);
        $noConfidence = EnrichmentProposal::factory()->create(['status' => 'pending', 'confidence' => null]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->filterTable('confidence_band', 'bassa')
            ->assertCanSeeTableRecords([$justBelowLow])
            ->assertCanNotSeeTableRecords([$justAtMedium, $justAtMediumEnd, $justAtHigh, $noConfidence])
            ->resetTableFilters()
            ->filterTable('confidence_band', 'media')
            ->assertCanSeeTableRecords([$justAtMedium, $justAtMediumEnd])
            ->assertCanNotSeeTableRecords([$justBelowLow, $justAtHigh, $noConfidence])
            ->resetTableFilters()
            ->filterTable('confidence_band', 'alta')
            ->assertCanSeeTableRecords([$justAtHigh])
            ->assertCanNotSeeTableRecords([$justBelowLow, $justAtMedium, $justAtMediumEnd, $noConfidence]);
    }

    public function test_heading_shows_queue_count_and_updates_after_an_action(): void
    {
        $admin = User::factory()->create();
        $first = EnrichmentProposal::factory()->create(['field' => 'brand', 'status' => 'pending']);
        EnrichmentProposal::factory()->create(['status' => 'pending']);

        $this->actingAs($admin);

        $component = Livewire::test(ReviewQueue::class);

        $component->assertSee('2 articoli da revisionare');

        $component->callTableAction('confirm', $first);

        $component->assertSee('1 articoli da revisionare');
    }

    /**
     * US-041 AC2: confirming a brand proposal writes `brand_id` and
     * `brand_source` (set to the proposal's own `origin`) to the product,
     * marks the proposal `applied`, and — crucially, the narrower behavior
     * introduced by this rewrite — leaves the product's overall
     * source/confidence/enrichment_status completely untouched.
     */
    public function test_confirm_action_writes_brand_value_without_touching_the_products_overall_fields(): void
    {
        $admin = User::factory()->create();
        $brand = Brand::factory()->create();
        $product = Product::factory()->create([
            'source' => 'file',
            'confidence' => 77,
            'enrichment_status' => 'needs_review',
        ]);
        $proposal = EnrichmentProposal::factory()->create([
            'product_id' => $product->id,
            'field' => 'brand',
            'value_id' => $brand->id,
            'origin' => 'ai',
            'status' => 'pending',
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->callTableAction('confirm', $proposal);

        $product->refresh();
        $proposal->refresh();

        $this->assertSame($brand->id, $product->brand_id);
        $this->assertSame('ai', $product->brand_source);
        $this->assertSame('applied', $proposal->status);

        $this->assertSame('file', $product->source);
        $this->assertSame(77, $product->confidence);
        $this->assertSame('needs_review', $product->enrichment_status);
    }

    /**
     * US-045 AC1: confirming a `product_type` proposal writes the plain
     * text value directly to `products.product_type` (there is no
     * `product_type_id`/`product_type_source`), and marks the proposal
     * `applied`.
     */
    public function test_confirm_action_writes_product_type_value_and_marks_proposal_applied(): void
    {
        $admin = User::factory()->create();
        $product = Product::factory()->create();
        $proposal = EnrichmentProposal::factory()->create([
            'product_id' => $product->id,
            'field' => 'product_type',
            'value' => 'Caldaia a condensazione',
            'origin' => 'ai',
            'status' => 'pending',
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->callTableAction('confirm', $proposal);

        $proposal->refresh();

        $this->assertSame('Caldaia a condensazione', $product->fresh()->product_type);
        $this->assertSame('applied', $proposal->status);
    }

    /**
     * US-045 AC1: the correction form for a `product_type` proposal is
     * prevalorized from `value` (like an attribute), not `value_id`.
     */
    public function test_correct_action_product_type_form_is_prefilled_with_current_text_value(): void
    {
        $admin = User::factory()->create();
        $proposal = EnrichmentProposal::factory()->create([
            'field' => 'product_type',
            'value' => 'Miscelatore',
            'status' => 'pending',
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->mountTableAction('correct', $proposal)
            ->assertTableActionDataSet(['value' => 'Miscelatore']);
    }

    /**
     * US-045 AC1: correcting a `product_type` proposal writes the submitted
     * text value directly to `products.product_type` and marks the proposal
     * `applied`.
     */
    public function test_correct_action_saves_submitted_product_type_value(): void
    {
        $admin = User::factory()->create();
        $product = Product::factory()->create();
        $proposal = EnrichmentProposal::factory()->create([
            'product_id' => $product->id,
            'field' => 'product_type',
            'value' => 'Miscelatore',
            'status' => 'pending',
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->callTableAction('correct', $proposal, ['value' => 'Caldaia a condensazione']);

        $proposal->refresh();

        $this->assertSame('Caldaia a condensazione', $product->fresh()->product_type);
        $this->assertSame('applied', $proposal->status);
    }

    /**
     * US-045: the `field` filter must include `product_type` so an admin can
     * isolate pending product-type proposals.
     */
    public function test_field_filter_narrows_the_queue_to_product_type_proposals(): void
    {
        $admin = User::factory()->create();
        $productTypeProposal = EnrichmentProposal::factory()->create(['field' => 'product_type', 'status' => 'pending']);
        $brandProposal = EnrichmentProposal::factory()->create(['field' => 'brand', 'status' => 'pending']);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->filterTable('field', 'product_type')
            ->assertCanSeeTableRecords([$productTypeProposal])
            ->assertCanNotSeeTableRecords([$brandProposal]);
    }

    /**
     * US-051 AC3: confirming a `descrizione_estesa` proposal writes the
     * proposed markdown text directly to `products.descrizione_estesa` (like
     * `product_type`) and marks the proposal `applied`.
     */
    public function test_confirm_action_writes_descrizione_estesa_value_and_marks_proposal_applied(): void
    {
        $admin = User::factory()->create();
        $product = Product::factory()->create();
        $proposal = EnrichmentProposal::factory()->create([
            'product_id' => $product->id,
            'field' => 'descrizione_estesa',
            'value' => "# Scheda tecnica\n\nDescrizione proposta dall'AI.",
            'origin' => 'ai',
            'status' => 'pending',
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->callTableAction('confirm', $proposal);

        $proposal->refresh();

        $this->assertSame("# Scheda tecnica\n\nDescrizione proposta dall'AI.", $product->fresh()->descrizione_estesa);
        $this->assertSame('applied', $proposal->status);
    }

    /**
     * US-051 AC3: the correction form for a `descrizione_estesa` proposal is
     * prevalorized from `value`, using a Textarea for the markdown body.
     */
    public function test_correct_action_descrizione_estesa_form_is_prefilled_with_current_text_value(): void
    {
        $admin = User::factory()->create();
        $proposal = EnrichmentProposal::factory()->create([
            'field' => 'descrizione_estesa',
            'value' => 'Testo proposto',
            'status' => 'pending',
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->mountTableAction('correct', $proposal)
            ->assertTableActionDataSet(['value' => 'Testo proposto']);
    }

    /**
     * US-051 AC3: correcting a `descrizione_estesa` proposal writes the
     * submitted text directly to `products.descrizione_estesa` and marks the
     * proposal `applied`.
     */
    public function test_correct_action_saves_submitted_descrizione_estesa_value(): void
    {
        $admin = User::factory()->create();
        $product = Product::factory()->create();
        $proposal = EnrichmentProposal::factory()->create([
            'product_id' => $product->id,
            'field' => 'descrizione_estesa',
            'value' => 'Testo proposto',
            'status' => 'pending',
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->callTableAction('correct', $proposal, ['value' => 'Testo corretto dal revisore']);

        $proposal->refresh();

        $this->assertSame('Testo corretto dal revisore', $product->fresh()->descrizione_estesa);
        $this->assertSame('applied', $proposal->status);
    }

    /**
     * US-051: discarding a `descrizione_estesa` proposal leaves the
     * product's existing extended description untouched.
     */
    public function test_discard_action_on_descrizione_estesa_leaves_the_product_untouched(): void
    {
        $admin = User::factory()->create();
        $product = Product::factory()->create(['descrizione_estesa' => 'Testo originale']);
        $proposal = EnrichmentProposal::factory()->create([
            'product_id' => $product->id,
            'field' => 'descrizione_estesa',
            'value' => 'Testo proposto',
            'status' => 'pending',
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->callTableAction('discard', $proposal);

        $proposal->refresh();

        $this->assertSame('discarded', $proposal->status);
        $this->assertSame('Testo originale', $product->fresh()->descrizione_estesa);
    }

    /**
     * US-051: the `field` filter must include `descrizione_estesa` so an
     * admin can isolate pending extended-description proposals, and the
     * field column must show a readable label for them.
     */
    public function test_field_filter_narrows_the_queue_to_descrizione_estesa_proposals(): void
    {
        $admin = User::factory()->create();
        $descriptionProposal = EnrichmentProposal::factory()->create(['field' => 'descrizione_estesa', 'status' => 'pending']);
        $brandProposal = EnrichmentProposal::factory()->create(['field' => 'brand', 'status' => 'pending']);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->filterTable('field', 'descrizione_estesa')
            ->assertCanSeeTableRecords([$descriptionProposal])
            ->assertCanNotSeeTableRecords([$brandProposal]);
    }

    /**
     * US-051: the field column reads "Descrizione estesa" for a
     * `descrizione_estesa` proposal, matching the label used by the filter.
     */
    public function test_field_column_shows_descrizione_estesa_label(): void
    {
        $admin = User::factory()->create();
        $proposal = EnrichmentProposal::factory()->create(['field' => 'descrizione_estesa', 'status' => 'pending']);

        $this->actingAs($admin);

        $component = Livewire::test(ReviewQueue::class);

        $this->assertSame('Descrizione estesa', $this->formattedColumnState($component, 'field', $proposal));
    }

    /**
     * US-044: the `field` filter must include `attribute_definition` so an
     * admin can isolate pending "new attribute key" proposals.
     */
    public function test_field_filter_narrows_the_queue_to_attribute_definition_proposals(): void
    {
        $admin = User::factory()->create();
        $definitionProposal = EnrichmentProposal::factory()->attributeDefinition()->create();
        $brandProposal = EnrichmentProposal::factory()->create(['field' => 'brand', 'status' => 'pending']);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->filterTable('field', 'attribute_definition')
            ->assertCanSeeTableRecords([$definitionProposal])
            ->assertCanNotSeeTableRecords([$brandProposal]);
    }

    /**
     * US-044: the "Campo" column shows a generic "Nuova chiave attributo"
     * label for an `attribute_definition` proposal, and "Valore proposto"
     * summarizes the proposed key/type/unit.
     */
    public function test_field_and_proposed_value_columns_show_attribute_definition_summary(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $proposal = EnrichmentProposal::factory()->attributeDefinition()->create([
            'attribute_key' => 'portata_lmin',
            'data_type' => 'numeric',
            'unit' => 'l/min',
        ]);

        $component = Livewire::test(ReviewQueue::class);

        $this->assertSame('Nuova chiave attributo', $this->formattedColumnState($component, 'field', $proposal));

        Livewire::test(ReviewQueue::class)
            ->assertTableColumnStateSet('proposed_value', 'portata_lmin (numeric, l/min)', $proposal);
    }

    /**
     * US-044 AC2: opening "Correggi" on an `attribute_definition` proposal
     * prevalorizes the form from the proposal's key/type/unit/description and
     * shows the existing registry keys most similar to the proposed one, so
     * the reviewer can catch a near-duplicate before approving.
     */
    public function test_correct_action_attribute_definition_form_is_prefilled_and_shows_similar_keys(): void
    {
        $admin = User::factory()->create();
        AttributeDefinition::factory()->create([
            'key' => 'portata_l_min',
            'data_type' => 'numeric',
            'canonical_unit' => 'l/min',
        ]);
        $proposal = EnrichmentProposal::factory()->attributeDefinition()->create([
            'attribute_key' => 'portata_lmin',
            'data_type' => 'numeric',
            'unit' => 'l/min',
            'value' => null,
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->mountTableAction('correct', $proposal)
            ->assertTableActionDataSet([
                'attribute_key' => 'portata_lmin',
                'data_type' => 'numeric',
                'unit' => 'l/min',
                'value' => null,
            ])
            ->assertMountedActionModalSee('portata_l_min');
    }

    /**
     * US-044 AC3: confirming an `attribute_definition` proposal as-is creates
     * the registry row with the AI-proposed key/type/unit, and marks the
     * proposal `applied` without touching any product.
     */
    public function test_confirm_action_creates_attribute_definition_and_marks_proposal_applied(): void
    {
        $admin = User::factory()->create();
        $proposal = EnrichmentProposal::factory()->attributeDefinition()->create([
            'attribute_key' => 'portata_lmin',
            'data_type' => 'numeric',
            'unit' => 'l/min',
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->callTableAction('confirm', $proposal);

        $proposal->refresh();

        $this->assertDatabaseHas(AttributeDefinition::class, [
            'key' => 'portata_lmin',
            'data_type' => 'numeric',
            'canonical_unit' => 'l/min',
        ]);
        $this->assertSame('applied', $proposal->status);
    }

    /**
     * US-044 AC3/AC2: correcting an `attribute_definition` proposal with a
     * reviewer-edited key/type/unit/description creates the registry row
     * with the corrected values instead of the AI-proposed ones.
     */
    public function test_correct_action_saves_corrected_attribute_definition_values(): void
    {
        $admin = User::factory()->create();
        $proposal = EnrichmentProposal::factory()->attributeDefinition()->create([
            'attribute_key' => 'portata_lmin',
            'data_type' => 'numeric',
            'unit' => 'l/min',
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->callTableAction('correct', $proposal, [
                'attribute_key' => 'portata_l_min',
                'data_type' => 'numeric',
                'unit' => 'l/min',
                'value' => 'Portata nominale in litri al minuto',
            ]);

        $proposal->refresh();

        $this->assertDatabaseHas(AttributeDefinition::class, [
            'key' => 'portata_l_min',
            'data_type' => 'numeric',
            'canonical_unit' => 'l/min',
            'description' => 'Portata nominale in litri al minuto',
        ]);
        $this->assertDatabaseMissing(AttributeDefinition::class, ['key' => 'portata_lmin']);
        $this->assertSame('applied', $proposal->status);
    }

    /**
     * US-044 AC3: discarding an `attribute_definition` proposal only marks it
     * `discarded` — no registry row is created.
     */
    public function test_discard_action_on_attribute_definition_creates_no_registry_row(): void
    {
        $admin = User::factory()->create();
        $proposal = EnrichmentProposal::factory()->attributeDefinition()->create([
            'attribute_key' => 'portata_lmin',
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->callTableAction('discard', $proposal);

        $proposal->refresh();

        $this->assertSame('discarded', $proposal->status);
        $this->assertDatabaseMissing(AttributeDefinition::class, ['key' => 'portata_lmin']);
    }

    /**
     * US-044 AC4/idempotency: a key already registered by the time the
     * proposal is approved (e.g. a concurrent duplicate proposal was
     * approved first) must not create a duplicate row or raise an error.
     */
    public function test_confirm_action_on_attribute_definition_is_idempotent_when_key_already_registered(): void
    {
        $admin = User::factory()->create();
        AttributeDefinition::factory()->create([
            'key' => 'portata_lmin',
            'data_type' => 'text',
            'canonical_unit' => null,
            'description' => 'Descrizione originale',
        ]);
        $proposal = EnrichmentProposal::factory()->attributeDefinition()->create([
            'attribute_key' => 'portata_lmin',
            'data_type' => 'numeric',
            'unit' => 'l/min',
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->callTableAction('confirm', $proposal);

        $proposal->refresh();

        $this->assertSame('applied', $proposal->status);
        $this->assertDatabaseCount(AttributeDefinition::class, 1);
        $this->assertDatabaseHas(AttributeDefinition::class, [
            'key' => 'portata_lmin',
            'data_type' => 'text',
            'description' => 'Descrizione originale',
        ]);
    }

    /**
     * US-041 AC2: confirming an attribute proposal writes the technical
     * attribute row (creating it if missing) with `source` set to the
     * proposal's own `origin`, and marks the proposal `applied`.
     */
    public function test_confirm_action_writes_attribute_value_and_marks_proposal_applied(): void
    {
        $admin = User::factory()->create();
        $product = Product::factory()->create();
        $proposal = EnrichmentProposal::factory()->create([
            'product_id' => $product->id,
            'field' => 'attribute',
            'attribute_key' => 'kW',
            'value' => '1.5',
            'unit' => 'kW',
            'origin' => 'regex',
            'status' => 'pending',
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->callTableAction('confirm', $proposal);

        $proposal->refresh();
        $attribute = $product->attributes()->where('key', 'kW')->first();

        $this->assertNotNull($attribute);
        $this->assertSame('1.5', $attribute->value);
        $this->assertSame('kW', $attribute->unit);
        $this->assertSame('regex', $attribute->source);
        $this->assertSame('applied', $proposal->status);
    }

    public function test_confirm_selected_bulk_action_promotes_every_selected_proposal(): void
    {
        $admin = User::factory()->create();
        $firstBrand = Brand::factory()->create();
        $secondBrand = Brand::factory()->create();
        $firstProposal = EnrichmentProposal::factory()->create([
            'field' => 'brand',
            'value_id' => $firstBrand->id,
            'origin' => 'ai',
            'confidence' => 70,
            'status' => 'pending',
        ]);
        $secondProposal = EnrichmentProposal::factory()->create([
            'field' => 'brand',
            'value_id' => $secondBrand->id,
            'origin' => 'file',
            'confidence' => 40,
            'status' => 'pending',
        ]);
        $untouched = EnrichmentProposal::factory()->create(['status' => 'pending']);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->selectTableRecords([$firstProposal->getKey(), $secondProposal->getKey()])
            ->callAction(TestAction::make('confirmSelected')->table()->bulk())
            ->assertNotified('2 proposte confermate');

        $firstProposal->refresh();
        $secondProposal->refresh();
        $untouched->refresh();

        $this->assertSame('applied', $firstProposal->status);
        $this->assertSame($firstBrand->id, $firstProposal->product->brand_id);
        $this->assertSame('ai', $firstProposal->product->brand_source);

        $this->assertSame('applied', $secondProposal->status);
        $this->assertSame($secondBrand->id, $secondProposal->product->brand_id);
        $this->assertSame('file', $secondProposal->product->brand_source);

        $this->assertSame('pending', $untouched->status);
    }

    public function test_correct_action_brand_form_is_prefilled_with_the_proposals_current_value_id(): void
    {
        $admin = User::factory()->create();
        $brand = Brand::factory()->create();
        $proposal = EnrichmentProposal::factory()->create([
            'field' => 'brand',
            'value_id' => $brand->id,
            'status' => 'pending',
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->mountTableAction('correct', $proposal)
            ->assertTableActionDataSet(['value_id' => $brand->id]);
    }

    /**
     * US-041 AC3: correcting a brand proposal writes the submitted value to
     * the product with `brand_source = 'manual'` (regardless of the
     * proposal's own origin), and marks the proposal `applied`.
     */
    public function test_correct_action_saves_submitted_brand_value_as_manual(): void
    {
        $admin = User::factory()->create();
        $originalBrand = Brand::factory()->create();
        $correctedBrand = Brand::factory()->create();
        $proposal = EnrichmentProposal::factory()->create([
            'field' => 'brand',
            'value_id' => $originalBrand->id,
            'origin' => 'ai',
            'status' => 'pending',
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->callTableAction('correct', $proposal, ['value_id' => $correctedBrand->id]);

        $proposal->refresh();

        $this->assertSame($correctedBrand->id, $proposal->product->brand_id);
        $this->assertSame('manual', $proposal->product->brand_source);
        $this->assertSame('applied', $proposal->status);
    }

    public function test_correct_action_family_form_is_prefilled_and_saves_submitted_value_as_manual(): void
    {
        $admin = User::factory()->create();
        $originalFamily = Family::factory()->create();
        $correctedFamily = Family::factory()->create();
        $proposal = EnrichmentProposal::factory()->create([
            'field' => 'family',
            'value_id' => $originalFamily->id,
            'status' => 'pending',
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->mountTableAction('correct', $proposal)
            ->assertTableActionDataSet(['value_id' => $originalFamily->id])
            ->setTableActionData(['value_id' => $correctedFamily->id])
            ->callMountedTableAction();

        $proposal->refresh();

        $this->assertSame($correctedFamily->id, $proposal->product->family_id);
        $this->assertSame('manual', $proposal->product->family_source);
        $this->assertSame('applied', $proposal->status);
    }

    public function test_correct_action_subfamily_form_is_prefilled_and_saves_submitted_value_as_manual(): void
    {
        $admin = User::factory()->create();
        $family = Family::factory()->create();
        $originalSubfamily = Subfamily::factory()->create(['family_id' => $family->id]);
        $correctedSubfamily = Subfamily::factory()->create(['family_id' => $family->id]);
        $proposal = EnrichmentProposal::factory()->create([
            'field' => 'subfamily',
            'value_id' => $originalSubfamily->id,
            'status' => 'pending',
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->mountTableAction('correct', $proposal)
            ->assertTableActionDataSet(['value_id' => $originalSubfamily->id])
            ->setTableActionData(['value_id' => $correctedSubfamily->id])
            ->callMountedTableAction();

        $proposal->refresh();

        $this->assertSame($correctedSubfamily->id, $proposal->product->subfamily_id);
        $this->assertSame('manual', $proposal->product->subfamily_source);
        $this->assertSame('applied', $proposal->status);
    }

    public function test_correct_action_attribute_form_is_prefilled_with_current_value(): void
    {
        $admin = User::factory()->create();
        $proposal = EnrichmentProposal::factory()->create([
            'field' => 'attribute',
            'attribute_key' => 'kW',
            'value' => '1.5',
            'unit' => 'kW',
            'status' => 'pending',
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->mountTableAction('correct', $proposal)
            ->assertTableActionDataSet([
                'value' => '1.5',
                'unit' => 'kW',
            ]);
    }

    /**
     * US-041 AC3: correcting an attribute proposal writes the submitted
     * value/unit to the product's attribute row with `source = 'manual'`.
     */
    public function test_correct_action_saves_submitted_attribute_value_as_manual(): void
    {
        $admin = User::factory()->create();
        $product = Product::factory()->create();
        $proposal = EnrichmentProposal::factory()->create([
            'product_id' => $product->id,
            'field' => 'attribute',
            'attribute_key' => 'kW',
            'value' => '1.5',
            'unit' => 'kW',
            'origin' => 'regex',
            'status' => 'pending',
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->callTableAction('correct', $proposal, [
                'value' => '3.2',
                'unit' => 'kW',
            ]);

        $proposal->refresh();
        $attribute = $product->attributes()->where('key', 'kW')->first();

        $this->assertNotNull($attribute);
        $this->assertSame('3.2', $attribute->value);
        $this->assertSame('manual', $attribute->source);
        $this->assertSame('applied', $proposal->status);
    }

    /**
     * US-041 AC4: discarding a proposal only marks it `discarded` — the
     * product is never touched, since the pending value was never applied in
     * the first place.
     */
    public function test_discard_action_only_marks_the_proposal_discarded_and_leaves_the_product_untouched(): void
    {
        $admin = User::factory()->create();
        $brand = Brand::factory()->create();
        $product = Product::factory()->create([
            'brand_id' => $brand->id,
            'brand_source' => 'file',
            'source' => 'file',
            'confidence' => 90,
            'enrichment_status' => 'needs_review',
        ]);

        $proposal = EnrichmentProposal::factory()->create([
            'product_id' => $product->id,
            'field' => 'attribute',
            'attribute_key' => 'kW',
            'value' => '1.5',
            'unit' => 'kW',
            'status' => 'pending',
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->callTableAction('discard', $proposal)
            ->assertCanNotSeeTableRecords([$proposal]);

        $proposal->refresh();
        $product->refresh();

        $this->assertSame('discarded', $proposal->status);
        $this->assertSame($brand->id, $product->brand_id);
        $this->assertSame('file', $product->brand_source);
        $this->assertNull($product->family_id);
        $this->assertNull($product->subfamily_id);
        $this->assertSame('file', $product->source);
        $this->assertSame(90, $product->confidence);
        $this->assertSame('needs_review', $product->enrichment_status);
        $this->assertSame(0, $product->attributes()->count());
    }

    public function test_discard_selected_bulk_action_discards_every_selected_proposal(): void
    {
        $admin = User::factory()->create();
        $first = EnrichmentProposal::factory()->create(['field' => 'brand', 'status' => 'pending']);
        $second = EnrichmentProposal::factory()->create(['field' => 'family', 'status' => 'pending']);
        $untouched = EnrichmentProposal::factory()->create(['status' => 'pending']);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->selectTableRecords([$first->getKey(), $second->getKey()])
            ->callAction(TestAction::make('discardSelected')->table()->bulk())
            ->assertNotified('2 proposte scartate');

        $first->refresh();
        $second->refresh();
        $untouched->refresh();

        $this->assertSame('discarded', $first->status);
        $this->assertSame('discarded', $second->status);
        $this->assertSame('pending', $untouched->status);

        $this->assertNull($first->product->fresh()->brand_id);
        $this->assertNull($second->product->fresh()->family_id);
    }

    /**
     * US-037 AC5 (non-regression): "Correggi" requires a per-record form and
     * has no sensible bulk equivalent, so it must never be exposed as a
     * bulk/toolbar action.
     */
    public function test_correct_action_is_not_exposed_as_a_bulk_action(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $component = Livewire::test(ReviewQueue::class)
            ->assertActionExists(TestAction::make('confirmSelected')->table()->bulk())
            ->assertActionExists(TestAction::make('discardSelected')->table()->bulk());

        $toolbarActionNames = collect($component->instance()->getTable()->getToolbarActions())
            ->map(fn (Action $action): string => $action->getName())
            ->all();

        $this->assertNotContains('correct', $toolbarActionNames);
    }

    /**
     * US-038/US-041: each row exposes a "Dettagli" link action resolving to
     * the standalone {@see ReviewQueueDetail} page for the proposal's
     * underlying PRODUCT, not for the proposal itself.
     */
    public function test_view_detail_action_links_to_the_review_queue_detail_page_of_the_underlying_product(): void
    {
        $admin = User::factory()->create();
        $product = Product::factory()->create();
        $proposal = EnrichmentProposal::factory()->create([
            'product_id' => $product->id,
            'status' => 'pending',
        ]);

        $this->actingAs($admin);

        $component = Livewire::test(ReviewQueue::class);

        $action = $component->instance()->getTable()->getAction('viewDetail');
        $action->record($proposal);

        $this->assertSame(ReviewQueueDetail::getUrl(['record' => $product]), $action->getUrl());
    }

    /**
     * Reads the fully formatted (post `->formatStateUsing()`) state for a
     * table column, scoped to a single proposal record.
     */
    private function formattedColumnState(Testable $component, string $columnName, EnrichmentProposal $record): string
    {
        $column = $this->resolveColumn($component, $columnName, $record);

        return (string) $column->formatState($column->getState());
    }

    private function resolveColumn(Testable $component, string $columnName, EnrichmentProposal $record): Column
    {
        $column = $component->instance()->getTable()->getColumn($columnName);

        $column->record($record->fresh());
        $column->clearCachedState();

        return $column;
    }
}
