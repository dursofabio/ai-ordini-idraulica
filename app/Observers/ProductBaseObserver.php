<?php

namespace App\Observers;

use App\Jobs\GenerateProductBaseEmbeddingJob;
use App\Models\ProductBase;

/**
 * Dispatches {@see GenerateProductBaseEmbeddingJob} whenever a product-base
 * is created or updated with a non-empty `description_ai`, so the vector
 * embedding stays in sync automatically without a manual re-run of
 * `catalog:embed`.
 */
class ProductBaseObserver
{
    /**
     * Fires once per INSERT. `created` (unlike `saved`) never fires again for
     * later updates on the same instance, so it needs no "was this really a
     * create" guard.
     */
    public function created(ProductBase $productBase): void
    {
        $this->dispatchIfDescriptionPresent($productBase);
    }

    /**
     * Fires once per UPDATE. `wasChanged` is reliable here because it is only
     * meaningful relative to a prior fetched/persisted state.
     */
    public function updated(ProductBase $productBase): void
    {
        if (! $productBase->wasChanged('description_ai')) {
            return;
        }

        $this->dispatchIfDescriptionPresent($productBase);
    }

    private function dispatchIfDescriptionPresent(ProductBase $productBase): void
    {
        if (trim((string) $productBase->description_ai) === '') {
            return;
        }

        GenerateProductBaseEmbeddingJob::dispatch($productBase->id);
    }
}
