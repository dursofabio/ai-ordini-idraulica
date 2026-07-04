<?php

namespace App\Services\Enrichment;

use App\Models\Product;
use App\Services\Ai\AttributeVocabulary;

/**
 * Orchestrates the deterministic (Step A) enrichment resolvers for a single
 * pending `Product`, in the order each one depends on: {@see
 * FileTaxonomyResolver} first (a raw file match is a stronger, certain
 * signal that must win over every textual deduction below it, and its
 * *_source = 'file' idempotency guards make BrandResolver no-op on whatever
 * it already linked), then {@see BrandResolver}. Technical attributes are no
 * longer part of this deterministic pass (US-043): they are extracted
 * exclusively by AI classification, anchored to the registry via
 * {@see AttributeVocabulary} and converted by
 * {@see AttributeUnitConverter} in {@see EnrichmentApplier}.
 *
 * No-ops (all counts zero) for a product that is not `enrichment_status =
 * pending`, since none of the resolvers have a deterministic outcome to
 * contribute for an already-processed product.
 */
class DeterministicEnrichmentPipeline
{
    public function __construct(
        private readonly FileTaxonomyResolver $fileTaxonomyResolver,
        private readonly BrandResolver $brandResolver,
    ) {}

    /**
     * Run the Step A resolvers on the given product, in dependency order.
     *
     * @return array{brands_linked_from_file: int, families_linked_from_file: int, subfamilies_linked_from_file: int, brands_resolved: int}
     */
    public function run(Product $product): array
    {
        $summary = [
            'brands_linked_from_file' => 0,
            'families_linked_from_file' => 0,
            'subfamilies_linked_from_file' => 0,
            'brands_resolved' => 0,
        ];

        if ($product->enrichment_status !== 'pending') {
            return $summary;
        }

        $fileLinked = $this->fileTaxonomyResolver->resolve($product);
        $summary['brands_linked_from_file'] = $fileLinked['brand'] ? 1 : 0;
        $summary['families_linked_from_file'] = $fileLinked['family'] ? 1 : 0;
        $summary['subfamilies_linked_from_file'] = $fileLinked['subfamily'] ? 1 : 0;

        $summary['brands_resolved'] = $this->brandResolver->resolve($product) ? 1 : 0;

        return $summary;
    }
}
