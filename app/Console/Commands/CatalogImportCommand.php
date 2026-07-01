<?php

namespace App\Console\Commands;

use App\Exceptions\DuplicateImportException;
use App\Jobs\ImportXlsxJob;
use App\Jobs\PromoteStagingToProductsJob;
use App\Services\ImportBatchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use InvalidArgumentException;

class CatalogImportCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'catalog:import {path : Percorso del file XLSX da importare}';

    /**
     * @var string
     */
    protected $description = 'Avvia un import del catalogo da file XLSX creando un batch, con deduplicazione per hash.';

    public function handle(ImportBatchService $service): int
    {
        $path = $this->argument('path');

        try {
            $batch = $service->startImport($path);
        } catch (DuplicateImportException $e) {
            $this->warn($e->getMessage());
            $this->line('Nessun nuovo batch creato.');

            return self::SUCCESS;
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        Bus::chain([
            new ImportXlsxJob($batch, (string) realpath($path)),
            new PromoteStagingToProductsJob($batch),
        ])->dispatch();

        $this->info("Import avviato: batch #{$batch->id} ({$batch->filename}) — stato {$batch->status->value}.");
        $this->line('Import accodato sulla coda «import».');

        return self::SUCCESS;
    }
}
