<?php

namespace Tests\Feature;

use App\Enums\ImportBatchStatus;
use App\Jobs\SeedTaxonomyFromStagingJob;
use App\Models\Brand;
use App\Models\Family;
use App\Models\ImportBatch;
use App\Models\StagingArticolo;
use App\Models\Subfamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * Chained between ImportXlsxJob and PromoteStagingToProductsJob so an admin
 * never has to run `catalog:seed-taxonomy` manually after every import:
 *  - Running the job creates Brand/Family/Subfamily records from the raw
 *    Marca/Fam/S.Fam values already sitting in staging_articoli.raw_row.
 *  - A failure transitions the batch to failed instead of leaving it stuck.
 *
 * Runs against in-memory SQLite via RequiresDatabase.
 */
class SeedTaxonomyFromStagingJobTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    private function enrichingBatch(): ImportBatch
    {
        return ImportBatch::factory()->create(['status' => ImportBatchStatus::Enriching]);
    }

    public function test_seeds_brand_family_and_subfamily_from_staging_rows(): void
    {
        $batch = $this->enrichingBatch();

        StagingArticolo::factory()->create([
            'import_batch_id' => $batch->id,
            'raw_row' => [
                'codice_articolo' => 'ART-SEED1',
                'descrizione' => 'Prodotto di test',
                'marca' => '01',
                'descrizione_marca' => 'WAVIN ITALIA SPA',
                'fam' => '12',
                'descrizione_fam' => 'TUBI E RACCORDI',
                's_fam' => 'RAC',
                'descrizione_s_fam' => 'RACCORDI A PRESSARE',
            ],
        ]);

        SeedTaxonomyFromStagingJob::dispatchSync($batch);

        $brand = Brand::query()->where('slug', 'wavin-italia-spa')->first();
        $this->assertNotNull($brand);
        $this->assertEqualsCanonicalizing(['01', 'WAVIN'], $brand->aliases);

        $family = Family::query()->where('slug', 'tubi-e-raccordi')->first();
        $this->assertNotNull($family);

        $subfamily = Subfamily::query()->where('name', 'RACCORDI A PRESSARE')->first();
        $this->assertNotNull($subfamily);
        $this->assertSame($family->id, $subfamily->family_id);
    }

    public function test_failed_callback_transitions_batch_to_failed(): void
    {
        $batch = $this->enrichingBatch();

        $job = new SeedTaxonomyFromStagingJob($batch);
        $job->failed(new \Exception('simulated seed failure'));

        $this->assertSame(ImportBatchStatus::Failed, $batch->fresh()->status);
    }
}
