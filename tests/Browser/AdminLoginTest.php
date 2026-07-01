<?php

namespace Tests\Browser;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Hash;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * US-002 demo scenario (spec "Dimostra"):
 * an admin opens /admin, is redirected to the Filament login page, enters
 * valid credentials, submits, and lands on the authenticated dashboard.
 *
 * Per-step screenshots are stored in docs/test-results/US-002/ as the visual
 * artifact of the run (Dusk does not record video). Actions are paced with
 * explicit visibility assertions and short holds so the artifacts are readable
 * by a non-technical reviewer.
 */
class AdminLoginTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_admin_logs_in_to_filament_backoffice(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin',
            'email' => AdminUserSeeder::DEFAULT_EMAIL,
            'password' => Hash::make(AdminUserSeeder::DEFAULT_PASSWORD),
        ]);

        $this->browse(function (Browser $browser) use ($admin) {
            // 1. A guest visits /admin and is redirected to the login page.
            $browser->visit('/admin')
                ->waitForLocation('/admin/login')
                ->waitFor('#form\\.email')
                ->assertPresent('#form\\.email')
                ->pause(400)
                ->screenshot('01-login-page');

            // 2. The admin fills in valid credentials.
            $browser->type('#form\\.email', $admin->email)
                ->pause(400)
                ->type('#form\\.password', AdminUserSeeder::DEFAULT_PASSWORD)
                ->pause(400)
                ->screenshot('02-credentials-filled');

            // 3. The admin submits the login form (Filament renders a single
            // submit button in the auth form).
            $browser->click('button[type="submit"]')
                ->pause(400);

            // 4. The admin lands on the authenticated Filament dashboard.
            $browser->waitForLocation('/admin')
                ->waitForText('Dashboard')
                ->assertPathIs('/admin')
                ->assertSee('Dashboard')
                ->pause(1500)
                ->screenshot('03-dashboard');
        });
    }
}
