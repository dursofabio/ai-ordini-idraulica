<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $isPgsql = DB::connection()->getDriverName() === 'pgsql';

        Schema::table('product_bases', function (Blueprint $table) use ($isPgsql) {
            // Postgres computes and stores the tsvector automatically from
            // title + description_ai. On other drivers (SQLite used by the
            // test suite) there is no generated-column / tsvector support,
            // so we fall back to a plain nullable text column. Same guard
            // pattern as create_product_embeddings_table.
            if ($isPgsql) {
                $table->tsvector('search_vector')
                    ->storedAs("to_tsvector('italian', coalesce(title, '') || ' ' || coalesce(description_ai, ''))")
                    ->nullable();
            } else {
                $table->text('search_vector')->nullable();
            }
        });

        if ($isPgsql) {
            DB::statement('CREATE INDEX product_bases_search_vector_gin_idx ON product_bases USING gin (search_vector)');
        }

        Schema::table('product_bases', function (Blueprint $table) {
            $indexes = collect(Schema::getIndexes('product_bases'))->pluck('name');

            if (! $indexes->contains('product_bases_brand_id_index')) {
                $table->index('brand_id');
            }

            if (! $indexes->contains('product_bases_family_id_index')) {
                $table->index('family_id');
            }

            if (! $indexes->contains('product_bases_subfamily_id_index')) {
                $table->index('subfamily_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_bases', function (Blueprint $table) {
            $indexes = collect(Schema::getIndexes('product_bases'))->pluck('name');

            if ($indexes->contains('product_bases_brand_id_index')) {
                $table->dropIndex(['brand_id']);
            }

            if ($indexes->contains('product_bases_family_id_index')) {
                $table->dropIndex(['family_id']);
            }

            if ($indexes->contains('product_bases_subfamily_id_index')) {
                $table->dropIndex(['subfamily_id']);
            }
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS product_bases_search_vector_gin_idx');
        }

        Schema::table('product_bases', function (Blueprint $table) {
            $table->dropColumn('search_vector');
        });
    }
};
