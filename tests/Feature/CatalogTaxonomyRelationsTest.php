<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Family;
use App\Models\Subfamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-003 acceptance criteria — relations and factories:
 *  - Subfamily belongsTo Family via nullable family_id; Family hasMany Subfamily.
 *  - Factories produce valid, persistable models.
 *
 * Runs against in-memory SQLite via RequiresDatabase.
 */
class CatalogTaxonomyRelationsTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_subfamily_belongs_to_family(): void
    {
        $family = Family::factory()->create();
        $subfamily = Subfamily::factory()->create(['family_id' => $family->id]);

        $this->assertInstanceOf(Family::class, $subfamily->family);
        $this->assertSame($family->id, $subfamily->family->id);
    }

    public function test_family_has_many_subfamilies(): void
    {
        $family = Family::factory()->create();
        Subfamily::factory()->count(3)->create(['family_id' => $family->id]);

        $this->assertCount(3, $family->subfamilies);
    }

    public function test_subfamily_family_id_is_nullable(): void
    {
        $subfamily = Subfamily::factory()->create(['family_id' => null]);

        $this->assertNull($subfamily->fresh()->family_id);
        $this->assertNull($subfamily->family);
    }

    public function test_brand_factory_produces_persistable_model(): void
    {
        $brand = Brand::factory()->create();

        $this->assertDatabaseHas('brands', ['id' => $brand->id]);
        $this->assertNotEmpty($brand->name);
        $this->assertNotEmpty($brand->slug);
        $this->assertIsArray($brand->aliases);
    }

    public function test_family_factory_produces_persistable_model(): void
    {
        $family = Family::factory()->create();

        $this->assertDatabaseHas('families', ['id' => $family->id]);
        $this->assertNotEmpty($family->name);
        $this->assertNotEmpty($family->slug);
    }

    public function test_subfamily_factory_associates_a_family_by_default(): void
    {
        $subfamily = Subfamily::factory()->create();

        $this->assertDatabaseHas('subfamilies', ['id' => $subfamily->id]);
        $this->assertNotNull($subfamily->family_id);
        $this->assertInstanceOf(Family::class, $subfamily->family);
    }
}
