<?php

use App\Services\Enrichment\LegacyAttributeKeyMigrator;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Converts every `product_attributes` row with `key = 'potenza_watt'`
     * into its canonical `potenza_kw` equivalent (value / 1000, unit 'kW'),
     * per US-042. The factor and target key are passed explicitly rather
     * than read from `attribute_definitions`: migrations run before
     * seeders, so the registry may legitimately be empty at this point — on
     * a fresh install this backfill is a no-op.
     */
    public function up(): void
    {
        (new LegacyAttributeKeyMigrator)->migrate('potenza_watt', 'potenza_kw', 0.001, 'kW');
    }

    /**
     * Reverse the migrations.
     *
     * Intentionally a no-op: the conversion is not reversible without data
     * loss, since the original source unit of the merged rows is discarded
     * once they are folded into `potenza_kw`.
     */
    public function down(): void
    {
        //
    }
};
