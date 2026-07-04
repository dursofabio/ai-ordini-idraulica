<?php

namespace App\Services\Ai;

use App\Exceptions\InvalidQueryParseResponseException;
use App\Models\AttributeDefinition;
use App\Services\Enrichment\AttributeUnitConverter;
use App\Services\Enrichment\UnknownAttributeUnitException;
use App\Services\Search\AppliedAttributeFilter;
use App\Services\Search\ParsedSearchQuery;
use App\Services\Search\QueryParser;
use Illuminate\Support\Facades\Log;

/**
 * Parses and validates a query-parse response from the Anthropic Messages
 * API against the schema expected by {@see QueryParsePromptBuilder} and the
 * closed attribute registry ({@see AttributeDefinitionCatalog}).
 *
 * A response is only accepted when it is syntactically valid JSON and
 * carries a `recognized_text` string; anything else raises
 * {@see InvalidQueryParseResponseException} so the caller
 * ({@see QueryParser}) can fall back to whole-text
 * search. Individual attribute entries are treated far more leniently
 * (US-048 AC3 — "no invented filter", never "no result at all"): a key
 * outside the registry, or a numeric value in a unit the registry doesn't
 * recognize, silently drops just that one attribute instead of failing the
 * whole parse.
 */
class QueryParseResponseValidator
{
    public function __construct(
        private readonly AttributeUnitConverter $converter = new AttributeUnitConverter,
    ) {}

    /**
     * @throws InvalidQueryParseResponseException
     */
    public function validate(ClaudeResponse $response, AttributeDefinitionCatalog $catalog): ParsedSearchQuery
    {
        $decoded = json_decode($this->stripMarkdownFences($response->content), associative: true);

        if (! is_array($decoded)) {
            throw new InvalidQueryParseResponseException('La risposta AI non è un JSON valido.');
        }

        if (! isset($decoded['recognized_text']) || ! is_string($decoded['recognized_text'])) {
            throw new InvalidQueryParseResponseException('La risposta AI non contiene "recognized_text".');
        }

        $rawAttributes = is_array($decoded['attributes'] ?? null) ? $decoded['attributes'] : [];

        $filters = collect($rawAttributes)
            ->map(fn (mixed $rawAttribute): ?AppliedAttributeFilter => $this->toAppliedFilter($rawAttribute, $catalog))
            ->filter()
            ->values()
            ->all();

        return new ParsedSearchQuery(recognizedText: $decoded['recognized_text'], appliedFilters: $filters);
    }

    private function toAppliedFilter(mixed $rawAttribute, AttributeDefinitionCatalog $catalog): ?AppliedAttributeFilter
    {
        if (! is_array($rawAttribute)) {
            return null;
        }

        $key = $rawAttribute['key'] ?? null;

        if (! is_string($key) || trim($key) === '') {
            return null;
        }

        $definition = $catalog->find($key);

        if ($definition === null) {
            return null;
        }

        return $definition->data_type === 'numeric'
            ? $this->numericFilter($definition, $rawAttribute)
            : $this->textFilter($definition, $rawAttribute);
    }

    private function numericFilter(AttributeDefinition $definition, array $rawAttribute): ?AppliedAttributeFilter
    {
        $unit = $this->nullableString($rawAttribute['unit'] ?? null);
        $valueNum = $rawAttribute['value_num'] ?? null;
        $min = $rawAttribute['min'] ?? null;
        $max = $rawAttribute['max'] ?? null;

        try {
            if (is_numeric($valueNum)) {
                $canonicalValue = $this->converter->convertToCanonical($definition, (float) $valueNum, $unit);

                return new AppliedAttributeFilter(
                    key: $definition->key,
                    label: $this->label($definition),
                    unit: $definition->canonical_unit,
                    min: $canonicalValue,
                    max: $canonicalValue,
                );
            }

            if (! is_numeric($min) && ! is_numeric($max)) {
                return null;
            }

            return new AppliedAttributeFilter(
                key: $definition->key,
                label: $this->label($definition),
                unit: $definition->canonical_unit,
                min: is_numeric($min) ? $this->converter->convertToCanonical($definition, (float) $min, $unit) : null,
                max: is_numeric($max) ? $this->converter->convertToCanonical($definition, (float) $max, $unit) : null,
            );
        } catch (UnknownAttributeUnitException $e) {
            Log::warning('Attributo scartato dal parsing query: unità sconosciuta.', [
                'key' => $definition->key,
                'unit' => $unit,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function textFilter(AttributeDefinition $definition, array $rawAttribute): ?AppliedAttributeFilter
    {
        $valueText = $this->nullableString($rawAttribute['value_text'] ?? null);

        if ($valueText === null) {
            return null;
        }

        return new AppliedAttributeFilter(
            key: $definition->key,
            label: $this->label($definition),
            unit: null,
            value: $valueText,
        );
    }

    private function label(AttributeDefinition $definition): string
    {
        $description = $this->nullableString($definition->description);

        return $description ?? $definition->key;
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
