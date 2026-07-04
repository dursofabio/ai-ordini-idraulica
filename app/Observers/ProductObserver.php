<?php

namespace App\Observers;

use App\Jobs\GenerateProductEmbeddingJob;
use App\Models\Product;

/**
 * Dispatches {@see GenerateProductEmbeddingJob} whenever a product is created
 * or updated with `product_type`, `description_clean`, or `brand_id` changed
 * — the three inputs to {@see Product::composeEmbeddingContent()} — so the
 * vector embedding stays in sync automatically without a manual re-run of
 * `catalog:embed`.
 */
class ProductObserver
{
    /**
     * Fires once per INSERT. `created` (unlike `saved`) never fires again for
     * later updates on the same instance, so it needs no "was this really a
     * create" guard.
     */
    public function created(Product $product): void
    {
        if (self::hasEmbeddingTrigger($product)) {
            GenerateProductEmbeddingJob::dispatch($product->id);
        }
    }

    /**
     * Fires once per UPDATE. `wasChanged` is reliable here because it is only
     * meaningful relative to a prior fetched/persisted state.
     */
    public function updated(Product $product): void
    {
        if ($product->wasChanged(['product_type', 'description_clean', 'brand_id'])) {
            GenerateProductEmbeddingJob::dispatch($product->id);
        }
    }

    private static function hasEmbeddingTrigger(Product $product): bool
    {
        return $product->product_type !== null
            || $product->description_clean !== null
            || $product->brand_id !== null;
    }
}
