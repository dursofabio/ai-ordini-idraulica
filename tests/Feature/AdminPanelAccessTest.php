<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-002 acceptance criteria for the Filament backoffice on /admin:
 *  - GET /admin does not error 500 for guests (redirect to / or 200 login).
 *  - An authenticated admin user can access the panel (status 200).
 *  - User::canAccessPanel() returns true for the admin user.
 *
 * Tests that touch the database skip gracefully when PostgreSQL is not
 * reachable (e.g. outside the Sail environment), matching the US-001 pattern.
 */
class AdminPanelAccessTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_admin_login_page_does_not_error_for_guests(): void
    {
        $response = $this->get('/admin/login');

        $response->assertOk();
    }

    public function test_admin_root_redirects_guest_to_login_without_server_error(): void
    {
        $response = $this->get('/admin');

        $this->assertLessThan(
            500,
            $response->getStatusCode(),
            'GET /admin must not return a server error for guests.'
        );
        $response->assertRedirect(Filament::getLoginUrl());
    }

    public function test_authenticated_admin_can_access_panel(): void
    {
        $admin = User::factory()->create();

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertOk();
    }

    public function test_can_access_panel_returns_true_for_admin(): void
    {
        $admin = User::factory()->create();

        $this->assertTrue($admin->canAccessPanel(Filament::getPanel('admin')));
    }
}
