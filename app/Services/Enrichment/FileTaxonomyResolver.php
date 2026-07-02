<?php

namespace App\Services\Enrichment;

use App\Models\Brand;
use App\Models\Family;
use App\Models\Product;
use App\Models\Subfamily;
use App\Services\Ai\TaxonomyCatalog;

/**
 * Links brand_id/family_id/subfamily_id directly from the raw Marca/Fam/S.Fam
 * values already carried by the import file (US-032), before any textual
 * deduction, clustering, or family propagation runs: a code or label that is
 * already an existing taxonomy entry is a strictly stronger signal than
 * deducing it from free text, so it always takes priority and must run first
 * in {@see DeterministicEnrichmentPipeline}.
 *
 * For each field, the raw code is tried before the raw label (e.g.
 * `marca_codice` before `descrizione_marca`) against {@see TaxonomyCatalog},
 * which matches by name or slug case-insensitively. The subfamily lookup is
 * scoped to the family resolved from the same row, so a same-named
 * subfamily under an unrelated family is never picked.
 *
 * Only writes a field that is still null (idempotency guard identical to
 * {@see BrandResolver}), and always sets `*_source = 'file'` so downstream
 * resolvers and AI classification never overwrite it (guarded in
 * {@see EnrichmentApplier}).
 */
class FileTaxonomyResolver
{
    /**
     * Confidence assigned when the brand is linked directly from the file:
     * this is a certain source, not a deduction, so it outranks every other
     * brand_source confidence value.
     */
    private const BRAND_CONFIDENCE = 100;

    public function __construct(
        private readonly TaxonomyCatalog $taxonomy,
    ) {}

    /**
     * Attempt to link brand/family/subfamily from the product's raw taxonomy
     * columns and persist whatever matched.
     *
     * @return array{brand: bool, family: bool, subfamily: bool}
     */
    public function resolve(Product $product): array
    {
        $linked = ['brand' => false, 'family' => false, 'subfamily' => false];
        $attributes = [];

        $brand = null;

        if ($product->brand_id === null) {
            $brand = $this->matchByCodeOrLabel(
                $product->marca_codice,
                $product->descrizione_marca,
                fn (string $value): ?Brand => $this->taxonomy->findBrand($value),
            );

            if ($brand !== null) {
                $attributes['brand_id'] = $brand->id;
                $attributes['brand_source'] = 'file';
                $attributes['confidence'] = self::BRAND_CONFIDENCE;
                $linked['brand'] = true;
            }
        }

        $family = null;

        if ($product->family_id === null) {
            $family = $this->matchByCodeOrLabel(
                $product->fam_codice,
                $product->fam_descrizione,
                fn (string $value): ?Family => $this->taxonomy->findFamily($value),
            );

            if ($family !== null) {
                $attributes['family_id'] = $family->id;
                $attributes['family_source'] = 'file';
                $linked['family'] = true;
            }
        }

        if ($product->subfamily_id === null) {
            $familyName = $family?->name ?? $product->fam_descrizione ?? $product->fam_codice;

            if ($familyName !== null) {
                $subfamily = $this->matchByCodeOrLabel(
                    $product->subfam_codice,
                    $product->subfam_descrizione,
                    fn (string $value): ?Subfamily => $this->taxonomy->findSubfamily($value, $familyName),
                );

                if ($subfamily !== null) {
                    $attributes['subfamily_id'] = $subfamily->id;
                    $attributes['subfamily_source'] = 'file';
                    $linked['subfamily'] = true;
                }
            }
        }

        if ($attributes !== []) {
            $product->fill($attributes)->save();
        }

        return $linked;
    }

    /**
     * Tries the raw code, then the raw label, against the given taxonomy
     * lookup, returning the first match. Blank values are skipped.
     *
     * @template TModel of Brand|Family|Subfamily
     *
     * @param  callable(string): (TModel|null)  $lookup
     * @return TModel|null
     */
    private function matchByCodeOrLabel(?string $code, ?string $label, callable $lookup): Brand|Family|Subfamily|null
    {
        foreach ([$code, $label] as $candidate) {
            $candidate = trim((string) $candidate);

            if ($candidate === '') {
                continue;
            }

            $match = $lookup($candidate);

            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }
}
