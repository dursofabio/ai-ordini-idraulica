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

        Schema::create('product_embeddings', function (Blueprint $table) use ($isPgsql) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->text('content');
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
        Schema::dropIfExists('product_embeddings');
    }
};
