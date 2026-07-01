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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('codice_articolo')->unique();
            $table->text('description_raw')->nullable();
            $table->string('descrizione_marca')->nullable();
            $table->decimal('costo', 12, 2)->default(0);
            $table->decimal('giacenza', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('enrichment_status')->default('pending')->index();
            $table->foreignId('product_base_id')->nullable()->constrained('product_bases')->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->foreignId('family_id')->nullable()->constrained('families')->nullOnDelete();
            $table->foreignId('subfamily_id')->nullable()->constrained('subfamilies')->nullOnDelete();
            $table->string('brand_source')->nullable();
            $table->string('family_source')->nullable();
            $table->string('subfamily_source')->nullable();
            $table->string('source')->nullable();
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->string('grouping_key')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
