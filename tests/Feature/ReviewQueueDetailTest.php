<?php

namespace Tests\Feature;

use App\Filament\Pages\ReviewQueue;
use App\Filament\Pages\ReviewQueueDetail;
use App\Models\Brand;
use App\Models\EnrichmentProposal;
use App\Models\Family;
use App\Models\Product;
use App\Models\Subfamily;
use App\Models\User;
use App\Services\Enrichment\EnrichmentApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-038 acceptance criteria for the standalone review-queue detail page:
 *  - AC2: the page shows every piece of available product information — raw
 *    file data, AI proposals with their origin, technical attributes with
 *    their origin, and confidence.
 *  - AC3: the form is precompiled with the record's current
 *    brand/family/subfamily and exposes a repeater to edit technical
 *    attributes.
 *  - AC4: saving applies the exact same field semantics as
 *    `ReviewQueue::correctAction()` (`source = 'manual'`, `confidence = 100`,
 *    `enrichment_status = 'enriched'`) and redirects to the queue; it also
 *    persists changes/additions to technical attributes.
 */
class ReviewQueueDetailTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_page_shows_all_available_product_information(): void
    {
        $admin = User::factory()->create();
        $brand = Brand::factory()->create(['name' => 'Marca AI']);
        $family = Family::factory()->create(['name' => 'Famiglia AI']);
        $subfamily = Subfamily::factory()->create(['name' => 'Sottofamiglia AI', 'family_id' => $family->id]);
        $product = Product::factory()->create([
            'enrichment_status' => 'needs_review',
            'codice_articolo' => 'ART-99001',
            'description_raw' => 'Valvola a sfera 1 pollice',
            'descrizione_marca' => 'Marca da file SPA',
            'fam_descrizione' => 'Famiglia da file',
            'subfam_descrizione' => 'Sottofamiglia da file',
            'costo' => 42.5,
            'giacenza' => 17,
            'brand_id' => $brand->id,
            'family_id' => $family->id,
            'subfamily_id' => $subfamily->id,
            'brand_source' => 'ai',
            'family_source' => 'regex',
            'subfamily_source' => 'file',
            'confidence' => 70,
        ]);
        $product->attributes()->create([
            'key' => 'kW',
            'value' => '1.5',
            'unit' => 'kW',
            'source' => 'regex',
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueueDetail::class, ['record' => $product->getRouteKey()])
            ->assertSee('ART-99001')
            ->assertSee('Valvola a sfera 1 pollice')
            ->assertSee('Marca da file SPA')
            ->assertSee('Famiglia da file')
            ->assertSee('Sottofamiglia da file')
            ->assertSee('Marca AI')
            ->assertSee('Origine: AI')
            ->assertSee('Famiglia AI')
            ->assertSee('Origine: Dedotta')
            ->assertSee('Sottofamiglia AI')
            ->assertSee('Origine: Da file')
            ->assertSee('70%')
            ->assertSee('kW: 1.5 kW · Dedotta');
    }

    public function test_form_is_prefilled_with_current_brand_family_and_subfamily(): void
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

        Livewire::test(ReviewQueueDetail::class, ['record' => $product->getRouteKey()])
            ->assertSchemaStateSet([
                'brand_id' => $brand->id,
                'family_id' => $family->id,
                'subfamily_id' => $subfamily->id,
            ]);
    }

    public function test_save_applies_manual_correction_semantics_and_redirects_to_queue(): void
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

        Livewire::test(ReviewQueueDetail::class, ['record' => $product->getRouteKey()])
            ->fillForm([
                'brand_id' => $correctedBrand->id,
                'family_id' => $correctedFamily->id,
                'subfamily_id' => $correctedSubfamily->id,
            ])
            ->call('save')
            ->assertRedirect(ReviewQueue::getUrl());

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

    public function test_save_persists_a_modified_technical_attribute_as_manual(): void
    {
        $admin = User::factory()->create();
        $product = Product::factory()->create(['enrichment_status' => 'needs_review']);
        $attribute = $product->attributes()->create([
            'key' => 'kW',
            'value' => '1.5',
            'unit' => 'kW',
            'source' => 'regex',
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueueDetail::class, ['record' => $product->getRouteKey()])
            ->fillForm([
                'attributes' => [
                    "record-{$attribute->id}" => [
                        'key' => 'kW',
                        'value' => '3.2',
                        'unit' => 'kW',
                    ],
                ],
            ])
            ->call('save');

        $attribute->refresh();

        $this->assertSame('3.2', $attribute->value);
        $this->assertSame('manual', $attribute->source);
    }

    /**
     * Regression guard: saving a correction to brand/family/subfamily (or to
     * one attribute among several) must not silently overwrite the
     * AI/regex/dictionary/file provenance of an untouched attribute — the
     * repeater round-trips the full relationship on every save, so the
     * "manual" stamp must only apply to rows that actually changed.
     */
    public function test_save_does_not_overwrite_source_of_an_untouched_technical_attribute(): void
    {
        $admin = User::factory()->create();
        $correctedBrand = Brand::factory()->create();
        $product = Product::factory()->create(['enrichment_status' => 'needs_review']);
        $untouchedAttribute = $product->attributes()->create([
            'key' => 'kW',
            'value' => '1.5',
            'unit' => 'kW',
            'source' => 'regex',
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueueDetail::class, ['record' => $product->getRouteKey()])
            ->fillForm(['brand_id' => $correctedBrand->id])
            ->call('save');

        $untouchedAttribute->refresh();

        $this->assertSame('regex', $untouchedAttribute->source);
        $this->assertSame('1.5', $untouchedAttribute->value);
    }

    public function test_save_persists_a_newly_added_technical_attribute_as_manual(): void
    {
        $admin = User::factory()->create();
        $product = Product::factory()->create(['enrichment_status' => 'needs_review']);

        $this->actingAs($admin);

        Livewire::test(ReviewQueueDetail::class, ['record' => $product->getRouteKey()])
            ->fillForm([
                'attributes' => [
                    'new-attribute-1' => [
                        'key' => 'Materiale',
                        'value' => 'Ottone',
                        'unit' => null,
                    ],
                ],
            ])
            ->call('save');

        $product->refresh();

        $this->assertSame(1, $product->attributes()->count());
        $newAttribute = $product->attributes()->first();
        $this->assertSame('Materiale', $newAttribute->key);
        $this->assertSame('Ottone', $newAttribute->value);
        $this->assertSame('manual', $newAttribute->source);
    }

    /**
     * US-041 regression: brand/family/subfamily are always resolved by a
     * full save (the form always submits a value for them), so any
     * `pending` proposal for those fields must be marked `applied` —
     * otherwise it would linger as a ghost row in the rewritten,
     * per-proposal {@see ReviewQueue}. A pending attribute proposal whose
     * key already has a `product_attributes` row is also resolved, since the
     * admin had a chance to see and review that row on this page even
     * without editing it.
     */
    public function test_save_marks_still_pending_taxonomy_and_resolved_attribute_proposals_as_applied(): void
    {
        $admin = User::factory()->create();
        $correctedBrand = Brand::factory()->create();
        $correctedFamily = Family::factory()->create();
        $correctedSubfamily = Subfamily::factory()->create(['family_id' => $correctedFamily->id]);
        $product = Product::factory()->create(['enrichment_status' => 'needs_review']);
        $product->attributes()->create([
            'key' => 'kW',
            'value' => '1.5',
            'unit' => 'kW',
            'source' => 'regex',
        ]);

        $pendingFamilyProposal = EnrichmentProposal::factory()->create([
            'product_id' => $product->id,
            'field' => 'family',
            'status' => 'pending',
        ]);
        $pendingAttributeProposal = EnrichmentProposal::factory()->create([
            'product_id' => $product->id,
            'field' => 'attribute',
            'attribute_key' => 'kW',
            'status' => 'pending',
        ]);
        $otherProductProposal = EnrichmentProposal::factory()->create(['status' => 'pending']);

        $this->actingAs($admin);

        Livewire::test(ReviewQueueDetail::class, ['record' => $product->getRouteKey()])
            ->fillForm([
                'brand_id' => $correctedBrand->id,
                'family_id' => $correctedFamily->id,
                'subfamily_id' => $correctedSubfamily->id,
            ])
            ->call('save')
            ->assertRedirect(ReviewQueue::getUrl());

        $pendingFamilyProposal->refresh();
        $pendingAttributeProposal->refresh();
        $otherProductProposal->refresh();

        $this->assertSame('applied', $pendingFamilyProposal->status);
        $this->assertSame('applied', $pendingAttributeProposal->status);
        $this->assertSame('pending', $otherProductProposal->status);
    }

    /**
     * US-045: this page has no field to correct `product_type` (out of
     * scope), so saving brand/family/subfamily corrections must leave an
     * independent pending `product_type` proposal on the same product
     * genuinely `pending` instead of silently marking it `applied`.
     */
    public function test_save_leaves_an_independent_pending_product_type_proposal_untouched(): void
    {
        $admin = User::factory()->create();
        $correctedBrand = Brand::factory()->create();
        $correctedFamily = Family::factory()->create();
        $correctedSubfamily = Subfamily::factory()->create(['family_id' => $correctedFamily->id]);
        $product = Product::factory()->create(['enrichment_status' => 'needs_review']);

        $pendingProductTypeProposal = EnrichmentProposal::factory()->create([
            'product_id' => $product->id,
            'field' => 'product_type',
            'value' => 'Caldaia a condensazione',
            'status' => 'pending',
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueueDetail::class, ['record' => $product->getRouteKey()])
            ->fillForm([
                'brand_id' => $correctedBrand->id,
                'family_id' => $correctedFamily->id,
                'subfamily_id' => $correctedSubfamily->id,
            ])
            ->call('save')
            ->assertRedirect(ReviewQueue::getUrl());

        $pendingProductTypeProposal->refresh();

        $this->assertSame('pending', $pendingProductTypeProposal->status);
        $this->assertNull($product->fresh()->product_type);
    }

    /**
     * Regression guard: a `pending` attribute proposal whose confidence was
     * too low to ever be written to `product_attributes` (see
     * {@see EnrichmentApplier}) never materializes a
     * row in the repeater, so the admin never actually saw or reviewed it on
     * this page. A full-page save must NOT silently mark it `applied` — that
     * would make the proposed value permanently disappear from the queue
     * without ever having been applied anywhere.
     */
    public function test_save_does_not_apply_a_pending_attribute_proposal_that_was_never_materialized(): void
    {
        $admin = User::factory()->create();
        $product = Product::factory()->create(['enrichment_status' => 'needs_review']);

        $neverWrittenAttributeProposal = EnrichmentProposal::factory()->create([
            'product_id' => $product->id,
            'field' => 'attribute',
            'attribute_key' => 'Materiale',
            'status' => 'pending',
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueueDetail::class, ['record' => $product->getRouteKey()])
            ->call('save')
            ->assertRedirect(ReviewQueue::getUrl());

        $neverWrittenAttributeProposal->refresh();

        $this->assertSame('pending', $neverWrittenAttributeProposal->status);
        $this->assertSame(0, $product->attributes()->count());
    }
}
