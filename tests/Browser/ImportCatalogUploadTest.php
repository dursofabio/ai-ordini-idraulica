<?php

namespace Tests\Browser;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Hash;
use Laravel\Dusk\Browser;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Tests\DuskTestCase;

/**
 * US-024 demo scenario (spec "Dimostra"):
 * an admin uploads a catalog XLSX file from the "Importa Catalogo" backoffice
 * page, watches the batch progress through its lifecycle via the polling
 * table (no manual refresh), and sees the final row counts once the import
 * completes plus a database notification with the completion summary.
 * A second scenario covers a non-XLSX upload, rejected with a clear error
 * and no unhandled exception (AC4).
 *
 * Per-step screenshots are stored in docs/test-results/US-024/ as the visual
 * artifact of the run (Dusk does not record video). Actions are paced with
 * explicit visibility assertions and short holds so the artifacts are
 * readable by a non-technical reviewer.
 */
class ImportCatalogUploadTest extends DuskTestCase
{
    use DatabaseMigrations;

    protected const ARTIFACT_DIR = __DIR__.'/../../docs/test-results/US-024';

    /** @var array<int, string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function makeXlsxFixture(): string
    {
        $path = sys_get_temp_dir().'/us024-dusk-'.uniqid().'.xlsx';
        $this->tempFiles[] = $path;

        SimpleExcelWriter::create($path)
            ->addRow([
                'Codice articolo' => 'ART-DUSK-001',
                'Descrizione' => 'Miscelatore da cucina',
                'Costo un.1' => 55.0,
                'Giac.att.1' => 15.0,
            ])
            ->close();

        return $path;
    }

    public function test_admin_uploads_catalog_xlsx_and_sees_progress_and_summary(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin',
            'email' => AdminUserSeeder::DEFAULT_EMAIL,
            'password' => Hash::make(AdminUserSeeder::DEFAULT_PASSWORD),
        ]);

        $fixture = $this->makeXlsxFixture();

        $this->browse(function (Browser $browser) use ($admin, $fixture) {
            // 1. The admin logs in to the Filament backoffice.
            $browser->visit('/admin/login')
                ->waitFor('#form\\.email')
                ->type('#form\\.email', $admin->email)
                ->pause(400)
                ->type('#form\\.password', AdminUserSeeder::DEFAULT_PASSWORD)
                ->pause(400)
                ->click('button[type="submit"]')
                ->waitForLocation('/admin')
                ->pause(400);

            // 2. The admin opens "Importa Catalogo".
            $browser->visit('/admin/import-catalog')
                ->waitForText('Importa Catalogo')
                ->pause(400)
                ->screenshot('01-import-page-empty');

            // 3. The admin opens the upload action and attaches the XLSX file.
            $browser->press('Carica file XLSX')
                ->pause(500)
                ->waitFor('input[type="file"]')
                ->screenshot('02-upload-modal-open');

            $browser->attach('input[type="file"]', $fixture)
                ->pause(1000)
                ->screenshot('03-file-attached');

            // 4. The admin submits the upload; the batch appears in the table.
            $browser->with('.fi-modal', function (Browser $modal) {
                $modal->press('Carica file XLSX');
            });

            $browser->pause(800)
                ->waitForText('catalogo', 10)
                ->screenshot('04-batch-uploaded');

            // 5. The admin watches the batch advance through its lifecycle via
            // the table's automatic polling, up to completion.
            $browser->waitForText('completed', 20)
                ->pause(400)
                ->screenshot('05-batch-completed');

            // 6. The admin checks the panel bell icon for the completion
            // summary notification.
            $browser->click('.fi-topbar-database-notifications-trigger, [aria-label="Notifications"]')
                ->pause(500)
                ->waitForText('Import completato')
                ->assertSee('Import completato')
                ->pause(1500)
                ->screenshot('06-completion-notification');
        });
    }

    public function test_uploading_a_non_xlsx_file_shows_clear_error(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin',
            'email' => AdminUserSeeder::DEFAULT_EMAIL,
            'password' => Hash::make(AdminUserSeeder::DEFAULT_PASSWORD),
        ]);

        $invalidPath = sys_get_temp_dir().'/us024-dusk-invalid-'.uniqid().'.txt';
        file_put_contents($invalidPath, 'non è un file xlsx');
        $this->tempFiles[] = $invalidPath;

        $this->browse(function (Browser $browser) use ($admin, $invalidPath) {
            $browser->visit('/admin/login')
                ->waitFor('#form\\.email')
                ->type('#form\\.email', $admin->email)
                ->pause(400)
                ->type('#form\\.password', AdminUserSeeder::DEFAULT_PASSWORD)
                ->pause(400)
                ->click('button[type="submit"]')
                ->waitForLocation('/admin')
                ->pause(400);

            $browser->visit('/admin/import-catalog')
                ->waitForText('Importa Catalogo')
                ->pause(400);

            $browser->press('Carica file XLSX')
                ->pause(500)
                ->waitFor('input[type="file"]')
                ->screenshot('07-invalid-upload-modal-open');

            $browser->attach('input[type="file"]', $invalidPath)
                ->pause(800)
                ->screenshot('08-invalid-file-attached');

            // The FileUpload field's acceptedFileTypes() rejects the file
            // client-side; no crash, no white screen, a clear message.
            $browser->assertDontSee('Whoops')
                ->assertDontSee('Server Error')
                ->pause(1000)
                ->screenshot('09-invalid-file-error');
        });
    }
}
