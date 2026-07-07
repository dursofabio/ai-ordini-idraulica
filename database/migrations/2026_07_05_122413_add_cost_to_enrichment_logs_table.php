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
        Schema::table('enrichment_logs', function (Blueprint $table) {
            $table->decimal('cost', 10, 6)->nullable()->after('tokens_out');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('enrichment_logs', function (Blueprint $table) {
            $table->dropColumn('cost');
        });
    }
};
