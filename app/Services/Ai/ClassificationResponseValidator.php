<?php

namespace App\Services\Ai;

use App\Exceptions\InvalidClassificationResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Parses and validates a batch classification response from the Anthropic
 * Messages API against the schema expected by
 * {@see ClassificationPromptBuilder} and the closed catalog taxonomy.
 *
 * A response is only accepted when it is syntactically valid JSON and has a
 * `results` array with exactly one entry per requested codice_articolo;
 * anything else raises {@see InvalidClassificationResponseException} so the
 * caller can retry or fall back to `needs_review` — a structural failure
 * means the whole response is untrustworthy.
 *
 * A brand/family/subfamily value outside the closed taxonomy, however, is a
 * per-product hallucination, not a structural failure: it is dropped to
 * `null` (with a warning log) exactly as if the model had followed the
 * prompt's own "se non sei sicuro, lascia il campo a null" rule, and the
 * rest of that product's result — and of the batch — survives. Failing the
 * whole batch here used to send up to 50 innocent products to
 * `needs_review` because of a single invented subfamily, which free-tier
 * models produce routinely. Malformed attribute entries get the same
 * per-entry leniency (see {@see self::parseAttributes()}).
 */
class ClassificationResponseValidator
{
    /**
     * @param  Collection<int, string>  $expectedCodiciArticolo
     *
     * @throws InvalidClassificationResponseException
     */
    public function validate(ClaudeResponse $response, Collection $expectedCodiciArticolo, TaxonomyCatalog $taxonomy): ValidatedClassification
    {
        $decoded = json_decode($this->stripMarkdownFences($response->content), associative: true);

        if (! is_array($decoded)) {
            throw new InvalidClassificationResponseException('La risposta AI non è un JSON valido.');
        }

        if (! isset($decoded['results']) || ! is_array($decoded['results'])) {
            throw new InvalidClassificationResponseException('La risposta AI non contiene un array "results".');
        }

        $results = collect();

        foreach ($decoded['results'] as $item) {
            $classified = $this->toClassifiedProduct($item, $taxonomy);

            $results->put($classified->codiceArticolo, $classified);
        }

        $missing = $expectedCodiciArticolo->diff($results->keys());

        if ($missing->isNotEmpty()) {
            throw new InvalidClassificationResponseException(
                'La risposta AI non include un risultato per: '.$missing->implode(', ').'.'
            );
        }

        return new ValidatedClassification($results);
    }

    private function toClassifiedProduct(mixed $item, TaxonomyCatalog $taxonomy): ClassifiedProduct
    {
        if (! is_array($item)) {
            throw new InvalidClassificationResponseException('Un elemento di "results" non è un oggetto valido.');
        }

        $codiceArticolo = $item['codice_articolo'] ?? null;

        if (! is_string($codiceArticolo) || $codiceArticolo === '') {
            throw new InvalidClassificationResponseException('Un elemento di "results" non ha un codice_articolo valido.');
        }

        $brand = $this->taxonomyValueOrNull(
            $this->nullableString($item['brand'] ?? null),
            fn (string $value): bool => $taxonomy->isValidBrand($value),
            'marca',
            $codiceArticolo,
        );

        $family = $this->taxonomyValueOrNull(
            $this->nullableString($item['family'] ?? null),
            fn (string $value): bool => $taxonomy->isValidFamily($value),
            'famiglia',
            $codiceArticolo,
        );

        // Scoped to the *surviving* family: if the family was just dropped as
        // invented, the subfamily is checked globally, mirroring how
        // EnrichmentApplier will later resolve it via findSubfamily().
        $subfamily = $this->taxonomyValueOrNull(
            $this->nullableString($item['subfamily'] ?? null),
            fn (string $value): bool => $taxonomy->isValidSubfamily($value, $family),
            'sottofamiglia',
            $codiceArticolo,
        );

        $confidence = $item['confidence'] ?? null;

        return new ClassifiedProduct(
            codiceArticolo: $codiceArticolo,
            brand: $brand,
            family: $family,
            subfamily: $subfamily,
            productType: $this->nullableString($item['product_type'] ?? null),
            enrichedDescription: $this->nullableString($item['enriched_description'] ?? null),
            confidence: is_numeric($confidence) ? (int) $confidence : null,
            attributes: $this->parseAttributes($item),
        );
    }

    /**
     * Returns `$value` when it belongs to the closed taxonomy according to
     * `$isValid`, or `null` when the model invented it — logging the dropped
     * value so hallucination frequency stays observable per model. `null` in,
     * `null` out.
     *
     * @param  callable(string): bool  $isValid
     */
    private function taxonomyValueOrNull(?string $value, callable $isValid, string $fieldLabel, string $codiceArticolo): ?string
    {
        if ($value === null || $isValid($value)) {
            return $value;
        }

        Log::warning('Valore fuori tassonomia scartato dalla classificazione AI.', [
            'field' => $fieldLabel,
            'value' => $value,
            'codice_articolo' => $codiceArticolo,
        ]);

        return null;
    }

    /**
     * Parses the optional per-result "attributes" array with free-form,
     * non-taxonomy-bound keys. Unlike brand/family/subfamily, a malformed
     * attribute entry (missing/empty key, non-numeric or out-of-range
     * confidence, or no value_num/value_text at all) is silently dropped
     * instead of invalidating the whole classification result — a bad
     * attribute proposal shouldn't cost the product its brand/family match.
     *
     * @return array<string, array{value_num?: float, value_text?: string, unit?: string, confidence: int}>
     */
    private function parseAttributes(mixed $item): array
    {
        $rawAttributes = is_array($item) ? ($item['attributes'] ?? null) : null;

        if (! is_array($rawAttributes)) {
            return [];
        }

        $attributes = [];

        foreach ($rawAttributes as $rawAttribute) {
            if (! is_array($rawAttribute)) {
                continue;
            }

            $key = $rawAttribute['key'] ?? null;

            if (! is_string($key) || trim($key) === '') {
                continue;
            }

            $confidence = $rawAttribute['confidence'] ?? null;

            if (! is_numeric($confidence) || (int) $confidence < 0 || (int) $confidence > 100) {
                continue;
            }

            $valueNum = $rawAttribute['value_num'] ?? null;
            $valueText = $this->nullableString($rawAttribute['value_text'] ?? null);

            if (! is_numeric($valueNum) && $valueText === null) {
                continue;
            }

            $attribute = ['confidence' => (int) $confidence];

            if (is_numeric($valueNum)) {
                $attribute['value_num'] = (float) $valueNum;
            }

            if ($valueText !== null) {
                $attribute['value_text'] = $valueText;
            }

            $unit = $this->nullableString($rawAttribute['unit'] ?? null);

            if ($unit !== null) {
                $attribute['unit'] = $unit;
            }

            $attributes[trim($key)] = $attribute;
        }

        return $attributes;
    }

    /**
     * Strips a leading/trailing ```` ```json ... ``` ```` (or plain ``` ```)
     * fence some models wrap the JSON in despite the prompt asking for raw
     * JSON only, so json_decode() below doesn't choke on the fence markers.
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
}
