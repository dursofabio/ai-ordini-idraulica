<?php

namespace Tests\Feature;

use App\Jobs\ClassifyProductsBatchJob;
use App\Models\Brand;
use App\Models\Product;
use App\Services\Enrichment\ClassificationBatchDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-014 acceptance criteria — batch selection and dispatch:
 *  - Only products missing brand_id or family_id, with enrichment_status
 *    'pending', are dispatched for classification.
 *  - Products already fully classified, or not pending, are excluded.
 *  - Each dispatched job receives a batch of 20-50 product IDs.
 *
 * Runs against in-memory SQLite via RequiresDatabase.
 */
class ClassificationBatchDispatcherTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_dispatches_jobs_only_for_eligible_pending_products(): void
    {
        Queue::fake();

        $brand = Brand::factory()->create();

        $eligibleMissingBrand = Product::factory()->create([
            'enrichment_status' => 'pending',
            'brand_id' => null,
        ]);

        $alreadyClassified = Product::factory()->create([
            'enrichment_status' => 'enriched',
            'brand_id' => $brand->id,
        ]);

        $needsReview = Product::factory()->create([
            'enrichment_status' => 'needs_review',
            'brand_id' => null,
        ]);

        (new ClassificationBatchDispatcher)->dispatch();

        Queue::assertPushed(ClassifyProductsBatchJob::class, function (ClassifyProductsBatchJob $job) use ($eligibleMissingBrand): bool {
            return in_array($eligibleMissingBrand->id, $job->productIds, true);
        });

        Queue::assertPushed(function (ClassifyProductsBatchJob $job) use ($alreadyClassified, $needsReview): bool {
            return ! in_array($alreadyClassified->id, $job->productIds, true)
                && ! in_array($needsReview->id, $job->productIds, true);
        });
    }

    public function test_dispatches_batches_sized_between_20_and_50(): void
    {
        Queue::fake();

        $products = Product::factory()->count(85)->create([
            'enrichment_status' => 'pending',
            'brand_id' => null,
        ]);

        (new ClassificationBatchDispatcher)->dispatch();

        $pushed = Queue::pushed(ClassifyProductsBatchJob::class);

        $this->assertCount(3, $pushed);

        foreach ($pushed as $job) {
            $size = count($job->productIds);

            $this->assertGreaterThanOrEqual(20, $size, 'Every dispatched batch must contain at least 20 products.');
            $this->assertLessThanOrEqual(50, $size, 'Every dispatched batch must contain at most 50 products.');
        }

        $dispatchedIds = $pushed->flatMap(fn (ClassifyProductsBatchJob $job): array => $job->productIds)->sort()->values();

        $this->assertSame($products->pluck('id')->sort()->values()->all(), $dispatchedIds->all());
    }

    public function test_does_not_dispatch_when_no_eligible_products_exist(): void
    {
        Queue::fake();

        Product::factory()->create([
            'enrichment_status' => 'enriched',
            'brand_id' => Brand::factory()->create()->id,
        ]);

        (new ClassificationBatchDispatcher)->dispatch();

        Queue::assertNotPushed(ClassifyProductsBatchJob::class);
    }
}
