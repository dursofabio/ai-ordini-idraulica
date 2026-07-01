<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * US-007 — prepares staging_articoli for the chunked XLSX read.
 *
 * The generic `payload` JSON column (US-005) is renamed to `raw_row` to match
 * the acceptance-criteria vocabulary, and the typed columns the reader maps
 * from the heading row (`descrizione`, `costo`, `giacenza`) are added. The
 * batch gains a `skipped_rows` counter for rows discarded on an empty
 * `codice_articolo`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staging_articoli', function (Blueprint $table) {
            $table->renameColumn('payload', 'raw_row');
        });

        Schema::table('staging_articoli', function (Blueprint $table) {
            $table->text('descrizione')->nullable()->after('codice_articolo');
            $table->decimal('costo', 14, 4)->nullable()->after('descrizione');
            $table->decimal('giacenza', 14, 3)->nullable()->after('costo');
        });

        Schema::table('import_batches', function (Blueprint $table) {
            $table->unsignedInteger('skipped_rows')->default(0)->after('error_rows');
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropColumn('skipped_rows');
        });

        Schema::table('staging_articoli', function (Blueprint $table) {
            $table->dropColumn(['descrizione', 'costo', 'giacenza']);
        });

        Schema::table('staging_articoli', function (Blueprint $table) {
            $table->renameColumn('raw_row', 'payload');
        });
    }
};
