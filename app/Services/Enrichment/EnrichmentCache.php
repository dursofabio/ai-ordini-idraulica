<?php

namespace App\Services\Enrichment;

use App\Models\Product;
use App\Services\Ai\ClassifiedProduct;
use Illuminate\Support\Facades\Cache;

/**
 * Caches AI classification results keyed by a hash of the product's
 * `description_raw`, so identical descriptions seen again — within the same
 * batch, across batches, or on a later reimport — are served from cache
 * instead of triggering a new Anthropic API call (US-016).
 *
 * TTL is read from `config('services.anthropic.enrichment_cache_ttl')`: null
 * means the entry never expires.
 */
class EnrichmentCache
{
    /**
     * Return the previously cached classification for this product's
     * `description_raw`, or null on a cache miss.
     */
    public function get(Product $product): ?ClassifiedProduct
    {
        return Cache::get($this->key($product));
    }

    /**
     * Store the classification result under this product's
     * `description_raw` hash, so any future product sharing that exact
     * description resolves from cache instead of calling the AI again.
     */
    public function put(Product $product, ClassifiedProduct $result): void
    {
        $ttl = config('services.anthropic.enrichment_cache_ttl');
        $key = $this->key($product);

        if ($ttl === null) {
            Cache::forever($key, $result);

            return;
        }

        Cache::put($key, $result, (int) $ttl);
    }

    /**
     * Cache key derived from the trimmed `description_raw`, so two products
     * with byte-identical descriptions (modulo surrounding whitespace) share
     * the same cache entry regardless of `codice_articolo`.
     */
    private function key(Product $product): string
    {
        return 'enrichment:classification:'.hash('sha256', trim((string) $product->description_raw));
    }
}
