<?php

namespace Tests\Feature;

use App\Exceptions\InvalidClassificationResponseException;
use App\Models\Brand;
use App\Models\Family;
use App\Services\Ai\ClassificationResponseValidator;
use App\Services\Ai\ClaudeResponse;
use App\Services\Ai\TaxonomyCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-014 acceptance criteria — classification response validation:
 *  - A syntactically valid JSON response matching the expected schema, with
 *    one result per requested codice_articolo and values within the closed
 *    taxonomy, is accepted.
 *  - Syntactically invalid JSON is rejected.
 *  - A structure missing the "results" key, or missing a result for one of
 *    the requested products, is rejected.
 *  - A brand/family/subfamily outside the existing taxonomy is rejected.
 *
 * Runs against in-memory SQLite via RequiresDatabase.
 */
class ClassificationResponseValidatorTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_accepts_a_valid_response_matching_the_expected_schema(): void
    {
        Brand::factory()->create(['name' => 'Grohe']);
        Family::factory()->create(['name' => 'Rubinetteria']);

        $response = $this->claudeResponse([
            'results' => [
                [
                    'codice_articolo' => 'ABC-001',
                    'brand' => 'Grohe',
                    'family' => 'Rubinetteria',
                    'subfamily' => null,
                    'product_type' => 'Miscelatore',
                    'enriched_description' => 'Miscelatore da cucina Grohe',
                    'confidence' => 90,
                ],
            ],
        ]);

        $validated = (new ClassificationResponseValidator)->validate($response, collect(['ABC-001']), new TaxonomyCatalog);

        $result = $validated->for('ABC-001');

        $this->assertNotNull($result);
        $this->assertSame('Grohe', $result->brand);
        $this->assertSame('Rubinetteria', $result->family);
        $this->assertSame(90, $result->confidence);
    }

    public function test_accepts_a_response_wrapped_in_a_markdown_json_fence(): void
    {
        Brand::factory()->create(['name' => 'Grohe']);

        $decoded = [
            'results' => [
                [
                    'codice_articolo' => 'ABC-001',
                    'brand' => 'Grohe',
                    'family' => null,
                    'subfamily' => null,
                    'product_type' => null,
                    'enriched_description' => 'x',
                    'confidence' => 80,
                ],
            ],
        ];

        $response = new ClaudeResponse(
            content: "```json\n".json_encode($decoded, JSON_UNESCAPED_UNICODE)."\n```",
            tokensIn: 100,
            tokensOut: 50,
            raw: [],
        );

        $validated = (new ClassificationResponseValidator)->validate($response, collect(['ABC-001']), new TaxonomyCatalog);

        $this->assertSame('Grohe', $validated->for('ABC-001')?->brand);
    }

    public function test_rejects_syntactically_invalid_json(): void
    {
        $response = new ClaudeResponse(content: 'not json {{{', tokensIn: 10, tokensOut: 5, raw: []);

        $this->expectException(InvalidClassificationResponseException::class);

        (new ClassificationResponseValidator)->validate($response, collect(['ABC-001']), new TaxonomyCatalog);
    }

    public function test_rejects_response_missing_results_key(): void
    {
        $response = $this->claudeResponse(['foo' => 'bar']);

        $this->expectException(InvalidClassificationResponseException::class);

        (new ClassificationResponseValidator)->validate($response, collect(['ABC-001']), new TaxonomyCatalog);
    }

    public function test_rejects_response_missing_a_result_for_a_requested_product(): void
    {
        $response = $this->claudeResponse([
            'results' => [
                [
                    'codice_articolo' => 'ABC-001',
                    'brand' => null,
                    'family' => null,
                    'subfamily' => null,
                    'product_type' => null,
                    'enriched_description' => 'x',
                    'confidence' => 50,
                ],
            ],
        ]);

        $this->expectException(InvalidClassificationResponseException::class);

        (new ClassificationResponseValidator)->validate($response, collect(['ABC-001', 'DEF-002']), new TaxonomyCatalog);
    }

    public function test_rejects_brand_outside_the_closed_taxonomy(): void
    {
        Brand::factory()->create(['name' => 'Grohe']);

        $response = $this->claudeResponse([
            'results' => [
                [
                    'codice_articolo' => 'ABC-001',
                    'brand' => 'MarcaInventata',
                    'family' => null,
                    'subfamily' => null,
                    'product_type' => null,
                    'enriched_description' => 'x',
                    'confidence' => 50,
                ],
            ],
        ]);

        $this->expectException(InvalidClassificationResponseException::class);

        (new ClassificationResponseValidator)->validate($response, collect(['ABC-001']), new TaxonomyCatalog);
    }

    public function test_rejects_family_outside_the_closed_taxonomy(): void
    {
        Family::factory()->create(['name' => 'Rubinetteria']);

        $response = $this->claudeResponse([
            'results' => [
                [
                    'codice_articolo' => 'ABC-001',
                    'brand' => null,
                    'family' => 'FamigliaInventata',
                    'subfamily' => null,
                    'product_type' => null,
                    'enriched_description' => 'x',
                    'confidence' => 50,
                ],
            ],
        ]);

        $this->expectException(InvalidClassificationResponseException::class);

        (new ClassificationResponseValidator)->validate($response, collect(['ABC-001']), new TaxonomyCatalog);
    }

    public function test_attaches_a_valid_free_key_attribute_to_the_classified_product(): void
    {
        $response = $this->claudeResponse([
            'results' => [
                [
                    'codice_articolo' => 'ABC-001',
                    'brand' => null,
                    'family' => null,
                    'subfamily' => null,
                    'product_type' => null,
                    'enriched_description' => 'x',
                    'confidence' => 90,
                    'attributes' => [
                        [
                            'key' => 'portata_lmin',
                            'value_num' => 12.5,
                            'unit' => 'L/min',
                            'confidence' => 88,
                        ],
                    ],
                ],
            ],
        ]);

        $validated = (new ClassificationResponseValidator)->validate($response, collect(['ABC-001']), new TaxonomyCatalog);

        $attributes = $validated->for('ABC-001')?->attributes;

        $this->assertSame([
            'portata_lmin' => [
                'confidence' => 88,
                'value_num' => 12.5,
                'unit' => 'L/min',
            ],
        ], $attributes);
    }

    public function test_discards_a_malformed_attribute_entry_without_rejecting_the_whole_result(): void
    {
        $response = $this->claudeResponse([
            'results' => [
                [
                    'codice_articolo' => 'ABC-001',
                    'brand' => null,
                    'family' => null,
                    'subfamily' => null,
                    'product_type' => null,
                    'enriched_description' => 'x',
                    'confidence' => 90,
                    'attributes' => [
                        // missing key
                        ['value_num' => 1, 'confidence' => 90],
                        // confidence out of range
                        ['key' => 'materiale', 'value_text' => 'ottone', 'confidence' => 150],
                        // confidence missing
                        ['key' => 'colore', 'value_text' => 'bianco'],
                        // empty key
                        ['key' => '', 'value_num' => 1, 'confidence' => 90],
                        // no value at all
                        ['key' => 'senza_valore', 'confidence' => 90],
                        // the only valid entry
                        ['key' => 'potenza_kw', 'value_num' => 1.5, 'unit' => 'kW', 'confidence' => 70],
                    ],
                ],
            ],
        ]);

        $result = (new ClassificationResponseValidator)
            ->validate($response, collect(['ABC-001']), new TaxonomyCatalog)
            ->for('ABC-001');

        $this->assertNotNull($result);
        $this->assertSame(['potenza_kw'], array_keys($result->attributes));
        $this->assertSame(70, $result->attributes['potenza_kw']['confidence']);
    }

    public function test_missing_attributes_array_results_in_no_attributes(): void
    {
        $response = $this->claudeResponse([
            'results' => [
                [
                    'codice_articolo' => 'ABC-001',
                    'brand' => null,
                    'family' => null,
                    'subfamily' => null,
                    'product_type' => null,
                    'enriched_description' => 'x',
                    'confidence' => 90,
                ],
            ],
        ]);

        $result = (new ClassificationResponseValidator)
            ->validate($response, collect(['ABC-001']), new TaxonomyCatalog)
            ->for('ABC-001');

        $this->assertNotNull($result);
        $this->assertSame([], $result->attributes);
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
