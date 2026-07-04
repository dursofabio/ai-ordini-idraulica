<?php

namespace Tests\Feature;

use App\Jobs\ClassifyProductsBatchJob;
use App\Models\Brand;
use App\Models\Family;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-026 acceptance criteria — `catalog:enrich {--only=pending}`:
 *  - Step A (deterministic resolvers) is applied synchronously to every
 *    pending product.
 *  - ClassifyProductsBatchJob is queued only for the products still missing
 *    brand/family after Step A.
 *  - `--only` with an unsupported value fails the command.
 *  - An empty catalog (no pending products) exits successfully with an
 *    explicit message.
 */
class CatalogEnrichCommandTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_applies_step_a_synchronously_and_queues_step_b_for_residuals(): void
    {
        $brand = Brand::factory()->create(['name' => 'Wavin Italia Spa', 'slug' => 'wavin-italia-spa', 'aliases' => ['01']]);
        $family = Family::factory()->create(['name' => 'Tubi e Raccordi', 'slug' => 'tubi-e-raccordi', 'aliases' => ['12']]);

        // Step A (FileTaxonomyResolver) links both brand and family straight
        // from the raw file codes synchronously, leaving this product fully
        // classified and excluded from Step B (only the missing-brand-or-
        // family products are queued for AI classification).
        $resolvable = Product::factory()->create([
            'marca_codice' => '01',
            'descrizione_marca' => null,
            'fam_codice' => '12',
            'fam_descrizione' => null,
            'enrichment_status' => 'pending',
            'brand_id' => null,
            'family_id' => null,
        ]);

        $unresolvable = Product::factory()->create([
            'description_raw' => 'ARTICOLO GENERICO SENZA MARCA NOTA',
            'description_clean' => null,
            'enrichment_status' => 'pending',
            'brand_id' => null,
            'family_id' => null,
        ]);

        $this->artisan('catalog:enrich', ['--only' => 'pending'])
            ->assertSuccessful();

        $resolvable->refresh();
        $unresolvable->refresh();

        $this->assertSame($brand->id, $resolvable->brand_id, 'Step A should link the brand from file synchronously.');
        $this->assertSame($family->id, $resolvable->family_id, 'Step A should link the family from file synchronously.');

        Queue::assertPushed(
            ClassifyProductsBatchJob::class,
            fn (ClassifyProductsBatchJob $job): bool => in_array($unresolvable->id, $job->productIds, true),
        );
        Queue::assertPushed(
            fn (ClassifyProductsBatchJob $job): bool => ! in_array($resolvable->id, $job->productIds, true),
        );
    }

    public function test_skip_ai_runs_step_a_only_and_never_queues_step_b(): void
    {
        Product::factory()->create([
            'description_raw' => 'ARTICOLO GENERICO SENZA MARCA NOTA',
            'description_clean' => null,
            'enrichment_status' => 'pending',
            'brand_id' => null,
        ]);

        $this->artisan('catalog:enrich', ['--skip-ai' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Step B (classificazione AI) saltato per via di --skip-ai.');

        Queue::assertNotPushed(ClassifyProductsBatchJob::class);
    }

    public function test_fails_with_an_unsupported_only_value(): void
    {
        $this->artisan('catalog:enrich', ['--only' => 'all'])
            ->assertFailed();

        Queue::assertNotPushed(ClassifyProductsBatchJob::class);
    }

    public function test_succeeds_with_no_pending_products(): void
    {
        Product::factory()->create(['enrichment_status' => 'enriched']);

        $this->artisan('catalog:enrich', ['--only' => 'pending'])
            ->assertSuccessful()
            ->expectsOutputToContain('Nessun prodotto in stato "pending"');

        Queue::assertNotPushed(ClassifyProductsBatchJob::class);
    }

    public function test_only_option_is_optional_and_defaults_to_pending_scope(): void
    {
        Product::factory()->create(['enrichment_status' => 'enriched']);

        $this->artisan('catalog:enrich')
            ->assertSuccessful();
    }
}
