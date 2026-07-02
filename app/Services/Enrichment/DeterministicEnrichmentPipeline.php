<?php

namespace App\Services\Enrichment;

use App\Models\Product;

/**
 * Orchestrates the deterministic (Step A) enrichment resolvers for a single
 * pending `Product`, in the order each one depends on: {@see
 * FileTaxonomyResolver} first (a raw file match is a stronger, certain
 * signal that must win over every textual deduction below it, and its
 * *_source = 'file' idempotency guards make BrandResolver/GroupingResolver/
 * FamilyPropagationResolver no-op on whatever it already linked), then
 * {@see BrandResolver} (grouping needs a resolved brand), then
 * {@see AttributeResolver} (independent, reads the same cleaned
 * description), then {@see GroupingResolver} (assigns/creates the
 * `ProductBase`), and finally {@see FamilyPropagationResolver} on the
 * resulting `ProductBase` — run only when grouping actually assigned one,
 * since propagation needs sibling variants to exist.
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
        private readonly AttributeResolver $attributeResolver,
        private readonly GroupingResolver $groupingResolver,
        private readonly FamilyPropagationResolver $familyPropagationResolver,
    ) {}

    /**
     * Run the Step A resolvers on the given product, in dependency order.
     *
     * @return array{brands_linked_from_file: int, families_linked_from_file: int, subfamilies_linked_from_file: int, brands_resolved: int, attributes_written: int, groups_resolved: int, families_propagated: int}
     */
    public function run(Product $product): array
    {
        $summary = [
            'brands_linked_from_file' => 0,
            'families_linked_from_file' => 0,
            'subfamilies_linked_from_file' => 0,
            'brands_resolved' => 0,
            'attributes_written' => 0,
            'groups_resolved' => 0,
            'families_propagated' => 0,
        ];

        if ($product->enrichment_status !== 'pending') {
            return $summary;
        }

        $fileLinked = $this->fileTaxonomyResolver->resolve($product);
        $summary['brands_linked_from_file'] = $fileLinked['brand'] ? 1 : 0;
        $summary['families_linked_from_file'] = $fileLinked['family'] ? 1 : 0;
        $summary['subfamilies_linked_from_file'] = $fileLinked['subfamily'] ? 1 : 0;

        $summary['brands_resolved'] = $this->brandResolver->resolve($product) ? 1 : 0;
        $summary['attributes_written'] = $this->attributeResolver->resolve($product);
        $summary['groups_resolved'] = $this->groupingResolver->resolve($product) ? 1 : 0;

        if ($summary['groups_resolved'] === 1 && $product->productBase !== null) {
            $summary['families_propagated'] = $this->familyPropagationResolver->resolve($product->productBase);
        }

        return $summary;
    }
}
