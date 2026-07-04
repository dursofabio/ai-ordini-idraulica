<?php

namespace App\Services\Enrichment;

use App\Models\AttributeDefinition;
use App\Models\Product;
use App\Services\Ai\AttributeVocabulary;
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
 *
 * `product_type` (US-045) is a plain cleaned-up product name/type with no
 * taxonomy to resolve against and no `*_source` column: it follows the same
 * confidence gate as brand/family/subfamily but is always overwritten by a
 * later reclassification, with no manual/file guard.
 *
 * Every brand/family/subfamily/product_type/attribute value produced by the
 * AI — whether actually written to the product or not — is also logged via
 * {@see EnrichmentProposalRecorder}: `status = 'applied'` when written,
 * `status = 'pending'` when confidence was too low to write it, or when the
 * value couldn't be converted/typed against the attribute registry (US-043).
 * A field that doesn't resolve against the taxonomy, or is guarded by a
 * manual/file/other authoritative source, never generates a proposal. An
 * attribute key absent from the registry is never written to
 * `product_attributes`, but — since US-044 — generates its own
 * `attribute_definition` proposal instead of disappearing silently, so a
 * reviewer can add it to the registry (or reject it) from the review queue.
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

    public function __construct(
        private readonly EnrichmentProposalRecorder $recorder,
        private readonly AttributeUnitConverter $converter,
    ) {}

    /**
     * Apply the classification result to the product and persist it.
     */
    public function apply(Product $product, ClassifiedProduct $result, TaxonomyCatalog $taxonomy, AttributeVocabulary $vocabulary): void
    {
        $confidence = $result->confidence ?? 0;

        // Proposed technical attributes are written independently of the
        // overall brand/family/subfamily confidence branch below: a single
        // attribute can carry its own high confidence even when the rest of
        // the classification is too weak to trust.
        $attributesForceReview = $this->applyAttributes($product, $result, $vocabulary);

        // Resolved unconditionally, even below the low-confidence threshold,
        // so every field that *would* resolve against the taxonomy is still
        // logged as a pending proposal — it just isn't written to $product.
        $attributes = $this->resolvedAttributes($product, $result, $taxonomy);

        if ($confidence < self::LOW_CONFIDENCE_THRESHOLD) {
            $this->recordResolvedProposals($product, $attributes, 'pending', $confidence);

            $product->fill(['enrichment_status' => 'needs_review'])->save();

            return;
        }

        $attributes['enrichment_status'] = ($confidence >= self::HIGH_CONFIDENCE_THRESHOLD && ! $attributesForceReview)
            ? 'enriched'
            : 'needs_review';
        $attributes['source'] = 'ai';
        $attributes['confidence'] = $confidence;

        $product->fill($attributes)->save();

        $this->recordResolvedProposals($product, $attributes, 'applied', $confidence);
    }

    /**
     * Records a proposal for each brand/family/subfamily field present in
     * `resolvedAttributes()`'s result, i.e. every field that resolved
     * against the taxonomy — regardless of whether `$status` is `applied`
     * (it was written to `$product`) or `pending` (confidence was too low).
     * Extra keys the caller may have merged in (`enrichment_status`,
     * `source`, `confidence`) are simply ignored.
     *
     * `product_type` (US-045) has no taxonomy row to reference — it is
     * logged in a dedicated branch using `value_text` instead of `value_id`.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function recordResolvedProposals(Product $product, array $attributes, string $status, int $confidence): void
    {
        foreach (['brand', 'family', 'subfamily'] as $field) {
            if (! array_key_exists("{$field}_id", $attributes)) {
                continue;
            }

            $this->recorder->record(
                product: $product,
                field: $field,
                origin: 'ai',
                status: $status,
                confidence: $confidence,
                valueId: $attributes["{$field}_id"],
            );
        }

        if (array_key_exists('product_type', $attributes)) {
            $this->recorder->record(
                product: $product,
                field: 'product_type',
                origin: 'ai',
                status: $status,
                confidence: $confidence,
                valueText: $attributes['product_type'],
            );
        }
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
     * `manual`) is never overwritten — this guard predates US-043's removal
     * of the regex extraction pass and is checked first, before anything
     * else: if it blocks the write, no proposal is recorded either.
     *
     * A key absent from {@see AttributeVocabulary} is never written to
     * `product_attributes` — the AI is instructed to only ever use canonical
     * keys, so a stray free key is not trusted for a direct write — but,
     * since US-044, it is no longer discarded outright either: it generates
     * an `attribute_definition` proposal via
     * {@see EnrichmentProposalRecorder::recordAttributeDefinitionProposal()}
     * so a reviewer can register it (or reject it) instead of the
     * fragmentation silently reappearing at the registry level.
     *
     * For a key present in the registry: below the low-confidence threshold,
     * a `pending` proposal is recorded with the value/unit exactly as read
     * from the AI (unconverted), same as before. At or above the threshold,
     * a `numeric` definition's `value_num` is converted to the canonical
     * unit via {@see AttributeUnitConverter}; a `text` definition's
     * `value_text` is written as-is (`unit` always `null`). Whenever the
     * value can't be converted ({@see UnknownAttributeUnitException}) or
     * doesn't match the definition's declared type (a `numeric` definition
     * with no `value_num`, or a `text` definition with no `value_text`), the
     * attribute is left unwritten and a `pending` proposal is recorded with
     * the original value/unit instead — mirroring the low-confidence branch,
     * without forcing the product into `needs_review`.
     *
     * @return bool whether at least one written attribute forces `needs_review`
     */
    private function applyAttributes(Product $product, ClassifiedProduct $result, AttributeVocabulary $vocabulary): bool
    {
        $forcesReview = false;

        foreach ($result->attributes as $key => $attribute) {
            if ($this->isBlockedByAuthoritativeSource($product, $key)) {
                continue;
            }

            $definition = $vocabulary->definitionFor($key);

            if ($definition === null) {
                $this->recorder->recordAttributeDefinitionProposal($product, $key, $attribute);

                continue;
            }

            $confidence = $attribute['confidence'] ?? 0;

            if ($confidence < self::LOW_CONFIDENCE_THRESHOLD) {
                $this->recordAttributeProposal($product, $key, $attribute, 'pending', $confidence);

                continue;
            }

            $converted = $this->convertedAttribute($definition, $attribute);

            if ($converted === null) {
                $this->recordAttributeProposal($product, $key, $attribute, 'pending', $confidence);

                continue;
            }

            $this->writeAttribute($product, $key, $converted, $confidence);

            $this->recordAttributeProposal($product, $key, $attribute, 'applied', $confidence);

            if ($confidence < self::HIGH_CONFIDENCE_THRESHOLD) {
                $forcesReview = true;
            }
        }

        return $forcesReview;
    }

    /**
     * Normalizes a proposed attribute against its registry definition ahead
     * of the write: a `numeric` definition converts `value_num`/`unit` to the
     * canonical unit, a `text` definition passes `value_text` through
     * unchanged with `unit` forced to `null` (never handed to the converter,
     * which would raise on a textual definition). Returns `null` when the
     * value doesn't match the definition's type (missing `value_num`/
     * `value_text`) or when the unit can't be converted, signaling the caller
     * to fall back to a `pending` proposal instead of writing.
     *
     * @param  array{value_num?: float, value_text?: string, unit?: string, confidence: int}  $attribute
     * @return array{value_num?: float, value_text?: string, unit?: string}|null
     */
    private function convertedAttribute(AttributeDefinition $definition, array $attribute): ?array
    {
        if ($definition->data_type === 'numeric') {
            if (! array_key_exists('value_num', $attribute) || $attribute['value_num'] === null) {
                return null;
            }

            try {
                $canonicalValue = $this->converter->convertToCanonical($definition, (float) $attribute['value_num'], $attribute['unit'] ?? null);
            } catch (UnknownAttributeUnitException) {
                return null;
            }

            return ['value_num' => $canonicalValue, 'unit' => $definition->canonical_unit];
        }

        if (! array_key_exists('value_text', $attribute) || $attribute['value_text'] === null) {
            return null;
        }

        return ['value_text' => $attribute['value_text'], 'unit' => null];
    }

    /**
     * Records a proposal for a technical attribute, always with the
     * value/unit exactly as read from the AI — never the converted
     * canonical value — so the proposal audit trail reflects what the AI
     * actually reported (US-043).
     *
     * @param  array{value_num?: float, value_text?: string, unit?: string, confidence: int}  $attribute
     */
    private function recordAttributeProposal(Product $product, string $key, array $attribute, string $status, int $confidence): void
    {
        $this->recorder->record(
            product: $product,
            field: 'attribute',
            origin: 'ai',
            status: $status,
            confidence: $confidence,
            attributeKey: $key,
            valueNum: $attribute['value_num'] ?? null,
            valueText: $attribute['value_text'] ?? null,
            unit: $attribute['unit'] ?? null,
        );
    }

    /**
     * Whether `$key` already carries a value from a source more authoritative
     * than the AI (i.e. anything other than `null`, `regex`, or `ai`), which
     * must block both the write and the proposal.
     */
    private function isBlockedByAuthoritativeSource(Product $product, string $key): bool
    {
        $existing = $product->attributes()->where('key', $key)->first();

        return $existing !== null && $existing->source !== null && $existing->source !== 'regex' && $existing->source !== 'ai';
    }

    /**
     * @param  array{value_num?: float, value_text?: string, unit?: string, confidence: int}  $attribute
     */
    private function writeAttribute(Product $product, string $key, array $attribute, int $confidence): void
    {
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
    }

    /**
     * Resolve brand/family/subfamily against the taxonomy and build the
     * attributes to persist, skipping any field already set manually or
     * whose AI value doesn't resolve to an existing taxonomy record.
     *
     * `product_type` (US-045) is a plain text value with no taxonomy to
     * resolve against and no `*_source` guard: it always overwrites any
     * previous value once confidence clears the write threshold, so a
     * reclassification keeps it in sync with the AI's current answer.
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

        if ($result->productType !== null) {
            $attributes['product_type'] = $result->productType;
        }

        return $attributes;
    }
}
