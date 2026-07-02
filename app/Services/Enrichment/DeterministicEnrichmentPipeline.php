<?php

namespace App\Services\Enrichment;

use App\Models\Product;

/**
 * Orchestrates the deterministic (Step A) enrichment resolvers for a single
 * pending `Product`, in the order each one depends on: {@see BrandResolver}
 * first (grouping needs a resolved brand), then {@see AttributeResolver}
 * (independent, reads the same cleaned description), then
 * {@see GroupingResolver} (assigns/creates the `ProductBase`), and finally
 * {@see FamilyPropagationResolver} on the resulting `ProductBase` — run only
 * when grouping actually assigned one, since propagation needs sibling
 * variants to exist.
 *
 * No-ops (all counts zero) for a product that is not `enrichment_status =
 * pending`, since none of the resolvers have a deterministic outcome to
 * contribute for an already-processed product.
 */
class DeterministicEnrichmentPipeline
{
    public function __construct(
        private readonly BrandResolver $brandResolver,
        private readonly AttributeResolver $attributeResolver,
        private readonly GroupingResolver $groupingResolver,
        private readonly FamilyPropagationResolver $familyPropagationResolver,
    ) {}

    /**
     * Run the Step A resolvers on the given product, in dependency order.
     *
     * @return array{brands_resolved: int, attributes_written: int, groups_resolved: int, families_propagated: int}
     */
    public function run(Product $product): array
    {
        $summary = [
            'brands_resolved' => 0,
            'attributes_written' => 0,
            'groups_resolved' => 0,
            'families_propagated' => 0,
        ];

        if ($product->enrichment_status !== 'pending') {
            return $summary;
        }

        $summary['brands_resolved'] = $this->brandResolver->resolve($product) ? 1 : 0;
        $summary['attributes_written'] = $this->attributeResolver->resolve($product);
        $summary['groups_resolved'] = $this->groupingResolver->resolve($product) ? 1 : 0;

        if ($summary['groups_resolved'] === 1 && $product->productBase !== null) {
            $summary['families_propagated'] = $this->familyPropagationResolver->resolve($product->productBase);
        }

        return $summary;
    }
}
