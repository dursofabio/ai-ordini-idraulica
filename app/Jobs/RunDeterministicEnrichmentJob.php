<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\Enrichment\DeterministicEnrichmentPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Re-runs the Step A deterministic resolvers ({@see DeterministicEnrichmentPipeline})
 * for a single product on demand, without waiting for (or duplicating) the
 * batch CLI flow.
 *
 * {@see DeterministicEnrichmentPipeline::run()} no-ops for any product whose
 * `enrichment_status` is not `pending`, so this job first resets the status
 * to `pending` before invoking it. Every resolver in the pipeline only fills
 * fields that are still `null`, so re-running it on an already-enriched
 * product is a safe no-op on the data (aside from the status reset itself).
 *
 * Runs on the dedicated `enrich` queue, matching {@see ClassifyProductsBatchJob}.
 */
class RunDeterministicEnrichmentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public int $productId,
    ) {
        $this->onQueue('enrich');
    }

    public function handle(DeterministicEnrichmentPipeline $pipeline): void
    {
        $product = Product::query()->findOrFail($this->productId);

        $product->enrichment_status = 'pending';
        $product->save();

        $pipeline->run($product);
    }
}
