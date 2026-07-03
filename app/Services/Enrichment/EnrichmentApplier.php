<?php

namespace App\Services\Enrichment;

use App\Models\Product;
use App\Services\Ai\ClassifiedProduct;
use App\Services\Ai\TaxonomyCatalog;

/**
 * Applies an AI classification result to a product according to confidence
 * bands, so only sufficiently confident values reach the catalog while
 * everything else is flagged for manual review:
 *  - confidence < {@see self::LOW_CONFIDENCE_THRESHOLD}: no AI value is
 *    applied; the product is only marked `needs_review`.
 *  - confidence between the low and high thresholds: AI values are applied,
 *    but the product is still marked `needs_review`.
 *  - confidence >= {@see self::HIGH_CONFIDENCE_THRESHOLD}: AI values are
 *    applied and the product is marked `enriched`.
 * Brand/family/subfamily are resolved against the closed taxonomy via
 * {@see TaxonomyCatalog}; values that don't resolve to an existing record
 * are ignored rather than raising an exception. A field already carrying
 * `*_source = 'manual'` or `*_source = 'file'` (US-032) is never
 * overwritten, regardless of confidence.
 */
class EnrichmentApplier
{
    /**
     * Confidence below this threshold (0-100) means no AI value is applied.
     */
    private const LOW_CONFIDENCE_THRESHOLD = 60;

    /**
     * Confidence at or above this threshold (0-100) marks the product fully
     * `enriched` instead of `needs_review`.
     */
    private const HIGH_CONFIDENCE_THRESHOLD = 85;

    /**
     * Apply the classification result to the product and persist it.
     */
    public function apply(Product $product, ClassifiedProduct $result, TaxonomyCatalog $taxonomy): void
    {
        $confidence = $result->confidence ?? 0;

        // Proposed technical attributes are written independently of the
        // overall brand/family/subfamily confidence branch below: a single
        // attribute can carry its own high confidence even when the rest of
        // the classification is too weak to trust.
        $attributesForceReview = $this->applyAttributes($product, $result);

        if ($confidence < self::LOW_CONFIDENCE_THRESHOLD) {
            $product->fill(['enrichment_status' => 'needs_review'])->save();

            return;
        }

        $attributes = $this->resolvedAttributes($product, $result, $taxonomy);

        $attributes['enrichment_status'] = ($confidence >= self::HIGH_CONFIDENCE_THRESHOLD && ! $attributesForceReview)
            ? 'enriched'
            : 'needs_review';
        $attributes['source'] = 'ai';
        $attributes['confidence'] = $confidence;

        $product->fill($attributes)->save();
    }

    /**
     * Writes each proposed technical attribute to `product_attributes`
     * according to its own confidence band, independent of the product's
     * overall brand/family/subfamily confidence:
     *  - confidence < {@see self::LOW_CONFIDENCE_THRESHOLD}: skipped, not written.
     *  - confidence between the low and high thresholds: written, and the
     *    product must be forced to `needs_review` even if brand/family were
     *    high-confidence.
     *  - confidence >= {@see self::HIGH_CONFIDENCE_THRESHOLD}: written without
     *    forcing anything; the final status follows the overall branch.
     * An existing row whose `source` is not `null`, `regex`, or `ai` (e.g.
     * `manual`) is never overwritten, mirroring the guard already applied by
     * {@see AttributeResolver::writeAttribute()} for the regex extraction pass.
     *
     * @return bool whether at least one written attribute forces `needs_review`
     */
    private function applyAttributes(Product $product, ClassifiedProduct $result): bool
    {
        $forcesReview = false;

        foreach ($result->attributes as $key => $attribute) {
            $confidence = $attribute['confidence'] ?? 0;

            if ($confidence < self::LOW_CONFIDENCE_THRESHOLD) {
                continue;
            }

            if (! $this->writeAttribute($product, $key, $attribute, $confidence)) {
                continue;
            }

            if ($confidence < self::HIGH_CONFIDENCE_THRESHOLD) {
                $forcesReview = true;
            }
        }

        return $forcesReview;
    }

    /**
     * @param  array{value_num?: float, value_text?: string, unit?: string, confidence: int}  $attribute
     */
    private function writeAttribute(Product $product, string $key, array $attribute, int $confidence): bool
    {
        $existing = $product->attributes()->where('key', $key)->first();

        if ($existing !== null && $existing->source !== null && $existing->source !== 'regex' && $existing->source !== 'ai') {
            return false;
        }

        $product->attributes()->updateOrCreate(
            ['key' => $key],
            [
                'value_num' => $attribute['value_num'] ?? null,
                'value_text' => $attribute['value_text'] ?? null,
                'unit' => $attribute['unit'] ?? null,
                'source' => 'ai',
                'confidence' => $confidence,
            ],
        );

        return true;
    }

    /**
     * Resolve brand/family/subfamily against the taxonomy and build the
     * attributes to persist, skipping any field already set manually or
     * whose AI value doesn't resolve to an existing taxonomy record.
     *
     * @return array<string, mixed>
     */
    private function resolvedAttributes(Product $product, ClassifiedProduct $result, TaxonomyCatalog $taxonomy): array
    {
        $attributes = [];

        if ($product->brand_source !== 'manual' && $product->brand_source !== 'file' && $result->brand !== null) {
            $brand = $taxonomy->findBrand($result->brand);

            if ($brand !== null) {
                $attributes['brand_id'] = $brand->id;
                $attributes['brand_source'] = 'ai';
            }
        }

        if ($product->family_source !== 'manual' && $product->family_source !== 'file' && $result->family !== null) {
            $family = $taxonomy->findFamily($result->family);

            if ($family !== null) {
                $attributes['family_id'] = $family->id;
                $attributes['family_source'] = 'ai';
            }
        }

        if ($product->subfamily_source !== 'manual' && $product->subfamily_source !== 'file' && $result->subfamily !== null) {
            $subfamily = $taxonomy->findSubfamily($result->subfamily, $result->family);

            if ($subfamily !== null) {
                $attributes['subfamily_id'] = $subfamily->id;
                $attributes['subfamily_source'] = 'ai';
            }
        }

        return $attributes;
    }
}
