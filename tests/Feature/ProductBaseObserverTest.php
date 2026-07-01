<?php

namespace Tests\Feature;

use App\Jobs\GenerateProductBaseEmbeddingJob;
use App\Models\ProductBase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-018 acceptance criteria — automatic embedding regeneration:
 *  - Updating description_ai on an existing product-base dispatches
 *    GenerateProductBaseEmbeddingJob with the correct id.
 *  - Saving the model without changing description_ai does not dispatch
 *    any job.
 *  - Creating a product-base with description_ai already set dispatches
 *    the job (saved fires on create too).
 */
class ProductBaseObserverTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_updating_description_ai_dispatches_embedding_job(): void
    {
        $productBase = ProductBase::factory()->create([
            'description_ai' => 'Tubo in PVC 32mm',
        ]);

        Queue::fake();

        $productBase->update(['description_ai' => 'Tubo in PVC 40mm']);

        Queue::assertPushed(
            GenerateProductBaseEmbeddingJob::class,
            static fn (GenerateProductBaseEmbeddingJob $job): bool => $job->productBaseId === $productBase->id,
        );
    }

    public function test_saving_without_changing_description_ai_does_not_dispatch_job(): void
    {
        $productBase = ProductBase::factory()->create([
            'description_ai' => 'Tubo in PVC 32mm',
        ]);

        Queue::fake();

        $productBase->update(['title' => 'Nuovo titolo']);

        Queue::assertNotPushed(GenerateProductBaseEmbeddingJob::class);
    }

    public function test_creating_product_base_with_description_ai_dispatches_job(): void
    {
        Queue::fake();

        $productBase = ProductBase::factory()->create([
            'description_ai' => 'Tubo in PVC 32mm',
        ]);

        Queue::assertPushed(
            GenerateProductBaseEmbeddingJob::class,
            static fn (GenerateProductBaseEmbeddingJob $job): bool => $job->productBaseId === $productBase->id,
        );
    }
}
