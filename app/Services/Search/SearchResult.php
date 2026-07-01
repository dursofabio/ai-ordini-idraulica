<?php

namespace App\Services\Search;

use App\Models\ProductBase;

/**
 * A single grouped search result: a product-base plus the grouping metadata
 * a user needs to pick the right variant without opening every product
 * (how many variants exist, and the power range they span).
 */
final readonly class SearchResult
{
    public function __construct(
        public ProductBase $productBase,
        public int $variantsCount,
        public ?float $powerRangeMin,
        public ?float $powerRangeMax,
    ) {}
}
