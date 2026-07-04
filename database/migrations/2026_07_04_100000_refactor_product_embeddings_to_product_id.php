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
     * US-046 moves embeddings from the product-base level to the single
     * product level: content is now product_type + brand (falling back to
     * description_clean), so the natural key becomes (product_id, model)
     * instead of (product_base_id, model). Rather than altering the
     * product_base_id column/constraint in place (which behaves
     * inconsistently across drivers — SQLite has no named foreign keys and
     * requires a full table rebuild for this kind of change), this migration
     * drops and recreates the table with the new shape. Existing rows are
     * fully regenerable via `catalog:embed`/`catalog:reindex` (see US-046
     * AC5), so no data migration is needed.
     */
    public function up(): void
    {
        $isPgsql = DB::connection()->getDriverName() === 'pgsql';

        Schema::dropIfExists('product_embeddings');

        Schema::create('product_embeddings', function (Blueprint $table) use ($isPgsql) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->text('content');
            // Hash of `content`, used to dedupe embedding generation across
            // product variants that share identical embedding content (same
            // product_type + brand) so they reuse one vector instead of
            // paying for a duplicate provider call.
            $table->string('content_hash')->index();
            $table->string('model');
            $table->unsignedSmallInteger('dimensions')->default(1024);

            // pgvector's `vector(1024)` type is only available on PostgreSQL.
            // On other drivers (SQLite used by the test suite) fall back to text
            // so the migration completes everywhere. Same guard pattern as the
            // enable_pgvector_extension migration.
            if ($isPgsql) {
                $table->vector('embedding', 1024);
            } else {
                $table->text('embedding')->nullable();
            }

            $table->timestamps();

            // One embedding per product per model.
            $table->unique(['product_id', 'model']);
        });

        // Approximate nearest-neighbour index for cosine similarity search.
        // HNSW + vector_cosine_ops are pgvector-specific.
        if ($isPgsql) {
            DB::statement(
                'CREATE INDEX product_embeddings_embedding_hnsw_idx '
                .'ON product_embeddings USING hnsw (embedding vector_cosine_ops)'
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $isPgsql = DB::connection()->getDriverName() === 'pgsql';

        Schema::dropIfExists('product_embeddings');

        Schema::create('product_embeddings', function (Blueprint $table) use ($isPgsql) {
            $table->id();
            $table->foreignId('product_base_id')->constrained('product_bases')->cascadeOnDelete();
            $table->text('content');
            $table->string('model');
            $table->unsignedSmallInteger('dimensions')->default(1024);

            if ($isPgsql) {
                $table->vector('embedding', 1024);
            } else {
                $table->text('embedding')->nullable();
            }

            $table->timestamps();

            $table->unique(['product_base_id', 'model']);
        });

        if ($isPgsql) {
            DB::statement(
                'CREATE INDEX product_embeddings_embedding_hnsw_idx '
                .'ON product_embeddings USING hnsw (embedding vector_cosine_ops)'
            );
        }
    }
};
