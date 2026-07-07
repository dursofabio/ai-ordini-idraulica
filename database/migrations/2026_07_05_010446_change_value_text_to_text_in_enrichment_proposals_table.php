<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // US-051: a deep-enrichment proposal for the `descrizione_estesa`
        // field carries a full markdown extended description in `value_text`,
        // which would silently truncate at the previous varchar(255) limit.
        Schema::table('enrichment_proposals', function (Blueprint $table) {
            $table->text('value_text')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('enrichment_proposals', function (Blueprint $table) {
            $table->string('value_text')->nullable()->change();
        });
    }
};
