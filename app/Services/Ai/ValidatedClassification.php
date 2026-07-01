<?php

namespace App\Services\Ai;

use Illuminate\Support\Collection;

/**
 * A validated batch classification result: one {@see ClassifiedProduct} per
 * requested codice_articolo, keyed by codice_articolo for O(1) lookup by
 * callers assembling per-product enrichment logs.
 *
 * Intentionally not `readonly`: `App\Jobs\ClassifyProductsBatchJob` replaces
 * individual entries in `$results` in place when a low-confidence result is
 * re-classified with the smart model, instead of rebuilding the whole DTO
 * for a single-entry update.
 */
final class ValidatedClassification
{
    /**
     * @param  Collection<string, ClassifiedProduct>  $results
     */
    public function __construct(
        public Collection $results,
    ) {}

    public function for(string $codiceArticolo): ?ClassifiedProduct
    {
        return $this->results->get($codiceArticolo);
    }
}
