<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-004 acceptance criteria — schema, indexes and defaults:
 *  - products, product_attributes tables exist with all columns.
 *  - products.codice_articolo is unique.
 *  - products.enrichment_status defaults to 'pending' and is indexed.
 *  - product_attributes has composite indexes (key, value_num) and (product_id, key).
 *
 * US-047 drops `product_bases` and the `product_base_id`/`grouping_key`
 * columns on `products` entirely (search now operates flat on `products`).
 *
 * US-050 adds the nullable `descrizione_estesa` markdown column (AC1).
 *
 * Runs against in-memory SQLite via RequiresDatabase, matching US-003 pattern.
 */
class ProductCatalogSchemaTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_product_bases_table_no_longer_exists(): void
    {
        $this->assertFalse(Schema::hasTable('product_bases'));
    }

    public function test_products_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('products'));
        $this->assertTrue(Schema::hasColumns('products', [
            'id', 'codice_articolo', 'description_raw', 'description_clean', 'descrizione_marca',
            'marca_codice', 'fam_codice', 'fam_descrizione', 'subfam_codice', 'subfam_descrizione',
            'costo', 'giacenza', 'is_active', 'enrichment_status',
            'brand_id', 'family_id', 'subfamily_id',
            'brand_source', 'family_source', 'subfamily_source', 'source',
            'confidence', 'product_type', 'descrizione_estesa', 'search_vector', 'created_at', 'updated_at',
        ]));
    }

    public function test_descrizione_estesa_is_nullable_and_mass_assignable(): void
    {
        $markdown = "# Scheda tecnica\n\nDescrizione **ricca** del prodotto.";

        $productWithDescription = Product::create([
            'codice_articolo' => 'ART-DESC-01',
            'description_raw' => 'Caldaia a condensazione',
            'descrizione_estesa' => $markdown,
        ]);

        $this->assertSame($markdown, $productWithDescription->fresh()->descrizione_estesa);

        $productWithoutDescription = Product::create([
            'codice_articolo' => 'ART-DESC-02',
            'description_raw' => 'Scaldabagno elettrico',
        ]);

        $this->assertNull($productWithoutDescription->fresh()->descrizione_estesa);
    }

    public function test_products_table_no_longer_has_grouping_columns(): void
    {
        $this->assertFalse(Schema::hasColumn('products', 'product_base_id'));
        $this->assertFalse(Schema::hasColumn('products', 'grouping_key'));
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

    public function test_product_attributes_has_composite_indexes(): void
    {
        $indexes = collect(Schema::getIndexes('product_attributes'))
            ->map(fn (array $index): array => $index['columns'])
            ->all();

        $this->assertContains(['key', 'value_num'], $indexes);
        $this->assertContains(['product_id', 'key'], $indexes);
    }
}
