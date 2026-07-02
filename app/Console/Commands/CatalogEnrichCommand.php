<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Enrichment\ClassificationBatchDispatcher;
use App\Services\Enrichment\DeterministicEnrichmentPipeline;
use Illuminate\Console\Command;
use Throwable;

/**
 * Runs the deterministic Step A resolvers (via
 * {@see DeterministicEnrichmentPipeline}) synchronously over every pending
 * product, then dispatches Step B AI classification for whatever remains
 * unresolved (via {@see ClassificationBatchDispatcher}) — unless `--skip-ai`
 * is passed, in which case Step B is never queued, so the deterministic
 * result (file link, brand/attribute/grouping/family) can be inspected on
 * its own without incurring any AI classification cost.
 *
 * `--only=pending` is the sole supported filter for MVP: it is accepted as a
 * no-op (the command already scopes to pending products by definition) so the
 * signature stays stable for future values, but any other value fails fast.
 */
class CatalogEnrichCommand extends Command
{
    /**
     * Number of pending products read per chunk.
     */
    public const CHUNK_SIZE = 500;

    /**
     * @var string
     */
    protected $signature = 'catalog:enrich
        {--only= : Filtra i prodotti da arricchire (solo "pending" è supportato)}
        {--skip-ai : Esegue solo lo Step A deterministico, senza accodare la classificazione AI (Step B)}';

    /**
     * @var string
     */
    protected $description = 'Esegue l\'arricchimento deterministico (Step A) sui prodotti pending e accoda la classificazione AI (Step B) per i residui.';

    public function handle(DeterministicEnrichmentPipeline $pipeline, ClassificationBatchDispatcher $dispatcher): int
    {
        $only = $this->option('only');

        if ($only !== null && $only !== 'pending') {
            $this->error("Valore non supportato per --only: \"{$only}\". Solo \"pending\" è supportato.");

            return self::FAILURE;
        }

        try {
            $summary = $this->runStepA($pipeline);
        } catch (Throwable $e) {
            $this->error("Errore durante l'arricchimento deterministico: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($summary['processed'] === 0) {
            $this->info('Nessun prodotto in stato "pending" da arricchire.');

            return self::SUCCESS;
        }

        $this->info("Prodotti processati (Step A): {$summary['processed']}");
        $this->line("  Marche collegate da file: {$summary['brands_linked_from_file']}");
        $this->line("  Famiglie collegate da file: {$summary['families_linked_from_file']}");
        $this->line("  Sottofamiglie collegate da file: {$summary['subfamilies_linked_from_file']}");
        $this->line("  Brand risolti (testuale): {$summary['brands_resolved']}");
        $this->line("  Attributi scritti: {$summary['attributes_written']}");
        $this->line("  Gruppi (product_base) risolti: {$summary['groups_resolved']}");
        $this->line("  Famiglie propagate: {$summary['families_propagated']}");

        if ($this->option('skip-ai')) {
            $this->info('Step B (classificazione AI) saltato per via di --skip-ai.');

            return self::SUCCESS;
        }

        try {
            $jobsQueued = $dispatcher->dispatch();
        } catch (Throwable $e) {
            $this->error("Errore durante l'accodamento della classificazione AI: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Job di classificazione AI accodati (Step B): {$jobsQueued}");

        return self::SUCCESS;
    }

    /**
     * @return array{processed: int, brands_linked_from_file: int, families_linked_from_file: int, subfamilies_linked_from_file: int, brands_resolved: int, attributes_written: int, groups_resolved: int, families_propagated: int}
     */
    private function runStepA(DeterministicEnrichmentPipeline $pipeline): array
    {
        $totals = [
            'processed' => 0,
            'brands_linked_from_file' => 0,
            'families_linked_from_file' => 0,
            'subfamilies_linked_from_file' => 0,
            'brands_resolved' => 0,
            'attributes_written' => 0,
            'groups_resolved' => 0,
            'families_propagated' => 0,
        ];

        Product::query()
            ->where('enrichment_status', 'pending')
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($chunk) use ($pipeline, &$totals): void {
                /** @var Product $product */
                foreach ($chunk as $product) {
                    $summary = $pipeline->run($product);

                    $totals['processed']++;

                    foreach ($summary as $key => $value) {
                        $totals[$key] += $value;
                    }
                }
            });

        return $totals;
    }
}
