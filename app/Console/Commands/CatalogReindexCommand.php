<?php

namespace App\Console\Commands;

use App\Models\ProductBase;
use App\Services\Search\SearchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Rebuilds the full-text search index backing `product_bases.search_vector`.
 *
 * On PostgreSQL, `search_vector` is a generated (`storedAs`) tsvector column
 * that Postgres keeps in sync on every write, so this command only needs to
 * rebuild the GIN index itself (`REINDEX INDEX`), which is the maintenance
 * operation actually needed after bulk writes or index bloat. `CONCURRENTLY`
 * is attempted first to avoid blocking reads/writes; it fails when run inside
 * an implicit transaction, so a plain `REINDEX INDEX` is the fallback.
 *
 * On any other driver (SQLite, used by the test suite) `search_vector` is a
 * plain text column with no generated-column support, so this command
 * recomputes it explicitly for every row from `title` + `description_ai`,
 * matching the same normalisation Postgres would apply via `to_tsvector`
 * closely enough for the column to be usable as a fallback search source
 * (see {@see SearchService::applyRanking()}, which
 * already falls back to a plain LIKE match on non-Postgres drivers instead of
 * relying on this column, so exact parity is not required here).
 */
class CatalogReindexCommand extends Command
{
    /**
     * Number of ProductBase rows re-computed per chunk on non-Postgres
     * drivers.
     */
    public const CHUNK_SIZE = 500;

    private const GIN_INDEX_NAME = 'product_bases_search_vector_gin_idx';

    /**
     * @var string
     */
    protected $signature = 'catalog:reindex';

    /**
     * @var string
     */
    protected $description = 'Ricostruisce l\'indice di full-text search (search_vector) su product_bases.';

    public function handle(): int
    {
        try {
            $processed = DB::connection()->getDriverName() === 'pgsql'
                ? $this->reindexPostgres()
                : $this->recomputeSearchVector();
        } catch (Throwable $e) {
            $this->error("Errore durante il reindex: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Reindex completato. Righe processate: {$processed}.");

        return self::SUCCESS;
    }

    private function reindexPostgres(): int
    {
        try {
            DB::statement('REINDEX INDEX CONCURRENTLY '.self::GIN_INDEX_NAME);
        } catch (Throwable) {
            // CONCURRENTLY cannot run inside a transaction block; fall back
            // to a plain (blocking) REINDEX when that is the case.
            DB::statement('REINDEX INDEX '.self::GIN_INDEX_NAME);
        }

        return ProductBase::query()->count();
    }

    private function recomputeSearchVector(): int
    {
        $processed = 0;

        ProductBase::query()
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($chunk) use (&$processed): void {
                /** @var ProductBase $productBase */
                foreach ($chunk as $productBase) {
                    $text = trim(($productBase->title ?? '').' '.($productBase->description_ai ?? ''));

                    $productBase->forceFill(['search_vector' => strtoupper($text)])->save();

                    $processed++;
                }
            });

        return $processed;
    }
}
