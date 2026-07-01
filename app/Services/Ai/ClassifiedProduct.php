<?php

namespace App\Services\Ai;

/**
 * A single validated classification result for one product within a batch,
 * as parsed and checked by {@see ClassificationResponseValidator}.
 */
final readonly class ClassifiedProduct
{
    public function __construct(
        public string $codiceArticolo,
        public ?string $brand,
        public ?string $family,
        public ?string $subfamily,
        public ?string $productType,
        public ?string $enrichedDescription,
        public ?int $confidence,
    ) {}
}
