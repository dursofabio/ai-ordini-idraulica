<?php

namespace Tests\Feature;

use App\Exceptions\InvalidDeepEnrichmentResponseException;
use App\Services\Ai\ClaudeResponse;
use App\Services\Ai\DeepEnrichmentResponseValidator;
use Tests\TestCase;

/**
 * US-051 acceptance criteria — deep enrichment response validation:
 *  - A syntactically valid JSON response matching the expected schema is
 *    accepted, including its extended description, proposed product type,
 *    overall confidence, and proposed attributes.
 *  - Syntactically invalid JSON, an out-of-range confidence (overall or per
 *    attribute), an attribute without a key or a value, and a missing
 *    confidence are all rejected.
 *  - `tipo_prodotto` is optional (defaults to null) but, when present, must
 *    be a non-empty string.
 */
class DeepEnrichmentResponseValidatorTest extends TestCase
{
    public function test_accepts_a_valid_response_matching_the_expected_schema(): void
    {
        $response = $this->claudeResponse([
            'descrizione_estesa' => "# Caldaia\n\nDescrizione tecnica.",
            'tipo_prodotto' => 'Caldaia a condensazione',
            'confidence' => 75,
            'attributes' => [
                ['key' => 'potenza', 'value' => '25', 'unit' => 'kW', 'confidence' => 80],
                ['key' => 'colore', 'value' => 'bianco', 'confidence' => 60],
            ],
        ]);

        $result = (new DeepEnrichmentResponseValidator)->validate($response, 'ABC-001');

        $this->assertSame('ABC-001', $result->codiceArticolo);
        $this->assertSame("# Caldaia\n\nDescrizione tecnica.", $result->extendedDescription);
        $this->assertSame('Caldaia a condensazione', $result->productType);
        $this->assertSame(75, $result->confidence);
        $this->assertSame('25', $result->attributes['potenza']['value']);
        $this->assertSame('kW', $result->attributes['potenza']['unit']);
        $this->assertSame(80, $result->attributes['potenza']['confidence']);
        $this->assertSame('bianco', $result->attributes['colore']['value']);
    }

    public function test_accepts_a_bare_json_number_as_the_attribute_value(): void
    {
        $response = $this->claudeResponse([
            'descrizione_estesa' => 'testo',
            'confidence' => 75,
            'attributes' => [
                ['key' => 'potenza', 'value' => 6.5, 'unit' => 'kW', 'confidence' => 80],
            ],
        ]);

        $result = (new DeepEnrichmentResponseValidator)->validate($response, 'ABC-001');

        $this->assertSame('6.5', $result->attributes['potenza']['value']);
    }

    public function test_preserves_a_non_numeric_value_as_plain_text(): void
    {
        $response = $this->claudeResponse([
            'descrizione_estesa' => 'testo',
            'confidence' => 75,
            'attributes' => [
                ['key' => 'attacco', 'value' => '1/2', 'unit' => '"', 'confidence' => 80],
                ['key' => 'diametro', 'value' => '1.200,5', 'unit' => 'mm', 'confidence' => 80],
            ],
        ]);

        $result = (new DeepEnrichmentResponseValidator)->validate($response, 'ABC-001');

        $this->assertSame('1/2', $result->attributes['attacco']['value']);
        $this->assertSame('1.200,5', $result->attributes['diametro']['value']);
    }

    /**
     * A reserved key duplicating a field the product already carries outside
     * `product_attributes` (its article code, description, or taxonomy) must
     * be dropped on its own rather than invalidating the whole response, and
     * matched case-insensitively since the AI is free-form on key naming.
     */
    public function test_drops_reserved_attribute_keys_without_rejecting_the_rest(): void
    {
        $response = $this->claudeResponse([
            'descrizione_estesa' => 'testo',
            'confidence' => 90,
            'attributes' => [
                ['key' => 'codice_articolo', 'value' => '0.737.203', 'confidence' => 100],
                ['key' => 'Descrizione', 'value' => 'EWE0200 IN RG PROD.ACS', 'confidence' => 100],
                ['key' => 'tipo_prodotto', 'value' => 'Scambiatore estraibile', 'confidence' => 100],
                ['key' => 'potenza', 'value' => '5', 'unit' => 'kW', 'confidence' => 90],
            ],
        ]);

        $result = (new DeepEnrichmentResponseValidator)->validate($response, 'ABC-001');

        $this->assertSame(['potenza'], array_keys($result->attributes));
    }

