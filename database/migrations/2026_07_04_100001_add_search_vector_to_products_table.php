<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * US-046 rebuilds the full-text search index at the single-product
     * level (product_type + descrizione_marca + description_clean), mirroring
     * the product_bases.search_vector column added for US-019. Same driver
     * guard: a real generated tsvector on PostgreSQL, a plain nullable text
     * column recomputed by `catalog:reindex` on any other driver.
     */
    public function up(): void
    {
        $isPgsql = DB::connection()->getDriverName() === 'pgsql';

        Schema::table('products', function (Blueprint $table) use ($isPgsql) {
            if ($isPgsql) {
                $table->tsvector('search_vector')
                    ->storedAs(
                        "to_tsvector('italian', coalesce(product_type, '') || ' ' "
                        ."|| coalesce(descrizione_marca, '') || ' ' || coalesce(description_clean, ''))"
                    )
                    ->nullable();
            } else {
                $table->text('search_vector')->nullable();
            }
        });

        if ($isPgsql) {
            DB::statement('CREATE INDEX products_search_vector_gin_idx ON products USING gin (search_vector)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS products_search_vector_gin_idx');
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('search_vector');
        });
    }
};
