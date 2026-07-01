<?php

namespace App\Services\Enrichment;

use App\Models\Product;

/**
 * Extracts deterministic technical attributes (power, capacity, thread size,
 * material) from a product's cleaned description via regex, before falling
 * back to AI-based enrichment (EP-004). Writes one `product_attributes` row
 * per matched attribute with `source='regex'`, and never overwrites a row
 * already assigned by a more authoritative source.
 */
class AttributeResolver
{
    /**
     * Series-code prefixes where the number immediately following the prefix
     * denotes the nominal power in kW (e.g. Vaillant Aroterm `VAI 8-025` = 8kW,
     * confirmed against the real catalog sample). Extend as new series are found.
     *
     * @var array<int, string>
     */
    private const KW_SERIES_PREFIXES = ['VAI'];

    /**
     * Known material codes, matched as a whole word, case-insensitive.
     *
     * @var array<int, string>
     */
    private const MATERIALS = ['RG', 'INOX', 'PVC', 'RAME', 'ACCIAIO', 'ALLUMINIO', 'OTTONE', 'GHISA', 'BRONZO', 'MULTISTRATO', 'ABS', 'PEX'];

    /**
     * Plausible mains/appliance voltages, used to guard the voltage extractor
     * against non-voltage tokens such as `1V` (fan speed) or `V.S` (safety
     * valve) found in the real catalog.
     *
     * @var array<int, string>
     */
    private const PLAUSIBLE_VOLTAGES = ['12', '24', '48', '110', '220', '230', '380', '400'];

    /**
     * Attempt to extract and persist technical attributes for the given
     * product. Returns the number of attributes written; a row already
     * assigned by a non-regex source is left untouched.
     */
    public function resolve(Product $product): int
    {
        $text = trim((string) ($product->description_clean ?? $product->description_raw));

        if ($text === '') {
            return 0;
        }

        $written = 0;

        foreach ($this->extract($text) as $key => $attribute) {
            if ($this->writeAttribute($product, $key, $attribute)) {
                $written++;
            }
        }

        return $written;
    }

    /**
     * @return array<string, array{value_num?: float, value_text?: string, unit?: string}>
     */
    private function extract(string $text): array
    {
        $attributes = [];

        if (($kw = $this->extractPotenzaKw($text)) !== null) {
            $attributes['potenza_kw'] = $kw;
        }

        if (($litri = $this->extractCapacitaLitri($text)) !== null) {
            $attributes['capacita_litri'] = $litri;
        }

        if (($pollici = $this->extractAttaccoPollici($text)) !== null) {
            $attributes['attacco_pollici'] = $pollici;
        }

        if (($diametroNominale = $this->extractDiametroNominale($text)) !== null) {
            $attributes['diametro_nominale'] = $diametroNominale;
        }

        if (($pressioneNominale = $this->extractPressioneNominale($text)) !== null) {
            $attributes['pressione_nominale'] = $pressioneNominale;
        }

        if (($pressioneBar = $this->extractPressioneBar($text)) !== null) {
            $attributes['pressione_bar'] = $pressioneBar;
        }

        if (($tensioneVolt = $this->extractTensioneVolt($text)) !== null) {
            $attributes['tensione_volt'] = $tensioneVolt;
        }

        if (($potenzaWatt = $this->extractPotenzaWatt($text)) !== null) {
            $attributes['potenza_watt'] = $potenzaWatt;
        }

        if (($coloreRal = $this->extractColoreRal($text)) !== null) {
            $attributes['colore_ral'] = $coloreRal;
        }

        if (($materiale = $this->extractMateriale($text)) !== null) {
            $attributes['materiale'] = $materiale;
        }

        return $attributes;
    }

    /**
     * Persists a single attribute for the product, guarding against
     * overwriting a row already assigned by a more authoritative source
     * (e.g. 'ai' or 'manual', assigned by a future EP-004 spec).
     *
     * @param  array{value_num?: float, value_text?: string, unit?: string}  $attribute
     */
    private function writeAttribute(Product $product, string $key, array $attribute): bool
    {
        $existing = $product->attributes()->where('key', $key)->first();

        if ($existing !== null && $existing->source !== null && $existing->source !== 'regex') {
            return false;
        }

        $product->attributes()->updateOrCreate(
            ['key' => $key],
            [...$attribute, 'source' => 'regex'],
        );

        return true;
    }

    /**
     * Explicit `KW` unit (e.g. `3.5KW`, `0,13KW`), falling back to a known
     * series-code prefix (e.g. `VAI 8-025`) where the trailing number
     * denotes the nominal power in kW.
     *
     * @return array{value_num: float, unit: string}|null
     */
    private function extractPotenzaKw(string $text): ?array
    {
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*[kK][wW]\b/u', $text, $matches)) {
            return ['value_num' => $this->toFloat($matches[1]), 'unit' => 'kW'];
        }

        $prefixes = implode('|', array_map(fn (string $prefix): string => preg_quote($prefix, '/'), self::KW_SERIES_PREFIXES));

        if (preg_match('/\b(?:'.$prefixes.')\s+(\d+(?:[.,]\d+)?)-\d+/iu', $text, $matches)) {
            return ['value_num' => $this->toFloat($matches[1]), 'unit' => 'kW'];
        }

