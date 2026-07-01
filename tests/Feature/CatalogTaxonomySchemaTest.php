<?php

namespace Tests\Feature;

use App\Models\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-003 acceptance criteria — schema and JSON cast:
 *  - brands, families, subfamilies tables exist with all expected columns.
 *  - Brand exposes `aliases` as a JSON array cast (round-trip through the DB).
 *
 * Runs against in-memory SQLite via RequiresDatabase, so it executes without
 * Docker/PostgreSQL, matching the US-001/US-002 pattern.
 */
class CatalogTaxonomySchemaTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_brands_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('brands'));
        $this->assertTrue(Schema::hasColumns('brands', [
            'id', 'name', 'slug', 'aliases', 'created_at', 'updated_at',
        ]));
    }

    public function test_families_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('families'));
        $this->assertTrue(Schema::hasColumns('families', [
            'id', 'name', 'slug', 'aliases', 'created_at', 'updated_at',
        ]));
    }

    public function test_subfamilies_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('subfamilies'));
        $this->assertTrue(Schema::hasColumns('subfamilies', [
            'id', 'name', 'slug', 'aliases', 'family_id', 'created_at', 'updated_at',
        ]));
    }

    public function test_brand_aliases_is_cast_to_array_round_trip(): void
    {
        $brand = Brand::create([
            'name' => 'Grohe',
            'slug' => 'grohe',
            'aliases' => ['GROHE AG', 'grohe-de'],
        ]);

        $fresh = $brand->fresh();

        $this->assertIsArray($fresh->aliases);
        $this->assertSame(['GROHE AG', 'grohe-de'], $fresh->aliases);
    }

    public function test_brand_aliases_accepts_null(): void
    {
        $brand = Brand::create([
            'name' => 'Bosch',
            'slug' => 'bosch',
            'aliases' => null,
        ]);

        $this->assertNull($brand->fresh()->aliases);
    }
}
