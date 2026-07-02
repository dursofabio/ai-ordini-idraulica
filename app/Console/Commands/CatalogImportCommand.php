<?php

namespace App\Console\Commands;

use App\Enums\ImportBatchStatus;
use App\Exceptions\DuplicateImportException;
use App\Jobs\ImportXlsxJob;
use App\Jobs\PromoteStagingToProductsJob;
use App\Jobs\SeedTaxonomyFromStagingJob;
use App\Models\ImportBatch;
use App\Services\ImportBatchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use InvalidArgumentException;
use Throwable;

class CatalogImportCommand extends Command
{
    /**
     * Default timeout (seconds) for `--wait`, matching the job chain's own
     * worst-case timeout (see {@see ImportXlsxJob::$timeout} and
     * {@see PromoteStagingToProductsJob::$timeout}) so the CLI does not give
     * up before the queue would.
     */
    private const DEFAULT_WAIT_TIMEOUT = 3600;

    /**
     * Polling interval (seconds) used while `--wait` is active.
     */
    private const POLL_INTERVAL_SECONDS = 2;

    /**
     * @var string
     */
    protected $signature = 'catalog:import
        {path : Percorso del file XLSX da importare}
        {--wait : Attende sincronamente il completamento del batch e stampa il riepilogo finale}
        {--timeout= : Timeout in secondi per --wait (default 3600)}';

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

        try {
            Bus::chain([
                new ImportXlsxJob($batch, (string) realpath($path)),
                new SeedTaxonomyFromStagingJob($batch),
                new PromoteStagingToProductsJob($batch),
            ])->dispatch();
        } catch (Throwable $e) {
            // On a synchronous queue connection (local dev, tests) the chain
            // runs inline and a job failure throws here after its own
            // failed() hook has already marked the batch Failed; the batch's
            // own status is the source of truth below, so this is expected
            // and not itself an error.
            // On a real queue connection this dispatch only enqueues and
            // should not normally throw for job-level failures, so a
            // genuine dispatch-time error (e.g. queue backend unreachable)
            // is still logged here rather than silently disappearing.
            report($e);
        }

        $batch->refresh();

        if ($batch->status === ImportBatchStatus::Failed) {
            $this->error("Import fallito: batch #{$batch->id} — stato {$batch->status->value}.");

            return self::FAILURE;
        }

        $this->info("Import avviato: batch #{$batch->id} ({$batch->filename}) — stato {$batch->status->value}.");

        if (! $this->option('wait')) {
            $this->line('Import accodato sulla coda «import». Usa --wait per attendere il riepilogo finale, oppure consultalo in seguito dal backoffice.');

            return self::SUCCESS;
        }

        return $this->waitForCompletion($batch);
    }

    private function waitForCompletion(ImportBatch $batch): int
    {
        $timeout = (int) ($this->option('timeout') ?? self::DEFAULT_WAIT_TIMEOUT);
        $deadline = microtime(true) + $timeout;

        $this->line('In attesa del completamento del batch...');

        while (! $batch->refresh()->status->isTerminal()) {
            if (microtime(true) >= $deadline) {
                $this->error("Timeout raggiunto ({$timeout}s) in attesa del completamento del batch #{$batch->id}.");

                return self::FAILURE;
            }

            sleep(self::POLL_INTERVAL_SECONDS);
        }

        if ($batch->status === ImportBatchStatus::Failed) {
            $this->error("Import fallito: batch #{$batch->id} — stato {$batch->status->value}.");

            return self::FAILURE;
        }

        $this->info("Import completato: batch #{$batch->id} ({$batch->filename}).");
        $this->line("  Totali: {$batch->total_rows}");
        $this->line("  Nuovi: {$batch->rows_new}");
        $this->line("  Aggiornati: {$batch->rows_updated}");
        $this->line("  Saltati: {$batch->skipped_rows}");

        return self::SUCCESS;
    }
}
