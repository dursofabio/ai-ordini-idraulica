<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-025 TASK-10 — the four coverage/cost widgets are auto-discovered by
 * AdminPanelProvider's discoverWidgets(app_path('Filament/Widgets')) and
 * mount on the default admin Dashboard without errors for an authenticated
 * user.
 */
class AdminDashboardWidgetsTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_admin_dashboard_mounts_all_catalog_widgets(): void
    {
        $admin = User::factory()->create();
        Product::factory()->create(['enrichment_status' => 'pending']);

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertOk();
        // Each widget renders its own Stat label; seeing all four confirms
        // the Dashboard mounted them via discoverWidgets() without erroring.
        $response->assertSee('Copertura marca');
        $response->assertSee('Pending');
        $response->assertSee('Articoli inattivi');
        $response->assertSee('Costo AI ultimo batch');
    }
}
