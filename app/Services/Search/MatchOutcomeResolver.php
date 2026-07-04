<?php

namespace App\Services\Search;

use Illuminate\Support\Collection;

/**
 * Resolves the tri-state confidence outcome of a search (US-049) from its
 * ordered top candidates ({@see SearchService::topCandidates()}): a
 * confident automatic match, guided disambiguation among close candidates,
 * or no results.
 *
 * The relative margin between the top-1 and top-2 candidate is computed on
 * the raw `vectorScore` only — never on the weighted `combined_score`, which
 * also folds in `ts_rank`. `ts_rank` isn't comparable across different
 * queries (its scale depends on the query's own term frequency/rarity), so
 * a margin partly driven by it would be meaningless from one search to the
 * next; the cosine similarity component doesn't have that problem.
 *
 * Precedence (first match wins):
 *  1. No candidates at all → {@see MatchOutcome::NoResults}, no margin.
 *  2. The top candidate is the exact `codice_articolo` match → always
 *     {@see MatchOutcome::AutoMatch}, regardless of its vector score — an
 *     exact code match is unambiguous by construction.
 *  3. A single candidate → always {@see MatchOutcome::AutoMatch} (margin
 *     1.0): there is nothing to disambiguate against.
 *  4. The top candidate's `vectorScore` is `null` or `<= 0` → cautious
 *     {@see MatchOutcome::Disambiguation} with no margin: a non-positive or
 *     missing top score means the vector signal can't vouch for a confident
 *     match (this is also the path taken on the non-pgsql fallback driver,
 *     where `vectorScore` is never populated — see {@see SearchResult}).
 *  5. The top two candidates have the exact same `vectorScore` (identical
 *     embeddings, e.g. product variants) → always
 *     {@see MatchOutcome::Disambiguation}, margin `0.0`.
 *  6. Otherwise, the relative margin `(score1 - score2) / score1` decides:
 *     at or above `config('search.confidence.auto_match_margin_threshold')`
 *     is an automatic match, below it is disambiguation.
 */
class MatchOutcomeResolver
{
    /**
     * @param  Collection<int, SearchResult>  $orderedCandidates  Ranked best-first, as returned by SearchService::topCandidates().
     */
    public function resolve(Collection $orderedCandidates): SearchMatchOutcome
    {
        if ($orderedCandidates->isEmpty()) {
            return new SearchMatchOutcome(MatchOutcome::NoResults, null, $orderedCandidates);
        }

        $top = $orderedCandidates->first();

        if ($top->isExactCodeMatch) {
            return new SearchMatchOutcome(MatchOutcome::AutoMatch, 1.0, $orderedCandidates);
        }

        if ($orderedCandidates->count() === 1) {
            return new SearchMatchOutcome(MatchOutcome::AutoMatch, 1.0, $orderedCandidates);
        }

        $topScore = $top->vectorScore;

        if ($topScore === null || $topScore <= 0.0) {
            return new SearchMatchOutcome(MatchOutcome::Disambiguation, null, $orderedCandidates);
        }

        $secondScore = $orderedCandidates->get(1)->vectorScore;

        if ($secondScore === $topScore) {
            return new SearchMatchOutcome(MatchOutcome::Disambiguation, 0.0, $orderedCandidates);
        }

        $margin = ($topScore - ($secondScore ?? 0.0)) / $topScore;

        $threshold = (float) config('search.confidence.auto_match_margin_threshold');

        $outcome = $margin >= $threshold ? MatchOutcome::AutoMatch : MatchOutcome::Disambiguation;

        return new SearchMatchOutcome($outcome, $margin, $orderedCandidates);
    }
}
