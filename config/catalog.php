<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Catalog Watch Path
    |--------------------------------------------------------------------------
    |
    | The gestionale export file watched by `catalog:import-if-changed`
    | (US-027). The scheduler checks this path on every run and starts a new
    | import batch only when its content hash differs from the last
    | completed batch (see ImportBatchService::startImport()).
    |
    */

    'watch_path' => env('CATALOG_WATCH_PATH', storage_path('import/catalogo.xlsx')),

    /*
    |--------------------------------------------------------------------------
    | Scheduler Check Frequency
    |--------------------------------------------------------------------------
    |
    | How often (in minutes) the scheduler runs `catalog:import-if-changed`.
    | Configurable via env so the check cadence can be tuned per environment
    | without touching code (US-027 AC: "frequenza di controllo configurabile").
    |
    */

    'schedule_frequency_minutes' => (int) env('CATALOG_SCHEDULE_FREQUENCY_MINUTES', 15),

];
