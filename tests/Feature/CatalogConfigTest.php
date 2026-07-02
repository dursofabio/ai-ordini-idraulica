<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * US-027 acceptance criteria — config/catalog.php exposes the watched path
 * and the scheduler check frequency, both overridable via environment
 * variables (CATALOG_WATCH_PATH, CATALOG_SCHEDULE_FREQUENCY_MINUTES) so the
 * check cadence is configurable without touching code.
 */
class CatalogConfigTest extends TestCase
{
    public function test_watch_path_defaults_to_storage_import_catalogo_xlsx(): void
    {
        $this->assertSame(storage_path('import/catalogo.xlsx'), config('catalog.watch_path'));
    }

    public function test_schedule_frequency_minutes_defaults_to_15(): void
    {
        $this->assertSame(15, config('catalog.schedule_frequency_minutes'));
    }

    public function test_watch_path_is_overridable_via_config(): void
    {
        config(['catalog.watch_path' => '/custom/path/catalogo.xlsx']);

        $this->assertSame('/custom/path/catalogo.xlsx', config('catalog.watch_path'));
    }

    public function test_schedule_frequency_minutes_is_overridable_via_config(): void
    {
        config(['catalog.schedule_frequency_minutes' => 30]);

        $this->assertSame(30, config('catalog.schedule_frequency_minutes'));
    }

    public function test_schedule_frequency_minutes_reflects_env_variable(): void
    {
        // config/catalog.php reads CATALOG_SCHEDULE_FREQUENCY_MINUTES at
        // config-cache build time via env(); re-loading the config file
        // with the env var set proves the wiring without needing a real
        // process restart.
        putenv('CATALOG_SCHEDULE_FREQUENCY_MINUTES=45');
        $_ENV['CATALOG_SCHEDULE_FREQUENCY_MINUTES'] = '45';

        $reloaded = require config_path('catalog.php');

        $this->assertSame(45, $reloaded['schedule_frequency_minutes']);

        putenv('CATALOG_SCHEDULE_FREQUENCY_MINUTES');
        unset($_ENV['CATALOG_SCHEDULE_FREQUENCY_MINUTES']);
    }
}
