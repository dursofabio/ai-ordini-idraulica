<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductBase;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-004 acceptance criteria — schema, indexes and defaults:
 *  - product_bases, products, product_attributes tables exist with all columns.
 *  - products.codice_articolo is unique.
 *  - products.enrichment_status defaults to 'pending' and is indexed.
 *  - product_attributes has composite indexes (key, value_num) and (product_id, key).
 *
 * Runs against in-memory SQLite via RequiresDatabase, matching US-003 pattern.
 */
class ProductCatalogSchemaTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_product_bases_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('product_bases'));
        $this->assertTrue(Schema::hasColumns('product_bases', [
            'id', 'title', 'grouping_key', 'brand_id', 'family_id', 'subfamily_id',
            'created_at', 'updated_at',
        ]));
    }

    public function test_products_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('products'));
        $this->assertTrue(Schema::hasColumns('products', [
            'id', 'codice_articolo', 'description_raw', 'description_clean', 'descrizione_marca',
            'costo', 'giacenza', 'is_active', 'enrichment_status',
            'product_base_id', 'brand_id', 'family_id', 'subfamily_id',
            'brand_source', 'family_source', 'subfamily_source', 'source',
            'confidence', 'grouping_key', 'created_at', 'updated_at',
        ]));
    }

    public function test_product_attributes_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('product_attributes'));
        $this->assertTrue(Schema::hasColumns('product_attributes', [
            'id', 'product_id', 'key', 'value_num', 'value_text', 'unit',
            'source', 'created_at', 'updated_at',
        ]));
    }

    public function test_codice_articolo_is_unique(): void
    {
        Product::factory()->create(['codice_articolo' => 'ART-0001']);

        $this->expectException(QueryException::class);

        Product::factory()->create(['codice_articolo' => 'ART-0001']);
    }

    public function test_enrichment_status_defaults_to_pending(): void
    {
        $product = Product::create([
            'codice_articolo' => 'ART-DEFAULT',
            'description_raw' => 'Caldaia a condensazione',
        ]);

        $this->assertSame('pending', $product->fresh()->enrichment_status);
    }

    public function test_enrichment_status_is_indexed(): void
    {
        $indexes = collect(Schema::getIndexes('products'))
            ->flatMap(fn (array $index): array => $index['columns'])
            ->all();

        $this->assertContains('enrichment_status', $indexes);
    }

    public function test_grouping_key_is_indexed_on_products(): void
    {
        $indexes = collect(Schema::getIndexes('products'))
            ->flatMap(fn (array $index): array => $index['columns'])
            ->all();

        $this->assertContains('grouping_key', $indexes);
    }

    public function test_product_bases_grouping_key_is_unique(): void
    {
        ProductBase::factory()->create(['grouping_key' => 'grohe-rubinetto-serie-x']);

        $this->expectException(QueryException::class);

        ProductBase::factory()->create(['grouping_key' => 'grohe-rubinetto-serie-x']);
    }

    public function test_product_attributes_has_composite_indexes(): void
    {
        $indexes = collect(Schema::getIndexes('product_attributes'))
            ->map(fn (array $index): array => $index['columns'])
            ->all();

        $this->assertContains(['key', 'value_num'], $indexes);
        $this->assertContains(['product_id', 'key'], $indexes);
    }
}
