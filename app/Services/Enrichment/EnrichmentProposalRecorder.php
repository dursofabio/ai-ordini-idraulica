<?php

namespace App\Services\Enrichment;

use App\Jobs\ClassifyProductsBatchJob;
use App\Models\EnrichmentProposal;
use App\Models\Product;
use App\Services\Ai\AttributeVocabulary;
use Illuminate\Support\Carbon;

/**
 * Records every brand/family/subfamily/attribute proposal made by
 * deterministic resolvers (e.g. {@see FileTaxonomyResolver},
 * {@see BrandResolver}) and the AI classifier (e.g. {@see EnrichmentApplier},
 * the sole source of attribute proposals since US-043 removed the regex
 * extraction pass) into `enrichment_proposals`, independent of whether the
 * value was actually applied to the product.
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
        ?string $value = null,
        ?string $unit = null,
        ?string $dataType = null,
    ): EnrichmentProposal {
        return $product->enrichmentProposals()->create([
            'field' => $field,
            'attribute_key' => $attributeKey,
            'value_id' => $valueId,
            'value' => $value,
            'unit' => $unit,
            'data_type' => $dataType,
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
     * `value`, `unit`. Missing optional keys are filled with `null` and
     * `created_at`/`updated_at` are stamped with `now()`.
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
                'value' => $row['value'] ?? null,
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

    /**
     * US-044 AC1/AC4: records a proposal for a new `attribute_definition`
     * registry entry when the AI reports a `$key` absent from
     * {@see AttributeVocabulary} — the safety net that keeps
     * an out-of-registry key from silently disappearing (US-043 behavior)
     * while still not writing anything to `product_attributes`.
     *
     * Accorpamento (AC4): if a `pending` `attribute_definition` proposal for
     * the same `$key` already exists — regardless of which product
     * originated it, since the proposal is about the registry, not a single
     * product — no second row is created, so repeated occurrences of the
     * same unknown key don't flood the queue. A previously `discarded`
     * proposal for the same key does not block a new one: discarding a
     * proposal is a decision about that one occurrence, not a permanent ban
     * on the key ever being proposed again.
     *
     * `data_type` is inferred deterministically from whether the AI's
     * reported value for this attribute looks numeric — no extra AI call.
     * `unit` is kept exactly as read (unconverted, there is no registry
     * entry yet to convert against). `value` is left `null`: the proposed
     * description is filled in later by the reviewer, not by the AI (US-044
     * assumption).
     *
     * @param  array{value?: string, unit?: string, confidence: int}  $attribute
     */
    public function recordAttributeDefinitionProposal(Product $product, string $key, array $attribute): void
    {
        $alreadyPending = EnrichmentProposal::query()
            ->where('field', 'attribute_definition')
            ->where('attribute_key', $key)
            ->where('status', 'pending')
            ->exists();

        if ($alreadyPending) {
            return;
        }

        $dataType = is_numeric($attribute['value'] ?? null) ? 'numeric' : 'text';

        $this->record(
            product: $product,
            field: 'attribute_definition',
            origin: 'ai',
            status: 'pending',
            confidence: $attribute['confidence'] ?? null,
            attributeKey: $key,
            unit: $attribute['unit'] ?? null,
            dataType: $dataType,
        );
    }
}
