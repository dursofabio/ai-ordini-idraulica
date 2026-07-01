<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductAttribute;
use App\Services\Enrichment\AttributeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-010 acceptance criteria — deterministic technical attribute extraction:
 *  - The kW extractor populates value_num/unit='kW' for explicit units
 *    (e.g. `3.5KW`) and for known series codes (e.g. `VAI 8-025`).
 *  - The litri extractor populates value_num/unit='L' for descriptions like
 *    `BOLLITORE 800 LT`.
 *  - The pollici extractor populates value_num/unit='"' for descriptions
 *    like `RIDUTTORE 1"`.
 *  - The materiale extractor populates value_text for codes like RG, INOX,
 *    PVC, RAME.
 *  - All extracted attributes have source='regex'.
 *
 * Runs against in-memory SQLite via RequiresDatabase, matching the
 * BrandResolverTest pattern.
 */
class AttributeResolverTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_extracts_kw_from_explicit_unit_with_dot_decimal(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'CALDAIA A CONDENSAZIONE 3.5KW -VAILLANT-',
        ]);

        $written = (new AttributeResolver)->resolve($product);

        $this->assertSame(1, $written);
        $attribute = $product->attributes()->where('key', 'potenza_kw')->first();
        $this->assertNotNull($attribute);
        $this->assertEquals(3.5, $attribute->value_num);
        $this->assertSame('kW', $attribute->unit);
        $this->assertSame('regex', $attribute->source);
    }

    public function test_extracts_kw_from_explicit_unit_with_comma_decimal(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'BOX BD 7/7 M4 0,13KW -VORTICE-',
        ]);

        (new AttributeResolver)->resolve($product);

        $attribute = $product->attributes()->where('key', 'potenza_kw')->first();
        $this->assertNotNull($attribute);
        $this->assertEquals(0.13, $attribute->value_num);
        $this->assertSame('kW', $attribute->unit);
    }

    public function test_extracts_kw_from_explicit_unit_with_space_and_lowercase(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'BY-PASS ECOBLOCK EXCLUSIV 35 Kw -VAILLANT-',
        ]);

        (new AttributeResolver)->resolve($product);

        $attribute = $product->attributes()->where('key', 'potenza_kw')->first();
        $this->assertNotNull($attribute);
        $this->assertEquals(35, $attribute->value_num);
        $this->assertSame('kW', $attribute->unit);
    }

    public function test_extracts_kw_from_vai_series_code(): void
    {
        $product = Product::factory()->create([
            'description_raw' => "UNITA' ESTERNA VAI 8-025 WNO VAILLANT",
        ]);

        (new AttributeResolver)->resolve($product);

        $attribute = $product->attributes()->where('key', 'potenza_kw')->first();
        $this->assertNotNull($attribute);
        $this->assertEquals(8, $attribute->value_num);
        $this->assertSame('kW', $attribute->unit);
        $this->assertSame('regex', $attribute->source);
    }

    public function test_does_not_extract_kw_when_no_unit_or_series_code_present(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'TUBO MULTISTRATO 16MM',
        ]);

        $written = (new AttributeResolver)->resolve($product);

        $this->assertSame(0, $written);
        $this->assertNull($product->attributes()->where('key', 'potenza_kw')->first());
    }

    public function test_extracts_litri_from_lt_unit(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'BOLLITORE 800 LT SOLARE IN RF PROD SOLARE ACS CON DOPPIO SCAMB ELLEGI',
        ]);

        $written = (new AttributeResolver)->resolve($product);

        $this->assertSame(1, $written);
        $attribute = $product->attributes()->where('key', 'capacita_litri')->first();
        $this->assertNotNull($attribute);
        $this->assertEquals(800, $attribute->value_num);
        $this->assertSame('L', $attribute->unit);
        $this->assertSame('regex', $attribute->source);
    }

    public function test_extracts_litri_from_lt_unit_lowercase_and_smaller_value(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'SF/N00050 SERBATOIO ACCUMULO VOLANO TERMICO A/R PDC 50 lt - ELLEGI -',
        ]);

        (new AttributeResolver)->resolve($product);

        $attribute = $product->attributes()->where('key', 'capacita_litri')->first();
        $this->assertNotNull($attribute);
        $this->assertEquals(50, $attribute->value_num);
        $this->assertSame('L', $attribute->unit);
    }

    public function test_does_not_extract_litri_when_no_lt_unit_present(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'TUBO MULTISTRATO 16MM',
        ]);

        $written = (new AttributeResolver)->resolve($product);

        $this->assertSame(0, $written);
        $this->assertNull($product->attributes()->where('key', 'capacita_litri')->first());
    }

    public function test_extracts_pollici_from_whole_number(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'RIDUTTORE PRESS.ACQUA 1" C/MANOM. -TECNOGAS-',
        ]);

        $written = (new AttributeResolver)->resolve($product);

        $this->assertSame(1, $written);
        $attribute = $product->attributes()->where('key', 'attacco_pollici')->first();
        $this->assertNotNull($attribute);
        $this->assertEquals(1, $attribute->value_num);
        $this->assertSame('"', $attribute->unit);
        $this->assertSame('regex', $attribute->source);
    }

    public function test_extracts_pollici_from_simple_fraction(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'GIUNTO FILETTATO 25*3/4" F AQUATHERM',
        ]);

        (new AttributeResolver)->resolve($product);

        $attribute = $product->attributes()->where('key', 'attacco_pollici')->first();
        $this->assertNotNull($attribute);
        $this->assertEquals(0.75, $attribute->value_num);
        $this->assertSame('"', $attribute->unit);
    }

    public function test_extracts_pollici_from_mixed_number(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'ADATTATORE 1 1/4" A 28MM RAME VAILLANT',
        ]);

        (new AttributeResolver)->resolve($product);

        $attribute = $product->attributes()->where('key', 'attacco_pollici')->first();
        $this->assertNotNull($attribute);
        $this->assertEquals(1.25, $attribute->value_num);
        $this->assertSame('"', $attribute->unit);
    }

    public function test_does_not_extract_pollici_when_no_inch_symbol_present(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'TUBO MULTISTRATO 16MM',
        ]);

        $written = (new AttributeResolver)->resolve($product);

        $this->assertSame(0, $written);
        $this->assertNull($product->attributes()->where('key', 'attacco_pollici')->first());
    }

    public function test_extracts_materiale_inox(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'CASSETTA ESTERNA DN 45 MM 610*370*210 INOX',
        ]);

        $written = (new AttributeResolver)->resolve($product);

        $this->assertSame(1, $written);
        $attribute = $product->attributes()->where('key', 'materiale')->first();
        $this->assertNotNull($attribute);
        $this->assertSame('INOX', $attribute->value_text);
        $this->assertNull($attribute->value_num);
        $this->assertNull($attribute->unit);
        $this->assertSame('regex', $attribute->source);
    }

    public function test_extracts_materiale_pvc_and_rame(): void
    {
        $pvc = Product::factory()->create([
            'description_raw' => 'PASSAGGIO NIPREN/PVC 110F*90M -REDI-',
        ]);
        $rame = Product::factory()->create([
            'description_raw' => 'SERPENTINA PORTAMANOMETRO 3/8 MF PN30 RAME -TECNOGAS-',
        ]);

        (new AttributeResolver)->resolve($pvc);
        (new AttributeResolver)->resolve($rame);

        $this->assertSame('PVC', $pvc->attributes()->where('key', 'materiale')->first()->value_text);
        $this->assertSame('RAME', $rame->attributes()->where('key', 'materiale')->first()->value_text);
    }

    public function test_extracts_materiale_rg_as_whole_word(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'SOLAR/SS0200 IN RG PROD.SOLARE ACS C/2 SCAMB.FISSO -ELLEGI-',
        ]);

        (new AttributeResolver)->resolve($product);

        $attribute = $product->attributes()->where('key', 'materiale')->first();
        $this->assertNotNull($attribute);
        $this->assertSame('RG', $attribute->value_text);
    }

    public function test_extracts_first_material_by_position_when_multiple_are_mentioned(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'RACCORDO PVC CON GUARNIZIONE INOX PER TUBO RAME',
        ]);

        (new AttributeResolver)->resolve($product);

        $attribute = $product->attributes()->where('key', 'materiale')->first();
        $this->assertNotNull($attribute);
        $this->assertSame('PVC', $attribute->value_text);
    }

    public function test_does_not_extract_materiale_when_no_known_code_present(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'TUBO MULTISTRATO 16MM',
        ]);

        $written = (new AttributeResolver)->resolve($product);

        $this->assertSame(0, $written);
        $this->assertNull($product->attributes()->where('key', 'materiale')->first());
    }

    public function test_resolve_writes_every_matched_attribute_in_a_single_call(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'CALDAIA 24KW BOLLITORE INOX 1" -VAILLANT-',
        ]);

        $written = (new AttributeResolver)->resolve($product);

        $this->assertSame(3, $written);
        $this->assertEquals(24, $product->attributes()->where('key', 'potenza_kw')->first()->value_num);
        $this->assertSame('INOX', $product->attributes()->where('key', 'materiale')->first()->value_text);
        $this->assertEquals(1, $product->attributes()->where('key', 'attacco_pollici')->first()->value_num);
        $this->assertSame(3, $product->attributes()->where('source', 'regex')->count());
    }

    public function test_resolve_is_idempotent_on_rerun(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'BOLLITORE 800 LT -ELLEGI-',
        ]);
        $resolver = new AttributeResolver;

        $resolver->resolve($product);
        $firstAttribute = $product->attributes()->where('key', 'capacita_litri')->first();
        $resolver->resolve($product);

        $this->assertSame(1, $product->attributes()->where('key', 'capacita_litri')->count());
        $this->assertSame($firstAttribute->id, $product->attributes()->where('key', 'capacita_litri')->first()->id);
    }

    public function test_resolve_does_not_overwrite_an_attribute_from_a_more_authoritative_source(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'CALDAIA 24KW -VAILLANT-',
        ]);
        $product->attributes()->create([
            'key' => 'potenza_kw',
            'value_num' => 99,
            'unit' => 'kW',
            'source' => 'ai',
        ]);

        $written = (new AttributeResolver)->resolve($product);

        $this->assertSame(0, $written);
        $attribute = $product->attributes()->where('key', 'potenza_kw')->first();
        $this->assertEquals(99, $attribute->value_num);
        $this->assertSame('ai', $attribute->source);
    }

    public function test_potenza_kw_range_query_returns_matching_products(): void
    {
        $inRange = Product::factory()->create(['description_raw' => 'UNITA ESTERNA VAI 3-025 WNO VAILLANT']);
        $alsoInRange = Product::factory()->create(['description_raw' => 'CALDAIA 6KW -VAILLANT-']);
        $outOfRange = Product::factory()->create(['description_raw' => 'CALDAIA 24KW -VAILLANT-']);
        $resolver = new AttributeResolver;
        $resolver->resolve($inRange);
        $resolver->resolve($alsoInRange);
        $resolver->resolve($outOfRange);

        $matches = ProductAttribute::where('key', 'potenza_kw')
            ->whereBetween('value_num', [2, 6])
            ->pluck('product_id');

        $this->assertCount(2, $matches);
        $this->assertContains($inRange->id, $matches);
        $this->assertContains($alsoInRange->id, $matches);
        $this->assertNotContains($outOfRange->id, $matches);
    }
}
