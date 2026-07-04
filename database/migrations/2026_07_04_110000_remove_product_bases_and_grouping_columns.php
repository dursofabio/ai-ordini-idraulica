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
     * US-047 finishes the flattening started by US-045/US-046: search now
     * operates directly on `products` (one row per SKU), so the
     * `product_bases` grouping table and the columns that pointed at it
     * (`products.product_base_id`, `products.grouping_key`) are no longer
     * read anywhere and are dropped outright. Grouping keys are not
     * meaningfully migratable to anything downstream, so there is no data
     * backfill here — same "drop & best-effort recreate in down()" pattern
     * as {@see 2026_07_04_100000_refactor_product_embeddings_to_product_id}.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['product_base_id']);
            $table->dropIndex(['product_base_id']);
            $table->dropIndex(['grouping_key']);
            $table->dropColumn(['product_base_id', 'grouping_key']);
        });

        Schema::dropIfExists('product_bases');
    }

    /**
     * Reverse the migrations.
     *
     * Best-effort recreate of `product_bases` mirroring its final shape
     * (create_product_bases_table + add_description_ai_to_product_bases_table
     * + add_search_vector_to_product_bases_table) and of the two dropped
     * `products` columns. No data is restored (grouping keys were not
     * migratable in the first place).
     */
    public function down(): void
    {
        $isPgsql = DB::connection()->getDriverName() === 'pgsql';

        Schema::create('product_bases', function (Blueprint $table) use ($isPgsql) {
            $table->id();
            $table->string('title')->nullable();
            $table->text('description_ai')->nullable();
            $table->string('grouping_key')->unique();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->foreignId('family_id')->nullable()->constrained('families')->nullOnDelete();
            $table->foreignId('subfamily_id')->nullable()->constrained('subfamilies')->nullOnDelete();
            $table->timestamps();

            $table->index('brand_id');
            $table->index('family_id');
            $table->index('subfamily_id');

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

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('product_base_id')->nullable()->after('enrichment_status')->constrained('product_bases')->nullOnDelete();
            $table->string('grouping_key')->nullable()->index();

            $table->index('product_base_id');
        });
    }
};
