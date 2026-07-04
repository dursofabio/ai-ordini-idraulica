<?php

namespace App\Services\Enrichment;

use App\Models\ProductAttribute;

/**
 * Backfill logic (US-042) that retires a legacy `product_attributes.key` in
 * favor of its canonical replacement, converting the stored value with a
 * fixed multiplicative factor. Extracted out of the migration so it is
 * independently testable.
 *
 * For each `$fromKey` row: if the product has no `$toKey` row yet, the row
 * is converted in place (key, value_num, unit) while preserving `source`
 * and `confidence`. If a `$toKey` row already exists, the row with the
 * higher effective confidence wins — effective confidence is
 * `confidence ?? (source === 'regex' ? 100 : 0)`, and a tie favors the
 * already-canonical row (no conversion, less risk). The losing row is
 * always removed.
 *
 * Idempotent: once no `$fromKey` rows remain, a second run is a no-op that
 * returns 0.
 */
class LegacyAttributeKeyMigrator
{
    /**
     * Migrates every `$fromKey` row to `$toKey`, converting `value_num` by
     * `$factor` and setting `unit` to `$canonicalUnit`. Returns the number
     * of `$fromKey` rows processed (converted or discarded as a losing
     * duplicate).
     */
    public function migrate(string $fromKey, string $toKey, float $factor, string $canonicalUnit): int
    {
        $migrated = 0;

        ProductAttribute::query()
            ->where('key', $fromKey)
            ->chunkById(200, function ($rows) use ($toKey, $factor, $canonicalUnit, &$migrated): void {
                /** @var ProductAttribute $row */
                foreach ($rows as $row) {
                    $this->migrateRow($row, $toKey, $factor, $canonicalUnit);
                    $migrated++;
                }
            });

        return $migrated;
    }

    private function migrateRow(ProductAttribute $row, string $toKey, float $factor, string $canonicalUnit): void
    {
        $convertedValue = $row->value_num !== null ? ((float) $row->value_num) * $factor : null;

        $existing = ProductAttribute::query()
            ->where('product_id', $row->product_id)
            ->where('key', $toKey)
            ->first();

        if ($existing === null) {
            $row->update([
                'key' => $toKey,
                'value_num' => $convertedValue,
                'unit' => $canonicalUnit,
            ]);

            return;
        }

        if ($this->effectiveConfidence($row) > $this->effectiveConfidence($existing)) {
            $existing->update([
                'value_num' => $convertedValue,
                'unit' => $canonicalUnit,
                'source' => $row->source,
                'confidence' => $row->confidence,
            ]);
        }

        $row->delete();
    }

    private function effectiveConfidence(ProductAttribute $attribute): int
    {
        if ($attribute->confidence !== null) {
            return $attribute->confidence;
        }

        return $attribute->source === 'regex' ? 100 : 0;
    }
}
