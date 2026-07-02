<?php

namespace App\Console\Commands;

use App\Exceptions\DuplicateImportException;
use App\Jobs\ImportXlsxJob;
use App\Jobs\PromoteStagingToProductsJob;
use App\Jobs\SeedTaxonomyFromStagingJob;
use App\Services\ImportBatchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scheduled counterpart to `catalog:import` (US-027): checks the configured
 * watch path and starts a new import batch only when the file's content hash
 * differs from the last completed batch.
 *
 * Designed to run silently on a tight cron cadence — it must not write any
 * log noise when there is nothing to do, so that the scheduler's log stays
 * readable (see AC: "i log non producono rumore quando non ci sono
 * modifiche").
 */
class CatalogImportIfChangedCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'catalog:import-if-changed';

    /**
     * @var string
     */
    protected $description = 'Avvia un import del catalogo solo se il file sorgente configurato è cambiato rispetto all\'ultimo batch completato.';

    public function handle(ImportBatchService $service): int
    {
        $path = config('catalog.watch_path');

        if (! is_file($path)) {
            return self::SUCCESS;
        }

        try {
            $batch = $service->startImport($path);
        } catch (DuplicateImportException) {
            // Hash unchanged since the last completed batch: nothing to do,
            // and nothing to log — this is the expected steady state.
            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('catalog:import-if-changed fallito', [
                'path' => $path,
                'exception' => $e->getMessage(),
            ]);

            return self::FAILURE;
        }

        Bus::chain([
            new ImportXlsxJob($batch, (string) realpath($path)),
            new SeedTaxonomyFromStagingJob($batch),
            new PromoteStagingToProductsJob($batch),
        ])->dispatch();

        Log::info('catalog:import-if-changed ha avviato un nuovo batch', [
            'batch_id' => $batch->id,
            'filename' => $batch->filename,
        ]);

        return self::SUCCESS;
    }
}
