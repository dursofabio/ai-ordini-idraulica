<?php

namespace App\Services\Ai;

use App\Models\Brand;
use App\Models\Family;
use App\Models\Subfamily;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Closed vocabulary of existing brands, families, and subfamilies used to
 * constrain AI classification: only values already present in the catalog
 * taxonomy may be assigned to a product. Brand/Family/Subfamily collections
 * are lazily loaded and memoized per instance, so a batch job can reuse one
 * catalog across many products without re-querying.
 */
class TaxonomyCatalog
{
    /**
     * @var Collection<int, Brand>|null
     */
    private ?Collection $brands = null;

    /**
     * @var Collection<int, Family>|null
     */
    private ?Collection $families = null;

    /**
     * @var Collection<int, Subfamily>|null
     */
    private ?Collection $subfamilies = null;

    /**
     * Whether the given brand name matches an existing brand's name, slug,
     * or alias, case-insensitively.
     */
    public function isValidBrand(string $name): bool
    {
        return $this->matches($this->brands(), $name);
    }

    /**
     * Whether the given family name matches an existing family's name,
     * slug, or alias, case-insensitively.
     */
    public function isValidFamily(string $name): bool
    {
        return $this->matches($this->families(), $name);
    }

    /**
     * Whether the given subfamily name matches an existing subfamily's name,
     * slug, or alias, case-insensitively. When `$familyName` is provided,
     * the match is additionally scoped to subfamilies belonging to that
     * family.
     */
    public function isValidSubfamily(string $name, ?string $familyName = null): bool
    {
        $subfamilies = $this->subfamilies();

        if ($familyName !== null) {
            $family = $this->find($this->families(), $familyName);

            if ($family === null) {
                return false;
            }

            $subfamilies = $subfamilies->where('family_id', $family->id);
        }

        return $this->matches($subfamilies, $name);
    }

    /**
     * Find the existing brand matching the given name, slug, or alias,
     * case-insensitively.
     */
    public function findBrand(string $name): ?Brand
    {
        return $this->find($this->brands(), $name);
    }

    /**
     * Find the existing family matching the given name, slug, or alias,
     * case-insensitively.
     */
    public function findFamily(string $name): ?Family
    {
        return $this->find($this->families(), $name);
    }

    /**
     * Find the existing subfamily matching the given name, slug, or alias,
     * case-insensitively. When `$familyName` is provided, the lookup is
     * additionally scoped to subfamilies belonging to that family.
     */
    public function findSubfamily(string $name, ?string $familyName = null): ?Subfamily
    {
        $subfamilies = $this->subfamilies();

        if ($familyName !== null) {
            $family = $this->find($this->families(), $familyName);

            if ($family === null) {
                return null;
            }

            $subfamilies = $subfamilies->where('family_id', $family->id);
        }

        return $this->find($subfamilies, $name);
    }

    /**
     * Render the closed taxonomy as human-readable text suitable for
     * inclusion in an AI prompt, grouping subfamilies under their family.
     */
    public function toPromptText(): string
    {
        $brandNames = $this->brands()->pluck('name')->implode(', ');

        $familyLines = $this->families()->map(function (Family $family): string {
            $subfamilyNames = $this->subfamilies()
                ->where('family_id', $family->id)
                ->pluck('name')
                ->implode(', ');

            return $subfamilyNames === ''
                ? "- {$family->name}"
                : "- {$family->name}: {$subfamilyNames}";
        })->implode("\n");

        return "Marche disponibili: {$brandNames}\n\nFamiglie e sottofamiglie disponibili:\n{$familyLines}";
    }

    /**
     * @return Collection<int, Brand>
     */
    private function brands(): Collection
    {
        return $this->brands ??= Brand::all(['id', 'name', 'slug', 'aliases']);
    }

    /**
     * @return Collection<int, Family>
     */
    private function families(): Collection
    {
        return $this->families ??= Family::all(['id', 'name', 'slug', 'aliases']);
    }

    /**
     * @return Collection<int, Subfamily>
     */
    private function subfamilies(): Collection
    {
        return $this->subfamilies ??= Subfamily::all(['id', 'name', 'slug', 'family_id', 'aliases']);
    }

    /**
     * @param  Collection<int, Brand|Family|Subfamily>  $items
     */
    private function matches(Collection $items, string $name): bool
    {
        return $this->find($items, $name) !== null;
    }

    /**
     * Matches against `name` and `slug` first, then falls back to `aliases`
     * (US-032): `catalog:seed-taxonomy` stores the raw ERP code — e.g. a
     * two-digit brand code, a short family code — as an alias rather than as
     * `name`/`slug`, so a code-based lookup only succeeds once aliases are
     * considered too.
     *
     * @template TModel of Brand|Family|Subfamily
     *
     * @param  Collection<int, TModel>  $items
     * @return TModel|null
     */
    private function find(Collection $items, string $name): Brand|Family|Subfamily|null
    {
        return $items->first(
            fn (Brand|Family|Subfamily $item): bool => $this->sameToken($item->name, $name)
                || $this->sameToken($item->slug, $name)
                || $this->matchesAlias($item, $name)
        );
    }

    private function matchesAlias(Brand|Family|Subfamily $item, string $name): bool
    {
        foreach ($item->aliases ?? [] as $alias) {
            if ($this->sameToken($alias, $name)) {
                return true;
            }
        }

        return false;
    }

    private function sameToken(string $a, string $b): bool
    {
        return Str::lower(trim($a)) === Str::lower(trim($b));
    }
}
