<?php

namespace App\Services\Enrichment;

use App\Models\AttributeDefinition;
use App\Models\EnrichmentProposal;

/**
 * Applies a reviewer's triage decision to a pending
 * {@see EnrichmentProposal}. Extracted from the "Da revisionare" queue so
 * every surface exposing triage actions (the queue itself and the product
 * page's proposals list) shares the exact same semantics and can never
 * diverge:
 *  - {@see confirm()}: writes the proposed value to the product with the
 *    proposal's own `origin` as the field's source — the same effect as the
 *    automatic high-confidence application performed by
 *    {@see EnrichmentApplier} — and marks the proposal `applied`.
 *  - {@see correct()}: writes the admin-submitted value with
 *    `source = 'manual'`, then marks the proposal `applied`.
 *  - {@see discard()}: marks the proposal `discarded` without touching the
 *    product at all, since the pending value was never applied in the first
 *    place.
 */
class EnrichmentProposalTriage
{
    /**
     * Promotes the pending proposal as-is: writes the proposed value with
     * the proposal's own `origin` as the field's source, and marks the
     * proposal `applied`.
     */
    public function confirm(EnrichmentProposal $proposal): void
    {
        $this->writeProposalValue($proposal, $proposal->origin, [
            'value_id' => $proposal->value_id,
            'value_text' => $proposal->value_text,
            'value_num' => $proposal->value_num,
            'unit' => $proposal->unit,
            'attribute_key' => $proposal->attribute_key,
            'data_type' => $proposal->data_type,
        ], $proposal->confidence);

        $proposal->update(['status' => 'applied']);
    }

    /**
     * Writes the admin-submitted value to the product with
     * `source = 'manual'`, then marks the proposal `applied`.
     *
     * @param  array{value_id?: ?int, value_text?: ?string, value_num?: ?float, unit?: ?string, attribute_key?: ?string, data_type?: ?string}  $data
     */
    public function correct(EnrichmentProposal $proposal, array $data): void
    {
        $this->writeProposalValue($proposal, 'manual', [
            'value_id' => $data['value_id'] ?? null,
            'value_text' => $data['value_text'] ?? null,
            'value_num' => $data['value_num'] ?? null,
            'unit' => $data['unit'] ?? null,
            'attribute_key' => $data['attribute_key'] ?? null,
            'data_type' => $data['data_type'] ?? null,
        ]);

        $proposal->update(['status' => 'applied']);
    }

    /**
     * Discards the proposal without touching the product — the proposed
     * value was never applied in the first place, so there is nothing to
     * roll back on the product itself.
     */
    public function discard(EnrichmentProposal $proposal): void
    {
        $proposal->update(['status' => 'discarded']);
    }

    /**
     * Writes `$values` to the proposal's underlying product: for brand/
     * family/subfamily, sets `{field}_id` and `{field}_source = $source`;
     * for a technical attribute, creates or updates the
     * `product_attributes` row for `attribute_key` with `source = $source`
     * (and `confidence = $confidence`, mirroring
     * {@see EnrichmentApplier::writeAttribute()} —
     * only passed by {@see confirm()}, since a manual correction has no
     * meaningful confidence score to carry over). `product_type` (US-045)
     * has no `_id`/`_source` columns: it writes the plain text value
     * directly onto the `product_type` column instead. `attribute_definition`
     * (US-044 AC3) doesn't touch the product at all: it creates the new
     * `AttributeDefinition` registry row instead, via `firstOrCreate` on the
     * key so an already-registered key (e.g. approved from a concurrent
     * duplicate proposal) is not re-created or errored on.
     *
     * @param  array{value_id: ?int, value_text: ?string, value_num: ?float, unit: ?string, attribute_key?: ?string, data_type?: ?string}  $values
     */
    private function writeProposalValue(EnrichmentProposal $proposal, string $source, array $values, ?int $confidence = null): void
    {
        if ($proposal->field === 'attribute_definition') {
            AttributeDefinition::query()->firstOrCreate(
                ['key' => $values['attribute_key']],
                [
                    'data_type' => $values['data_type'],
                    'canonical_unit' => $values['unit'],
                    'description' => $values['value_text'],
                ],
            );

            return;
        }

        if ($proposal->field === 'attribute') {
            $proposal->product->attributes()->updateOrCreate(
                ['key' => $proposal->attribute_key],
                [
                    'value_text' => $values['value_text'],
                    'value_num' => $values['value_num'],
                    'unit' => $values['unit'],
                    'source' => $source,
                    'confidence' => $confidence,
                ],
            );

            return;
        }

        if ($proposal->field === 'product_type') {
            $proposal->product->update([
                'product_type' => $values['value_text'],
            ]);

            return;
        }

        $proposal->product->update([
            "{$proposal->field}_id" => $values['value_id'],
            "{$proposal->field}_source" => $source,
        ]);
    }
}
