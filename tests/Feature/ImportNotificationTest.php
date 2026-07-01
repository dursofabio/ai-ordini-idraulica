<?php

namespace Tests\Feature;

use App\Jobs\ImportXlsxJob;
use App\Jobs\PromoteStagingToProductsJob;
use App\Models\ImportBatch;
use App\Models\User;
use App\Services\ImportBatchService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;
use Throwable;

/**
 * US-024 AC3/AC4 — database notifications on import completion/failure:
 *  - PromoteStagingToProductsJob sends a success notification with the
 *    totals/new/updated/skipped summary to every panel-eligible user once
 *    the batch completes.
 *  - ImportXlsxJob and PromoteStagingToProductsJob each send a danger
 *    notification when their step fails, so the failure is visible via the
 *    panel bell icon even if the admin has left the import page.
 *
 * Runs the chain in sync (no Bus::fake()) like CatalogImportDispatchTest, so
 * the real job handlers execute and persist real database notifications.
 */
class ImportNotificationTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    private function makeXlsx(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'us024notif_').'.xlsx';

        SimpleExcelWriter::create($path)
            ->addRow([
                'Codice articolo' => 'ART-300',
                'Descrizione' => 'Sifone lavabo',
                'Costo un.1' => 9.5,
                'Giac.att.1' => 20.0,
            ])
            ->close();

        return $path;
    }

    public function test_completing_the_chain_sends_a_success_notification_with_the_summary_to_panel_users(): void
    {
        $admin = User::factory()->create();
        $path = $this->makeXlsx();

        $batch = app(ImportBatchService::class)->startImport($path);

        Bus::chain([
            new ImportXlsxJob($batch, $path),
            new PromoteStagingToProductsJob($batch),
        ])->dispatch();

        $notification = $admin->fresh()->notifications()->firstOrFail();

        $this->assertSame('Import completato', $notification->data['title']);
        $this->assertStringContainsString('Totali: 1', $notification->data['body']);
        $this->assertStringContainsString('nuovi: 1', $notification->data['body']);
        $this->assertStringContainsString('saltati: 0', $notification->data['body']);

        @unlink($path);
    }

    public function test_completing_the_chain_notifies_every_panel_eligible_user(): void
    {
        $firstAdmin = User::factory()->create();
        $secondAdmin = User::factory()->create();
        $path = $this->makeXlsx();

        $batch = app(ImportBatchService::class)->startImport($path);

        Bus::chain([
            new ImportXlsxJob($batch, $path),
            new PromoteStagingToProductsJob($batch),
        ])->dispatch();

        $this->assertSame(1, $firstAdmin->fresh()->notifications()->count());
        $this->assertSame(1, $secondAdmin->fresh()->notifications()->count());

        @unlink($path);
    }

    public function test_reader_failure_marks_batch_failed_and_sends_a_danger_notification(): void
    {
        $admin = User::factory()->create();

        $path = tempnam(sys_get_temp_dir(), 'us024badnotif_').'.xlsx';
        file_put_contents($path, 'contenuto-non-valido-come-xlsx');

        $batch = app(ImportBatchService::class)->startImport($path);

        try {
            ImportXlsxJob::dispatchSync($batch, $path);
        } catch (Throwable) {
            // Sync queue rethrows after failed() runs; the state/notification below is what matters.
        }

        $this->assertSame('failed', $batch->fresh()->status->value);

        $notification = $admin->fresh()->notifications()->firstOrFail();
        $this->assertSame('Import fallito', $notification->data['title']);

        @unlink($path);
    }

    public function test_promote_job_failed_callback_sends_a_danger_notification(): void
    {
        $admin = User::factory()->create();

        $batch = ImportBatch::factory()->create(['status' => 'enriching']);

        $job = new PromoteStagingToProductsJob($batch);
        $job->failed(new Exception('simulated upsert failure'));

        $this->assertSame('failed', $batch->fresh()->status->value);

        $notification = $admin->fresh()->notifications()->firstOrFail();
        $this->assertSame('Import fallito', $notification->data['title']);
        $this->assertStringContainsString('simulated upsert failure', $notification->data['body']);
    }
}
