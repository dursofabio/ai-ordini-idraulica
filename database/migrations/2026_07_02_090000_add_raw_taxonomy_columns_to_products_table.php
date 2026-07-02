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
        Schema::table('products', function (Blueprint $table) {
            $table->string('marca_codice')->nullable()->after('descrizione_marca');
            $table->string('fam_codice')->nullable()->after('marca_codice');
            $table->string('fam_descrizione')->nullable()->after('fam_codice');
            $table->string('subfam_codice')->nullable()->after('fam_descrizione');
            $table->string('subfam_descrizione')->nullable()->after('subfam_codice');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'marca_codice',
                'fam_codice',
                'fam_descrizione',
                'subfam_codice',
                'subfam_descrizione',
            ]);
        });
    }
};
