<?php

namespace Tests\Feature;

use App\Models\ProductBase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-026 acceptance criteria — `catalog:reindex`:
 *  - On non-Postgres drivers (SQLite, used here), search_vector is
 *    recomputed from title + description_ai for every product-base.
 *  - A query failure during the reindex exits with a non-zero code.
 *
 * Runs against in-memory SQLite via RequiresDatabase, matching the sibling
 * command test suites.
 */
class CatalogReindexCommandTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_recomputes_search_vector_from_title_and_description(): void
    {
        $productBase = ProductBase::factory()->create([
            'title' => 'Caldaia Vaillant',
            'description_ai' => 'Caldaia a condensazione 25kW',
        ]);

        $this->artisan('catalog:reindex')->assertSuccessful();

        $productBase->refresh();
        $this->assertNotNull($productBase->search_vector);
        $this->assertStringContainsString('CALDAIA VAILLANT', $productBase->search_vector);
        $this->assertStringContainsString('CONDENSAZIONE', $productBase->search_vector);
    }

    public function test_prints_the_number_of_rows_processed(): void
    {
        ProductBase::factory()->count(3)->create();

        $this->artisan('catalog:reindex')
            ->assertSuccessful()
            ->expectsOutputToContain('Righe processate: 3');
    }

    public function test_fails_with_a_non_zero_exit_code_when_the_query_fails(): void
    {
        ProductBase::factory()->create();

        // Drop the column the command writes to, so the UPDATE issued
        // during the reindex fails at the database level.
        Schema::table('product_bases', function ($table): void {
            $table->dropColumn('search_vector');
        });

        $this->artisan('catalog:reindex')->assertFailed();
    }
}
