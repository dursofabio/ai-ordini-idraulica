<?php

namespace App\Services\Ai;

use App\Models\AttributeDefinition;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Closed vocabulary of registered attribute keys used to constrain the
 * natural-language query parser (US-048): only keys already present in
 * {@see AttributeDefinition} (the central registry introduced by US-042) may
 * become a hard filter. Twin of {@see TaxonomyCatalog}/{@see AttributeVocabulary}:
 * definitions are lazily loaded and memoized per instance, so a single
 * request can reuse one catalog without re-querying. Unlike
 * {@see AttributeVocabulary::definitionFor()} (exact-key match, used to
 * anchor AI-authored classification output), {@see self::find()} is
 * case-insensitive: it resolves a key coming back from the query-parse model,
 * which is not guaranteed to preserve the registry's exact casing.
 */
class AttributeDefinitionCatalog
{
    /**
     * @var Collection<int, AttributeDefinition>|null
     */
    private ?Collection $definitions = null;

    /**
     * Render the closed registry as human-readable text suitable for
     * inclusion in an AI prompt, one line per definition in the format
     * `- chiave | tipo | unità canonica | descrizione`.
     */
    public function toPromptText(): string
    {
        return $this->definitions()
            ->map(function (AttributeDefinition $definition): string {
                $type = $definition->data_type === 'numeric' ? 'numerico' : 'testo libero';
                $unit = $definition->canonical_unit ?? 'testo libero';

                return "- {$definition->key} | {$type} | {$unit} | {$definition->description}";
            })
            ->implode("\n");
    }

    /**
     * Find the registered definition matching `$key`, case-insensitively
     * after trimming whitespace. Returns `null` for any key not present in
     * the registry.
     */
    public function find(string $key): ?AttributeDefinition
    {
        $normalizedKey = Str::lower(trim($key));

        return $this->definitions()->first(
            fn (AttributeDefinition $definition): bool => Str::lower($definition->key) === $normalizedKey
        );
    }

    /**
     * @return Collection<int, AttributeDefinition>
     */
    private function definitions(): Collection
    {
        return $this->definitions ??= AttributeDefinition::all();
    }
}
