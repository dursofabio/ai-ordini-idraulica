<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-002 acceptance criterion: GET /horizon serves the Horizon dashboard.
 *
 * Horizon's Authorize middleware grants access in the local environment or
 * when the `viewHorizon` gate passes. In the testing environment we exercise
 * the gate by acting as the configured admin user, which proves both that the
 * dashboard route resolves and that the admin authorization wiring works.
 *
 * Skips gracefully when PostgreSQL is not reachable (US-001 pattern).
 */
class HorizonDashboardAccessTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_admin_can_reach_horizon_dashboard(): void
    {
        $admin = User::factory()->create([
            'email' => AdminUserSeeder::DEFAULT_EMAIL,
        ]);

        $response = $this->actingAs($admin)->get('/horizon');

        $response->assertOk();
    }
}
