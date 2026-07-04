<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-026/US-046/US-047 acceptance criteria — `catalog:reindex`:
 *  - On non-Postgres drivers (SQLite, used here), products.search_vector is
 *    recomputed from product_type + descrizione_marca + description_clean
 *    for every product (US-047 removed the product_bases branch entirely —
 *    the table no longer exists).
 *  - A query failure during the reindex exits with a non-zero code.
 *
 * Runs against in-memory SQLite via RequiresDatabase, matching the sibling
 * command test suites.
 */
class CatalogReindexCommandTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_recomputes_product_search_vector_from_type_brand_and_description(): void
    {
        $product = Product::factory()->create([
            'product_type' => 'Caldaia a condensazione',
            'descrizione_marca' => 'Vaillant',
            'description_clean' => 'Caldaia a condensazione 25kW risparmio energetico',
        ]);

        $this->artisan('catalog:reindex')->assertSuccessful();

        $product->refresh();
        $this->assertNotNull($product->search_vector);
        $this->assertStringContainsString('CALDAIA A CONDENSAZIONE', $product->search_vector);
        $this->assertStringContainsString('VAILLANT', $product->search_vector);
        $this->assertStringContainsString('RISPARMIO ENERGETICO', $product->search_vector);
    }

    public function test_prints_the_number_of_rows_processed(): void
    {
        Product::factory()->count(2)->create();

        $this->artisan('catalog:reindex')
            ->assertSuccessful()
            ->expectsOutputToContain('Righe processate: 2 (products)');
    }

    public function test_fails_with_a_non_zero_exit_code_when_the_query_fails(): void
    {
        Product::factory()->create();

        // Drop the column the command writes to, so the UPDATE issued
        // during the reindex fails at the database level.
        Schema::table('products', function ($table): void {
            $table->dropColumn('search_vector');
        });

        $this->artisan('catalog:reindex')->assertFailed();
    }
}
