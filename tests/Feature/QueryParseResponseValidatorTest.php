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
 *  - A known numeric attribute in a non-canonical unit is converted to the
 *    registry's canonical unit.
 *  - A known textual attribute produces an exact-match filter.
 *  - A key outside the registry is silently dropped (no exception).
 *  - An unconvertible unit drops only that attribute (with a warning),
 *    without failing the whole parse.
 *  - Malformed JSON or a response missing "recognized_text" throws
 *    InvalidQueryParseResponseException.
 *
 * Runs against in-memory SQLite via RequiresDatabase.
 */
class QueryParseResponseValidatorTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_known_numeric_attribute_in_non_canonical_unit_is_converted(): void
    {
        AttributeDefinition::factory()->create([
            'key' => 'potenza_kw',
            'data_type' => 'numeric',
            'canonical_unit' => 'kW',
            'accepted_units' => ['kW' => 1, 'W' => 0.001],
        ]);

        $response = $this->claudeResponse([
            'recognized_text' => 'caldaia',
            'attributes' => [
                ['key' => 'potenza_kw', 'value_num' => 3500, 'unit' => 'W'],
            ],
        ]);

        $parsed = (new QueryParseResponseValidator)->validate($response, new AttributeDefinitionCatalog);

        $this->assertSame('caldaia', $parsed->recognizedText);
        $this->assertCount(1, $parsed->appliedFilters);

        $filter = $parsed->appliedFilters[0];
        $this->assertSame('potenza_kw', $filter->key);
        $this->assertSame(3.5, $filter->min);
        $this->assertSame(3.5, $filter->max);
        $this->assertSame('kW', $filter->unit);
    }

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
                ['key' => 'materiale', 'value_text' => 'inox'],
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
                ['key' => 'chiave_inesistente', 'value_text' => 'qualcosa'],
            ],
        ]);

        $parsed = (new QueryParseResponseValidator)->validate($response, new AttributeDefinitionCatalog);

        $this->assertSame('tubo particolare', $parsed->recognizedText);
        $this->assertSame([], $parsed->appliedFilters);
    }

    public function test_unconvertible_unit_drops_only_that_attribute(): void
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
                ['key' => 'potenza_kw', 'value_num' => 10, 'unit' => 'BTU'],
                ['key' => 'materiale', 'value_text' => 'inox'],
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
