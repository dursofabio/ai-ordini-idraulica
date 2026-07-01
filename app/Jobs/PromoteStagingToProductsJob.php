<?php

namespace App\Jobs;

use App\Enums\ImportBatchStatus;
use App\Models\ImportBatch;
use App\Models\Product;
use App\Models\StagingArticolo;
use App\Services\ImportBatchService;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Promotes `staging_articoli` rows for a batch into `products` via an
 * idempotent upsert keyed on `codice_articolo`.
 *
 * Rows are read in bounded chunks: for each chunk, the existing `products`
 * rows sharing a `codice_articolo` are fetched in one query (no N+1), and the
 * chunk is then split into two upsert groups because they need different
 * `enrichment_status` handling that a single `ON DUPLICATE KEY UPDATE` clause
 * cannot express row-by-row: (1) new rows or rows whose description changed —
 * write `description_raw` and force `enrichment_status` back to `pending` so
 * enrichment re-runs; (2) existing rows whose description is unchanged —
 * touch only `costo`/`giacenza`/`is_active`, leaving `enrichment_status`
 * untouched. Re-running the job on the same staging data is a no-op for
 * `products` beyond touching `updated_at`: no new rows, `rows_new` = 0.
 */
class PromoteStagingToProductsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Number of staging rows read per chunk.
     */
    public const CHUNK_SIZE = 1000;

    /**
     * Upsert jobs are heavy but resumable per batch only as a whole: run once.
     */
    public int $tries = 1;

    /**
     * Generous ceiling for the full 42k-row upsert; memory, not time, is the risk.
     */
    public int $timeout = 3600;

    public function __construct(
        public ImportBatch $batch,
    ) {
        $this->onQueue('import');
    }

    public function handle(ImportBatchService $service): void
    {
        $rowsNew = 0;
        $rowsUpdated = 0;

        StagingArticolo::query()
            ->where('import_batch_id', $this->batch->id)
            ->orderBy('id')
            ->chunk(self::CHUNK_SIZE, function ($chunk) use (&$rowsNew, &$rowsUpdated): void {
                $codes = $chunk->pluck('codice_articolo')->all();

                $existing = Product::query()
                    ->whereIn('codice_articolo', $codes)
                    ->get()
                    ->keyBy('codice_articolo');

                $now = Carbon::now();
                $newOrChanged = [];
                $unchanged = [];

                /** @var StagingArticolo $row */
                foreach ($chunk as $row) {
                    $product = $existing->get($row->codice_articolo);
                    $isActive = self::computeIsActive($row->costo, $row->giacenza);

                    if ($product === null) {
                        $rowsNew++;

                        $newOrChanged[] = [
                            'codice_articolo' => $row->codice_articolo,
                            'description_raw' => $row->descrizione,
                            'costo' => $row->costo ?? 0,
                            'giacenza' => $row->giacenza ?? 0,
                            'is_active' => $isActive,
                            'enrichment_status' => 'pending',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];

                        continue;
                    }

                    $rowsUpdated++;

                    if (! self::sameDescription($product->description_raw, $row->descrizione)) {
                        $newOrChanged[] = [
                            'codice_articolo' => $row->codice_articolo,
                            'description_raw' => $row->descrizione,
                            'costo' => $row->costo ?? 0,
                            'giacenza' => $row->giacenza ?? 0,
                            'is_active' => $isActive,
                            'enrichment_status' => 'pending',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];

                        continue;
                    }

                    $unchanged[] = [
                        'codice_articolo' => $row->codice_articolo,
                        'description_raw' => $product->description_raw,
                        'costo' => $row->costo ?? 0,
                        'giacenza' => $row->giacenza ?? 0,
                        'is_active' => $isActive,
                        'enrichment_status' => $product->enrichment_status,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($newOrChanged !== []) {
                    DB::table('products')->upsert(
                        $newOrChanged,
                        ['codice_articolo'],
                        ['description_raw', 'costo', 'giacenza', 'is_active', 'enrichment_status', 'updated_at'],
                    );
                }

                if ($unchanged !== []) {
                    DB::table('products')->upsert(
                        $unchanged,
                        ['codice_articolo'],
                        ['costo', 'giacenza', 'is_active', 'updated_at'],
                    );
                }
            });

        $this->batch->forceFill([
            'rows_new' => $rowsNew,
            'rows_updated' => $rowsUpdated,
        ])->save();

        $service->markCompleted($this->batch);

        Notification::make()
            ->title('Import completato')
            ->body("Totali: {$this->batch->total_rows}, nuovi: {$this->batch->rows_new}, aggiornati: {$this->batch->rows_updated}, saltati: {$this->batch->skipped_rows}.")
            ->success()
            ->sendToDatabase($service->panelRecipients());
    }

    /**
     * Mark the batch failed when the upsert blows up, if the lifecycle allows it.
     *
     * AC4: also notifies panel-eligible users so the failure is visible even
     * if the admin has left the import page.
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
            ->body("Errore durante la promozione del batch #{$batch->id} ({$batch->filename}): {$exception?->getMessage()}")
            ->danger()
            ->sendToDatabase($service->panelRecipients());
    }

    /**
     * Whether a product should be considered active: inactive only when both
     * cost and stock are exhausted (null treated as zero).
     */
    private static function computeIsActive(mixed $costo, mixed $giacenza): bool
    {
        $costo = (float) ($costo ?? 0);
        $giacenza = (float) ($giacenza ?? 0);

        return ! ($costo == 0.0 && $giacenza <= 0.0);
    }

    /**
     * Whether the staging description matches the product's stored
     * description, comparing as trimmed strings so null/empty are equivalent.
     */
    private static function sameDescription(?string $existing, ?string $incoming): bool
    {
        return trim((string) $existing) === trim((string) $incoming);
    }
}
