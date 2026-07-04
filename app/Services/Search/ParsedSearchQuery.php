<?php

namespace App\Services\Search;

use App\Services\Ai\QueryParseResponseValidator;

/**
 * The outcome of parsing a natural-language search query (US-048): the
 * residual descriptive text meant to feed semantic/full-text ranking, plus
 * the technical attributes recognized as hard filters. Produced by
 * {@see QueryParser}, either from a successful AI parse
 * (validated by {@see QueryParseResponseValidator}) or via
 * {@see self::wholeTextFallback()} when parsing is disabled, cached, or
 * fails.
 */
final readonly class ParsedSearchQuery
{
    /**
     * @param  array<int, AppliedAttributeFilter>  $appliedFilters
     */
    public function __construct(
        public string $recognizedText,
        public array $appliedFilters = [],
    ) {}

    /**
     * No attribute was recognized (or recognition was skipped/failed): the
     * whole original query is treated as descriptive text, with no hard
     * filter applied.
     */
    public static function wholeTextFallback(string $query): self
    {
        return new self(recognizedText: $query, appliedFilters: []);
    }
}
