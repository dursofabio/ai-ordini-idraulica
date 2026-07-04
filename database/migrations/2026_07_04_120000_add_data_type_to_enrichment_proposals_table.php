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
        Schema::table('enrichment_proposals', function (Blueprint $table) {
            // US-044: carries the proposed data type ('numeric'/'text') for a
            // 'attribute_definition' proposal, inferred deterministically from
            // whether the AI populated value_num or value_text for that
            // attribute. Unused (null) by every other proposal field.
            $table->string('data_type')->nullable()->after('unit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('enrichment_proposals', function (Blueprint $table) {
            $table->dropColumn('data_type');
        });
    }
};
