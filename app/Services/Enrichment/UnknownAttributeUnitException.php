<?php

namespace App\Services\Enrichment;

use RuntimeException;

/**
 * Thrown by {@see AttributeUnitConverter} when a value's unit is neither the
 * canonical unit nor one of the accepted units of an attribute definition.
 * The registry never performs a silent write for an unrecognized unit — the
 * caller decides whether to route the value to manual review (US-043).
 */
class UnknownAttributeUnitException extends RuntimeException
{
    public static function forUnit(string $key, string $unit): self
    {
        return new self("Unità sconosciuta [{$unit}] per l'attributo [{$key}].");
    }
}
