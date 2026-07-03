<?php

namespace App\Services\Ai;

/**
 * A single validated classification result for one product within a batch,
 * as parsed and checked by {@see ClassificationResponseValidator}.
 */
final readonly class ClassifiedProduct
{
    /**
     * @param  array<string, array{value_num?: float, value_text?: string, unit?: string, confidence: int}>  $attributes
     *                                                                                                                    Proposed technical attributes, keyed by free-form attribute key (not limited to the
     *                                                                                                                    regex-known key list). Each entry carries its own confidence (0-100).
     */
    public function __construct(
        public string $codiceArticolo,
        public ?string $brand,
        public ?string $family,
        public ?string $subfamily,
        public ?string $productType,
        public ?string $enrichedDescription,
        public ?int $confidence,
        public array $attributes = [],
    ) {}
}
