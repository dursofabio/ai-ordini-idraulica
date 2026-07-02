<?php

namespace Tests\Feature;

use App\Filament\Widgets\CatalogCoverageWidget;
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
 * US-025 AC1 — CatalogCoverageWidget shows the percentage of products with
 * brand, family, and subfamily valorized.
 */
class CatalogCoverageWidgetTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_shows_coverage_percentages_for_brand_family_and_subfamily(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $brand = Brand::factory()->create();
        $family = Family::factory()->create();
        $subfamily = Subfamily::factory()->create();

        // 4 products total, only 1 has all three attributes valorized (25%).
        Product::factory()->create([
            'brand_id' => $brand->id,
            'family_id' => $family->id,
            'subfamily_id' => $subfamily->id,
        ]);
        Product::factory()->create(['brand_id' => null, 'family_id' => null, 'subfamily_id' => null]);
        Product::factory()->create(['brand_id' => null, 'family_id' => null, 'subfamily_id' => null]);
        Product::factory()->create(['brand_id' => null, 'family_id' => null, 'subfamily_id' => null]);

        Livewire::test(CatalogCoverageWidget::class)
            ->assertSee('25%');
    }

    public function test_shows_zero_percent_without_dividing_by_zero_when_catalog_is_empty(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        Livewire::test(CatalogCoverageWidget::class)
            ->assertSee('0%')
            ->assertOk();
    }
}
