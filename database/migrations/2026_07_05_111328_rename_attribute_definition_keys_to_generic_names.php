<?php

use App\Services\Enrichment\LegacyAttributeKeyMigrator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Renames the registry keys that embedded their canonical unit in the
     * name (e.g. `potenza_kw`) to a generic, unit-free name (e.g. `potenza`):
     * the AI now proposes attribute keys without the unit already declared
     * in the prompt's registry listing, so the unit alone (already a
     * separate `unit` column) would otherwise be duplicated in the key
     * itself. `pressione_nominale` and `materiale` are untouched:
     * `pressione_nominale` (PN rating) is a distinct physical quantity from
     * `pressione_bar` (an actual bar reading), not merely a unit suffix on
     * the same concept, so renaming both to `pressione` would collide.
     *
     * Reuses {@see LegacyAttributeKeyMigrator} (US-042) for `product_attributes`
     * so a product that already has both the legacy and the new key keeps
     * only one row, resolved by confidence — same conflict handling as any
     * other key migration in this registry. `attribute_definitions` and
     * `enrichment_proposals` have no such per-product conflict (a definition
     * key is unique registry-wide, and a proposal is just historical audit
     * data), so those two are a plain rename.
     */
    public function up(): void
    {
        $renames = [
            'potenza_kw' => 'potenza',
            'capacita_litri' => 'capacita',
            'attacco_pollici' => 'attacco',
            'diametro_nominale' => 'diametro',
            'pressione_bar' => 'pressione',
            'tensione_volt' => 'tensione',
            'portata_lmin' => 'portata',
            'colore_ral' => 'colore',
        ];

        $migrator = new LegacyAttributeKeyMigrator;

        foreach ($renames as $from => $to) {
            $definition = DB::table('attribute_definitions')->where('key', $from)->first();

            if ($definition !== null && $definition->canonical_unit !== null) {
                $migrator->migrate($from, $to, 1.0, $definition->canonical_unit);
            } else {
                DB::table('product_attributes')->where('key', $from)->update(['key' => $to]);
            }

            DB::table('attribute_definitions')->where('key', $from)->update(['key' => $to]);
            DB::table('enrichment_proposals')->where('attribute_key', $from)->update(['attribute_key' => $to]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * Intentionally a no-op, same reasoning as the `potenza_watt`→`potenza_kw`
     * backfill this mirrors: rows from a legacy/new key conflict are merged
     * by {@see LegacyAttributeKeyMigrator}, discarding the losing row, so the
     * rename is not reversible without data loss.
     */
    public function down(): void
    {
        //
    }
};
