<?php

namespace App\Services\Enrichment;

use App\Models\AttributeDefinition;
use LogicException;

/**
 * Deterministic value+unit → canonical-unit conversion, driven entirely by
 * the multiplicative factors in {@see AttributeDefinition::$accepted_units}
 * (US-042). Unit matching is case-insensitive after trimming; the
 * definition's `canonical_unit` is always accepted at factor 1, even when
 * omitted from `accepted_units`.
 *
 * This service is the contract US-043's AI-only extraction will use to
 * normalize extracted values — the AI never performs conversion arithmetic
 * itself. The service is stateless and dependency-free: looking up the
 * definition for a given key is left to the caller, so a batch process can
 * load the whole registry once.
 */
class AttributeUnitConverter
{
    /**
     * Converts `$value`, expressed in `$unit`, to the definition's canonical
     * unit.
     *
     * - An `$unit` matching the canonical unit or one of `accepted_units`
     *   (case-insensitive, trimmed) returns `$value * factor`.
     * - A `null` `$unit` on a numeric definition is assumed to already be in
     *   the canonical unit and is returned unchanged (factor 1) — this
     *   favors legacy rows that carry no unit.
     * - An unrecognized unit never results in a silent write: it throws
     *   {@see UnknownAttributeUnitException}.
     *
     * @throws UnknownAttributeUnitException when the unit is not recognized
     * @throws LogicException when called on a textual attribute definition
     */
    public function convertToCanonical(AttributeDefinition $definition, float $value, ?string $unit): float
    {
        if ($definition->data_type !== 'numeric') {
            throw new LogicException("La conversione non è definita per l'attributo testuale [{$definition->key}].");
        }

        if ($unit === null) {
            return $value;
        }

        $factor = $this->resolveFactor($definition, $unit);

        if ($factor === null) {
            throw UnknownAttributeUnitException::forUnit($definition->key, $unit);
        }

        return $value * $factor;
    }

    private function resolveFactor(AttributeDefinition $definition, string $unit): ?float
    {
        $normalizedUnit = mb_strtolower(trim($unit));

        if ($definition->canonical_unit !== null && mb_strtolower(trim($definition->canonical_unit)) === $normalizedUnit) {
            return 1.0;
        }

        /** @var array<string, float|int> $acceptedUnits */
        $acceptedUnits = $definition->accepted_units ?? [];

        foreach ($acceptedUnits as $acceptedUnit => $factor) {
            if (mb_strtolower(trim((string) $acceptedUnit)) === $normalizedUnit) {
                return (float) $factor;
            }
        }

        return null;
    }
}
