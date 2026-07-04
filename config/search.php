<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Hybrid Search Fusion Weights
    |--------------------------------------------------------------------------
    |
    | SearchService combines a vector similarity score (cosine, via pgvector)
    | and a full-text score (Postgres tsvector/ts_rank) into a single ranking
    | score: weights.vector * vector_score + weights.fts * fts_score. The
    | defaults follow US-019's acceptance criteria (70% vector / 30% FTS).
    |
    */

    'weights' => [
        'vector' => (float) env('SEARCH_WEIGHT_VECTOR', 0.7),
        'fts' => (float) env('SEARCH_WEIGHT_FTS', 0.3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Query Embedding Cache
    |--------------------------------------------------------------------------
    |
    | The embedding for a search query is cached (keyed by model + query hash)
    | to avoid re-calling the embedding provider on repeated searches.
    |
    */

    'cache' => [
        'ttl' => (int) env('SEARCH_QUERY_CACHE_TTL', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Relevance Cutoff
    |--------------------------------------------------------------------------
    |
    | When a free-text query is submitted with no structured filter narrowing
    | the pool, product-bases with neither a real FTS match nor a meaningful
    | vector match (fts_score and vector_score both at or below this
    | threshold) are excluded, instead of ranking the entire catalog by an
    | effectively meaningless score. The default is a small epsilon rather
    | than exactly 0: Postgres's `ts_rank` returns a tiny non-zero float
    | (e.g. 1e-20) even for completely unrelated text, which a strict ">  0"
    | check would treat as a real match. Skipped for filter-only or blank
    | queries. The exact `codice_articolo` match is always kept regardless.
    |
    */

    'relevance' => [
        'min_score' => (float) env('SEARCH_MIN_RELEVANCE_SCORE', 0.000001),
    ],

    /*
    |--------------------------------------------------------------------------
    | Natural-Language Query Parsing (US-048)
    |--------------------------------------------------------------------------
    |
    | The AI query parser (App\Services\Search\QueryParser) turns a free-text
    | search into recognized text + hard attribute filters, anchored to the
    | closed attribute registry (US-042). It can be switched off (e.g. in
    | environments without AI credentials, like Dusk) to fall back to plain
    | full-text/vector search with no AI call. Its result is cached per query
    | to bound AI cost on repeated searches.
    |
    */

    'natural_language' => [
        'enabled' => (bool) env('SEARCH_NL_PARSING_ENABLED', true),
        'cache_ttl' => (int) env('SEARCH_QUERY_PARSE_CACHE_TTL', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Match Confidence (US-049)
    |--------------------------------------------------------------------------
    |
    | Declares a tri-state search outcome — automatic match / disambiguation /
    | no results — from the relative margin between the top-1 and top-2
    | candidates' cosine similarity score: (score1 - score2) / score1. This is
    | always computed on the raw vector_score, never on the weighted
    | combined_score (which also folds in ts_rank, not comparable across
    | queries — see MatchOutcomeResolver). `max_candidates` bounds how many
    | top-ranked rows SearchService::topCandidates() fetches to compute that
    | margin and to offer as disambiguation options.
    |
    */

    'confidence' => [
        'auto_match_margin_threshold' => (float) env('SEARCH_CONFIDENCE_MARGIN_THRESHOLD', 0.15),
        'max_candidates' => (int) env('SEARCH_CONFIDENCE_MAX_CANDIDATES', 5),
    ],

];
