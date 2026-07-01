<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Verifies the acceptance criteria on environment configuration for US-001:
 * .env.example must declare the pgsql/redis stack expected by Sail.
 */
class EnvironmentConfigurationTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private function envExample(): array
    {
        $path = base_path('.env.example');
        $this->assertFileExists($path, '.env.example must exist');

        $vars = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $vars[trim($key)] = trim($value);
        }

        return $vars;
    }

    public function test_env_example_declares_postgres_as_database_connection(): void
    {
        $this->assertSame('pgsql', $this->envExample()['DB_CONNECTION'] ?? null);
        $this->assertSame('pgsql', $this->envExample()['DB_HOST'] ?? null);
    }

    public function test_env_example_declares_redis_for_queue_and_cache(): void
    {
        $env = $this->envExample();

        $this->assertSame('redis', $env['QUEUE_CONNECTION'] ?? null);
        $this->assertSame('redis', $env['CACHE_STORE'] ?? null);
        $this->assertSame('redis', $env['REDIS_HOST'] ?? null);
    }

    public function test_default_database_connection_is_postgres(): void
    {
        // DB_CONNECTION is not overridden by phpunit.xml, so runtime config
        // reflects the configured environment.
        $this->assertSame('pgsql', config('database.default'));
    }
}
