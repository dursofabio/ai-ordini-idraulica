<?php

namespace Tests\Feature;

use App\Models\AttributeDefinition;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-042 acceptance criteria — `attribute_definitions` schema:
 *  - Table exists with `key` (unique), `data_type`, `canonical_unit`,
 *    `accepted_units`, `description` columns.
 *  - `key` uniqueness is enforced at the database level.
 *  - `accepted_units` is cast to an array.
 *  - The factory's `numeric()`/`text()` states produce the expected shape.
 *
 * Runs against in-memory SQLite via RequiresDatabase, matching the
 * ProductCatalogSchemaTest pattern.
 */
class AttributeDefinitionTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_attribute_definitions_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('attribute_definitions'));
        $this->assertTrue(Schema::hasColumns('attribute_definitions', [
            'id', 'key', 'data_type', 'canonical_unit', 'accepted_units', 'description', 'created_at', 'updated_at',
        ]));
    }

    public function test_key_column_is_unique(): void
    {
        AttributeDefinition::factory()->create(['key' => 'potenza_kw']);

        $this->expectException(QueryException::class);

        AttributeDefinition::factory()->create(['key' => 'potenza_kw']);
    }

    public function test_accepted_units_is_cast_to_array(): void
    {
        $definition = AttributeDefinition::factory()->create([
            'accepted_units' => ['kW' => 1, 'W' => 0.001],
        ]);

        $fresh = $definition->fresh();

        $this->assertIsArray($fresh->accepted_units);
        $this->assertSame(['kW' => 1, 'W' => 0.001], $fresh->accepted_units);
    }

    public function test_numeric_state_produces_numeric_definition_with_canonical_unit(): void
    {
        $definition = AttributeDefinition::factory()->numeric()->create();

        $this->assertSame('numeric', $definition->data_type);
        $this->assertNotNull($definition->canonical_unit);
        $this->assertIsArray($definition->accepted_units);
    }

    public function test_text_state_produces_textual_definition_without_unit(): void
    {
        $definition = AttributeDefinition::factory()->text()->create();

        $this->assertSame('text', $definition->data_type);
        $this->assertNull($definition->canonical_unit);
        $this->assertNull($definition->accepted_units);
    }
}
