<?php

namespace Tests\Feature;

use App\Enums\ImportBatchStatus;
use App\Filament\Resources\ImportBatches\Pages\ListImportBatches;
use App\Models\ImportBatch;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-029 AC1/AC2/AC5 — the "Storico Import" list surfaces import batches
 * ordered by most recent first, with their outcome counters, a color-coded
 * status badge, a status filter, and no write actions.
 */
class ImportBatchResourceTableTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_table_lists_batches_ordered_by_most_recent_first(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $older = ImportBatch::factory()->create(['created_at' => now()->subHour()]);
        $newer = ImportBatch::factory()->create(['created_at' => now()]);

        Livewire::test(ListImportBatches::class)
            ->assertCanSeeTableRecords([$newer, $older], inOrder: true);
    }

    public function test_table_columns_show_batch_outcome_counters(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $batch = ImportBatch::factory()->create([
            'filename' => 'catalogo-luglio.xlsx',
            'status' => 'completed',
            'total_rows' => 100,
            'rows_new' => 40,
            'rows_updated' => 55,
            'error_rows' => 3,
            'skipped_rows' => 2,
        ]);

        Livewire::test(ListImportBatches::class)
            ->assertTableColumnStateSet('filename', 'catalogo-luglio.xlsx', $batch)
            ->assertTableColumnStateSet('total_rows', 100, $batch)
            ->assertTableColumnStateSet('rows_new', 40, $batch)
            ->assertTableColumnStateSet('rows_updated', 55, $batch)
            ->assertTableColumnStateSet('error_rows', 3, $batch)
            ->assertTableColumnStateSet('skipped_rows', 2, $batch);
    }

    public function test_status_column_uses_danger_badge_for_failed_batches(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $failed = ImportBatch::factory()->create(['status' => 'failed']);

        Livewire::test(ListImportBatches::class)
            ->assertTableColumnStateSet('status', ImportBatchStatus::Failed, $failed)
            ->assertTableColumnExists(
                'status',
                checkColumnUsing: fn (TextColumn $column): bool => $column->getColor($failed->status) === 'danger',
                record: $failed,
            );
    }

    public function test_error_rows_column_is_highlighted_when_batch_has_errors(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $withErrors = ImportBatch::factory()->create(['error_rows' => 5]);
        $withoutErrors = ImportBatch::factory()->create(['error_rows' => 0]);

        Livewire::test(ListImportBatches::class)
            ->assertTableColumnStateSet('error_rows', 5, $withErrors)
            ->assertTableColumnStateSet('error_rows', 0, $withoutErrors)
            ->assertTableColumnExists(
                'error_rows',
                checkColumnUsing: fn (TextColumn $column): bool => $column->getColor(5) === 'danger',
                record: $withErrors,
            )
            ->assertTableColumnExists(
                'error_rows',
                checkColumnUsing: fn (TextColumn $column): bool => $column->getColor(0) === 'gray',
                record: $withoutErrors,
            );
    }

    public function test_table_can_be_filtered_by_status(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $completed = ImportBatch::factory()->create(['status' => 'completed']);
        $failed = ImportBatch::factory()->create(['status' => 'failed']);

        Livewire::test(ListImportBatches::class)
            ->assertCanSeeTableRecords([$completed, $failed])
            ->filterTable('status', 'completed')
            ->assertCanSeeTableRecords([$completed])
            ->assertCanNotSeeTableRecords([$failed]);
    }

    public function test_list_page_has_no_create_action(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        Livewire::test(ListImportBatches::class)
            ->assertActionDoesNotExist(CreateAction::class);
    }

    public function test_table_has_no_row_or_bulk_write_actions(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $batch = ImportBatch::factory()->create();

        Livewire::test(ListImportBatches::class)
            ->assertTableActionDoesNotExist(EditAction::class, record: $batch)
            ->assertTableActionDoesNotExist(DeleteAction::class, record: $batch)
            ->assertTableBulkActionDoesNotExist(DeleteBulkAction::class);
    }
}
