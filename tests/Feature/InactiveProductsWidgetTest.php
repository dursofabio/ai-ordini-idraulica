<?php

namespace Tests\Feature;

use App\Filament\Widgets\InactiveProductsWidget;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-025 AC4 — InactiveProductsWidget counts products with `is_active = false`.
 */
class InactiveProductsWidgetTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_counts_only_inactive_products_among_a_mixed_set(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        Product::factory()->count(3)->create(['is_active' => true]);
        Product::factory()->count(2)->create(['is_active' => false]);

        Livewire::test(InactiveProductsWidget::class)
            ->assertSee('2');
    }
}
