<?php

namespace Tests\Feature;

use App\Services\Search\SearchService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * US-019 acceptance criteria — query embedding is cached (Redis/array store)
 * so repeated searches with the same query don't re-call the embedding
 * provider, while distinct queries produce distinct cache entries.
 */
class SearchServiceEmbeddingCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.embedding', [
            'base_url' => 'https://embedding.test',
            'model' => 'bge-m3',
            'api_key' => null,
            'dimensions' => 4,
            'timeout' => 120,
            'retry_times' => 2,
            'retry_delay_ms' => 1,
        ]);
    }

    public function test_repeated_search_with_same_query_reuses_cached_embedding(): void
    {
        Http::fake([
            '*' => Http::response(['embedding' => [0.1, 0.2, 0.3, 0.4]]),
        ]);

        $service = app(SearchService::class);

        $service->search('scaldabagno a pompa di calore');
        $service->search('scaldabagno a pompa di calore');

        Http::assertSentCount(1);
    }

    public function test_different_queries_produce_different_cache_entries(): void
    {
        Http::fake([
            '*' => Http::response(['embedding' => [0.1, 0.2, 0.3, 0.4]]),
        ]);

        $service = app(SearchService::class);

        $service->search('scaldabagno a pompa di calore');
        $service->search('caldaia a condensazione');

        Http::assertSentCount(2);
    }
}