    public function test_accepts_a_null_extended_description_with_an_empty_attributes_array(): void
    {
        $response = $this->claudeResponse([
            'descrizione_estesa' => null,
            'tipo_prodotto' => null,
            'confidence' => 20,
            'attributes' => [],
        ]);

        $result = (new DeepEnrichmentResponseValidator)->validate($response, 'ABC-001');

        $this->assertNull($result->extendedDescription);
        $this->assertNull($result->productType);
        $this->assertSame(20, $result->confidence);
        $this->assertSame([], $result->attributes);
    }

    /**
     * `tipo_prodotto` is optional (older cached responses or a minimal
     * schema): its absence must not invalidate an otherwise-valid response.
     */
    public function test_accepts_a_missing_product_type_as_null(): void
    {
        $response = $this->claudeResponse([
            'descrizione_estesa' => 'testo',
            'confidence' => 60,
            'attributes' => [],
        ]);

        $result = (new DeepEnrichmentResponseValidator)->validate($response, 'ABC-001');

        $this->assertNull($result->productType);
    }

    public function test_rejects_a_non_string_product_type(): void
    {
        $response = $this->claudeResponse([
            'descrizione_estesa' => 'testo',
            'tipo_prodotto' => 42,
            'confidence' => 60,
            'attributes' => [],
        ]);

        $this->expectException(InvalidDeepEnrichmentResponseException::class);

        (new DeepEnrichmentResponseValidator)->validate($response, 'ABC-001');
    }

    public function test_rejects_an_empty_product_type_string(): void
    {
        $response = $this->claudeResponse([
            'descrizione_estesa' => 'testo',
            'tipo_prodotto' => '   ',
            'confidence' => 60,
            'attributes' => [],
        ]);

        $this->expectException(InvalidDeepEnrichmentResponseException::class);

        (new DeepEnrichmentResponseValidator)->validate($response, 'ABC-001');
    }

    public function test_rejects_syntactically_invalid_json(): void
    {
        $response = new ClaudeResponse(content: 'not json at all', tokensIn: 10, tokensOut: 5, raw: []);

        $this->expectException(InvalidDeepEnrichmentResponseException::class);

        (new DeepEnrichmentResponseValidator)->validate($response, 'ABC-001');
    }

    public function test_rejects_a_missing_confidence(): void
    {
        $response = $this->claudeResponse([
            'descrizione_estesa' => 'testo',
            'attributes' => [],
        ]);

        $this->expectException(InvalidDeepEnrichmentResponseException::class);

        (new DeepEnrichmentResponseValidator)->validate($response, 'ABC-001');
    }

    public function test_rejects_an_out_of_range_overall_confidence(): void
    {
        $response = $this->claudeResponse([
            'descrizione_estesa' => 'testo',
            'confidence' => 150,
            'attributes' => [],
        ]);

        $this->expectException(InvalidDeepEnrichmentResponseException::class);

        (new DeepEnrichmentResponseValidator)->validate($response, 'ABC-001');
    }

    public function test_rejects_an_empty_extended_description_string(): void
    {
        $response = $this->claudeResponse([
            'descrizione_estesa' => '   ',
            'confidence' => 50,
            'attributes' => [],
        ]);

        $this->expectException(InvalidDeepEnrichmentResponseException::class);

        (new DeepEnrichmentResponseValidator)->validate($response, 'ABC-001');
    }

    public function test_rejects_an_attribute_without_a_key(): void
    {
        $response = $this->claudeResponse([
            'descrizione_estesa' => 'testo',
            'confidence' => 70,
            'attributes' => [
                ['value' => '25', 'unit' => 'kW', 'confidence' => 80],
            ],
        ]);

        $this->expectException(InvalidDeepEnrichmentResponseException::class);

        (new DeepEnrichmentResponseValidator)->validate($response, 'ABC-001');
    }

    public function test_rejects_an_attribute_without_a_value(): void
    {
        $response = $this->claudeResponse([
            'descrizione_estesa' => 'testo',
            'confidence' => 70,
            'attributes' => [
                ['key' => 'potenza', 'unit' => 'kW', 'confidence' => 80],
            ],
        ]);

        $this->expectException(InvalidDeepEnrichmentResponseException::class);

        (new DeepEnrichmentResponseValidator)->validate($response, 'ABC-001');
    }

    public function test_rejects_an_out_of_range_attribute_confidence(): void
    {
        $response = $this->claudeResponse([
            'descrizione_estesa' => 'testo',
            'confidence' => 70,
            'attributes' => [
                ['key' => 'potenza', 'value' => '25', 'unit' => 'kW', 'confidence' => -5],
            ],
        ]);

        $this->expectException(InvalidDeepEnrichmentResponseException::class);

        (new DeepEnrichmentResponseValidator)->validate($response, 'ABC-001');
    }

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
