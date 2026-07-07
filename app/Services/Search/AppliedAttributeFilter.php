<?php

namespace App\Services\Search;

/**
 * A single hard attribute filter extracted from a natural-language search
 * query (US-048), already resolved against the closed attribute registry
 * (US-042). An exact-match textual value, mirroring the attribute
 * constraint shape {@see SearchService::applyAttributeConstraint()} accepts.
 */
final readonly class AppliedAttributeFilter
{
    public function __construct(
        public string $key,
        public string $label,
        public string $value,
    ) {}

    /**
     * Render this filter in the shape accepted by
     * `SearchService`'s `filters['attributes']` entries.
     *
     * @return array{key: string, value: string}
     */
    public function toSearchFilterArray(): array
    {
        return ['key' => $this->key, 'value' => $this->value];
    }

    /**
     * Human-readable rendering of this filter for the "interpretation"
     * banner shown to the user (e.g. "Materiale: inox").
     */
    public function toDisplayLabel(): string
    {
        return "{$this->label}: {$this->value}";
    }
}
