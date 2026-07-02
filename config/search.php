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

];
