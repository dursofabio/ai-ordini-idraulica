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
    private const MATERIALS = ['RG', 'INOX', 'PVC', 'RAME'];

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
