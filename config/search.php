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

];
