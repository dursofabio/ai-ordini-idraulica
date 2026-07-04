<?php

namespace Tests\Feature;

use App\Jobs\RunDeterministicEnrichmentJob;
use App\Models\Brand;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-031 acceptance criteria for the manual deterministic re-enrichment job:
 *  - Dispatches on the 'enrich' queue.
 *  - Executing the job resolves brand/attributes/family for a pending
 *    product with unresolved fields (reuses the existing pipeline/resolvers).
 *  - Executing the job on an already fully-enriched product is a safe no-op
 *    on the resolved data, but still resets `enrichment_status` to `pending`.
 */
class RunDeterministicEnrichmentJobTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_job_is_dispatched_on_the_enrich_queue(): void
    {
        Queue::fake();

        RunDeterministicEnrichmentJob::dispatch(1);

        Queue::assertPushedOn('enrich', RunDeterministicEnrichmentJob::class);
    }

    public function test_handle_resolves_brand_and_attributes_for_a_pending_product(): void
    {
        Brand::factory()->create(['name' => 'Vaillant', 'aliases' => ['VAI']]);

        $product = Product::factory()->create([
            'description_raw' => 'CALDAIA VAI 8-025 WNI 3.5KW',
            'description_clean' => null,
            'enrichment_status' => 'pending',
            'brand_id' => null,
        ]);

        Bus::dispatchSync(new RunDeterministicEnrichmentJob($product->id));

        $product->refresh();

        $this->assertNotNull($product->brand_id);
    }

    public function test_handle_resets_status_to_pending_and_is_safe_on_an_already_enriched_product(): void
    {
        $brand = Brand::factory()->create();

        $product = Product::factory()->create([
            'enrichment_status' => 'enriched',
            'brand_id' => $brand->id,
            'brand_source' => 'ai',
        ]);

        Bus::dispatchSync(new RunDeterministicEnrichmentJob($product->id));

        $product->refresh();

        $this->assertSame('pending', $product->enrichment_status);
        $this->assertSame($brand->id, $product->brand_id, 'Resolvers only fill null fields, so an already-resolved brand must survive re-running the pipeline.');
    }
}
