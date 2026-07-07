<?php

namespace Tests\Feature;

use App\Filament\Resources\EnrichmentLogs\Pages\ListEnrichmentLogs;
use App\Filament\Resources\EnrichmentLogs\Pages\ViewEnrichmentLog;
use App\Models\EnrichmentLog;
use App\Models\Product;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * Admin-facing "Storico chiamate AI" section: a global, read-only view of
 * every `enrichment_logs` row (across all products) exposing the exact
 * request payload sent to the AI provider and the raw response received,
 * so an admin can audit an AI call without querying the database.
 */
class EnrichmentLogResourceTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_table_lists_logs_ordered_by_most_recent_first(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $older = EnrichmentLog::factory()->create(['created_at' => now()->subHour()]);
        $newer = EnrichmentLog::factory()->create(['created_at' => now()]);

        Livewire::test(ListEnrichmentLogs::class)
            ->assertCanSeeTableRecords([$newer, $older], inOrder: true);
    }

    public function test_table_can_be_filtered_by_step(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $classification = EnrichmentLog::factory()->create(['step' => 'ai_classification']);
        $deepEnrichment = EnrichmentLog::factory()->create(['step' => 'ai_deep_enrichment']);

        Livewire::test(ListEnrichmentLogs::class)
            ->assertCanSeeTableRecords([$classification, $deepEnrichment])
            ->filterTable('step', 'ai_classification')
            ->assertCanSeeTableRecords([$classification])
            ->assertCanNotSeeTableRecords([$deepEnrichment]);
    }

    public function test_list_page_has_no_create_action(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        Livewire::test(ListEnrichmentLogs::class)
            ->assertActionDoesNotExist(CreateAction::class);
    }

    public function test_table_has_no_row_or_bulk_write_actions(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $log = EnrichmentLog::factory()->create();

        Livewire::test(ListEnrichmentLogs::class)
            ->assertTableActionDoesNotExist(EditAction::class, record: $log)
            ->assertTableActionDoesNotExist(DeleteAction::class, record: $log)
            ->assertTableBulkActionDoesNotExist(DeleteBulkAction::class);
    }

    public function test_view_page_shows_full_request_and_response_payload(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create(['codice_articolo' => 'ABC-001']);

        $log = EnrichmentLog::factory()->create([
            'product_id' => $product->id,
            'request_payload' => ['model' => 'claude-smart-test', 'messages' => [['role' => 'user', 'content' => 'Classifica questo prodotto.']]],
            'response_payload' => ['content' => [['type' => 'text', 'text' => '{"brand":"Test"}']]],
        ]);

        Livewire::test(ViewEnrichmentLog::class, ['record' => $log->getRouteKey()])
            ->assertOk()
            ->assertSchemaStateSet([
                'request_payload' => $log->request_payload,
                'response_payload' => $log->response_payload,
            ]);
    }

    public function test_view_page_has_no_edit_action(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $log = EnrichmentLog::factory()->create();

        Livewire::test(ViewEnrichmentLog::class, ['record' => $log->getRouteKey()])
            ->assertActionDoesNotExist(EditAction::class);
    }
}
