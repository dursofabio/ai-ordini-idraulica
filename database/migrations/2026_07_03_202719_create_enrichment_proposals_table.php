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
        Schema::create('enrichment_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('field');
            $table->string('attribute_key')->nullable();
            $table->unsignedBigInteger('value_id')->nullable();
            $table->decimal('value_num', 12, 3)->nullable();
            $table->string('value_text')->nullable();
            $table->string('unit')->nullable();
            $table->string('origin');
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->string('status')->default('pending')->index();
            $table->timestamps();

            $table->index(['product_id', 'field', 'attribute_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('enrichment_proposals');
    }
};