        return null;
    }

    /**
     * @return array{value_num: float, unit: string}|null
     */
    private function extractCapacitaLitri(string $text): ?array
    {
        if (! preg_match('/(\d+(?:[.,]\d+)?)\s*LT\b/iu', $text, $matches)) {
            return null;
        }

        return ['value_num' => $this->toFloat($matches[1]), 'unit' => 'L'];
    }

    /**
     * Matches a whole number (`1"`), a simple fraction (`3/4"`), or a
     * space-separated mixed number (`1 1/4"`) immediately before `"`.
     *
     * @return array{value_num: float, unit: string}|null
     */
    private function extractAttaccoPollici(string $text): ?array
    {
        if (! preg_match('/(?:(\d+)\s+)?(\d+\/\d+|\d+)"/u', $text, $matches)) {
            return null;
        }

        $value = $this->fractionToFloat($matches[2]);

        if ($matches[1] !== '') {
            $value += (float) $matches[1];
        }

        return ['value_num' => $value, 'unit' => '"'];
    }

    /**
     * Nominal diameter (e.g. `DN80`, `DN 15`, `DN15-20-25` → first value),
     * a plumbing standard used for fittings and valves.
     *
     * @return array{value_num: float, unit: string}|null
     */
    private function extractDiametroNominale(string $text): ?array
    {
        if (! preg_match('/\bDN\s*(\d+)/iu', $text, $matches)) {
            return null;
        }

        return ['value_num' => (float) $matches[1], 'unit' => 'DN'];
    }

    /**
     * Nominal pressure (e.g. `PN10`, `PN16`), the pressure rating of flanged
     * fittings and joints.
     *
     * @return array{value_num: float, unit: string}|null
     */
    private function extractPressioneNominale(string $text): ?array
    {
        if (! preg_match('/\bPN\s*(\d+)/iu', $text, $matches)) {
            return null;
        }

        return ['value_num' => (float) $matches[1], 'unit' => 'PN'];
    }

    /**
     * Pressure rating in bar (e.g. `18BAR`, `1,5 BAR`), normalising millibar
     * units (`MBAR`/`MB`, e.g. `100 MBAR` → 0.1 bar). The `MBAR`/`MB`
     * alternatives are tried before `BAR` so the unit is classified correctly.
     *
     * @return array{value_num: float, unit: string}|null
     */
    private function extractPressioneBar(string $text): ?array
    {
        if (! preg_match('/(\d+(?:[.,]\d+)?)\s*(MBAR|MB|BAR)\b/iu', $text, $matches)) {
            return null;
        }

        $value = $this->toFloat($matches[1]);

        if (strtoupper($matches[2]) !== 'BAR') {
            $value /= 1000;
        }

        return ['value_num' => $value, 'unit' => 'bar'];
    }

    /**
     * Mains/appliance voltage, restricted to a whitelist of plausible values
     * so tokens like `1V` (fan speed) are not misread as volts. Handles the
     * optional `DC`/`AC`/`~` suffix (e.g. `230V`, `24VDC`).
     *
     * @return array{value_num: float, unit: string}|null
     */
    private function extractTensioneVolt(string $text): ?array
    {
        $voltages = implode('|', self::PLAUSIBLE_VOLTAGES);

        if (! preg_match('/\b('.$voltages.')\s*V(?:DC|AC|~)?\b/iu', $text, $matches)) {
            return null;
        }

        return ['value_num' => (float) $matches[1], 'unit' => 'V'];
    }

    /**
     * Power in watts (e.g. `1200W`, `500W`), requiring at least two digits so
     * model-version suffixes such as `/2 W` are ignored. The `W` of `kW`
     * never matches because the intervening `K` breaks the digit→`W` sequence.
     *
     * @return array{value_num: float, unit: string}|null
     */
    private function extractPotenzaWatt(string $text): ?array
    {
        if (! preg_match('/\b(\d{2,4})\s*W\b/iu', $text, $matches)) {
            return null;
        }

        return ['value_num' => (float) $matches[1], 'unit' => 'W'];
    }

    /**
     * RAL colour code (e.g. `RAL9010`), normalised to the canonical
     * `RAL####` form. Only `value_text` is populated.
     *
     * @return array{value_text: string}|null
     */
    private function extractColoreRal(string $text): ?array
    {
        if (! preg_match('/\bRAL\s*(\d{3,4})/iu', $text, $matches)) {
            return null;
        }

        return ['value_text' => 'RAL'.$matches[1]];
    }

    /**
     * Dictionary match against known material codes as a whole word,
     * case-insensitive, taking the earliest occurring token when more than
     * one material is mentioned.
     *
     * @return array{value_text: string}|null
     */
    private function extractMateriale(string $text): ?array
    {
        $earliestOffset = null;
        $match = null;

        foreach (self::MATERIALS as $material) {
            $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($material, '/').'(?![\p{L}\p{N}])/iu';

            if (preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE)
                && ($earliestOffset === null || $matches[0][1] < $earliestOffset)) {
                $earliestOffset = $matches[0][1];
                $match = $material;
            }
        }

        return $match === null ? null : ['value_text' => $match];
    }

    private function fractionToFloat(string $token): float
    {
        if (! str_contains($token, '/')) {
            return (float) $token;
        }

        [$numerator, $denominator] = array_map('floatval', explode('/', $token, 2));

        return $denominator === 0.0 ? 0.0 : $numerator / $denominator;
    }

    private function toFloat(string $token): float
    {
        return (float) str_replace(',', '.', $token);
    }
}
