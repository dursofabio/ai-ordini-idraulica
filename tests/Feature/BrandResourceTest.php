<?php

namespace Tests\Feature;

use App\Filament\Resources\Brands\Pages\CreateBrand;
use App\Filament\Resources\Brands\Pages\EditBrand;
use App\Filament\Resources\Brands\Pages\ListBrands;
use App\Models\Brand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-022 acceptance criteria for the Brand backoffice resource:
 *  - AC1: `BrandResource` allows full CRUD, including the `aliases` field
 *    edited as a list of strings.
 *  - AC4: an alias already associated with another brand (either as one of
 *    its own aliases or as its `name`) cannot be saved; reusing an alias
 *    already owned by the record being edited is not a false positive.
 */
class BrandResourceTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_list_shows_brands(): void
    {
        $admin = User::factory()->create();
        $brand = Brand::factory()->create();

        $this->actingAs($admin);

        Livewire::test(ListBrands::class)
            ->assertCanSeeTableRecords([$brand]);
    }

    public function test_list_shows_alias_count_when_an_alias_is_a_numeric_string(): void
    {
        $admin = User::factory()->create();
        $brand = Brand::factory()->create(['aliases' => ['10', 'FISCHER']]);

        $this->actingAs($admin);

        Livewire::test(ListBrands::class)
            ->assertCanSeeTableRecords([$brand])
            ->assertTableColumnStateSet('aliases', 2, $brand);
    }

    public function test_can_create_brand_with_name_slug_and_aliases(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin);

        Livewire::test(CreateBrand::class)
            ->fillForm([
                'name' => 'Vaillant',
                'slug' => 'vaillant',
                'aliases' => ['VAILL', 'VLT'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('brands', [
            'name' => 'Vaillant',
            'slug' => 'vaillant',
        ]);

        $brand = Brand::where('slug', 'vaillant')->firstOrFail();
        $this->assertSame(['VAILL', 'VLT'], $brand->aliases);
    }

    public function test_can_edit_brand_name_slug_and_aliases(): void
    {
        $admin = User::factory()->create();
        $brand = Brand::factory()->create([
            'name' => 'Old Name',
            'slug' => 'old-name',
            'aliases' => ['OLD'],
        ]);

        $this->actingAs($admin);

        Livewire::test(EditBrand::class, ['record' => $brand->getRouteKey()])
            ->fillForm([
                'name' => 'New Name',
                'slug' => 'new-name',
                'aliases' => ['NEW'],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $brand->refresh();

        $this->assertSame('New Name', $brand->name);
        $this->assertSame('new-name', $brand->slug);
        $this->assertSame(['NEW'], $brand->aliases);
    }

    public function test_cannot_save_alias_already_used_by_another_brand(): void
    {
        $admin = User::factory()->create();
        Brand::factory()->create(['name' => 'Vaillant', 'aliases' => ['VAILL']]);

        $this->actingAs($admin);

        Livewire::test(CreateBrand::class)
            ->fillForm([
                'name' => 'Other Brand',
                'slug' => 'other-brand',
                'aliases' => ['VAILL'],
            ])
            ->call('create')
            ->assertHasFormErrors(['aliases']);

        $this->assertDatabaseMissing('brands', ['name' => 'Other Brand']);
    }

    public function test_cannot_save_alias_colliding_with_another_brands_name(): void
    {
        $admin = User::factory()->create();
        Brand::factory()->create(['name' => 'Vaillant', 'aliases' => []]);

        $this->actingAs($admin);

        Livewire::test(CreateBrand::class)
            ->fillForm([
                'name' => 'Other Brand',
                'slug' => 'other-brand',
                'aliases' => ['vaillant'],
            ])
            ->call('create')
            ->assertHasFormErrors(['aliases']);
    }

    public function test_editing_brand_reusing_its_own_existing_alias_does_not_fail(): void
    {
        $admin = User::factory()->create();
        $brand = Brand::factory()->create(['name' => 'Vaillant', 'aliases' => ['VAILL']]);

        $this->actingAs($admin);

        Livewire::test(EditBrand::class, ['record' => $brand->getRouteKey()])
            ->fillForm([
                'name' => 'Vaillant',
                'slug' => $brand->slug,
                'aliases' => ['VAILL', 'VLT'],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $brand->refresh();
        $this->assertSame(['VAILL', 'VLT'], $brand->aliases);
    }

    /**
     * `whereJsonContains` alone would miss this because JSON containment is
     * an exact-value match; the uniqueness rule must fold case before
     * comparing so it stays consistent with BrandResolver's case-insensitive
     * alias matching.
     */
    public function test_cannot_save_alias_colliding_with_another_brands_alias_in_different_case(): void
    {
        $admin = User::factory()->create();
        Brand::factory()->create(['name' => 'Vaillant', 'aliases' => ['VAILL']]);

        $this->actingAs($admin);

        Livewire::test(CreateBrand::class)
            ->fillForm([
                'name' => 'Other Brand',
                'slug' => 'other-brand',
                'aliases' => ['vaill'],
            ])
            ->call('create')
            ->assertHasFormErrors(['aliases']);

        $this->assertDatabaseMissing('brands', ['name' => 'Other Brand']);
    }

    public function test_cannot_save_two_case_insensitive_duplicate_aliases_in_the_same_submission(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin);

        Livewire::test(CreateBrand::class)
            ->fillForm([
                'name' => 'New Brand',
                'slug' => 'new-brand',
                'aliases' => ['VAILL', 'vaill'],
            ])
            ->call('create')
            ->assertHasFormErrors(['aliases']);

        $this->assertDatabaseMissing('brands', ['name' => 'New Brand']);
    }
}
