<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withSchedule(function (Schedule $schedule): void {
        // US-027: reimport the catalog whenever the watched gestionale file
        // changes, at the configurable cadence set in config/catalog.php.
        $schedule->command('catalog:import-if-changed')
            ->cron('*/'.config('catalog.schedule_frequency_minutes').' * * * *')
            ->withoutOverlapping();

        // US-027: nightly job filling in embeddings for product-bases that
        // don't have one yet, reusing the existing catalog:embed command.
        $schedule->command('catalog:embed', ['--missing' => true])
            ->daily()
            ->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
