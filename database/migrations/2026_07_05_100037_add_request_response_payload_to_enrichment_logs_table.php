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
            $table->json('request_payload')->nullable()->after('output');
            $table->json('response_payload')->nullable()->after('request_payload');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('enrichment_logs', function (Blueprint $table) {
            $table->dropColumn(['request_payload', 'response_payload']);
        });
    }
};
