<?php

namespace App\Services\Enrichment;

use App\Models\Product;
use App\Models\ProductBase;
use Illuminate\Support\Str;

/**
 * Groups product variants (e.g. different sizes of the same commercial
 * article) under a single `ProductBase`, so downstream search does not
 * surface one hit per size. The `grouping_key` is a deterministic hash of
 * brand + normalized description, with the size token (e.g. the `025` in
 * `VAI 8-025`) stripped so all sizes of the same series collapse to one key.
 * No-ops when the product already has a `product_base_id` (idempotency) or
 * when its brand is not yet resolved (US-009 must run first).
 */
class GroupingResolver
{
    /**
     * Attempt to resolve and persist the product_base for the given product.
     * No-ops when the product already has a product_base_id, or when its
     * brand_id is not resolved.
     *
     * @return bool Whether a product_base was assigned.
     */
    public function resolve(Product $product): bool
    {
        if ($product->product_base_id !== null) {
            return false;
        }

        if ($product->brand_id === null) {
            return false;
        }

        $text = trim((string) ($product->description_clean ?? $product->description_raw));

        if ($text === '') {
            return false;
        }

        $normalized = $this->normalizeForGrouping($text);
        $key = $this->groupingKey($product->brand_id, $normalized);

        $base = ProductBase::query()->firstOrCreate(
            ['grouping_key' => $key],
            [
                'title' => $this->readableTitle($product, $normalized),
                'brand_id' => $product->brand_id,
            ],
        );

        $product->fill([
            'product_base_id' => $base->id,
            'grouping_key' => $key,
        ])->save();

        return true;
    }

    /**
     * Strips the size token from a series-code pattern (e.g. `VAI 8-025` →
     * `VAI 8`) and returns the remaining text normalized to uppercase with
     * collapsed whitespace, so variants that only differ by size collapse to
     * the same normalized string.
     */
    public function normalizeForGrouping(string $text): string
    {
        $stripped = preg_replace('/(\p{L}+\s+\d+)-\d{2,4}\b/u', '$1', $text) ?? $text;

        $upper = Str::upper($stripped);
        $squeezed = preg_replace('/\s+/u', ' ', $upper) ?? $upper;

        return trim($squeezed);
    }

    /**
     * Deterministic hash of brand + normalized description, used as the
     * unique key that all variants of the same series share.
     */
    private function groupingKey(int $brandId, string $normalized): string
    {
        return hash('sha256', $brandId.'|'.$normalized);
    }

    /**
     * Human-readable title composed of the brand name and the normalized
     * description, rendered in Title Case (e.g. "Vaillant Vai 8 Wni").
     */
    private function readableTitle(Product $product, string $normalized): string
    {
        $brandName = $product->brand?->name;

        $title = $brandName !== null
            ? $brandName.' '.$normalized
            : $normalized;

        return Str::title(Str::lower($title));
    }
}
