<?php

namespace App\Services\Search;

use App\Services\Enrichment\AttributeUnitConverter;

/**
 * A single hard attribute filter extracted from a natural-language search
 * query (US-048), already resolved against the closed attribute registry
 * (US-042) and, for numeric attributes, already converted to the
 * definition's canonical unit via {@see AttributeUnitConverter}.
 * Either `value` (textual exact match) or `min`/`max` (numeric range, either
 * bound optional) is set, mirroring the two attribute constraint shapes
 * {@see SearchService::applyAttributeConstraint()}
 * already accepts.
 */
final readonly class AppliedAttributeFilter
{
    public function __construct(
        public string $key,
        public string $label,
        public ?string $unit = null,
        public ?string $value = null,
        public ?float $min = null,
        public ?float $max = null,
    ) {}

    /**
     * Render this filter in the shape accepted by
     * `SearchService`'s `filters['attributes']` entries.
     *
     * @return array{key: string, value?: string, min?: float, max?: float}
     */
    public function toSearchFilterArray(): array
    {
        $filter = ['key' => $this->key];

        if ($this->value !== null) {
            $filter['value'] = $this->value;

            return $filter;
        }

        if ($this->min !== null) {
            $filter['min'] = $this->min;
        }

        if ($this->max !== null) {
            $filter['max'] = $this->max;
        }

        return $filter;
    }

    /**
     * Human-readable rendering of this filter for the "interpretation"
     * banner shown to the user (e.g. "Materiale: inox",
     * "Attacco filettato: 1\"", "Potenza nominale: 2 - 5 kW").
     */
    public function toDisplayLabel(): string
    {
        if ($this->value !== null) {
            return "{$this->label}: {$this->value}";
        }

        $unit = $this->unit !== null ? " {$this->unit}" : '';

        if ($this->min !== null && $this->max !== null) {
            return "{$this->label}: {$this->formatNumber($this->min)} - {$this->formatNumber($this->max)}{$unit}";
        }

        if ($this->min !== null) {
            return "{$this->label}: ≥ {$this->formatNumber($this->min)}{$unit}";
        }

        return "{$this->label}: ≤ {$this->formatNumber($this->max)}{$unit}";
    }

    private function formatNumber(?float $value): string
    {
        if ($value === null) {
            return '';
        }

        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
