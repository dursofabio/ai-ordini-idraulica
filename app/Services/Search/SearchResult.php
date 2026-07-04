<?php

namespace App\Services\Search;

use App\Models\Product;

/**
 * A single flat search result: a product plus the fused ranking scores
 * computed by {@see SearchService}.
 *
 * `vectorScore` is the raw cosine-similarity component (never the weighted
 * `combined_score`) — `null` on the non-pgsql fallback ranking path, which
 * has no vector fusion at all (see `SearchService::applyRanking()`).
 * `isExactCodeMatch` mirrors the exact `codice_articolo` match forced to the
 * top of the ranking, independent of driver.
 */
final readonly class SearchResult
{
    public function __construct(
        public Product $product,
        public ?float $vectorScore = null,
        public bool $isExactCodeMatch = false,
    ) {}
}
