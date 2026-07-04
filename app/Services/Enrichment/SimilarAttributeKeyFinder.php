<?php

namespace App\Services\Enrichment;

use App\Models\AttributeDefinition;
use App\Services\Ai\AttributeVocabulary;
use App\Services\Ai\TaxonomyCatalog;
use Illuminate\Support\Collection;

/**
 * US-044: assists the human review of a proposed `attribute_definition`
 * (AC2) by surfacing the existing registry keys most likely to already cover
 * the same concept as the proposed one — e.g. `portata_lmin` proposed
 * against an existing `portata_l_min` — so the reviewer can catch a
 * near-duplicate before it fragments the registry.
 *
 * The registry ({@see AttributeDefinition}) holds at most a few dozen rows,
 * so an embedding-based semantic search (as used for products, EP-009) would
 * be disproportionate: normalized Levenshtein distance, computed in memory
 * against every definition, is enough for a human-assisted equivalence
 * check. Definitions are lazily loaded and memoized per instance, same spirit
 * as {@see AttributeVocabulary} and
 * {@see TaxonomyCatalog}.
 */
class SimilarAttributeKeyFinder
{
    /**
     * Default number of closest keys returned by {@see find()}.
     */
    private const DEFAULT_LIMIT = 5;

    /**
     * @var Collection<int, AttributeDefinition>|null
     */
    private ?Collection $definitions = null;

    /**
     * Returns the `$limit` registered definitions whose `key` is closest to
     * `$key` by normalized Levenshtein distance (0 = identical, ascending
     * order = closest first), each shaped as
     * `['key' => ..., 'data_type' => ..., 'canonical_unit' => ...,
     * 'description' => ...]`. Returns an empty collection when the registry
     * is empty, without raising.
     *
     * @return Collection<int, array{key: string, data_type: string, canonical_unit: ?string, description: ?string}>
     */
    public function find(string $key, int $limit = self::DEFAULT_LIMIT): Collection
    {
        $normalizedKey = trim($key);

        return $this->definitions()
            ->sortBy(fn (AttributeDefinition $definition): float => $this->normalizedDistance($normalizedKey, $definition->key))
            ->take($limit)
            ->map(fn (AttributeDefinition $definition): array => [
                'key' => $definition->key,
                'data_type' => $definition->data_type,
                'canonical_unit' => $definition->canonical_unit,
                'description' => $definition->description,
            ])
            ->values();
    }

    /**
     * Levenshtein edit distance normalized by the longer of the two strings'
     * length, so the result is comparable across key pairs of different
     * lengths (0 = identical, 1 = maximally different).
     */
    private function normalizedDistance(string $a, string $b): float
    {
        $maxLength = max(strlen($a), strlen($b));

        if ($maxLength === 0) {
            return 0.0;
        }

        return levenshtein($a, $b) / $maxLength;
    }

    /**
     * @return Collection<int, AttributeDefinition>
     */
    private function definitions(): Collection
    {
        return $this->definitions ??= AttributeDefinition::all();
    }
}
