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
 * `*_source = 'manual'` is never overwritten, regardless of confidence.
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

        if ($confidence < self::LOW_CONFIDENCE_THRESHOLD) {
            $product->fill(['enrichment_status' => 'needs_review'])->save();

            return;
        }

        $attributes = $this->resolvedAttributes($product, $result, $taxonomy);

        $attributes['enrichment_status'] = $confidence >= self::HIGH_CONFIDENCE_THRESHOLD
            ? 'enriched'
            : 'needs_review';
        $attributes['source'] = 'ai';
        $attributes['confidence'] = $confidence;

        $product->fill($attributes)->save();
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

        if ($product->brand_source !== 'manual' && $result->brand !== null) {
            $brand = $taxonomy->findBrand($result->brand);

            if ($brand !== null) {
                $attributes['brand_id'] = $brand->id;
                $attributes['brand_source'] = 'ai';
            }
        }

        if ($product->family_source !== 'manual' && $result->family !== null) {
            $family = $taxonomy->findFamily($result->family);

            if ($family !== null) {
                $attributes['family_id'] = $family->id;
                $attributes['family_source'] = 'ai';
            }
        }

        if ($product->subfamily_source !== 'manual' && $result->subfamily !== null) {
            $subfamily = $taxonomy->findSubfamily($result->subfamily, $result->family);

            if ($subfamily !== null) {
                $attributes['subfamily_id'] = $subfamily->id;
                $attributes['subfamily_source'] = 'ai';
            }
        }

        return $attributes;
    }
}
