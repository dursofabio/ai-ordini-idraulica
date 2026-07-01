<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Family;
use App\Models\Subfamily;
use App\Services\Ai\TaxonomyCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-014 acceptance criteria — closed-vocabulary taxonomy validation:
 *  - Only brands/families/subfamilies already present in the database are
 *    considered valid.
 *  - Matching is case-insensitive against both name and slug.
 *  - Any value not present in the catalog is rejected.
 *
 * Runs against in-memory SQLite via RequiresDatabase.
 */
class TaxonomyCatalogTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_recognises_existing_brand_by_name_case_insensitively(): void
    {
        Brand::factory()->create(['name' => 'Grohe', 'slug' => 'grohe']);

        $catalog = new TaxonomyCatalog;

        $this->assertTrue($catalog->isValidBrand('grohe'));
        $this->assertTrue($catalog->isValidBrand('GROHE'));
    }

    public function test_recognises_existing_brand_by_slug(): void
    {
        Brand::factory()->create(['name' => 'Grohe', 'slug' => 'grohe-it']);

        $catalog = new TaxonomyCatalog;

        $this->assertTrue($catalog->isValidBrand('grohe-it'));
    }

    public function test_rejects_brand_not_present_in_catalog(): void
    {
        Brand::factory()->create(['name' => 'Grohe', 'slug' => 'grohe']);

        $catalog = new TaxonomyCatalog;

        $this->assertFalse($catalog->isValidBrand('Hansgrohe'));
    }

    public function test_recognises_existing_family_case_insensitively(): void
    {
        Family::factory()->create(['name' => 'Rubinetteria', 'slug' => 'rubinetteria']);

        $catalog = new TaxonomyCatalog;

        $this->assertTrue($catalog->isValidFamily('rubinetteria'));
        $this->assertTrue($catalog->isValidFamily('RUBINETTERIA'));
    }

    public function test_rejects_family_not_present_in_catalog(): void
    {
        Family::factory()->create(['name' => 'Rubinetteria', 'slug' => 'rubinetteria']);

        $catalog = new TaxonomyCatalog;

        $this->assertFalse($catalog->isValidFamily('Riscaldamento'));
    }

    public function test_recognises_existing_subfamily_case_insensitively(): void
    {
        $family = Family::factory()->create(['name' => 'Rubinetteria']);
        Subfamily::factory()->create(['name' => 'Miscelatori', 'slug' => 'miscelatori', 'family_id' => $family->id]);

        $catalog = new TaxonomyCatalog;

        $this->assertTrue($catalog->isValidSubfamily('miscelatori'));
        $this->assertTrue($catalog->isValidSubfamily('MISCELATORI'));
    }

    public function test_recognises_subfamily_scoped_to_its_family(): void
    {
        $family = Family::factory()->create(['name' => 'Rubinetteria']);
        Subfamily::factory()->create(['name' => 'Miscelatori', 'family_id' => $family->id]);

        $catalog = new TaxonomyCatalog;

        $this->assertTrue($catalog->isValidSubfamily('Miscelatori', 'Rubinetteria'));
    }

    public function test_rejects_subfamily_that_belongs_to_a_different_family(): void
    {
        $family = Family::factory()->create(['name' => 'Rubinetteria']);
        $otherFamily = Family::factory()->create(['name' => 'Riscaldamento']);
        Subfamily::factory()->create(['name' => 'Miscelatori', 'family_id' => $family->id]);

        $catalog = new TaxonomyCatalog;

        $this->assertFalse($catalog->isValidSubfamily('Miscelatori', 'Riscaldamento'));
        $this->assertFalse($catalog->isValidFamily('NonEsistente'));
        $this->assertNotNull($otherFamily->id);
    }

    public function test_rejects_subfamily_not_present_in_catalog(): void
    {
        $family = Family::factory()->create(['name' => 'Rubinetteria']);
        Subfamily::factory()->create(['name' => 'Miscelatori', 'family_id' => $family->id]);

        $catalog = new TaxonomyCatalog;

        $this->assertFalse($catalog->isValidSubfamily('Valvole'));
    }
}
