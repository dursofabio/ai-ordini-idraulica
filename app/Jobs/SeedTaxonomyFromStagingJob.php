<?php

namespace App\Jobs;

use App\Console\Commands\CatalogSeedTaxonomyCommand;
use App\Enums\ImportBatchStatus;
use App\Models\ImportBatch;
use App\Services\ImportBatchService;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Refreshes the Brand/Family/Subfamily taxonomy from the just-imported
 * staging rows, chained between {@see ImportXlsxJob} and
 * {@see PromoteStagingToProductsJob} so an admin never has to run
 * `catalog:seed-taxonomy` by hand after every import: a raw Marca/Fam/S.Fam
 * value already present in the file can then be linked by
 * `App\Services\Enrichment\FileTaxonomyResolver` during Step A enrichment,
 * having been created here if it didn't already exist.
 *
 * Delegates entirely to `catalog:seed-taxonomy`
 * ({@see CatalogSeedTaxonomyCommand}), which scans the
 * whole `staging_articoli` table (not just this batch) and is idempotent by
 * design (upserts by slug, merges aliases) — see its own docblock.
 */
class SeedTaxonomyFromStagingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Run once per batch, matching the sibling import-chain jobs.
     */
    public int $tries = 1;

    /**
     * Generous ceiling matching the sibling import-chain jobs; the taxonomy
     * scan grows with the whole staging table, not just this batch.
     */
    public int $timeout = 3600;

    public function __construct(
        public ImportBatch $batch,
    ) {
        $this->onQueue('import');
    }

    public function handle(): void
    {
        Artisan::call('catalog:seed-taxonomy');
    }

    /**
     * Mark the batch failed when the seed blows up, if the lifecycle allows
     * it, so the chain doesn't leave the batch stuck in `enriching` forever.
     */
    public function failed(?Throwable $exception): void
    {
        $batch = $this->batch->fresh() ?? $this->batch;
        $service = app(ImportBatchService::class);

        if ($batch->status->canTransitionTo(ImportBatchStatus::Failed)) {
            $service->markFailed($batch);
        }

        Notification::make()
            ->title('Import fallito')
            ->body("Errore durante il seed della tassonomia per il batch #{$batch->id} ({$batch->filename}): {$exception?->getMessage()}")
            ->danger()
            ->sendToDatabase($service->panelRecipients());
    }
}
