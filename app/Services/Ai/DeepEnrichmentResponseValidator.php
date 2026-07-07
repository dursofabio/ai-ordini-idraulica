<?php

namespace App\Services\Ai;

use App\Exceptions\InvalidDeepEnrichmentResponseException;
use Illuminate\Support\Facades\Log;

/**
 * Parses and validates a deep-enrichment response from the Anthropic
 * Messages API against the schema expected by
 * {@see DeepEnrichmentPromptBuilder}.
 *
 * Unlike {@see ClassificationResponseValidator} (which tolerates per-item
 * hallucinations in a batch by dropping just that field), this validator is
 * strict: since a deep enrichment call always targets a single, higher-value
 * product, any structural problem — invalid JSON, a missing/out-of-range
 * confidence, an empty extended description, or a malformed attribute entry
 * — invalidates the whole response so the caller can retry or fall back to
 * `needs_review`.
 *
 * An attribute proposing a key that duplicates a field the product already
 * carries outside `product_attributes` (its article code, description, or
 * taxonomy) is a content hallucination, not a structural problem: it is
 * dropped on its own (with a warning log), same leniency as
 * {@see ClassificationResponseValidator::taxonomyValueOrNull()}, rather than
 * invalidating an otherwise-valid response over one bad attribute.
 */
class DeepEnrichmentResponseValidator
{
    /**
     * Attribute keys that must never be accepted, because they duplicate a
     * field the product already carries outside `product_attributes` —
     * proposing one of these as a technical attribute would let a reviewer
     * overwrite the product's own identity/description/taxonomy fields
     * through the attribute-proposal pipeline instead of the dedicated one.
     * Matched case-insensitively since the AI is free-form on key naming.
     */
    private const RESERVED_ATTRIBUTE_KEYS = [
        'codice_articolo',
        'descrizione',
        'descrizione_estesa',
        'tipo_prodotto',
        'product_type',
        'marca',
        'brand',
        'famiglia',
        'family',
        'sottofamiglia',
        'subfamily',
    ];

    /**
     * @throws InvalidDeepEnrichmentResponseException
     */
    public function validate(ClaudeResponse $response, string $expectedCodiceArticolo): DeepEnrichedProduct
    {
        $decoded = json_decode($this->stripMarkdownFences($response->content), associative: true);

        if (! is_array($decoded)) {
            throw new InvalidDeepEnrichmentResponseException('La risposta AI non è un JSON valido.');
        }

        $confidence = $decoded['confidence'] ?? null;

        if (! is_numeric($confidence) || (int) $confidence < 0 || (int) $confidence > 100) {
            throw new InvalidDeepEnrichmentResponseException('La risposta AI non contiene una confidence complessiva valida (0-100).');
        }

        $rawDescription = $decoded['descrizione_estesa'] ?? null;

        if ($rawDescription !== null && ! is_string($rawDescription)) {
            throw new InvalidDeepEnrichmentResponseException('Il campo "descrizione_estesa" deve essere una stringa o null.');
        }

        $extendedDescription = $this->nullableString($rawDescription);

        if (is_string($rawDescription) && $extendedDescription === null) {
            throw new InvalidDeepEnrichmentResponseException('Il campo "descrizione_estesa" non può essere una stringa vuota.');
        }

        $rawProductType = $decoded['tipo_prodotto'] ?? null;

        if ($rawProductType !== null && ! is_string($rawProductType)) {
            throw new InvalidDeepEnrichmentResponseException('Il campo "tipo_prodotto" deve essere una stringa o null.');
        }

        $productType = $this->nullableString($rawProductType);

        if (is_string($rawProductType) && $productType === null) {
            throw new InvalidDeepEnrichmentResponseException('Il campo "tipo_prodotto" non può essere una stringa vuota.');
        }

        return new DeepEnrichedProduct(
            codiceArticolo: $expectedCodiceArticolo,
            extendedDescription: $extendedDescription,
            productType: $productType,
            confidence: (int) $confidence,
            attributes: $this->parseAttributes($decoded),
        );
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array<string, array{value: string, unit?: string, confidence: int}>
     *
     * @throws InvalidDeepEnrichmentResponseException
     */
    private function parseAttributes(array $decoded): array
    {
        $rawAttributes = $decoded['attributes'] ?? [];

        if (! is_array($rawAttributes)) {
            throw new InvalidDeepEnrichmentResponseException('Il campo "attributes" non è un array valido.');
        }

        $attributes = [];

        foreach ($rawAttributes as $rawAttribute) {
            if (! is_array($rawAttribute)) {
                throw new InvalidDeepEnrichmentResponseException('Un elemento di "attributes" non è un oggetto valido.');
            }

            $key = $rawAttribute['key'] ?? null;

            if (! is_string($key) || trim($key) === '') {
                throw new InvalidDeepEnrichmentResponseException('Un attributo non ha una chiave valida.');
            }

            $key = trim($key);

            if (in_array(strtolower($key), self::RESERVED_ATTRIBUTE_KEYS, true)) {
                Log::warning('Attributo con chiave riservata scartato dalla risposta di deep enrichment.', [
                    'key' => $key,
                ]);

                continue;
            }

            $attributeConfidence = $rawAttribute['confidence'] ?? null;

            if (! is_numeric($attributeConfidence) || (int) $attributeConfidence < 0 || (int) $attributeConfidence > 100) {
                throw new InvalidDeepEnrichmentResponseException("L'attributo \"{$key}\" non ha una confidence valida (0-100).");
            }

            $value = $this->attributeValueToString($rawAttribute['value'] ?? null);

            if ($value === null) {
                throw new InvalidDeepEnrichmentResponseException("L'attributo \"{$key}\" non ha alcun valore (value).");
            }

            $attribute = ['confidence' => (int) $attributeConfidence, 'value' => $value];

            $unit = $this->nullableString($rawAttribute['unit'] ?? null);

            if ($unit !== null) {
                $attribute['unit'] = $unit;
            }

            $attributes[$key] = $attribute;
        }

        return $attributes;
    }

    /**
     * Strips a leading/trailing ```` ```json ... ``` ```` (or plain ``` ```)
     * fence some models wrap the JSON in, mirroring
     * {@see ClassificationResponseValidator::stripMarkdownFences()}.
     */
    private function stripMarkdownFences(string $content): string
    {
        $trimmed = trim($content);

        if (! str_starts_with($trimmed, '```')) {
            return $trimmed;
        }

        $trimmed = preg_replace('/^```[a-zA-Z]*\s*/', '', $trimmed) ?? $trimmed;
        $trimmed = preg_replace('/\s*```$/', '', $trimmed) ?? $trimmed;

        return trim($trimmed);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * The prompt asks for `value` as a JSON string, but tolerates the model
     * emitting a bare JSON number instead of following that instruction.
     */
    private function attributeValueToString(mixed $value): ?string
    {
        if (is_string($value)) {
            return $this->nullableString($value);
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }
}
