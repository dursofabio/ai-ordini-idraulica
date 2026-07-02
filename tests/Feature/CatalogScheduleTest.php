<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * US-027 acceptance criteria — the two catalog schedules registered in
 * bootstrap/app.php:
 *  - `catalog:import-if-changed` runs on the configurable cron cadence from
 *    config('catalog.schedule_frequency_minutes').
 *  - `catalog:embed --missing` runs once a day.
 *
 * Asserted against the Schedule instance resolved from the container so a
 * silent regression in bootstrap/app.php's ->withSchedule() wiring is
 * caught by the suite rather than discovered in production.
 *
 * bootstrap/app.php's ->withSchedule() callback is only invoked once the
 * console `Artisan` application boots (see Illuminate\Console\Application's
 * constructor, which dispatches ArtisanStarting), so each test triggers a
 * throwaway Artisan call first to force that bootstrap before inspecting
 * the resolved Schedule's events.
 */
class CatalogScheduleTest extends TestCase
{
    public function test_import_if_changed_is_scheduled_at_configured_frequency(): void
    {
        config(['catalog.schedule_frequency_minutes' => 15]);

        Artisan::call('list');
        $events = app(Schedule::class)->events();

        $event = collect($events)->first(
            fn ($event): bool => Str::contains($event->command, 'catalog:import-if-changed')
        );

        $this->assertNotNull($event, 'catalog:import-if-changed non è registrato nello scheduler.');
        $this->assertSame('*/15 * * * *', $event->expression);
    }

    public function test_embed_missing_is_scheduled_daily(): void
    {
        Artisan::call('list');
        $events = app(Schedule::class)->events();

        $event = collect($events)->first(
            fn ($event): bool => Str::contains($event->command, 'catalog:embed')
        );

        $this->assertNotNull($event, 'catalog:embed --missing non è registrato nello scheduler.');
        $this->assertStringContainsString('--missing', $event->command);
        $this->assertSame('0 0 * * *', $event->expression);
    }
}
