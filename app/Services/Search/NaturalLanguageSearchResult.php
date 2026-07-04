<?php

namespace App\Services\Search;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * The outcome of a natural-language search (US-048): the parsed
 * interpretation (recognized text + applied attribute filters, exposed so
 * the caller can show it back to the user) alongside the underlying
 * {@see SearchService} results.
 */
final readonly class NaturalLanguageSearchResult
{
    /**
     * @param  Collection<int, SearchResult>|LengthAwarePaginator<int, SearchResult>  $results
     */
    public function __construct(
        public ParsedSearchQuery $interpretation,
        public Collection|LengthAwarePaginator $results,
    ) {}
}
