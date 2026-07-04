<?php

namespace Tests\Feature;

use App\Models\AttributeDefinition;
use App\Services\Enrichment\AttributeUnitConverter;
use App\Services\Enrichment\UnknownAttributeUnitException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-042 acceptance criteria — deterministic value+unit → canonical-unit
 * conversion:
 *  - Identity conversion (canonical unit → canonical unit).
 *  - The spec's "Dimostra" case: 3500 W → 3.5 kW.
 *  - Case-insensitive and trimmed unit matching.
 *  - Millibar → bar and millilitre → litre conversions.
 *  - A NULL unit on a numeric definition is treated as already canonical.
 *  - An unrecognized unit throws, never a silent write.
 *  - Conversion is undefined for textual attribute definitions.
 *
 * Runs against in-memory SQLite via RequiresDatabase.
 */
class AttributeUnitConverterTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    private AttributeUnitConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->converter = new AttributeUnitConverter;
    }

    public function test_identity_conversion_from_canonical_unit(): void
    {
        $definition = AttributeDefinition::factory()->numeric()->create([
            'key' => 'potenza_kw',
            'canonical_unit' => 'kW',
            'accepted_units' => ['kW' => 1, 'W' => 0.001],
        ]);

        $result = $this->converter->convertToCanonical($definition, 3.5, 'kW');

        $this->assertSame(3.5, $result);
    }

    public function test_converts_3500_watt_to_3_5_kw(): void
    {
        $definition = AttributeDefinition::factory()->numeric()->create([
            'key' => 'potenza_kw',
            'canonical_unit' => 'kW',
            'accepted_units' => ['kW' => 1, 'W' => 0.001],
        ]);

        $result = $this->converter->convertToCanonical($definition, 3500, 'W');

        $this->assertSame(3.5, $result);
    }

    public function test_unit_matching_is_case_insensitive_and_trims_whitespace(): void
    {
        $definition = AttributeDefinition::factory()->numeric()->create([
            'key' => 'potenza_kw',
            'canonical_unit' => 'kW',
            'accepted_units' => ['kW' => 1, 'W' => 0.001],
        ]);

        $this->assertSame(3.5, $this->converter->convertToCanonical($definition, 3500, ' w '));
        $this->assertSame(3.5, $this->converter->convertToCanonical($definition, 3.5, 'Kw'));
    }

    public function test_converts_millibar_to_bar(): void
    {
        $definition = AttributeDefinition::factory()->numeric()->create([
            'key' => 'pressione_bar',
            'canonical_unit' => 'bar',
            'accepted_units' => ['bar' => 1, 'mbar' => 0.001, 'mb' => 0.001],
        ]);

        $result = $this->converter->convertToCanonical($definition, 100, 'mbar');

        $this->assertSame(0.1, $result);
    }

    public function test_converts_millilitre_to_litre(): void
    {
        $definition = AttributeDefinition::factory()->numeric()->create([
            'key' => 'capacita_litri',
            'canonical_unit' => 'L',
            'accepted_units' => ['L' => 1, 'LT' => 1, 'ML' => 0.001],
        ]);

        $result = $this->converter->convertToCanonical($definition, 500, 'ML');

        $this->assertSame(0.5, $result);
    }

    public function test_null_unit_on_numeric_definition_is_treated_as_already_canonical(): void
    {
        $definition = AttributeDefinition::factory()->numeric()->create([
            'key' => 'potenza_kw',
            'canonical_unit' => 'kW',
            'accepted_units' => ['kW' => 1, 'W' => 0.001],
        ]);

        $result = $this->converter->convertToCanonical($definition, 3.5, null);

        $this->assertSame(3.5, $result);
    }

    public function test_unknown_unit_throws_with_key_and_unit_in_message(): void
    {
        $definition = AttributeDefinition::factory()->numeric()->create([
            'key' => 'potenza_kw',
            'canonical_unit' => 'kW',
            'accepted_units' => ['kW' => 1, 'W' => 0.001],
        ]);

        try {
            $this->converter->convertToCanonical($definition, 3.5, 'MW');
            $this->fail('Expected UnknownAttributeUnitException to be thrown.');
        } catch (UnknownAttributeUnitException $exception) {
            $this->assertStringContainsString('potenza_kw', $exception->getMessage());
            $this->assertStringContainsString('MW', $exception->getMessage());
        }
    }

    public function test_textual_definition_throws_logic_exception(): void
    {
        $definition = AttributeDefinition::factory()->text()->create([
            'key' => 'materiale',
        ]);

        $this->expectException(LogicException::class);

        $this->converter->convertToCanonical($definition, 1, 'anything');
    }
}
