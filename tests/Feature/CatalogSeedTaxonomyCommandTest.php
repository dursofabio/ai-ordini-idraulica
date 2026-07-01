<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Family;
use App\Models\StagingArticolo;
use App\Models\Subfamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * `catalog:seed-taxonomy` — seeds Brand/Family/Subfamily from the distinct
 * Marca/Fam/S.Fam values already present in staging_articoli.raw_row:
 *  - Distinct (code, label) pairs become one taxonomy record each.
 *  - Rows with an empty code are ignored.
 *  - A multi-word brand/family name also gets its first word as an alias.
 *  - Subfamilies with the same name under different families stay distinct.
 *  - Re-running merges new aliases into existing records instead of
 *    duplicating rows or discarding aliases added elsewhere (e.g. manually).
 */
class CatalogSeedTaxonomyCommandTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_seeds_brands_families_and_subfamilies_from_staging_rows(): void
    {
        $this->stagingRow([
            'marca' => 'ELLEGI',
            'descrizione_marca' => 'ELLEGI SRL',
            'fam' => '49',
            'descrizione_fam' => 'SCALDABAGNI BOLLITORE E SOLARE',
            's_fam' => 'BOLL',
            'descrizione_s_fam' => 'BOLLITORI',
        ]);

        $this->stagingRow([
            'marca' => '01',
            'descrizione_marca' => 'WAVIN ITALIA SPA',
            'fam' => '',
            'descrizione_fam' => '',
            's_fam' => '',
            'descrizione_s_fam' => '',
        ]);

        $this->artisan('catalog:seed-taxonomy')->assertExitCode(0);

        $ellegi = Brand::query()->where('slug', 'ellegi-srl')->first();
        $this->assertNotNull($ellegi);
        $this->assertSame(['ELLEGI'], $ellegi->aliases);

        $wavin = Brand::query()->where('slug', 'wavin-italia-spa')->first();
        $this->assertNotNull($wavin);
        $this->assertEqualsCanonicalizing(['01', 'WAVIN'], $wavin->aliases);

        $family = Family::query()->where('slug', 'scaldabagni-bollitore-e-solare')->first();
        $this->assertNotNull($family);
        $this->assertEqualsCanonicalizing(['49', 'SCALDABAGNI'], $family->aliases);

        $subfamily = Subfamily::query()->where('name', 'BOLLITORI')->first();
        $this->assertNotNull($subfamily);
        $this->assertSame($family->id, $subfamily->family_id);
    }

    public function test_skips_rows_with_an_empty_code(): void
    {
        $this->stagingRow(['marca' => '', 'descrizione_marca' => 'Senza Codice']);

        $this->artisan('catalog:seed-taxonomy')->assertExitCode(0);

        $this->assertSame(0, Brand::query()->count());
    }

    public function test_scopes_subfamilies_with_the_same_name_to_their_own_family(): void
    {
        $this->stagingRow([
            'marca' => '', 'descrizione_marca' => '',
            'fam' => '15', 'descrizione_fam' => 'CONDIZIONAMENTO',
            's_fam' => 'ACCE', 'descrizione_s_fam' => 'ACCESSORI',
        ]);

        $this->stagingRow([
            'marca' => '', 'descrizione_marca' => '',
            'fam' => '05', 'descrizione_fam' => 'RADIATORI ED ACCESSORI',
            's_fam' => 'ACCE', 'descrizione_s_fam' => 'ACCESSORI',
        ]);

        $this->artisan('catalog:seed-taxonomy')->assertExitCode(0);

        $this->assertSame(2, Subfamily::query()->where('name', 'ACCESSORI')->count());
        $this->assertSame(2, Subfamily::query()->count());
    }

    public function test_rerunning_merges_aliases_without_duplicating_records(): void
    {
        $this->stagingRow(['marca' => 'CORD', 'descrizione_marca' => 'CORDIVARI']);

        $this->artisan('catalog:seed-taxonomy')->assertExitCode(0);

        $brand = Brand::query()->where('slug', 'cordivari')->first();
        $brand->update(['aliases' => ['CORDIVARI-MANUAL']]);

        $this->stagingRow(['marca' => 'CORD', 'descrizione_marca' => 'CORDIVARI']);

        $this->artisan('catalog:seed-taxonomy')->assertExitCode(0);

        $this->assertSame(1, Brand::query()->where('slug', 'cordivari')->count());
        $this->assertEqualsCanonicalizing(
            ['CORDIVARI-MANUAL', 'CORD'],
            $brand->fresh()->aliases,
        );
    }

    /**
     * @param  array<string, string>  $overrides
     */
    private function stagingRow(array $overrides): StagingArticolo
    {
        return StagingArticolo::factory()->create([
            'raw_row' => array_merge([
                'codice_articolo' => 'ABC-001',
                'descrizione' => 'Prodotto di test',
                'marca' => '',
                'descrizione_marca' => '',
                'fam' => '',
                'descrizione_fam' => '',
                's_fam' => '',
                'descrizione_s_fam' => '',
            ], $overrides),
        ]);
    }
}
