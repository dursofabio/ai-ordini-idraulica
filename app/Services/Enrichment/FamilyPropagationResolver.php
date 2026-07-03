<?php

namespace App\Services\Enrichment;

use App\Models\Product;
use App\Models\ProductBase;
use Illuminate\Database\Eloquent\Collection;

/**
 * Propagates the prevailing family_id/subfamily_id within a ProductBase
 * group to sibling variants that have no classification yet, increasing
 * taxonomy coverage before AI-based enrichment is requested (US-011 must
 * have already assigned product_base_id for a group to exist).
 *
 * Only variants with a null family_id (respectively subfamily_id) are
 * candidates for a write, which structurally guarantees that values with
 * family_source `file`, `ai`, or `manual` — and values already propagated
 * by a previous run — are never overwritten, and makes the resolver
 * idempotent by construction.
 */
class FamilyPropagationResolver
{
    public function __construct(private readonly EnrichmentProposalRecorder $recorder) {}

    /**
     * Propagate the prevailing family_id and subfamily_id, independently,
     * to the variants of the given ProductBase that currently have a null
     * value for each column.
     *
     * @return int Total number of variant rows updated (family + subfamily).
     */
    public function resolve(ProductBase $productBase): int
    {
        $products = $productBase->products;

        $updated = $this->propagateColumn($products, 'family_id', 'family_source');
        $updated += $this->propagateColumn($products, 'subfamily_id', 'subfamily_source');

        return $updated;
    }

    /**
     * Computes the prevailing value for the given column and, when one
     * exists, writes it to every variant in the group whose column is
     * currently null, together with the `propagated` source.
     *
     * @param  Collection<int, Product>  $products
     */
    private function propagateColumn(Collection $products, string $column, string $sourceColumn): int
    {
        $prevailingValue = $this->prevailingValue($products, $column);

        if ($prevailingValue === null) {
            return 0;
        }

        $candidateIds = $products
            ->whereNull($column)
            ->pluck('id');

        if ($candidateIds->isEmpty()) {
            return 0;
        }

        $updated = Product::query()
            ->whereIn('id', $candidateIds)
            ->update([
                $column => $prevailingValue,
                $sourceColumn => 'propagated',
            ]);

        if ($updated > 0) {
            $field = $column === 'family_id' ? 'family' : 'subfamily';

            $this->recorder->insertMany($candidateIds->map(fn (int $id): array => [
                'product_id' => $id,
                'field' => $field,
                'value_id' => $prevailingValue,
                'origin' => 'propagated',
                'status' => 'applied',
                'confidence' => 100,
            ])->all());
        }

        return $updated;
    }

    /**
     * Determines the prevailing (most frequent) non-null value for the
     * given column among the group's variants. Ties are broken by the
     * lowest id, so the result is deterministic across runs. Returns null
     * when no variant in the group has a value for the column.
     *
     * @param  Collection<int, Product>  $products
     */
    private function prevailingValue(Collection $products, string $column): ?int
    {
        $counts = $products
            ->pluck($column)
            ->filter(fn (?int $value) => $value !== null)
            ->countBy();

        if ($counts->isEmpty()) {
            return null;
        }

        $maxCount = $counts->max();

        return $counts
            ->filter(fn (int $count) => $count === $maxCount)
            ->keys()
            ->map(fn (string|int $value) => (int) $value)
            ->sort()
            ->first();
    }
}
