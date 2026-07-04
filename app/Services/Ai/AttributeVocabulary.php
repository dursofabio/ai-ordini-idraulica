<?php

namespace App\Services\Ai;

use App\Models\AttributeDefinition;
use Illuminate\Database\Eloquent\Collection;

/**
 * Closed vocabulary of technical attribute keys used to constrain AI
 * extraction (US-043): only keys already registered in
 * {@see AttributeDefinition} (the central registry introduced by US-042) may
 * be assigned to a product's `product_attributes`. Speculative to
 * {@see TaxonomyCatalog}: definitions are lazily loaded and memoized per
 * instance, so a batch job can reuse one vocabulary across many products
 * without re-querying.
 */
class AttributeVocabulary
{
    /**
     * @var Collection<int, AttributeDefinition>|null
     */
    private ?Collection $definitions = null;

    /**
     * Render the registry as human-readable text suitable for inclusion in
     * an AI prompt, one line per definition in the format
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
     * Find the registered definition matching `$key` exactly (case-sensitive,
     * after trimming whitespace). Returns `null` for any key not present in
     * the registry — no fuzzy matching: the prompt is responsible for
     * imposing canonical keys, not this lookup.
     */
    public function definitionFor(string $key): ?AttributeDefinition
    {
        $normalizedKey = trim($key);

        return $this->definitions()->first(
            fn (AttributeDefinition $definition): bool => $definition->key === $normalizedKey
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
