<?php

namespace App\Services\Enrichment;

use App\Jobs\ClassifyProductsBatchJob;
use App\Models\EnrichmentProposal;
use App\Models\Product;
use Illuminate\Support\Carbon;

/**
 * Records every brand/family/subfamily/attribute proposal made by
 * deterministic resolvers (e.g. {@see FileTaxonomyResolver},
 * {@see BrandResolver}, {@see AttributeResolver}) and the AI classifier
 * (e.g. {@see EnrichmentApplier}) into `enrichment_proposals`, independent
 * of whether the value was actually applied to the product.
 *
 * This is a pure logging service: it never mutates the product itself and
 * never changes any existing direct-write behavior. Callers decide whether
 * a proposal was `applied` or left `pending` for manual review.
 */
class EnrichmentProposalRecorder
{
    /**
     * Persist a single proposal row for `$product`.
     */
    public function record(
        Product $product,
        string $field,
        string $origin,
        string $status,
        ?int $confidence = null,
        ?string $attributeKey = null,
        ?int $valueId = null,
        ?float $valueNum = null,
        ?string $valueText = null,
        ?string $unit = null,
    ): EnrichmentProposal {
        return $product->enrichmentProposals()->create([
            'field' => $field,
            'attribute_key' => $attributeKey,
            'value_id' => $valueId,
            'value_num' => $valueNum,
            'value_text' => $valueText,
            'unit' => $unit,
            'origin' => $origin,
            'confidence' => $confidence,
            'status' => $status,
        ]);
    }

    /**
     * Bulk-insert proposal rows, bypassing Eloquent events/casts — same
     * pattern as `EnrichmentLog::query()->insert()` in
     * {@see ClassifyProductsBatchJob::logResults()}.
     *
     * Each row must already contain `product_id`, `field`, `origin`, and
     * `status`, and MAY contain `confidence`, `attribute_key`, `value_id`,
     * `value_num`, `value_text`, `unit`. Missing optional keys are filled
     * with `null` and `created_at`/`updated_at` are stamped with `now()`.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function insertMany(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $now = Carbon::now();

        $normalizedRows = array_map(
            fn (array $row): array => [
                'product_id' => $row['product_id'],
                'field' => $row['field'],
                'attribute_key' => $row['attribute_key'] ?? null,
                'value_id' => $row['value_id'] ?? null,
                'value_num' => $row['value_num'] ?? null,
                'value_text' => $row['value_text'] ?? null,
                'unit' => $row['unit'] ?? null,
                'origin' => $row['origin'],
                'confidence' => $row['confidence'] ?? null,
                'status' => $row['status'],
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $rows,
        );

        EnrichmentProposal::query()->insert($normalizedRows);
    }
}
