<?php

namespace Tests\Feature;

use App\Filament\Pages\ImportCatalog;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-024 AC2 — the "Importa Catalogo" page lists ImportBatch records with
 * their progress columns, ordered by most recent first, so an admin can
 * follow an import without a manual refresh (the ->poll('5s') itself isn't
 * asserted here — it's a Filament table-level concern — but the underlying
 * query/columns it polls against are).
 */
class ImportCatalogTableTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_table_lists_batches_ordered_by_most_recent_first(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $older = ImportBatch::factory()->create(['created_at' => now()->subHour()]);
        $newer = ImportBatch::factory()->create(['created_at' => now()]);

        Livewire::test(ImportCatalog::class)
            ->assertCanSeeTableRecords([$newer, $older], inOrder: true);
    }

    public function test_table_columns_show_batch_progress_fields(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $batch = ImportBatch::factory()->create([
            'filename' => 'catalogo-luglio.xlsx',
            'status' => 'completed',
            'total_rows' => 100,
            'processed_rows' => 100,
            'rows_new' => 40,
            'rows_updated' => 55,
            'skipped_rows' => 5,
            'finished_at' => now(),
        ]);

        Livewire::test(ImportCatalog::class)
            ->assertTableColumnStateSet('filename', 'catalogo-luglio.xlsx', $batch)
            ->assertTableColumnStateSet('total_rows', 100, $batch)
            ->assertTableColumnStateSet('processed_rows', 100, $batch)
            ->assertTableColumnStateSet('rows_new', 40, $batch)
            ->assertTableColumnStateSet('rows_updated', 55, $batch)
            ->assertTableColumnStateSet('skipped_rows', 5, $batch);
    }

    public function test_table_shows_batches_with_different_statuses(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $uploaded = ImportBatch::factory()->create(['status' => 'uploaded']);
        $importing = ImportBatch::factory()->create(['status' => 'importing']);
        $failed = ImportBatch::factory()->create(['status' => 'failed']);

        Livewire::test(ImportCatalog::class)
            ->assertCanSeeTableRecords([$uploaded, $importing, $failed]);
    }
}
