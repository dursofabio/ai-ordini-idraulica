<?php

namespace Tests\Feature;

use App\Filament\Pages\ReviewQueue;
use App\Models\Brand;
use App\Models\Family;
use App\Models\Product;
use App\Models\Subfamily;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
