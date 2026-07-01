<?php

namespace Tests\Feature;

use App\Enums\ImportBatchStatus;
use App\Exceptions\InvalidStatusTransitionException;
use App\Models\ImportBatch;
use App\Services\ImportBatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-006 acceptance criteria — batch status lifecycle:
 *  - The batch advances uploaded → importing → enriching → completed/failed.
 *  - Terminal states record finished_at.
 *  - Illegal transitions are rejected with InvalidStatusTransitionException.
 *
 * Runs against in-memory SQLite via RequiresDatabase.
 */
class ImportBatchStatusLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_full_lifecycle_to_completed(): void
    {
        $service = app(ImportBatchService::class);
        $batch = ImportBatch::factory()->create(['status' => ImportBatchStatus::Uploaded]);

        $service->markImporting($batch);
        $this->assertSame(ImportBatchStatus::Importing, $batch->fresh()->status);

        $service->markEnriching($batch);
        $this->assertSame(ImportBatchStatus::Enriching, $batch->fresh()->status);

        $service->markCompleted($batch);
        $fresh = $batch->fresh();
        $this->assertSame(ImportBatchStatus::Completed, $fresh->status);
        $this->assertNotNull($fresh->finished_at);
    }

    public function test_can_fail_from_importing(): void
    {
        $service = app(ImportBatchService::class);
        $batch = ImportBatch::factory()->create(['status' => ImportBatchStatus::Importing]);

        $service->markFailed($batch);

        $fresh = $batch->fresh();
        $this->assertSame(ImportBatchStatus::Failed, $fresh->status);
        $this->assertNotNull($fresh->finished_at);
    }

    public function test_illegal_transition_uploaded_to_completed_is_rejected(): void
    {
        $service = app(ImportBatchService::class);
        $batch = ImportBatch::factory()->create(['status' => ImportBatchStatus::Uploaded]);

        $this->expectException(InvalidStatusTransitionException::class);

        $service->markCompleted($batch);
    }

    public function test_terminal_status_cannot_transition_further(): void
    {
        $service = app(ImportBatchService::class);
        $batch = ImportBatch::factory()->create(['status' => ImportBatchStatus::Completed]);

        $this->expectException(InvalidStatusTransitionException::class);

        $service->markImporting($batch);
    }

    public function test_enum_transition_graph(): void
    {
        $this->assertTrue(ImportBatchStatus::Uploaded->canTransitionTo(ImportBatchStatus::Importing));
        $this->assertFalse(ImportBatchStatus::Uploaded->canTransitionTo(ImportBatchStatus::Enriching));
        $this->assertTrue(ImportBatchStatus::Importing->canTransitionTo(ImportBatchStatus::Failed));
        $this->assertTrue(ImportBatchStatus::Enriching->canTransitionTo(ImportBatchStatus::Completed));
        $this->assertTrue(ImportBatchStatus::Completed->isTerminal());
        $this->assertTrue(ImportBatchStatus::Failed->isTerminal());
        $this->assertFalse(ImportBatchStatus::Uploaded->isTerminal());
    }
}
