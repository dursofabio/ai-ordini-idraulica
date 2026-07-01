<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Deterministically provisions the admin user used to log in to the Filament
 * backoffice (US-002). The credentials are configurable via environment so the
 * same seeder is safe to run locally, in tests, and during onboarding.
 *
 * Idempotent: re-running the seeder updates the existing user instead of
 * creating duplicates.
 */
class AdminUserSeeder extends Seeder
{
    public const DEFAULT_EMAIL = 'admin@example.com';

    public const DEFAULT_PASSWORD = 'password';

    public function run(): void
    {
        $email = (string) env('ADMIN_EMAIL', self::DEFAULT_EMAIL);
        $password = (string) env('ADMIN_PASSWORD', self::DEFAULT_PASSWORD);

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Admin',
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ],
        );
    }
}
