<?php

namespace App\Jobs;

use App\Enums\ImportBatchStatus;
use App\Models\ImportBatch;
use App\Models\StagingArticolo;
use App\Services\ImportBatchService;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Spatie\SimpleExcel\SimpleExcelReader;
use Throwable;

/**
 * Reads a catalog XLSX file into `staging_articoli` in bounded chunks.
 *
 * The 42k+ row file is streamed through openspout (via spatie/simple-excel):
 * the reader yields one row at a time, so memory stays flat regardless of file
 * size, and rows are bulk-inserted 1.000 at a time. The heading row drives the
 * column mapping — headers are normalised to snake_case keys stored verbatim in
 * `raw_row`, and the typed columns are pulled from the known headers via
 * {@see self::COLUMN_MAP}. Rows without a `codice_articolo` are skipped and
 * counted on the batch.
 */
class ImportXlsxJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Number of rows buffered per bulk insert.
     */
    public const CHUNK_SIZE = 1000;

    /**
     * Normalised heading-row key => staging_articoli typed column.
     *
     * Centralised so the TeamSystem-specific column names stay in one place.
     */
    public const COLUMN_MAP = [
        'codice_articolo' => 'codice_articolo',
        'descrizione' => 'descrizione',
        'costo_un_1' => 'costo',
        'giac_att_1' => 'giacenza',
    ];

    /**
     * Read jobs are heavy but resumable per file only as a whole: run once.
     */
    public int $tries = 1;

    /**
     * Generous ceiling for the full 42k-row read; memory, not time, is the risk.
     */
    public int $timeout = 3600;

    public function __construct(
        public ImportBatch $batch,
        public string $path,
    ) {
        $this->onQueue('import');
    }

    public function handle(ImportBatchService $service): void
    {
        $service->markImporting($this->batch);

        $total = 0;
        $processed = 0;
        $skipped = 0;

        $rows = SimpleExcelReader::create($this->path)
            ->formatHeadersUsing(fn (string $header): string => self::normaliseHeader($header))
            ->getRows();

        foreach ($rows->chunk(self::CHUNK_SIZE) as $chunk) {
            $now = Carbon::now();
            $insert = [];

            /** @var array<string, mixed> $raw */
            foreach ($chunk as $raw) {
                $total++;

                $codice = trim((string) ($raw['codice_articolo'] ?? ''));

                if ($codice === '') {
                    $skipped++;

                    continue;
                }

                $insert[] = [
                    'import_batch_id' => $this->batch->id,
                    'raw_row' => json_encode($raw, JSON_UNESCAPED_UNICODE),
                    'row_number' => $total,
                    'codice_articolo' => $codice,
                    'descrizione' => self::stringOrNull($raw['descrizione'] ?? null),
                    'costo' => self::numericOrNull($raw['costo_un_1'] ?? null),
                    'giacenza' => self::numericOrNull($raw['giac_att_1'] ?? null),
                    'status' => 'pending',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($insert !== []) {
                StagingArticolo::insert($insert);
                $processed += count($insert);
            }
        }

        $this->batch->forceFill([
            'total_rows' => $total,
            'processed_rows' => $processed,
            'skipped_rows' => $skipped,
        ])->save();

        $service->markEnriching($this->batch);
    }

    /**
     * Mark the batch failed when the read blows up, if the lifecycle allows it.
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
            ->body("Errore durante la lettura del batch #{$batch->id} ({$batch->filename}): {$exception?->getMessage()}")
            ->danger()
            ->sendToDatabase($service->panelRecipients());
    }

    /**
     * Normalise an XLSX header into a snake_case key: lower-cased, with every
     * run of non-alphanumeric characters collapsed to a single underscore
     * ("Descrizione Marca" => "descrizione_marca", "Giac.att.1" => "giac_att_1").
     */
    public static function normaliseHeader(string $header): string
    {
        $key = strtolower(trim($header));
        $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? '';

        return trim($key, '_');
    }

    private static function stringOrNull(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }

    private static function numericOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
