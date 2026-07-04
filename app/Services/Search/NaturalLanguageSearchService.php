<?php

namespace App\Services\Search;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Thin orchestration layer (US-048) on top of {@see SearchService}: parses
 * the free-text query via {@see QueryParser} into recognized text + hard
 * attribute filters, merges those filters with whatever structured filters
 * the caller already applied (brand/family/subfamily/numeric ranges — they
 * combine in AND, {@see SearchService::applyFilters()} already supports more
 * than one attribute constraint), and delegates ranking to
 * {@see SearchService} using the recognized text instead of the raw query.
 *
 * {@see SearchService} itself is intentionally left untouched (US-048's
 * "minimo disturbo" principle): its own test suite assumes a text search
 * never triggers an AI call, which stays true — the AI call happens here,
 * one layer up.
 */
class NaturalLanguageSearchService
{
    public function __construct(
        private readonly QueryParser $queryParser,
        private readonly SearchService $searchService,
        private readonly MatchOutcomeResolver $matchOutcomeResolver,
    ) {}

    /**
     * @param  array{brand_id?: int, family_id?: int, subfamily_id?: int, attributes?: array<int, array{key: string, min?: float, max?: float, value?: string}>}  $filters
     */
    public function search(string $query, array $filters = []): NaturalLanguageSearchResult
    {
        $parsed = $this->queryParser->parse($query);

        /** @var Collection<int, SearchResult> $results */
        $results = $this->searchService->search($parsed->recognizedText, $this->mergeFilters($filters, $parsed));

        return new NaturalLanguageSearchResult($parsed, $results);
    }

    /**
     * @param  array{brand_id?: int, family_id?: int, subfamily_id?: int, attributes?: array<int, array{key: string, min?: float, max?: float, value?: string}>}  $filters
     */
    public function paginate(string $query, array $filters, int $perPage, int $page): NaturalLanguageSearchResult
    {
        $parsed = $this->queryParser->parse($query);

        /** @var LengthAwarePaginator<int, SearchResult> $results */
        $results = $this->searchService->paginate($parsed->recognizedText, $this->mergeFilters($filters, $parsed), $perPage, $page);

        return new NaturalLanguageSearchResult($parsed, $results);
    }

    /**
     * Resolves the tri-state confidence outcome (US-049) of a query: parses
     * it the same way {@see search()}/{@see paginate()} do, fetches the top
     * `config('search.confidence.max_candidates')` ranked candidates via
     * {@see SearchService::topCandidates()}, and delegates the automatic
     * match/disambiguation/no-results decision to
     * {@see MatchOutcomeResolver}.
     *
     * @param  array{brand_id?: int, family_id?: int, subfamily_id?: int, attributes?: array<int, array{key: string, min?: float, max?: float, value?: string}>}  $filters
     */
    public function matchOutcome(string $query, array $filters = []): SearchMatchOutcome
    {
        $parsed = $this->queryParser->parse($query);

        $candidates = $this->searchService->topCandidates(
            $parsed->recognizedText,
            $this->mergeFilters($filters, $parsed),
            (int) config('search.confidence.max_candidates'),
        );

        return $this->matchOutcomeResolver->resolve($candidates);
    }

    /**
     * Fuses the attribute filters extracted from the query into the
     * caller's already-explicit filters, in AND. Any structured filter the
     * page already applied (brand/family/subfamily/fixed numeric ranges)
     * survives untouched.
     *
     * @param  array{brand_id?: int, family_id?: int, subfamily_id?: int, attributes?: array<int, array{key: string, min?: float, max?: float, value?: string}>}  $filters
     * @return array{brand_id?: int, family_id?: int, subfamily_id?: int, attributes?: array<int, array{key: string, min?: float, max?: float, value?: string}>}
     */
    private function mergeFilters(array $filters, ParsedSearchQuery $parsed): array
    {
        if ($parsed->appliedFilters === []) {
            return $filters;
        }

        $filters['attributes'] = array_merge(
            $filters['attributes'] ?? [],
            array_map(
                fn (AppliedAttributeFilter $filter): array => $filter->toSearchFilterArray(),
                $parsed->appliedFilters,
            ),
        );

        return $filters;
    }
}
