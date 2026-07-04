<?php

namespace Tests\Feature;

use App\Services\Search\QueryParser;
use Database\Seeders\AttributeDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-048 TASK-09 — QueryParser orchestration:
 *  - A repeated identical query reuses the cached parse (a single HTTP
 *    call), mirroring SearchService's query-embedding cache
 *    (SearchServiceEmbeddingCacheTest).
 *  - `search.natural_language.enabled = false` makes zero HTTP calls and
 *    falls back to whole-text.
 *  - An AI/validation failure falls back to whole-text without propagating.
 *  - A realistic "tubo inox 1 pollice" response produces `attacco_pollici`
 *    converted to its canonical unit.
 *
 * Runs against in-memory SQLite via RequiresDatabase.
 */
class QueryParserTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');

        config()->set('services.ai_provider', 'anthropic');

        config()->set('services.anthropic', [
            'api_key' => 'test-api-key',
            'model' => 'claude-test-model',
            'model_fast' => 'claude-fast-test',
            'model_smart' => 'claude-smart-test',
            'version' => '2023-06-01',
            'base_url' => 'https://api.anthropic.test',
            'timeout' => 120,
            'retry_times' => 0,
            'retry_delay_ms' => 1,
        ]);

        config()->set('search.natural_language', [
            'enabled' => true,
            'cache_ttl' => 3600,
        ]);
    }

    public function test_repeated_query_reuses_cache_with_a_single_http_call(): void
    {
        Http::fake([
            '*' => Http::response($this->parseBody('caldaia a condensazione', [])),
        ]);

        $parser = app(QueryParser::class);

        $parser->parse('caldaia a condensazione');
        $parser->parse('caldaia a condensazione');

        Http::assertSentCount(1);
    }

    public function test_disabled_parsing_makes_no_http_call_and_falls_back_to_whole_text(): void
    {
        config()->set('search.natural_language.enabled', false);

        Http::fake();

        $parsed = app(QueryParser::class)->parse('caldaia a condensazione');

        Http::assertNothingSent();
        $this->assertSame('caldaia a condensazione', $parsed->recognizedText);
        $this->assertSame([], $parsed->appliedFilters);
    }

    public function test_ai_validation_failure_falls_back_to_whole_text_without_propagating(): void
    {
        Http::fake([
            '*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'not valid json {{{']],
                'usage' => ['input_tokens' => 5, 'output_tokens' => 5],
            ]),
        ]);

        $parsed = app(QueryParser::class)->parse('caldaia a condensazione');

        $this->assertSame('caldaia a condensazione', $parsed->recognizedText);
        $this->assertSame([], $parsed->appliedFilters);
    }

    public function test_realistic_response_for_tubo_inox_produces_converted_attacco_pollici_filter(): void
    {
        $this->seed(AttributeDefinitionSeeder::class);

        Http::fake([
            '*' => Http::response($this->parseBody('tubo inox', [
                ['key' => 'attacco_pollici', 'value_num' => 1, 'unit' => 'pollici'],
                ['key' => 'materiale', 'value_text' => 'inox'],
            ])),
        ]);

        $parsed = app(QueryParser::class)->parse('tubo inox 1 pollice');

        $this->assertSame('tubo inox', $parsed->recognizedText);
        $this->assertCount(2, $parsed->appliedFilters);

        $attaccoFilter = collect($parsed->appliedFilters)->firstWhere('key', 'attacco_pollici');
        $this->assertNotNull($attaccoFilter);
        $this->assertSame(1.0, $attaccoFilter->min);
        $this->assertSame(1.0, $attaccoFilter->max);
    }

    /**
     * @param  array<int, array<string, mixed>>  $attributes
     * @return array<string, mixed>
     */
    private function parseBody(string $recognizedText, array $attributes): array
    {
        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'recognized_text' => $recognizedText,
                    'attributes' => $attributes,
                ], JSON_UNESCAPED_UNICODE),
            ]],
            'usage' => ['input_tokens' => 50, 'output_tokens' => 20],
        ];
    }
}
