<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductAttribute;
use App\Services\Enrichment\AttributeResolver;
use App\Services\Enrichment\EnrichmentProposalRecorder;
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

        $written = (new AttributeResolver(new EnrichmentProposalRecorder))->resolve($product);

        $this->assertSame(1, $written);
        $attribute = $product->attributes()->where('key', 'potenza_kw')->first();
        $this->assertNotNull($attribute);
        $this->assertEquals(3.5, $attribute->value_num);
        $this->assertSame('kW', $attribute->unit);
        $this->assertSame('regex', $attribute->source);
        $this->assertDatabaseHas('enrichment_proposals', [
            'product_id' => $product->id,
            'field' => 'attribute',
            'attribute_key' => 'potenza_kw',
            'origin' => 'regex',
            'status' => 'applied',
            'confidence' => 100,
            'value_num' => 3.5,
        ]);
    }

    public function test_extracts_kw_from_explicit_unit_with_comma_decimal(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'BOX BD 7/7 M4 0,13KW -VORTICE-',
        ]);

        (new AttributeResolver(new EnrichmentProposalRecorder))->resolve($product);

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

        (new AttributeResolver(new EnrichmentProposalRecorder))->resolve($product);

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

        (new AttributeResolver(new EnrichmentProposalRecorder))->resolve($product);

        $attribute = $product->attributes()->where('key', 'potenza_kw')->first();
        $this->assertNotNull($attribute);
        $this->assertEquals(8, $attribute->value_num);
        $this->assertSame('kW', $attribute->unit);
        $this->assertSame('regex', $attribute->source);
    }

    public function test_does_not_extract_kw_when_no_unit_or_series_code_present(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'STAFFA DI FISSAGGIO UNIVERSALE',
        ]);

        $written = (new AttributeResolver(new EnrichmentProposalRecorder))->resolve($product);

        $this->assertSame(0, $written);
        $this->assertNull($product->attributes()->where('key', 'potenza_kw')->first());
    }

    public function test_extracts_litri_from_lt_unit(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'BOLLITORE 800 LT SOLARE IN RF PROD SOLARE ACS CON DOPPIO SCAMB ELLEGI',
        ]);

        $written = (new AttributeResolver(new EnrichmentProposalRecorder))->resolve($product);

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

        (new AttributeResolver(new EnrichmentProposalRecorder))->resolve($product);

        $attribute = $product->attributes()->where('key', 'capacita_litri')->first();
        $this->assertNotNull($attribute);
        $this->assertEquals(50, $attribute->value_num);
        $this->assertSame('L', $attribute->unit);
    }

    public function test_does_not_extract_litri_when_no_lt_unit_present(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'STAFFA DI FISSAGGIO UNIVERSALE',
        ]);

        $written = (new AttributeResolver(new EnrichmentProposalRecorder))->resolve($product);

        $this->assertSame(0, $written);
        $this->assertNull($product->attributes()->where('key', 'capacita_litri')->first());
    }

    public function test_extracts_pollici_from_whole_number(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'RIDUTTORE PRESS.ACQUA 1" C/MANOM. -TECNOGAS-',
        ]);

        $written = (new AttributeResolver(new EnrichmentProposalRecorder))->resolve($product);

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

        (new AttributeResolver(new EnrichmentProposalRecorder))->resolve($product);

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

        (new AttributeResolver(new EnrichmentProposalRecorder))->resolve($product);

        $attribute = $product->attributes()->where('key', 'attacco_pollici')->first();
        $this->assertNotNull($attribute);
        $this->assertEquals(1.25, $attribute->value_num);
        $this->assertSame('"', $attribute->unit);
    }

    public function test_does_not_extract_pollici_when_no_inch_symbol_present(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'STAFFA DI FISSAGGIO UNIVERSALE',
        ]);

        $written = (new AttributeResolver(new EnrichmentProposalRecorder))->resolve($product);

        $this->assertSame(0, $written);
        $this->assertNull($product->attributes()->where('key', 'attacco_pollici')->first());
    }

    public function test_extracts_materiale_inox(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'CASSETTA ESTERNA 610*370*210 INOX',
        ]);

        $written = (new AttributeResolver(new EnrichmentProposalRecorder))->resolve($product);

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

        (new AttributeResolver(new EnrichmentProposalRecorder))->resolve($pvc);
        (new AttributeResolver(new EnrichmentProposalRecorder))->resolve($rame);

        $this->assertSame('PVC', $pvc->attributes()->where('key', 'materiale')->first()->value_text);
        $this->assertSame('RAME', $rame->attributes()->where('key', 'materiale')->first()->value_text);
    }

    public function test_extracts_materiale_rg_as_whole_word(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'SOLAR/SS0200 IN RG PROD.SOLARE ACS C/2 SCAMB.FISSO -ELLEGI-',
        ]);

        (new AttributeResolver(new EnrichmentProposalRecorder))->resolve($product);

        $attribute = $product->attributes()->where('key', 'materiale')->first();
        $this->assertNotNull($attribute);
        $this->assertSame('RG', $attribute->value_text);
    }

    public function test_extracts_first_material_by_position_when_multiple_are_mentioned(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'RACCORDO PVC CON GUARNIZIONE INOX PER TUBO RAME',
        ]);

        (new AttributeResolver(new EnrichmentProposalRecorder))->resolve($product);

        $attribute = $product->attributes()->where('key', 'materiale')->first();
        $this->assertNotNull($attribute);
        $this->assertSame('PVC', $attribute->value_text);
    }

    public function test_does_not_extract_materiale_when_no_known_code_present(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'STAFFA DI FISSAGGIO UNIVERSALE',
        ]);

        $written = (new AttributeResolver(new EnrichmentProposalRecorder))->resolve($product);

        $this->assertSame(0, $written);
        $this->assertNull($product->attributes()->where('key', 'materiale')->first());
    }

    public function test_resolve_writes_every_matched_attribute_in_a_single_call(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'CALDAIA 24KW BOLLITORE INOX 1" -VAILLANT-',
        ]);

        $written = (new AttributeResolver(new EnrichmentProposalRecorder))->resolve($product);

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
        $resolver = new AttributeResolver(new EnrichmentProposalRecorder);

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

        $written = (new AttributeResolver(new EnrichmentProposalRecorder))->resolve($product);

        $this->assertSame(0, $written);
        $attribute = $product->attributes()->where('key', 'potenza_kw')->first();
        $this->assertEquals(99, $attribute->value_num);
        $this->assertSame('ai', $attribute->source);
        $this->assertDatabaseMissing('enrichment_proposals', [
            'product_id' => $product->id,
            'attribute_key' => 'potenza_kw',
        ]);
    }

    public function test_potenza_kw_range_query_returns_matching_products(): void
    {
        $inRange = Product::factory()->create(['description_raw' => 'UNITA ESTERNA VAI 3-025 WNO VAILLANT']);
        $alsoInRange = Product::factory()->create(['description_raw' => 'CALDAIA 6KW -VAILLANT-']);
        $outOfRange = Product::factory()->create(['description_raw' => 'CALDAIA 24KW -VAILLANT-']);
        $resolver = new AttributeResolver(new EnrichmentProposalRecorder);
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

    public function test_extracts_diametro_nominale_from_dn_pattern(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'FILTRO GAS MTN DN80 UNI8042 2BAR C/PR.PRESS. -TECNOGAS-',
        ]);

        (new AttributeResolver(new EnrichmentProposalRecorder))->resolve($product);

        $attribute = $product->attributes()->where('key', 'diametro_nominale')->first();
        $this->assertNotNull($attribute);
        $this->assertEquals(80, $attribute->value_num);
        $this->assertSame('DN', $attribute->unit);
        $this->assertSame('regex', $attribute->source);
    }

    public function test_extracts_diametro_nominale_with_space(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'FLEX ESTENS.GAS DN 15*400 F/M C/GUAINA BIANCA+OR',
        ]);

        (new AttributeResolver(new EnrichmentProposalRecorder))->resolve($product);

        $attribute = $product->attributes()->where('key', 'diametro_nominale')->first();
        $this->assertNotNull($attribute);
        $this->assertEquals(15, $attribute->value_num);
        $this->assertSame('DN', $attribute->unit);
    }

    public function test_extracts_diametro_and_pressione_nominale_in_a_single_call(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'GIUNTO COMPENSATORE AWF DN25 PN16 TECNOGAS',
        ]);

        (new AttributeResolver(new EnrichmentProposalRecorder))->resolve($product);

        $dn = $product->attributes()->where('key', 'diametro_nominale')->first();
        $pn = $product->attributes()->where('key', 'pressione_nominale')->first();
        $this->assertNotNull($dn);
        $this->assertNotNull($pn);
        $this->assertEquals(25, $dn->value_num);
        $this->assertSame('DN', $dn->unit);
        $this->assertEquals(16, $pn->value_num);
        $this->assertSame('PN', $pn->unit);
        $this->assertSame('regex', $dn->source);
        $this->assertSame('regex', $pn->source);
    }

    public function test_extracts_colore_ral_as_value_text(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'TERMOARREDO TEKNO 1200*500 ELETTRICO BIANCO RAL9010 IDROEXPERT',
        ]);

        (new AttributeResolver(new EnrichmentProposalRecorder))->resolve($product);

        $attribute = $product->attributes()->where('key', 'colore_ral')->first();
        $this->assertNotNull($attribute);
        $this->assertSame('RAL9010', $attribute->value_text);
        $this->assertNull($attribute->value_num);
        $this->assertNull($attribute->unit);
        $this->assertSame('regex', $attribute->source);
    }

    public function test_extracts_pressione_bar_from_bar_unit(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'VALVOLA DI SICUREZZA CENTRAL.VT TARAT.18BAR 1/4',
        ]);

        (new AttributeResolver(new EnrichmentProposalRecorder))->resolve($product);

        $attribute = $product->attributes()->where('key', 'pressione_bar')->first();
        $this->assertNotNull($attribute);
        $this->assertEquals(18, $attribute->value_num);
        $this->assertSame('bar', $attribute->unit);
        $this->assertSame('regex', $attribute->source);
    }

    public function test_extracts_pressione_bar_normalising_millibar(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'MANOMETRO RAD.0/100 MBAR D.80MM 3/8 -TECNOGAS-',
        ]);

        (new AttributeResolver(new EnrichmentProposalRecorder))->resolve($product);

        $attribute = $product->attributes()->where('key', 'pressione_bar')->first();
        $this->assertNotNull($attribute);
        $this->assertEquals(0.1, $attribute->value_num);
        $this->assertSame('bar', $attribute->unit);
    }

    public function test_extracts_tensione_volt_from_plausible_voltage(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'BOBINA PER TRASFORMAZ.ELETTR. IN ADPE 230V RM/NC',
        ]);

        (new AttributeResolver(new EnrichmentProposalRecorder))->resolve($product);

        $attribute = $product->attributes()->where('key', 'tensione_volt')->first();
        $this->assertNotNull($attribute);
        $this->assertEquals(230, $attribute->value_num);
        $this->assertSame('V', $attribute->unit);
        $this->assertSame('regex', $attribute->source);
    }

    public function test_does_not_extract_tensione_volt_from_fan_speed_token(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'CASSA VENTILANTE AUTOPORTANTI VORT QBK COMFORT 10/10 4M 1V EP VORTICE',
        ]);

        (new AttributeResolver(new EnrichmentProposalRecorder))->resolve($product);

        $this->assertNull($product->attributes()->where('key', 'tensione_volt')->first());
    }

    public function test_extracts_potenza_watt_from_explicit_unit(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'RESISTENZA FLANGIATA CURVA 1200W',
        ]);

        (new AttributeResolver(new EnrichmentProposalRecorder))->resolve($product);

        $attribute = $product->attributes()->where('key', 'potenza_watt')->first();
        $this->assertNotNull($attribute);
        $this->assertEquals(1200, $attribute->value_num);
        $this->assertSame('W', $attribute->unit);
        $this->assertSame('regex', $attribute->source);
    }

    public function test_does_not_extract_potenza_watt_from_model_version_suffix(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'MODULO ACQUA CALDA SANIT. VPM20/25/2 W',
        ]);

        (new AttributeResolver(new EnrichmentProposalRecorder))->resolve($product);

        $this->assertNull($product->attributes()->where('key', 'potenza_watt')->first());
    }

    public function test_does_not_extract_potenza_watt_from_kw_description(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'CALDAIA A CONDENSAZIONE 24KW -VAILLANT-',
        ]);

        (new AttributeResolver(new EnrichmentProposalRecorder))->resolve($product);

        $this->assertNull($product->attributes()->where('key', 'potenza_watt')->first());
        $this->assertEquals(24, $product->attributes()->where('key', 'potenza_kw')->first()->value_num);
    }

    public function test_extracts_extended_material_codes_as_whole_word(): void
    {
        $acciaio = Product::factory()->create(['description_raw' => 'TUBO ACCIAIO NERO 3/4 SENZA SALDATURA']);
        $ottone = Product::factory()->create(['description_raw' => 'RACCORDO OTTONE FILETTATO M/F']);
        $alluminio = Product::factory()->create(['description_raw' => 'RADIATORE ALLUMINIO 5 ELEMENTI']);
        $ghisa = Product::factory()->create(['description_raw' => 'CALDAIA GHISA 4 ELEMENTI']);
        $resolver = new AttributeResolver(new EnrichmentProposalRecorder);
        $resolver->resolve($acciaio);
        $resolver->resolve($ottone);
        $resolver->resolve($alluminio);
        $resolver->resolve($ghisa);

        $this->assertSame('ACCIAIO', $acciaio->attributes()->where('key', 'materiale')->first()->value_text);
        $this->assertSame('OTTONE', $ottone->attributes()->where('key', 'materiale')->first()->value_text);
        $this->assertSame('ALLUMINIO', $alluminio->attributes()->where('key', 'materiale')->first()->value_text);
        $this->assertSame('GHISA', $ghisa->attributes()->where('key', 'materiale')->first()->value_text);
    }

    public function test_diametro_and_pressione_nominale_range_queries_return_matching_products(): void
    {
        $products = [
            'FILTRO GAS MTN DN80 UNI8042 -TECNOGAS-',
            'GIUNTO COMPENSATORE AWF DN25 PN16 TECNOGAS',
            'FLEX ESTENS.GAS DN 15*400 F/M',
        ];
        $resolver = new AttributeResolver(new EnrichmentProposalRecorder);
        foreach ($products as $description) {
            $resolver->resolve(Product::factory()->create(['description_raw' => $description]));
        }

        $diametroInRange = ProductAttribute::where('key', 'diametro_nominale')
            ->whereBetween('value_num', [15, 50])
            ->count();
        $pressione = ProductAttribute::where('key', 'pressione_nominale')
            ->where('value_num', 16)
            ->count();

        $this->assertSame(2, $diametroInRange);
        $this->assertSame(1, $pressione);
    }
}
