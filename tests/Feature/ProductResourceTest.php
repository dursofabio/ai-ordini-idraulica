<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Brand;
use App\Models\Family;
use App\Models\Product;
use App\Models\Subfamily;
use App\Models\User;
use Filament\Support\Icons\Heroicon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-021 acceptance criteria for the product backoffice resource:
 *  - AC1: the list page can be filtered by `enrichment_status`, `brand`, and
 *    `family`, each showing only the matching subset of products.
 *  - AC2: editing `brand_id`/`family_id`/`subfamily_id` from the Edit page
 *    stamps the corresponding `*_source` as `'manual'` and, when any of the
 *    three actually changed, also sets `source = 'manual'` and
 *    `confidence = 100`; resubmitting unchanged values leaves all of those
 *    fields untouched.
 *  - AC5: manually-set brand/family are visually distinct in the table
 *    (badge color/icon) and the edit form helper text reflects the field's
 *    origin (manual vs AI-assigned).
 *
 * US-050 adds the `descrizione_estesa` markdown editor to the edit page
 * (AC3), saved as-is and left null when empty (AC4).
 */
class ProductResourceTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_list_filters_by_enrichment_status(): void
    {
        $admin = User::factory()->create();
        $pending = Product::factory()->create(['enrichment_status' => 'pending']);
        $enriched = Product::factory()->create(['enrichment_status' => 'enriched']);

        $this->actingAs($admin);

        Livewire::test(ListProducts::class)
            ->filterTable('enrichment_status', 'enriched')
            ->assertCanSeeTableRecords([$enriched])
            ->assertCanNotSeeTableRecords([$pending]);
    }

    public function test_list_filters_by_brand(): void
    {
        $admin = User::factory()->create();
        $brandA = Brand::factory()->create();
        $brandB = Brand::factory()->create();
        $productA = Product::factory()->create(['brand_id' => $brandA->id]);
        $productB = Product::factory()->create(['brand_id' => $brandB->id]);

        $this->actingAs($admin);

        Livewire::test(ListProducts::class)
            ->filterTable('brand', $brandA->id)
            ->assertCanSeeTableRecords([$productA])
            ->assertCanNotSeeTableRecords([$productB]);
    }

    public function test_list_filters_by_family(): void
    {
        $admin = User::factory()->create();
        $familyA = Family::factory()->create();
        $familyB = Family::factory()->create();
        $productA = Product::factory()->create(['family_id' => $familyA->id]);
        $productB = Product::factory()->create(['family_id' => $familyB->id]);

        $this->actingAs($admin);

        Livewire::test(ListProducts::class)
            ->filterTable('family', $familyA->id)
            ->assertCanSeeTableRecords([$productA])
            ->assertCanNotSeeTableRecords([$productB]);
    }

    public function test_editing_brand_sets_brand_source_manual_and_confidence_100(): void
    {
        $admin = User::factory()->create();
        $brandA = Brand::factory()->create();
        $brandB = Brand::factory()->create();
        $product = Product::factory()->create([
            'brand_id' => $brandA->id,
            'brand_source' => 'ai',
            'source' => 'ai',
            'confidence' => 40,
        ]);

        $this->actingAs($admin);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['brand_id' => $brandB->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $product->refresh();

        $this->assertSame('manual', $product->brand_source);
        $this->assertSame('manual', $product->source);
        $this->assertSame(100, $product->confidence);
    }

    public function test_editing_family_sets_family_source_manual_and_confidence_100(): void
    {
        $admin = User::factory()->create();
        $familyA = Family::factory()->create();
        $familyB = Family::factory()->create();
        $product = Product::factory()->create([
            'family_id' => $familyA->id,
            'family_source' => 'ai',
            'source' => 'ai',
            'confidence' => 40,
        ]);

        $this->actingAs($admin);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['family_id' => $familyB->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $product->refresh();

        $this->assertSame('manual', $product->family_source);
        $this->assertSame('manual', $product->source);
        $this->assertSame(100, $product->confidence);
    }

    public function test_saving_without_changing_brand_or_family_does_not_alter_existing_sources(): void
    {
        $admin = User::factory()->create();
        $brand = Brand::factory()->create();
        $family = Family::factory()->create();
        $subfamily = Subfamily::factory()->create(['family_id' => $family->id]);
        $product = Product::factory()->create([
            'brand_id' => $brand->id,
            'family_id' => $family->id,
            'subfamily_id' => $subfamily->id,
            'brand_source' => 'manual',
            'family_source' => 'ai',
            'subfamily_source' => 'ai',
            'source' => 'manual',
            'confidence' => 100,
        ]);

        $this->actingAs($admin);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm([
                'brand_id' => $brand->id,
                'family_id' => $family->id,
                'subfamily_id' => $subfamily->id,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $product->refresh();

        $this->assertSame('manual', $product->brand_source);
        $this->assertSame('ai', $product->family_source);
        $this->assertSame('ai', $product->subfamily_source);
        $this->assertSame('manual', $product->source);
        $this->assertSame(100, $product->confidence);
    }

    /**
     * Filament v5's table testing helpers don't expose a dedicated
     * "assert column color/icon" method; `assertTableColumnStateSet` and
     * `assertTableColumnFormattedStateSet` only cover the column's textual
     * state, not its badge color/icon. `assertTableColumnExists` however
     * accepts an optional `$record`, binds it to the resolved column
     * instance via `$column->record($record)`, and lets us run assertions
     * inside a `$checkColumnUsing` closure — which is enough to directly
     * exercise `TextColumn::getColor($state)`/`getIcon($state)` for a given
     * record, matching exactly what `ProductsTable` configures.
     */
    public function test_manual_brand_is_visually_distinct_in_table(): void
    {
        $admin = User::factory()->create();
        $manualBrand = Brand::factory()->create();
        $aiBrand = Brand::factory()->create();
        $manualProduct = Product::factory()->create([
            'brand_id' => $manualBrand->id,
            'brand_source' => 'manual',
        ]);
        $aiProduct = Product::factory()->create([
            'brand_id' => $aiBrand->id,
            'brand_source' => 'ai',
        ]);

        $this->actingAs($admin);

        $component = Livewire::test(ListProducts::class);

        $component->assertTableColumnExists(
            'brand.name',
            function ($column): bool {
                $state = $column->getState();

                return $column->getColor($state) === 'info' && $column->getIcon($state) === Heroicon::OutlinedLockClosed;
            },
            $manualProduct,
        );

        $component->assertTableColumnExists(
            'brand.name',
            function ($column): bool {
                $state = $column->getState();

                return $column->getColor($state) === 'gray' && $column->getIcon($state) === null;
            },
            $aiProduct,
        );
    }

    public function test_manual_source_shown_in_edit_form_helper_text(): void
    {
        $admin = User::factory()->create();
        $manualProduct = Product::factory()->create(['brand_source' => 'manual']);
        $aiProduct = Product::factory()->create(['brand_source' => 'ai']);

        $this->actingAs($admin);

        Livewire::test(EditProduct::class, ['record' => $manualProduct->getRouteKey()])
            ->assertSee('🔒 Impostato manualmente');

        Livewire::test(EditProduct::class, ['record' => $aiProduct->getRouteKey()])
            ->assertSee('Origine: ai');
    }

    public function test_editing_descrizione_estesa_saves_the_markdown_content(): void
    {
        $admin = User::factory()->create();
        $product = Product::factory()->create();

        $this->actingAs($admin);

        $markdown = "# Scheda tecnica\n\nDescrizione **ricca** del prodotto.";

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['descrizione_estesa' => $markdown])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($markdown, $product->refresh()->descrizione_estesa);
    }

    public function test_saving_with_empty_descrizione_estesa_keeps_it_empty_without_errors(): void
    {
        $admin = User::factory()->create();
        $product = Product::factory()->create(['descrizione_estesa' => null]);

        $this->actingAs($admin);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['descrizione_estesa' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($product->refresh()->descrizione_estesa);
    }
}
