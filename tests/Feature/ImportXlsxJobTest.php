<?php

namespace Tests\Feature;

use App\Enums\ImportBatchStatus;
use App\Jobs\ImportXlsxJob;
use App\Models\ImportBatch;
use App\Models\StagingArticolo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;
use Throwable;

/**
 * US-007 acceptance criteria — the chunked XLSX reader job:
 *  - Maps typed columns (codice_articolo, descrizione, costo, giacenza) and
 *    stores the whole row (snake_cased headers) in raw_row.
 *  - Skips rows without a codice_articolo and records the skipped count.
 *  - Processes files larger than one chunk without losing rows.
 *  - Advances the batch uploaded -> importing -> enriching, and -> failed on
 *    an unreadable file.
 *
 * Runs against in-memory SQLite via RequiresDatabase; the sync queue runs the
 * job inline (see phpunit.xml QUEUE_CONNECTION=sync).
 */
class ImportXlsxJobTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    /** @var array<int, string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    /**
     * Write an XLSX fixture with the real TeamSystem heading row.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function makeXlsx(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'us007_').'.xlsx';
        $this->tempFiles[] = $path;

        $writer = SimpleExcelWriter::create($path);
        foreach ($rows as $row) {
            $writer->addRow($row);
        }
        $writer->close();

        return $path;
    }

    private function row(string $codice, string $descrizione, float $costo, float $giacenza, string $marca = 'ACME'): array
    {
        return [
            'Codice articolo' => $codice,
            'Descrizione' => $descrizione,
            'Costo un.1' => $costo,
            'Giac.att.1' => $giacenza,
            'Descrizione Marca' => $marca,
        ];
    }

    public function test_job_maps_typed_fields_and_stores_snake_cased_raw_row(): void
    {
        $batch = ImportBatch::factory()->create(['status' => ImportBatchStatus::Uploaded]);
        $path = $this->makeXlsx([
            $this->row('ART-001', 'Tubo rame 18mm', 12.5, 40.0, 'Rehau'),
        ]);

        ImportXlsxJob::dispatchSync($batch, $path);

        $row = StagingArticolo::where('codice_articolo', 'ART-001')->firstOrFail();

        $this->assertSame('Tubo rame 18mm', $row->descrizione);
        $this->assertEquals(12.5, (float) $row->costo);
        $this->assertEquals(40.0, (float) $row->giacenza);
        $this->assertSame($batch->id, $row->import_batch_id);
        $this->assertSame('pending', $row->status);

        // The full row is preserved in raw_row keyed by snake_cased headers.
        $this->assertIsArray($row->raw_row);
        $this->assertSame('ART-001', $row->raw_row['codice_articolo']);
        $this->assertArrayHasKey('descrizione_marca', $row->raw_row);
        $this->assertSame('Rehau', $row->raw_row['descrizione_marca']);
        $this->assertArrayHasKey('giac_att_1', $row->raw_row);
    }

    public function test_job_skips_rows_without_codice_and_counts_them(): void
    {
        $batch = ImportBatch::factory()->create(['status' => ImportBatchStatus::Uploaded]);
        $path = $this->makeXlsx([
            $this->row('ART-001', 'Valvola', 5.0, 10.0),
            $this->row('', 'Riga senza codice', 9.0, 3.0),
            $this->row('ART-002', 'Raccordo', 2.5, 100.0),
            $this->row('   ', 'Codice solo spazi', 1.0, 1.0),
        ]);

        ImportXlsxJob::dispatchSync($batch, $path);

        $fresh = $batch->fresh();

        $this->assertSame(4, $fresh->total_rows);
        $this->assertSame(2, $fresh->processed_rows);
        $this->assertSame(2, $fresh->skipped_rows);
        $this->assertDatabaseCount('staging_articoli', 2);
        $this->assertDatabaseMissing('staging_articoli', ['descrizione' => 'Riga senza codice']);
    }

    public function test_job_processes_files_spanning_multiple_chunks(): void
    {
        $count = ImportXlsxJob::CHUNK_SIZE * 2 + 500; // 2500 rows -> 3 chunks

        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = $this->row(sprintf('ART-%05d', $i), "Articolo {$i}", $i + 0.5, $i);
        }

        $batch = ImportBatch::factory()->create(['status' => ImportBatchStatus::Uploaded]);
        $path = $this->makeXlsx($rows);

        ImportXlsxJob::dispatchSync($batch, $path);

        $fresh = $batch->fresh();

        $this->assertSame($count, $fresh->total_rows);
        $this->assertSame($count, $fresh->processed_rows);
        $this->assertSame(0, $fresh->skipped_rows);
        $this->assertDatabaseCount('staging_articoli', $count);
    }

    public function test_job_advances_batch_to_enriching_on_success(): void
    {
        $batch = ImportBatch::factory()->create(['status' => ImportBatchStatus::Uploaded]);
        $path = $this->makeXlsx([
            $this->row('ART-001', 'Tubo', 3.0, 5.0),
        ]);

        ImportXlsxJob::dispatchSync($batch, $path);

        $this->assertSame(ImportBatchStatus::Enriching, $batch->fresh()->status);
    }

    public function test_job_marks_batch_failed_on_unreadable_file(): void
    {
        $batch = ImportBatch::factory()->create(['status' => ImportBatchStatus::Uploaded]);

        // A .xlsx path whose bytes are not a valid workbook: openspout throws
        // while streaming, after the batch has moved to importing.
        $path = tempnam(sys_get_temp_dir(), 'us007bad_').'.xlsx';
        $this->tempFiles[] = $path;
        file_put_contents($path, 'questo-non-e-un-xlsx');

        try {
            ImportXlsxJob::dispatchSync($batch, $path);
        } catch (Throwable) {
            // Sync queue rethrows after failed() runs; the state change is what matters.
        }

        $this->assertSame(ImportBatchStatus::Failed, $batch->fresh()->status);
    }
}
