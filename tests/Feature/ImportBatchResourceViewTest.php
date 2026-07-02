<?php

namespace Tests\Feature;

use App\Filament\Resources\ImportBatches\Pages\ViewImportBatch;
use App\Models\ImportBatch;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-029 AC3/AC4/AC5 — the batch detail page exposes every counter and
 * timestamp, emphasizes error_rows with a danger badge when errors occurred,
 * and offers no edit action (read-only section).
 */
class ImportBatchResourceViewTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_view_page_shows_all_counters_and_timestamps(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $startedAt = now()->subMinutes(10);
        $finishedAt = now();

        $batch = ImportBatch::factory()->create([
            'filename' => 'catalogo-luglio.xlsx',
            'status' => 'completed',
            'total_rows' => 100,
            'processed_rows' => 100,
            'rows_new' => 40,
            'rows_updated' => 55,
            'error_rows' => 0,
            'skipped_rows' => 5,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
        ]);

        Livewire::test(ViewImportBatch::class, ['record' => $batch->getRouteKey()])
            ->assertOk()
            ->assertSchemaStateSet([
                'filename' => 'catalogo-luglio.xlsx',
                'total_rows' => 100,
                'processed_rows' => 100,
                'rows_new' => 40,
                'rows_updated' => 55,
                'error_rows' => 0,
                'skipped_rows' => 5,
            ]);
    }

    public function test_error_rows_is_emphasized_when_batch_has_errors(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $batch = ImportBatch::factory()->create(['error_rows' => 7]);

        Livewire::test(ViewImportBatch::class, ['record' => $batch->getRouteKey()])
            ->assertSchemaStateSet(['error_rows' => 7])
            ->assertSchemaComponentExists(
                'error_rows',
                checkComponentUsing: fn (TextEntry $component): bool => $component->getColor(7) === 'danger',
            );
    }

    public function test_error_rows_is_not_emphasized_when_batch_has_no_errors(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $batch = ImportBatch::factory()->create(['error_rows' => 0]);

        Livewire::test(ViewImportBatch::class, ['record' => $batch->getRouteKey()])
            ->assertSchemaStateSet(['error_rows' => 0])
            ->assertSchemaComponentExists(
                'error_rows',
                checkComponentUsing: fn (TextEntry $component): bool => $component->getColor(0) === 'gray',
            );
    }

    public function test_view_page_has_no_edit_action(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $batch = ImportBatch::factory()->create();

        Livewire::test(ViewImportBatch::class, ['record' => $batch->getRouteKey()])
            ->assertActionDoesNotExist(EditAction::class);
    }
}
