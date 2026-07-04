<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Rebuilds the full-text search index backing `products.search_vector`
 * (US-046, flattened onto the single-product level by US-047).
 *
 * On PostgreSQL, the column is a generated (`storedAs`) tsvector column that
 * Postgres keeps in sync on every write, so this command only needs to
 * rebuild the GIN index itself (`REINDEX INDEX`), which is the maintenance
 * operation actually needed after bulk writes or index bloat. `CONCURRENTLY`
 * is attempted first to avoid blocking reads/writes; it fails when run
 * inside an implicit transaction, so a plain `REINDEX INDEX` is the
 * fallback.
 *
 * On any other driver (SQLite, used by the test suite) the column is a plain
 * text column with no generated-column support, so this command recomputes
 * it explicitly for every row — `product_type` + `descrizione_marca` +
 * `description_clean` — matching the same normalisation Postgres would
 * apply via `to_tsvector` closely enough for the column to be usable as a
 * fallback search source (see {@see SearchService::applyRanking()},
 * which already falls back to a plain LIKE match on non-Postgres drivers
 * instead of relying on this column, so exact parity is not required here).
 */
class CatalogReindexCommand extends Command
{
    /**
     * Number of rows re-computed per chunk on non-Postgres drivers.
     */
    public const CHUNK_SIZE = 500;

    private const PRODUCTS_GIN_INDEX_NAME = 'products_search_vector_gin_idx';

    /**
     * @var string
     */
    protected $signature = 'catalog:reindex';

    /**
     * @var string
     */
    protected $description = 'Ricostruisce l\'indice di full-text search (search_vector) su products.';

    public function handle(): int
    {
        try {
            $productRows = DB::connection()->getDriverName() === 'pgsql'
                ? $this->reindexPostgres()
                : $this->recomputeSearchVectors();
        } catch (Throwable $e) {
            $this->error("Errore durante il reindex: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Reindex completato. Righe processate: {$productRows} (products).");

        return self::SUCCESS;
    }

    private function reindexPostgres(): int
    {
        $this->reindexPostgresIndex(self::PRODUCTS_GIN_INDEX_NAME);

        return Product::query()->count();
    }

    private function reindexPostgresIndex(string $indexName): void
    {
        try {
            DB::statement('REINDEX INDEX CONCURRENTLY '.$indexName);
        } catch (Throwable) {
            // CONCURRENTLY cannot run inside a transaction block; fall back
            // to a plain (blocking) REINDEX when that is the case.
            DB::statement('REINDEX INDEX '.$indexName);
        }
    }

    private function recomputeSearchVectors(): int
    {
        $productRows = 0;

        Product::query()
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($chunk) use (&$productRows): void {
                /** @var Product $product */
                foreach ($chunk as $product) {
                    $text = trim(
                        ($product->product_type ?? '').' '
                        .($product->descrizione_marca ?? '').' '
                        .($product->description_clean ?? '')
                    );

                    $product->forceFill(['search_vector' => strtoupper($text)])->save();

                    $productRows++;
                }
            });

        return $productRows;
    }
}
