<?php

namespace App\Services\Search;

use App\Services\Ai\AiClient;
use App\Services\Ai\AttributeDefinitionCatalog;
use App\Services\Ai\QueryParsePromptBuilder;
use App\Services\Ai\QueryParseResponseValidator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Parses a free-text search query into a {@see ParsedSearchQuery} — residual
 * descriptive text plus hard attribute filters anchored to the closed
 * attribute registry (US-042/US-048) — via the fast AI model
 * ({@see AiClient::modelFast()}), the same tier used for bulk classification
 * (US-043).
 *
 * This is the single entry point that may call the AI: query parsing is
 * skipped entirely (no HTTP call) for a blank query or when
 * `config('search.natural_language.enabled')` is false, and the parsed
 * result is cached per query (`config('search.natural_language.cache_ttl')`)
 * to bound AI cost on repeated searches, mirroring
 * {@see SearchService}'s query-embedding cache. Any failure along the way —
 * a request exception, an invalid AI response, an unconvertible unit that
 * bubbles up unexpectedly — is logged as a warning and degrades to
 * {@see ParsedSearchQuery::wholeTextFallback()} instead of breaking the
 * search: a parser failure must never be user-visible as a search failure.
 */
class QueryParser
{
    public function __construct(
        private readonly AiClient $aiClient,
        private readonly QueryParsePromptBuilder $promptBuilder,
        private readonly QueryParseResponseValidator $validator,
        private readonly AttributeDefinitionCatalog $catalog,
    ) {}

    public function parse(string $query): ParsedSearchQuery
    {
        if (trim($query) === '') {
            return ParsedSearchQuery::wholeTextFallback($query);
        }

        if (! config('search.natural_language.enabled')) {
            return ParsedSearchQuery::wholeTextFallback($query);
        }

        $cacheKey = 'search:query-parse:'.md5($query);
        $ttl = (int) config('search.natural_language.cache_ttl');

        return Cache::remember($cacheKey, $ttl, function () use ($query): ParsedSearchQuery {
            try {
                $payload = $this->promptBuilder->build($query, $this->catalog, $this->aiClient->modelFast());
                $response = $this->aiClient->messages($payload);

                return $this->validator->validate($response, $this->catalog);
            } catch (Throwable $e) {
                Log::warning('Parsing AI della query di ricerca fallito, fallback a testo intero.', [
                    'query' => $query,
                    'exception' => $e->getMessage(),
                ]);

                return ParsedSearchQuery::wholeTextFallback($query);
            }
        });
    }
}
