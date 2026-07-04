<?php

namespace App\Services\Search;

use Illuminate\Support\Collection;

/**
 * The result of {@see MatchOutcomeResolver::resolve()} (US-049): the
 * tri-state outcome, the confidence margin behind it (`null` when a margin
 * isn't meaningful — no results, a single candidate, or a top vector score
 * too weak to trust), and the ordered candidates it was computed from, so
 * the caller can render them as disambiguation options.
 */
final readonly class SearchMatchOutcome
{
    /**
     * @param  Collection<int, SearchResult>  $candidates
     */
    public function __construct(
        public MatchOutcome $outcome,
        public ?float $margin,
        public Collection $candidates,
    ) {}
}
