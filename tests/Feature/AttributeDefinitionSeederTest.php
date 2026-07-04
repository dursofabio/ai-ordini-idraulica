<?php

namespace Tests\Feature;

use App\Models\AttributeDefinition;
use Database\Seeders\AttributeDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-042 acceptance criteria — attribute registry seeding:
 *  - The 10 catalog keys are seeded (9 canonical definitions derived from
 *    the regex extractors + `portata_lmin`).
 *  - `potenza_watt` does NOT exist as a definition — it is retired.
 *  - `potenza_kw` accepts `W` with a 0.001 conversion factor.
 *  - Textual definitions have a NULL `canonical_unit`.
 *  - The seeder is idempotent: running it twice does not create duplicates.
 *
 * Runs against in-memory SQLite via RequiresDatabase.
 */
class AttributeDefinitionSeederTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    private const EXPECTED_KEYS = [
        'potenza_kw',
        'capacita_litri',
        'attacco_pollici',
        'diametro_nominale',
        'pressione_nominale',
        'pressione_bar',
        'tensione_volt',
        'colore_ral',
        'materiale',
        'portata_lmin',
    ];

    public function test_seeds_the_expected_canonical_keys(): void
    {
        $this->seed(AttributeDefinitionSeeder::class);

        $this->assertSame(
            count(self::EXPECTED_KEYS),
            AttributeDefinition::query()->count(),
        );

        foreach (self::EXPECTED_KEYS as $key) {
            $this->assertTrue(
                AttributeDefinition::query()->where('key', $key)->exists(),
                "Expected attribute definition [{$key}] to be seeded.",
            );
        }
    }

    public function test_potenza_watt_is_not_seeded_as_a_definition(): void
    {
        $this->seed(AttributeDefinitionSeeder::class);

        $this->assertFalse(
            AttributeDefinition::query()->where('key', 'potenza_watt')->exists(),
        );
    }

    public function test_potenza_kw_accepts_watt_with_conversion_factor(): void
    {
        $this->seed(AttributeDefinitionSeeder::class);

        $definition = AttributeDefinition::query()->where('key', 'potenza_kw')->firstOrFail();

        $this->assertSame('kW', $definition->canonical_unit);
        $this->assertSame(0.001, $definition->accepted_units['W']);
    }

    public function test_textual_definitions_have_no_canonical_unit(): void
    {
        $this->seed(AttributeDefinitionSeeder::class);

        foreach (['colore_ral', 'materiale'] as $key) {
            $definition = AttributeDefinition::query()->where('key', $key)->firstOrFail();

            $this->assertSame('text', $definition->data_type);
            $this->assertNull($definition->canonical_unit);
            $this->assertNull($definition->accepted_units);
        }
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(AttributeDefinitionSeeder::class);
        $this->seed(AttributeDefinitionSeeder::class);

        $this->assertSame(
            count(self::EXPECTED_KEYS),
            AttributeDefinition::query()->count(),
        );
    }
}
