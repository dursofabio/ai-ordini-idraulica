<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductAttribute;
use App\Services\Enrichment\LegacyAttributeKeyMigrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-042 acceptance criteria — legacy attribute key backfill:
 *  - A lone `potenza_watt` row is converted in place to `potenza_kw`,
 *    preserving `source` and `confidence` (the spec's "Dimostra" case:
 *    3500 W → 3.5 kW).
 *  - On conflict with an existing `potenza_kw` row, the row with the higher
 *    effective confidence wins in both directions.
 *  - NULL confidence rule: `regex` NULL counts as 100, other origins' NULL
 *    counts as 0.
 *  - A tie favors the already-canonical row.
 *  - Rows already on canonical units, and other keys, are left untouched.
 *  - The migrator is idempotent: a second run returns 0 and converts
 *    nothing further.
 *
 * Runs against in-memory SQLite via RequiresDatabase.
 */
class LegacyAttributeKeyMigratorTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    private LegacyAttributeKeyMigrator $migrator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migrator = new LegacyAttributeKeyMigrator;
    }

    public function test_converts_lone_watt_row_to_kw_preserving_source_and_confidence(): void
    {
        $product = Product::factory()->create();
        ProductAttribute::factory()->create([
            'product_id' => $product->id,
            'key' => 'potenza_watt',
            'value_num' => 3500,
            'unit' => 'W',
            'source' => 'regex',
            'confidence' => null,
        ]);

        $migrated = $this->migrator->migrate('potenza_watt', 'potenza_kw', 0.001, 'kW');

        $this->assertSame(1, $migrated);
        $this->assertSame(0, $product->attributes()->where('key', 'potenza_watt')->count());

        $attribute = $product->attributes()->where('key', 'potenza_kw')->firstOrFail();
        $this->assertEquals(3.5, $attribute->value_num);
        $this->assertSame('kW', $attribute->unit);
        $this->assertSame('regex', $attribute->source);
        $this->assertNull($attribute->confidence);
    }

    public function test_conflict_watt_wins_with_higher_confidence(): void
    {
        $product = Product::factory()->create();
        ProductAttribute::factory()->create([
            'product_id' => $product->id,
            'key' => 'potenza_watt',
            'value_num' => 3500,
            'unit' => 'W',
            'source' => 'ai',
            'confidence' => 90,
        ]);
        ProductAttribute::factory()->create([
            'product_id' => $product->id,
            'key' => 'potenza_kw',
            'value_num' => 1.0,
            'unit' => 'kW',
            'source' => 'ai',
            'confidence' => 40,
        ]);

        $migrated = $this->migrator->migrate('potenza_watt', 'potenza_kw', 0.001, 'kW');

        $this->assertSame(1, $migrated);
        $this->assertSame(0, $product->attributes()->where('key', 'potenza_watt')->count());
        $this->assertSame(1, $product->attributes()->where('key', 'potenza_kw')->count());

        $attribute = $product->attributes()->where('key', 'potenza_kw')->firstOrFail();
        $this->assertEquals(3.5, $attribute->value_num);
        $this->assertSame('kW', $attribute->unit);
        $this->assertSame('ai', $attribute->source);
        $this->assertSame(90, $attribute->confidence);
    }

    public function test_conflict_canonical_wins_with_higher_confidence(): void
    {
        $product = Product::factory()->create();
        ProductAttribute::factory()->create([
            'product_id' => $product->id,
            'key' => 'potenza_watt',
            'value_num' => 3500,
            'unit' => 'W',
            'source' => 'ai',
            'confidence' => 30,
        ]);
        ProductAttribute::factory()->create([
            'product_id' => $product->id,
            'key' => 'potenza_kw',
            'value_num' => 1.0,
            'unit' => 'kW',
            'source' => 'ai',
            'confidence' => 80,
        ]);

        $migrated = $this->migrator->migrate('potenza_watt', 'potenza_kw', 0.001, 'kW');

        $this->assertSame(1, $migrated);
        $this->assertSame(0, $product->attributes()->where('key', 'potenza_watt')->count());

        $attribute = $product->attributes()->where('key', 'potenza_kw')->firstOrFail();
        $this->assertEquals(1.0, $attribute->value_num);
        $this->assertSame('kW', $attribute->unit);
        $this->assertSame('ai', $attribute->source);
        $this->assertSame(80, $attribute->confidence);
    }

    public function test_null_confidence_rule_regex_counts_as_100_other_origins_as_0(): void
    {
        $product = Product::factory()->create();
        // regex NULL (=100) should beat ai with an explicit low confidence.
        ProductAttribute::factory()->create([
            'product_id' => $product->id,
            'key' => 'potenza_watt',
            'value_num' => 2000,
            'unit' => 'W',
            'source' => 'regex',
            'confidence' => null,
        ]);
        ProductAttribute::factory()->create([
            'product_id' => $product->id,
            'key' => 'potenza_kw',
            'value_num' => 1.0,
            'unit' => 'kW',
            'source' => 'ai',
            'confidence' => 10,
        ]);

        $this->migrator->migrate('potenza_watt', 'potenza_kw', 0.001, 'kW');

        $attribute = $product->attributes()->where('key', 'potenza_kw')->firstOrFail();
        $this->assertEquals(2.0, $attribute->value_num);
        $this->assertSame('regex', $attribute->source);
    }

    public function test_null_confidence_on_non_regex_origin_counts_as_zero(): void
    {
        $product = Product::factory()->create();
        // ai NULL (=0) should lose against a canonical row with explicit low confidence.
        ProductAttribute::factory()->create([
            'product_id' => $product->id,
            'key' => 'potenza_watt',
            'value_num' => 2000,
            'unit' => 'W',
            'source' => 'ai',
            'confidence' => null,
        ]);
        ProductAttribute::factory()->create([
            'product_id' => $product->id,
            'key' => 'potenza_kw',
            'value_num' => 1.0,
            'unit' => 'kW',
            'source' => 'ai',
            'confidence' => 1,
        ]);

        $this->migrator->migrate('potenza_watt', 'potenza_kw', 0.001, 'kW');

        $attribute = $product->attributes()->where('key', 'potenza_kw')->firstOrFail();
        $this->assertEquals(1.0, $attribute->value_num);
        $this->assertSame('ai', $attribute->source);
        $this->assertSame(1, $attribute->confidence);
    }

    public function test_tie_favors_the_already_canonical_row(): void
    {
        $product = Product::factory()->create();
        ProductAttribute::factory()->create([
            'product_id' => $product->id,
            'key' => 'potenza_watt',
            'value_num' => 3500,
            'unit' => 'W',
            'source' => 'ai',
            'confidence' => 50,
        ]);
        ProductAttribute::factory()->create([
            'product_id' => $product->id,
            'key' => 'potenza_kw',
            'value_num' => 1.0,
            'unit' => 'kW',
            'source' => 'ai',
            'confidence' => 50,
        ]);

        $this->migrator->migrate('potenza_watt', 'potenza_kw', 0.001, 'kW');

        $attribute = $product->attributes()->where('key', 'potenza_kw')->firstOrFail();
        $this->assertEquals(1.0, $attribute->value_num);
    }

    public function test_rows_already_canonical_and_other_keys_are_untouched(): void
    {
        $product = Product::factory()->create();
        ProductAttribute::factory()->create([
            'product_id' => $product->id,
            'key' => 'potenza_kw',
            'value_num' => 5.0,
            'unit' => 'kW',
            'source' => 'regex',
            'confidence' => null,
        ]);
        ProductAttribute::factory()->create([
            'product_id' => $product->id,
            'key' => 'materiale',
            'value_text' => 'INOX',
            'source' => 'regex',
        ]);

        $migrated = $this->migrator->migrate('potenza_watt', 'potenza_kw', 0.001, 'kW');

        $this->assertSame(0, $migrated);
        $this->assertEquals(5.0, $product->attributes()->where('key', 'potenza_kw')->firstOrFail()->value_num);
        $this->assertSame('INOX', $product->attributes()->where('key', 'materiale')->firstOrFail()->value_text);
    }

    public function test_migration_is_idempotent(): void
    {
        $product = Product::factory()->create();
        ProductAttribute::factory()->create([
            'product_id' => $product->id,
            'key' => 'potenza_watt',
            'value_num' => 3500,
            'unit' => 'W',
            'source' => 'regex',
            'confidence' => null,
        ]);

        $firstRun = $this->migrator->migrate('potenza_watt', 'potenza_kw', 0.001, 'kW');
        $secondRun = $this->migrator->migrate('potenza_watt', 'potenza_kw', 0.001, 'kW');

        $this->assertSame(1, $firstRun);
        $this->assertSame(0, $secondRun);
        $this->assertSame(1, $product->attributes()->where('key', 'potenza_kw')->count());
    }
}
