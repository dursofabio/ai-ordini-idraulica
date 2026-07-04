<?php

namespace App\Services\Search;

/**
 * Tri-state outcome of a search's confidence resolution (US-049).
 *
 * {@see MatchOutcomeResolver} decides which one applies from the top-ranked
 * candidates of a query: a confident automatic match, candidates too close
 * to call automatically (guided disambiguation), or no candidates at all.
 */
enum MatchOutcome: string
{
    case AutoMatch = 'auto_match';
    case Disambiguation = 'disambiguation';
    case NoResults = 'no_results';
}
