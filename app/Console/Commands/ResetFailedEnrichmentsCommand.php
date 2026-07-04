<?php

namespace App\Console\Commands;

use App\Filament\Pages\ReviewQueue;
use App\Models\EnrichmentLog;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Clears products stuck in `needs_review` purely because every AI
 * classification attempt on record for them failed to produce a parseable
 * response — the "error" {@see EnrichmentLog} rows written by
 * `ClassifyProductsBatchJob::markNeedsReview()` — as opposed to a product
 * that did get a valid-but-uncertain AI answer and is genuinely awaiting
 * human triage in {@see ReviewQueue}: those are left
 * untouched since deleting their audit trail or resetting their status would
 * discard real classification work.
 *
 * For each qualifying product this deletes its failed-attempt EnrichmentLog
 * rows and resets `enrichment_status` back to `pending`, so the next
 * `catalog:enrich` run picks it up for reclassification.
 *
 * Restarts queue workers first (`queue:restart`) so any Horizon worker
 * already resident in memory with a stale AI-client build picks up the
 * latest deployed code before these products are requeued.
 */
class ResetFailedEnrichmentsCommand extends Command
{
    /**
     * Number of `needs_review` products read per chunk.
     */
    public const CHUNK_SIZE = 500;

    /**
     * @var string
     */
    protected $signature = 'catalog:reset-failed-enrichments';

    /**
     * @var string
     */
    protected $description = 'Ripulisce i prodotti "needs_review" causati esclusivamente da tentativi di classificazione AI falliti (risposta non valida), riportandoli a "pending".';

    public function handle(): int
    {
        $this->call('queue:restart');
        $this->line('Worker in coda riavviati (queue:restart), così raccolgono l\'ultima versione del codice.');

        $resetProducts = 0;
        $deletedLogs = 0;

        Product::query()
            ->where('enrichment_status', 'needs_review')
            ->with(['enrichmentLogs' => fn ($query) => $query->where('step', 'ai_classification')])
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($products) use (&$resetProducts, &$deletedLogs): void {
                DB::transaction(function () use ($products, &$resetProducts, &$deletedLogs): void {
                    foreach ($products as $product) {
                        [$failed, $legit] = $product->enrichmentLogs->partition(
                            fn (EnrichmentLog $log): bool => is_array($log->output) && array_key_exists('error', $log->output)
                        );

                        if ($failed->isEmpty() || $legit->isNotEmpty()) {
                            continue;
                        }

                        EnrichmentLog::query()->whereIn('id', $failed->pluck('id'))->delete();
                        $product->update(['enrichment_status' => 'pending']);

                        $resetProducts++;
                        $deletedLogs += $failed->count();
                    }
                });
            });

        if ($resetProducts === 0) {
            $this->info('Nessun prodotto "needs_review" causato da un fallimento di classificazione AI.');

            return self::SUCCESS;
        }

        $this->info("Prodotti riportati a \"pending\": {$resetProducts}");
        $this->info("Log di arricchimento falliti eliminati: {$deletedLogs}");
        $this->line('Esegui "catalog:enrich" per rilanciare la classificazione AI su questi prodotti.');

        return self::SUCCESS;
    }
}
