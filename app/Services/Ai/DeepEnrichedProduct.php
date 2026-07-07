<?php

namespace App\Services\Ai;

/**
 * A single validated deep-enrichment result for one product, as parsed and
 * checked by {@see DeepEnrichmentResponseValidator}.
 */
final readonly class DeepEnrichedProduct
{
    /**
     * @param  array<string, array{value: string, unit?: string, confidence: int}>  $attributes
     *                                                                                           Proposed technical attributes, keyed by attribute key. Each entry
     *                                                                                           carries its own confidence (0-100).
     */
    public function __construct(
        public string $codiceArticolo,
        public ?string $extendedDescription,
        public ?string $productType,
        public ?int $confidence,
        public array $attributes = [],
    ) {}
}
