<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * US-008 — tracks the outcome of the staging-to-products upsert.
 *
 * The batch gains `rows_new` and `rows_updated` counters so the upsert step
 * can report how many products were inserted versus updated, alongside the
 * existing `skipped_rows` counter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->unsignedInteger('rows_new')->default(0)->after('skipped_rows');
            $table->unsignedInteger('rows_updated')->default(0)->after('rows_new');
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropColumn(['rows_new', 'rows_updated']);
        });
    }
};
