<?php

namespace Tests\Feature;

use App\Exceptions\InvalidClassificationResponseException;
use App\Models\Brand;
use App\Models\Family;
use App\Models\Subfamily;
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
 *  - A brand/family/subfamily outside the existing taxonomy is dropped to
 *    null for that product only (as if the model had answered null, per the
 *    prompt's own "se non sei sicuro, lascia il campo a null" rule), never
 *    failing the batch: one invented subfamily must not send the other
 *    products of the batch to needs_review.
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

    public function test_drops_an_invented_brand_keeping_the_rest_of_the_result(): void
    {
        Brand::factory()->create(['name' => 'Grohe']);
        Family::factory()->create(['name' => 'Rubinetteria']);

        $response = $this->claudeResponse([
            'results' => [
                [
                    'codice_articolo' => 'ABC-001',
                    'brand' => 'MarcaInventata',
                    'family' => 'Rubinetteria',
                    'subfamily' => null,
                    'product_type' => 'Miscelatore',
                    'enriched_description' => 'x',
                    'confidence' => 88,
                ],
            ],
        ]);

        $result = (new ClassificationResponseValidator)
            ->validate($response, collect(['ABC-001']), new TaxonomyCatalog)
            ->for('ABC-001');

        $this->assertNotNull($result);
        $this->assertNull($result->brand);
        $this->assertSame('Rubinetteria', $result->family);
        $this->assertSame('Miscelatore', $result->productType);
        $this->assertSame(88, $result->confidence);
    }

    public function test_drops_an_invented_family_and_its_now_unscopable_subfamily_falls_back_to_a_global_check(): void
    {
        Family::factory()->create(['name' => 'Rubinetteria']);
        Subfamily::factory()->create(['name' => 'Miscelatori', 'family_id' => Family::query()->first()->id]);

        $response = $this->claudeResponse([
            'results' => [
                [
                    'codice_articolo' => 'ABC-001',
                    'brand' => null,
                    'family' => 'FamigliaInventata',
                    'subfamily' => 'Miscelatori',
                    'product_type' => null,
                    'enriched_description' => 'x',
                    'confidence' => 70,
                ],
            ],
        ]);

        $result = (new ClassificationResponseValidator)
            ->validate($response, collect(['ABC-001']), new TaxonomyCatalog)
            ->for('ABC-001');

        $this->assertNotNull($result);
        $this->assertNull($result->family);
        // The subfamily exists in the taxonomy, so once the invented family
        // is dropped it survives the (now global) membership check.
        $this->assertSame('Miscelatori', $result->subfamily);
    }

    public function test_one_invented_subfamily_does_not_reject_the_other_products_of_the_batch(): void
    {
        Brand::factory()->create(['name' => 'Grohe']);

        $response = $this->claudeResponse([
            'results' => [
                [
                    'codice_articolo' => 'ABC-001',
                    'brand' => 'Grohe',
                    'family' => null,
                    'subfamily' => 'CIRCOLATORI',
                    'product_type' => null,
                    'enriched_description' => 'x',
                    'confidence' => 90,
                ],
                [
                    'codice_articolo' => 'DEF-002',
                    'brand' => 'Grohe',
                    'family' => null,
                    'subfamily' => null,
                    'product_type' => 'Miscelatore',
                    'enriched_description' => 'y',
                    'confidence' => 92,
                ],
            ],
        ]);

        $validated = (new ClassificationResponseValidator)
            ->validate($response, collect(['ABC-001', 'DEF-002']), new TaxonomyCatalog);

        $offender = $validated->for('ABC-001');
        $innocent = $validated->for('DEF-002');

        $this->assertNotNull($offender);
        $this->assertNull($offender->subfamily);
        $this->assertSame('Grohe', $offender->brand);

        $this->assertNotNull($innocent);
        $this->assertSame('Grohe', $innocent->brand);
        $this->assertSame('Miscelatore', $innocent->productType);
        $this->assertSame(92, $innocent->confidence);
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
                            'key' => 'portata',
                            'value' => '12.5',
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
            'portata' => [
                'confidence' => 88,
                'value' => '12.5',
                'unit' => 'L/min',
            ],
        ], $attributes);
    }

    public function test_accepts_a_bare_json_number_as_the_attribute_value(): void
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
                        ['key' => 'portata', 'value' => 12.5, 'unit' => 'L/min', 'confidence' => 88],
                    ],
                ],
            ],
        ]);

        $result = (new ClassificationResponseValidator)
            ->validate($response, collect(['ABC-001']), new TaxonomyCatalog)
            ->for('ABC-001');

        $this->assertSame('12.5', $result->attributes['portata']['value']);
    }

    public function test_preserves_a_non_numeric_value_as_plain_text(): void
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
                        ['key' => 'attacco', 'value' => '1/2', 'unit' => '"', 'confidence' => 80],
                    ],
                ],
            ],
        ]);

        $result = (new ClassificationResponseValidator)
            ->validate($response, collect(['ABC-001']), new TaxonomyCatalog)
            ->for('ABC-001');

        $this->assertSame('1/2', $result->attributes['attacco']['value']);
    }

    /**
     * A reserved key duplicating a field the product already carries outside
     * `product_attributes` (its article code, description, or taxonomy) must
     * be dropped on its own rather than rejecting the whole result, and
     * matched case-insensitively since the AI is free-form on key naming.
     */
    public function test_drops_reserved_attribute_keys_without_rejecting_the_rest(): void
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
                        ['key' => 'codice_articolo', 'value' => '0.737.203', 'confidence' => 100],
                        ['key' => 'Descrizione', 'value' => 'EWE0200 IN RG PROD.ACS', 'confidence' => 100],
                        ['key' => 'tipo_prodotto', 'value' => 'Scambiatore estraibile', 'confidence' => 100],
                        ['key' => 'potenza', 'value' => '5', 'unit' => 'kW', 'confidence' => 90],
                    ],
                ],
            ],
        ]);

        $result = (new ClassificationResponseValidator)
            ->validate($response, collect(['ABC-001']), new TaxonomyCatalog)
            ->for('ABC-001');

        $this->assertSame(['potenza'], array_keys($result->attributes));
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
                        ['value' => '1', 'confidence' => 90],
                        // confidence out of range
                        ['key' => 'materiale', 'value' => 'ottone', 'confidence' => 150],
                        // confidence missing
                        ['key' => 'colore', 'value' => 'bianco'],
                        // empty key
                        ['key' => '', 'value' => '1', 'confidence' => 90],
                        // no value at all
                        ['key' => 'senza_valore', 'confidence' => 90],
                        // the only valid entry
                        ['key' => 'potenza', 'value' => '1.5', 'unit' => 'kW', 'confidence' => 70],
                    ],
                ],
            ],
        ]);

        $result = (new ClassificationResponseValidator)
            ->validate($response, collect(['ABC-001']), new TaxonomyCatalog)
            ->for('ABC-001');

        $this->assertNotNull($result);
        $this->assertSame(['potenza'], array_keys($result->attributes));
        $this->assertSame(70, $result->attributes['potenza']['confidence']);
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
