<?php

namespace Tests\Feature;

use App\Jobs\ImportXlsxJob;
use App\Models\StagingArticolo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-007 acceptance criteria — catalog:import wires the chunked read job:
 *  - A valid file creates a batch and queues ImportXlsxJob on the `import` queue.
 *  - Run end to end (sync), the command populates staging_articoli.
 *
 * Runs against in-memory SQLite via RequiresDatabase.
 */
class CatalogImportDispatchTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    private function makeXlsx(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'us007cmd_').'.xlsx';

        SimpleExcelWriter::create($path)
            ->addRow([
                'Codice articolo' => 'ART-100',
                'Descrizione' => 'Miscelatore lavabo',
                'Costo un.1' => 34.9,
                'Giac.att.1' => 12.0,
                'Descrizione Marca' => 'Grohe',
            ])
            ->close();

        return $path;
    }

    public function test_command_queues_import_job_on_import_queue(): void
    {
        Queue::fake();
        $path = $this->makeXlsx();

        $this->artisan('catalog:import', ['path' => $path])->assertSuccessful();

        Queue::assertPushedOn('import', ImportXlsxJob::class);

        @unlink($path);
    }

    public function test_command_populates_staging_end_to_end(): void
    {
        // No Queue::fake(): the sync connection runs the job inline.
        $path = $this->makeXlsx();

        $this->artisan('catalog:import', ['path' => $path])->assertSuccessful();

        $this->assertDatabaseCount('staging_articoli', 1);
        $this->assertDatabaseHas('staging_articoli', [
            'codice_articolo' => 'ART-100',
            'descrizione' => 'Miscelatore lavabo',
        ]);
        $this->assertSame(1, StagingArticolo::count());

        @unlink($path);
    }
}
