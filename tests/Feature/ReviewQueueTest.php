<?php

namespace Tests\Feature;

use App\Filament\Pages\ReviewQueue;
use App\Models\Brand;
use App\Models\Family;
use App\Models\Product;
use App\Models\Subfamily;
use App\Models\User;
use Filament\Tables\Columns\Column;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-023 acceptance criteria for the "Da revisionare" review queue:
 *  - AC1: the queue lists only `enrichment_status = 'needs_review'` products,
 *    ordered by ascending confidence (NULL first), showing
 *    description_raw/brand.name/family.name/confidence.
 *  - AC2: "Confirm" promotes the AI proposal as-is
 *    (`enrichment_status = 'enriched'`) without touching
 *    brand_id/family_id/subfamily_id or any `*_source`/`source`.
 *  - AC3: "Correct" saves the submitted values with `*_source = 'manual'`,
 *    `source = 'manual'`, `confidence = 100`, `enrichment_status = 'enriched'`,
 *    with the form precompiled from the record's current values.
 *  - AC4: "Discard" clears any non-manual AI proposal while preserving
 *    fields already `*_source = 'manual'`, and keeps `needs_review`.
 *  - AC5: the page heading shows the current queue count and reflects it
 *    after an action changes the queue.
 */
class ReviewQueueTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_queue_only_lists_needs_review_products(): void
    {
        $admin = User::factory()->create();
        $needsReview = Product::factory()->create(['enrichment_status' => 'needs_review']);
        $pending = Product::factory()->create(['enrichment_status' => 'pending']);
        $enriched = Product::factory()->create(['enrichment_status' => 'enriched']);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->assertCanSeeTableRecords([$needsReview])
            ->assertCanNotSeeTableRecords([$pending, $enriched]);
    }

    public function test_queue_is_ordered_by_ascending_confidence_with_null_first(): void
    {
        $admin = User::factory()->create();
        $highConfidence = Product::factory()->create(['enrichment_status' => 'needs_review', 'confidence' => 80]);
        $lowConfidence = Product::factory()->create(['enrichment_status' => 'needs_review', 'confidence' => 20]);
        $noConfidence = Product::factory()->create(['enrichment_status' => 'needs_review', 'confidence' => null]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->assertCanSeeTableRecords([$noConfidence, $lowConfidence, $highConfidence], inOrder: true);
    }

    public function test_queue_columns_show_description_brand_family_and_confidence(): void
    {
        $admin = User::factory()->create();
        $brand = Brand::factory()->create(['name' => 'Marca AI']);
        $family = Family::factory()->create(['name' => 'Famiglia AI']);
        $product = Product::factory()->create([
            'enrichment_status' => 'needs_review',
            'description_raw' => 'Raccordo a T da 3/4 pollici',
            'brand_id' => $brand->id,
            'family_id' => $family->id,
            'confidence' => 45,
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->assertTableColumnStateSet('description_raw', 'Raccordo a T da 3/4 pollici', $product)
            ->assertTableColumnStateSet('brand.name', 'Marca AI', $product)
            ->assertTableColumnStateSet('family.name', 'Famiglia AI', $product)
            ->assertTableColumnStateSet('confidence', 45, $product);
    }

    public function test_heading_shows_queue_count_and_updates_after_an_action(): void
    {
        $admin = User::factory()->create();
        $first = Product::factory()->create(['enrichment_status' => 'needs_review']);
        Product::factory()->create(['enrichment_status' => 'needs_review']);

        $this->actingAs($admin);

        $component = Livewire::test(ReviewQueue::class);

        $component->assertSee('2 articoli da revisionare');

        $component->callTableAction('confirm', $first);

        $component->assertSee('1 articoli da revisionare');
    }

    public function test_confirm_action_promotes_ai_proposal_and_removes_record_from_queue(): void
    {
        $admin = User::factory()->create();
        $brand = Brand::factory()->create();
        $family = Family::factory()->create();
        $product = Product::factory()->create([
            'enrichment_status' => 'needs_review',
            'brand_id' => $brand->id,
            'family_id' => $family->id,
            'brand_source' => 'ai',
            'family_source' => 'ai',
            'source' => 'ai',
            'confidence' => 70,
        ]);

        $this->actingAs($admin);

        $component = Livewire::test(ReviewQueue::class);
        $component->callTableAction('confirm', $product);

        $product->refresh();

        $this->assertSame('enriched', $product->enrichment_status);
        $this->assertSame($brand->id, $product->brand_id);
        $this->assertSame($family->id, $product->family_id);
        $this->assertSame('ai', $product->brand_source);
        $this->assertSame('ai', $product->family_source);
        $this->assertSame('ai', $product->source);
        $this->assertSame(70, $product->confidence);

        $component->assertCanNotSeeTableRecords([$product]);
    }

    public function test_correct_action_form_is_prefilled_with_current_values(): void
    {
        $admin = User::factory()->create();
        $brand = Brand::factory()->create();
        $family = Family::factory()->create();
        $subfamily = Subfamily::factory()->create(['family_id' => $family->id]);
        $product = Product::factory()->create([
            'enrichment_status' => 'needs_review',
            'brand_id' => $brand->id,
            'family_id' => $family->id,
            'subfamily_id' => $subfamily->id,
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->mountTableAction('correct', $product)
            ->assertTableActionDataSet([
                'brand_id' => $brand->id,
                'family_id' => $family->id,
                'subfamily_id' => $subfamily->id,
            ]);
    }

    public function test_correct_action_saves_submitted_values_as_manual(): void
    {
        $admin = User::factory()->create();
        $originalBrand = Brand::factory()->create();
        $correctedBrand = Brand::factory()->create();
        $correctedFamily = Family::factory()->create();
        $correctedSubfamily = Subfamily::factory()->create(['family_id' => $correctedFamily->id]);
        $product = Product::factory()->create([
            'enrichment_status' => 'needs_review',
            'brand_id' => $originalBrand->id,
            'brand_source' => 'ai',
            'source' => 'ai',
            'confidence' => 50,
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->callTableAction('correct', $product, [
                'brand_id' => $correctedBrand->id,
                'family_id' => $correctedFamily->id,
                'subfamily_id' => $correctedSubfamily->id,
            ]);

        $product->refresh();

        $this->assertSame($correctedBrand->id, $product->brand_id);
        $this->assertSame($correctedFamily->id, $product->family_id);
        $this->assertSame($correctedSubfamily->id, $product->subfamily_id);
        $this->assertSame('manual', $product->brand_source);
        $this->assertSame('manual', $product->family_source);
        $this->assertSame('manual', $product->subfamily_source);
        $this->assertSame('manual', $product->source);
        $this->assertSame(100, $product->confidence);
        $this->assertSame('enriched', $product->enrichment_status);
    }

    public function test_discard_action_clears_non_manual_ai_fields_and_keeps_needs_review(): void
    {
        $admin = User::factory()->create();
        $brand = Brand::factory()->create();
        $family = Family::factory()->create();
        $product = Product::factory()->create([
            'enrichment_status' => 'needs_review',
            'brand_id' => $brand->id,
            'family_id' => $family->id,
            'brand_source' => 'ai',
            'family_source' => 'ai',
            'source' => 'ai',
            'confidence' => 30,
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->callTableAction('discard', $product)
            ->assertCanSeeTableRecords([$product]);

        $product->refresh();

        $this->assertNull($product->brand_id);
        $this->assertNull($product->family_id);
        $this->assertNull($product->brand_source);
        $this->assertNull($product->family_source);
        $this->assertNull($product->source);
        $this->assertNull($product->confidence);
        $this->assertSame('needs_review', $product->enrichment_status);
    }

    public function test_discard_action_preserves_fields_already_manual(): void
    {
        $admin = User::factory()->create();
        $manualBrand = Brand::factory()->create();
        $aiFamily = Family::factory()->create();
        $product = Product::factory()->create([
            'enrichment_status' => 'needs_review',
            'brand_id' => $manualBrand->id,
            'brand_source' => 'manual',
            'family_id' => $aiFamily->id,
            'family_source' => 'ai',
            'source' => 'ai',
            'confidence' => 40,
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->callTableAction('discard', $product);

        $product->refresh();

        $this->assertSame($manualBrand->id, $product->brand_id);
        $this->assertSame('manual', $product->brand_source);
        $this->assertNull($product->family_id);
        $this->assertNull($product->family_source);
        $this->assertSame('needs_review', $product->enrichment_status);
    }

    public function test_subfamily_column_shows_proposed_subfamily_name(): void
    {
        $admin = User::factory()->create();
        $family = Family::factory()->create();
        $subfamily = Subfamily::factory()->create(['family_id' => $family->id, 'name' => 'Sottofamiglia AI']);
        $product = Product::factory()->create([
            'enrichment_status' => 'needs_review',
            'family_id' => $family->id,
            'subfamily_id' => $subfamily->id,
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->assertTableColumnStateSet('subfamily.name', 'Sottofamiglia AI', $product);
    }

    public function test_attributes_column_formats_numeric_attribute_with_trimmed_value(): void
    {
        $admin = User::factory()->create();
        $product = Product::factory()->create(['enrichment_status' => 'needs_review']);
        $product->attributes()->create([
            'key' => 'kW',
            'value_num' => 1.5,
            'value_text' => null,
            'unit' => 'kW',
            'source' => 'regex',
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->assertTableColumnStateSet('attributes', ['kW: 1.5 kW · Dedotta · Confidenza: N/D'], $product->fresh());
    }

    /**
     * Guards against naive trailing-zero trimming corrupting whole-number
     * values (e.g. "10" becoming "1"): `value_num` is cast `decimal:3`, so
     * Eloquent always yields a fixed 3-decimal string ("10.000"), and the
     * column's rtrim(..., '.') stops exactly at the decimal point.
     */
    public function test_attributes_column_does_not_strip_significant_digits_from_whole_number_value(): void
    {
        $admin = User::factory()->create();
        $product = Product::factory()->create(['enrichment_status' => 'needs_review']);
        $product->attributes()->create([
            'key' => 'DN',
            'value_num' => 100,
            'value_text' => null,
            'unit' => null,
            'source' => 'regex',
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->assertTableColumnStateSet('attributes', ['DN: 100 · Dedotta · Confidenza: N/D'], $product->fresh());
    }

    public function test_attributes_column_lists_multiple_attributes_as_separate_items(): void
    {
        $admin = User::factory()->create();
        $product = Product::factory()->create(['enrichment_status' => 'needs_review']);
        $product->attributes()->create([
            'key' => 'kW',
            'value_num' => 1.5,
            'value_text' => null,
            'unit' => 'kW',
            'source' => 'regex',
        ]);
        $product->attributes()->create([
            'key' => 'Materiale',
            'value_num' => null,
            'value_text' => 'Ottone',
            'unit' => null,
            'source' => 'ai',
        ]);

        $this->actingAs($admin);

        $component = Livewire::test(ReviewQueue::class);
        $state = $this->resolveColumn($component, 'attributes', $product)->getState();

        $this->assertCount(2, $state);
        $this->assertContains('kW: 1.5 kW · Dedotta · Confidenza: N/D', $state);
        $this->assertContains('Materiale: Ottone · AI · Confidenza: N/D', $state);
    }

    public function test_attributes_column_shows_dash_when_product_has_no_attributes(): void
    {
        $admin = User::factory()->create();
        $product = Product::factory()->create(['enrichment_status' => 'needs_review']);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->assertTableColumnStateSet('attributes', null, $product);
    }

    public function test_brand_family_and_subfamily_descriptions_use_their_own_source_field(): void
    {
        $admin = User::factory()->create();
        $brand = Brand::factory()->create();
        $family = Family::factory()->create();
        $subfamily = Subfamily::factory()->create(['family_id' => $family->id]);
        $product = Product::factory()->create([
            'enrichment_status' => 'needs_review',
            'brand_id' => $brand->id,
            'brand_source' => 'ai',
            'family_id' => $family->id,
            'family_source' => 'regex',
            'subfamily_id' => $subfamily->id,
            'subfamily_source' => 'file',
        ]);

        $this->actingAs($admin);

        $component = Livewire::test(ReviewQueue::class);

        $this->assertSame('Origine: AI', $this->columnDescription($component, 'brand.name', $product));
        $this->assertSame('Origine: Dedotta', $this->columnDescription($component, 'family.name', $product));
        $this->assertSame('Origine: Da file', $this->columnDescription($component, 'subfamily.name', $product));
    }

    /**
     * @return array<int, array{0: ?string, 1: string}>
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
            'unknown/null' => [null, '—'],
        ];
    }

    public function test_origin_label_maps_each_source_value_to_expected_label(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $component = Livewire::test(ReviewQueue::class);

        foreach (self::originSourceProvider() as [$source, $expectedLabel]) {
            $brand = Brand::factory()->create();
            $product = Product::factory()->create([
                'enrichment_status' => 'needs_review',
                'brand_id' => $brand->id,
                'brand_source' => $source,
            ]);

            $this->assertSame(
                "Origine: {$expectedLabel}",
                $this->columnDescription($component, 'brand.name', $product),
                "Failed asserting origin label for source [{$source}]."
            );
        }
    }

    public function test_confidence_column_formats_null_state_as_nd_and_keeps_percentage_for_values(): void
    {
        $admin = User::factory()->create();
        $withoutConfidence = Product::factory()->create(['enrichment_status' => 'needs_review', 'confidence' => null]);
        $withConfidence = Product::factory()->create(['enrichment_status' => 'needs_review', 'confidence' => 45]);

        $this->actingAs($admin);

        $component = Livewire::test(ReviewQueue::class);

        $this->assertSame('N/D', $this->formattedColumnState($component, 'confidence', $withoutConfidence));
        $this->assertSame('45%', $this->formattedColumnState($component, 'confidence', $withConfidence));
    }

    public function test_confirm_action_still_works_when_product_has_attributes_and_subfamily(): void
    {
        $admin = User::factory()->create();
        $family = Family::factory()->create();
        $subfamily = Subfamily::factory()->create(['family_id' => $family->id]);
        $product = Product::factory()->create([
            'enrichment_status' => 'needs_review',
            'family_id' => $family->id,
            'subfamily_id' => $subfamily->id,
        ]);
        $product->attributes()->create([
            'key' => 'kW',
            'value_num' => 2,
            'unit' => 'kW',
            'source' => 'ai',
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->callTableAction('confirm', $product);

        $product->refresh();

        $this->assertSame('enriched', $product->enrichment_status);
    }

    /**
     * US-036 AC1/AC2: `codice_articolo` and the raw file-imported taxonomy
     * (marca/famiglia/sottofamiglia) plus `costo`/`giacenza` are shown
     * alongside the existing AI-proposed columns for comparison.
     */
    public function test_queue_shows_codice_articolo_raw_file_taxonomy_costo_and_giacenza(): void
    {
        $admin = User::factory()->create();
        $family = Family::factory()->create();
        $subfamily = Subfamily::factory()->create(['family_id' => $family->id]);
        $product = Product::factory()->create([
            'enrichment_status' => 'needs_review',
            'codice_articolo' => 'ART-12345',
            'descrizione_marca' => 'Marca da file SPA',
            'fam_descrizione' => 'Famiglia da file',
            'subfam_descrizione' => 'Sottofamiglia da file',
            'family_id' => $family->id,
            'subfamily_id' => $subfamily->id,
            'costo' => 42.5,
            'giacenza' => 17,
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->assertTableColumnStateSet('codice_articolo', 'ART-12345', $product)
            ->assertTableColumnStateSet('descrizione_marca', 'Marca da file SPA', $product)
            ->assertTableColumnStateSet('fam_descrizione', 'Famiglia da file', $product)
            ->assertTableColumnStateSet('subfam_descrizione', 'Sottofamiglia da file', $product)
            ->assertTableColumnStateSet('costo', '42.50', $product)
            ->assertTableColumnStateSet('giacenza', '17.00', $product);
    }

    /**
     * US-036 AC3: brand/family/subfamily/confidence columns are sortable by
     * clicking their header, overriding the table's default confidence-based
     * ordering.
     */
    public function test_brand_family_subfamily_and_confidence_columns_are_sortable(): void
    {
        $admin = User::factory()->create();
        $brandA = Brand::factory()->create(['name' => 'Alfa Marca']);
        $brandB = Brand::factory()->create(['name' => 'Beta Marca']);
        $familyA = Family::factory()->create(['name' => 'Alfa Famiglia']);
        $familyB = Family::factory()->create(['name' => 'Beta Famiglia']);
        $subfamilyA = Subfamily::factory()->create(['name' => 'Alfa Sottofamiglia', 'family_id' => $familyA->id]);
        $subfamilyB = Subfamily::factory()->create(['name' => 'Beta Sottofamiglia', 'family_id' => $familyB->id]);

        $first = Product::factory()->create([
            'enrichment_status' => 'needs_review',
            'brand_id' => $brandA->id,
            'family_id' => $familyA->id,
            'subfamily_id' => $subfamilyA->id,
            'confidence' => 20,
        ]);
        $second = Product::factory()->create([
            'enrichment_status' => 'needs_review',
            'brand_id' => $brandB->id,
            'family_id' => $familyB->id,
            'subfamily_id' => $subfamilyB->id,
            'confidence' => 80,
        ]);

        $this->actingAs($admin);

        $component = Livewire::test(ReviewQueue::class);

        $component->sortTable('brand.name')
            ->assertCanSeeTableRecords([$first, $second], inOrder: true);
        $component->sortTable('brand.name', 'desc')
            ->assertCanSeeTableRecords([$second, $first], inOrder: true);

        $component->sortTable('family.name')
            ->assertCanSeeTableRecords([$first, $second], inOrder: true);
        $component->sortTable('family.name', 'desc')
            ->assertCanSeeTableRecords([$second, $first], inOrder: true);

        $component->sortTable('subfamily.name')
            ->assertCanSeeTableRecords([$first, $second], inOrder: true);
        $component->sortTable('subfamily.name', 'desc')
            ->assertCanSeeTableRecords([$second, $first], inOrder: true);

        $component->sortTable('confidence')
            ->assertCanSeeTableRecords([$first, $second], inOrder: true);
        $component->sortTable('confidence', 'desc')
            ->assertCanSeeTableRecords([$second, $first], inOrder: true);
    }

    /**
     * US-036 AC4: SelectFilter on brand/family/subfamily narrows the queue to
     * products with the selected relationship.
     */
    public function test_brand_family_and_subfamily_filters_narrow_the_queue(): void
    {
        $admin = User::factory()->create();
        $brand = Brand::factory()->create();
        $otherBrand = Brand::factory()->create();
        $family = Family::factory()->create();
        $otherFamily = Family::factory()->create();
        $subfamily = Subfamily::factory()->create(['family_id' => $family->id]);
        $otherSubfamily = Subfamily::factory()->create(['family_id' => $otherFamily->id]);

        $matching = Product::factory()->create([
            'enrichment_status' => 'needs_review',
            'brand_id' => $brand->id,
            'family_id' => $family->id,
            'subfamily_id' => $subfamily->id,
        ]);
        $other = Product::factory()->create([
            'enrichment_status' => 'needs_review',
            'brand_id' => $otherBrand->id,
            'family_id' => $otherFamily->id,
            'subfamily_id' => $otherSubfamily->id,
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueue::class)
            ->filterTable('brand', $brand->id)
            ->assertCanSeeTableRecords([$matching])
            ->assertCanNotSeeTableRecords([$other])
            ->resetTableFilters()
            ->filterTable('family', $family->id)
            ->assertCanSeeTableRecords([$matching])
            ->assertCanNotSeeTableRecords([$other])
            ->resetTableFilters()
            ->filterTable('subfamily', $subfamily->id)
            ->assertCanSeeTableRecords([$matching])
            ->assertCanNotSeeTableRecords([$other]);
    }

    /**
     * US-036 AC4: the `confidence_band` filter applies the bassa (<60),
     * media (60-84) and alta (>=85) thresholds, with edge cases exactly on
     * the 59/60 and 84/85 boundaries and a null-confidence product excluded
     * from every band.
     */
    public function test_confidence_band_filter_applies_low_medium_and_high_thresholds(): void
    {
        $admin = User::factory()->create();
        $justBelowLow = Product::factory()->create(['enrichment_status' => 'needs_review', 'confidence' => 59]);
        $justAtMedium = Product::factory()->create(['enrichment_status' => 'needs_review', 'confidence' => 60]);
        $justAtMediumEnd = Product::factory()->create(['enrichment_status' => 'needs_review', 'confidence' => 84]);
        $justAtHigh = Product::factory()->create(['enrichment_status' => 'needs_review', 'confidence' => 85]);
        $noConfidence = Product::factory()->create(['enrichment_status' => 'needs_review', 'confidence' => null]);

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

    /**
     * Reads the rendered `->description()` (below the state) for a table
     * column, scoped to a single record — mirrors how Filament's own
     * `assertTableColumnStateSet()` resolves state for a given record.
     */
    private function columnDescription(Testable $component, string $columnName, Product $record): ?string
    {
        $column = $this->resolveColumn($component, $columnName, $record);

        $description = $column->getDescriptionBelow();

        return $description === null ? null : (string) $description;
    }

    /**
     * Reads the fully formatted (post `->formatStateUsing()`) state for a
     * table column, scoped to a single record.
     */
    private function formattedColumnState(Testable $component, string $columnName, Product $record): string
    {
        $column = $this->resolveColumn($component, $columnName, $record);

        return (string) $column->formatState($column->getState());
    }

    private function resolveColumn(Testable $component, string $columnName, Product $record): Column
    {
        $column = $component->instance()->getTable()->getColumn($columnName);

        $column->record($record->fresh());
        $column->clearCachedState();

        return $column;
    }
}
