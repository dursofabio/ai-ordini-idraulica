<?php

namespace Tests\Feature;

use App\Exceptions\InvalidQueryParseResponseException;
use App\Models\AttributeDefinition;
use App\Services\Ai\AttributeDefinitionCatalog;
use App\Services\Ai\ClaudeResponse;
use App\Services\Ai\QueryParseResponseValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-048 TASK-07 — QueryParseResponseValidator matrix:
 *  - A known textual attribute produces an exact-match filter.
 *  - A key outside the registry is silently dropped (no exception).
 *  - A numeric registry key is silently dropped (numeric attribute filtering
 *    isn't supported for now — search is being redesigned).
 *  - Malformed JSON or a response missing "recognized_text" throws
 *    InvalidQueryParseResponseException.
 *
 * Runs against in-memory SQLite via RequiresDatabase.
 */
class QueryParseResponseValidatorTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_known_textual_attribute_produces_exact_match_filter(): void
    {
        AttributeDefinition::factory()->create([
            'key' => 'materiale',
            'data_type' => 'text',
            'canonical_unit' => null,
            'accepted_units' => null,
        ]);

        $response = $this->claudeResponse([
            'recognized_text' => 'tubo',
            'attributes' => [
                ['key' => 'materiale', 'value' => 'inox'],
            ],
        ]);

        $parsed = (new QueryParseResponseValidator)->validate($response, new AttributeDefinitionCatalog);

        $this->assertCount(1, $parsed->appliedFilters);
        $filter = $parsed->appliedFilters[0];
        $this->assertSame('materiale', $filter->key);
        $this->assertSame('inox', $filter->value);
        $this->assertSame(['key' => 'materiale', 'value' => 'inox'], $filter->toSearchFilterArray());
    }

    public function test_key_outside_registry_is_dropped_without_exception(): void
    {
        $response = $this->claudeResponse([
            'recognized_text' => 'tubo particolare',
            'attributes' => [
                ['key' => 'chiave_inesistente', 'value' => 'qualcosa'],
            ],
        ]);

        $parsed = (new QueryParseResponseValidator)->validate($response, new AttributeDefinitionCatalog);

        $this->assertSame('tubo particolare', $parsed->recognizedText);
        $this->assertSame([], $parsed->appliedFilters);
    }

    public function test_numeric_registry_key_is_dropped_without_exception(): void
    {
        AttributeDefinition::factory()->create([
            'key' => 'potenza_kw',
            'data_type' => 'numeric',
            'canonical_unit' => 'kW',
            'accepted_units' => ['kW' => 1, 'W' => 0.001],
        ]);
        AttributeDefinition::factory()->create([
            'key' => 'materiale',
            'data_type' => 'text',
            'canonical_unit' => null,
            'accepted_units' => null,
        ]);

        $response = $this->claudeResponse([
            'recognized_text' => 'caldaia inox',
            'attributes' => [
                ['key' => 'potenza_kw', 'value' => '3500'],
                ['key' => 'materiale', 'value' => 'inox'],
            ],
        ]);

        $parsed = (new QueryParseResponseValidator)->validate($response, new AttributeDefinitionCatalog);

        $this->assertCount(1, $parsed->appliedFilters);
        $this->assertSame('materiale', $parsed->appliedFilters[0]->key);
    }

    public function test_malformed_json_throws_invalid_query_parse_response_exception(): void
    {
        $response = new ClaudeResponse(content: 'not json {{{', tokensIn: 10, tokensOut: 5, raw: []);

        $this->expectException(InvalidQueryParseResponseException::class);

        (new QueryParseResponseValidator)->validate($response, new AttributeDefinitionCatalog);
    }

    public function test_response_missing_recognized_text_throws_invalid_query_parse_response_exception(): void
    {
        $response = $this->claudeResponse(['attributes' => []]);

        $this->expectException(InvalidQueryParseResponseException::class);

        (new QueryParseResponseValidator)->validate($response, new AttributeDefinitionCatalog);
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function claudeResponse(array $decoded): ClaudeResponse
    {
        return new ClaudeResponse(
            content: json_encode($decoded, JSON_UNESCAPED_UNICODE) ?: '{}',
            tokensIn: 100,
            tokensOut: 50,
            raw: [],
        );
    }
}
