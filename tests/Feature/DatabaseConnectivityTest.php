<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\TestCase;

/**
 * Integration test for US-001: verifies PostgreSQL is reachable and the
 * pgvector extension is active (acceptance criterion:
 * SELECT 1 FROM pg_extension WHERE extname='vector' returns a row).
 *
 * Runs against a live PostgreSQL service (Sail). Skips gracefully when the
 * database is not reachable or is not PostgreSQL, so the suite stays green
 * outside the container.
 */
class DatabaseConnectivityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Not connected to PostgreSQL (requires Sail pgsql service).');
        }

        try {
            DB::connection()->getPdo();
        } catch (PDOException $e) {
            $this->markTestSkipped('PostgreSQL is not reachable: '.$e->getMessage());
        }

        // Ensure the extension migration has run against the current (test)
        // database, so the assertion is valid regardless of which database
        // the suite targets (e.g. the dedicated `testing` database).
        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_postgres_connection_responds(): void
    {
        $result = DB::selectOne('SELECT 1 AS ok');

        $this->assertSame(1, (int) $result->ok);
    }

    public function test_pgvector_extension_is_enabled(): void
    {
        $row = DB::selectOne("SELECT 1 AS present FROM pg_extension WHERE extname = 'vector'");

        $this->assertNotNull($row, "The 'vector' extension must be enabled in PostgreSQL.");
        $this->assertSame(1, (int) $row->present);
    }
}
