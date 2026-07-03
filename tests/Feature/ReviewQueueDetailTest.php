<?php

namespace Tests\Feature;

use App\Filament\Pages\ReviewQueue;
use App\Filament\Pages\ReviewQueueDetail;
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
            'value_num' => 1.5,
            'value_text' => null,
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
            'value_num' => 1.5,
            'value_text' => null,
            'unit' => 'kW',
            'source' => 'regex',
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueueDetail::class, ['record' => $product->getRouteKey()])
            ->fillForm([
                'attributes' => [
                    "record-{$attribute->id}" => [
                        'key' => 'kW',
                        'value_text' => null,
                        'value_num' => 3.2,
                        'unit' => 'kW',
                    ],
                ],
            ])
            ->call('save');

        $attribute->refresh();

        $this->assertSame('3.200', $attribute->value_num);
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
            'value_num' => 1.5,
            'value_text' => null,
            'unit' => 'kW',
            'source' => 'regex',
        ]);

        $this->actingAs($admin);

        Livewire::test(ReviewQueueDetail::class, ['record' => $product->getRouteKey()])
            ->fillForm(['brand_id' => $correctedBrand->id])
            ->call('save');

        $untouchedAttribute->refresh();

        $this->assertSame('regex', $untouchedAttribute->source);
        $this->assertSame('1.500', $untouchedAttribute->value_num);
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
                        'value_text' => 'Ottone',
                        'value_num' => null,
                        'unit' => null,
                    ],
                ],
            ])
            ->call('save');

        $product->refresh();

        $this->assertSame(1, $product->attributes()->count());
        $newAttribute = $product->attributes()->first();
        $this->assertSame('Materiale', $newAttribute->key);
        $this->assertSame('Ottone', $newAttribute->value_text);
        $this->assertSame('manual', $newAttribute->source);
    }
}
