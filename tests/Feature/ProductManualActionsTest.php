<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\ListProducts;
use App\Jobs\ClassifyProductsBatchJob;
use App\Jobs\DeepEnrichProductJob;
use App\Jobs\GenerateProductEmbeddingJob;
use App\Jobs\RunDeterministicEnrichmentJob;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-031 acceptance criteria for the manual per-product reprocessing actions
 * exposed on the products table row:
 *  - AC1: "relaunchDeterministicEnrichment" queues RunDeterministicEnrichmentJob
 *    for that product only and shows a confirmation notification.
 *  - AC2: "relaunchAiClassification" queues ClassifyProductsBatchJob for that
 *    product only, regardless of its current enrichment_status, and shows a
 *    confirmation notification.
 *  - AC3: "regenerateEmbedding" queues GenerateProductEmbeddingJob for that
 *    product only and shows a confirmation notification (US-046: embedding
 *    is generated from the product's own product_type/brand, so it no
 *    longer depends on a linked product-base).
 *  - AC5: the classification action is disabled (and defensively guarded)
 *    when the product has no description.
 *
 * US-051 AC1: "deepEnrichWithAi" queues DeepEnrichProductJob for that product
 * only and shows a confirmation notification; disabled when the product has
 * no description, mirroring "relaunchAiClassification".
 */
class ProductManualActionsTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_relaunch_deterministic_enrichment_dispatches_job_for_that_product_only(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create();
        $other = Product::factory()->create();

        Queue::fake();

        Livewire::test(ListProducts::class)
            ->callTableAction('relaunchDeterministicEnrichment', $product)
            ->assertNotified();

        Queue::assertPushed(
            RunDeterministicEnrichmentJob::class,
            static fn (RunDeterministicEnrichmentJob $job): bool => $job->productId === $product->id,
        );
        Queue::assertNotPushed(
            RunDeterministicEnrichmentJob::class,
            static fn (RunDeterministicEnrichmentJob $job) => $job->productId === $other->id,
        );
    }

    public function test_relaunch_ai_classification_dispatches_job_regardless_of_current_status(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create([
            'description_raw' => 'CALDAIA A CONDENSAZIONE 25KW',
            'enrichment_status' => 'enriched',
        ]);

        Queue::fake();

        Livewire::test(ListProducts::class)
            ->callTableAction('relaunchAiClassification', $product)
            ->assertNotified();

        Queue::assertPushed(
            ClassifyProductsBatchJob::class,
            static fn (ClassifyProductsBatchJob $job): bool => $job->productIds === [$product->id],
        );

        $this->assertSame('pending', $product->fresh()->enrichment_status);
    }

    public function test_relaunch_ai_classification_is_disabled_when_description_is_empty(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create([
            'description_raw' => '',
            'description_clean' => null,
        ]);

        Queue::fake();

        Livewire::test(ListProducts::class)
            ->assertTableActionDisabled('relaunchAiClassification', $product);

        Queue::assertNotPushed(ClassifyProductsBatchJob::class);
    }

    public function test_deep_enrich_with_ai_dispatches_job_for_that_product_only(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create([
            'description_raw' => 'CALDAIA A CONDENSAZIONE 25KW',
        ]);
        $other = Product::factory()->create();

        Queue::fake();

        Livewire::test(ListProducts::class)
            ->callTableAction('deepEnrichWithAi', $product)
            ->assertNotified();

        Queue::assertPushed(
            DeepEnrichProductJob::class,
            static fn (DeepEnrichProductJob $job): bool => $job->productId === $product->id,
        );
        Queue::assertNotPushed(
            DeepEnrichProductJob::class,
            static fn (DeepEnrichProductJob $job): bool => $job->productId === $other->id,
        );
    }

    public function test_deep_enrich_with_ai_is_disabled_when_description_is_empty(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create([
            'description_raw' => '',
            'description_clean' => null,
        ]);

        Queue::fake();

        Livewire::test(ListProducts::class)
            ->assertTableActionDisabled('deepEnrichWithAi', $product);

        Queue::assertNotPushed(DeepEnrichProductJob::class);
    }

    public function test_regenerate_embedding_dispatches_job_for_that_product_only(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create();
        $other = Product::factory()->create();

        Queue::fake();

        Livewire::test(ListProducts::class)
            ->callTableAction('regenerateEmbedding', $product)
            ->assertNotified();

        Queue::assertPushed(
            GenerateProductEmbeddingJob::class,
            static fn (GenerateProductEmbeddingJob $job): bool => $job->productId === $product->id,
        );
        Queue::assertNotPushed(
            GenerateProductEmbeddingJob::class,
            static fn (GenerateProductEmbeddingJob $job): bool => $job->productId === $other->id,
        );
    }
}
