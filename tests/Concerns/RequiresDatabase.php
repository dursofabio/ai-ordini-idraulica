<?php

namespace Tests\Concerns;

use Illuminate\Contracts\Foundation\Application;

/**
 * Provides a self-contained database for tests that need one.
 *
 * The application default connection is PostgreSQL (Sail), which is only
 * reachable inside the container. To keep database-backed tests executable in
 * every environment — including hosts without Docker — this trait forces the
 * default connection to an in-memory SQLite database as soon as the
 * application is created. `createApplication()` runs at the very start of
 * TestCase::setUp(), before the RefreshDatabase trait migrates, so the schema
 * is built against SQLite.
 *
 * Nothing global is mutated (no putenv), so the override cannot leak into other
 * tests. When SQLite is unavailable (no pdo_sqlite extension), the tests skip
 * gracefully instead of erroring.
 */
trait RequiresDatabase
{
    public function createApplication(): Application
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension is not available.');
        }

        /** @var Application $app */
        $app = parent::createApplication();

        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['db']->purge();

        return $app;
    }
}
