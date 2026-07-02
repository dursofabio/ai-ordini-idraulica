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
 * unresolved (via {@see ClassificationBatchDispatcher}).
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
    protected $signature = 'catalog:enrich {--only= : Filtra i prodotti da arricchire (solo "pending" è supportato)}';

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
            [$processed, $brandsResolved, $attributesWritten, $groupsResolved, $familiesPropagated] = $this->runStepA($pipeline);
        } catch (Throwable $e) {
            $this->error("Errore durante l'arricchimento deterministico: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($processed === 0) {
            $this->info('Nessun prodotto in stato "pending" da arricchire.');

            return self::SUCCESS;
        }

        try {
            $jobsQueued = $dispatcher->dispatch();
        } catch (Throwable $e) {
            $this->error("Errore durante l'accodamento della classificazione AI: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Prodotti processati (Step A): {$processed}");
        $this->line("  Brand risolti: {$brandsResolved}");
        $this->line("  Attributi scritti: {$attributesWritten}");
        $this->line("  Gruppi (product_base) risolti: {$groupsResolved}");
        $this->line("  Famiglie propagate: {$familiesPropagated}");
        $this->info("Job di classificazione AI accodati (Step B): {$jobsQueued}");

        return self::SUCCESS;
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int}
     */
    private function runStepA(DeterministicEnrichmentPipeline $pipeline): array
    {
        $processed = 0;
        $brandsResolved = 0;
        $attributesWritten = 0;
        $groupsResolved = 0;
        $familiesPropagated = 0;

        Product::query()
            ->where('enrichment_status', 'pending')
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($chunk) use ($pipeline, &$processed, &$brandsResolved, &$attributesWritten, &$groupsResolved, &$familiesPropagated): void {
                /** @var Product $product */
                foreach ($chunk as $product) {
                    $summary = $pipeline->run($product);

                    $processed++;
                    $brandsResolved += $summary['brands_resolved'];
                    $attributesWritten += $summary['attributes_written'];
                    $groupsResolved += $summary['groups_resolved'];
                    $familiesPropagated += $summary['families_propagated'];
                }
            });

        return [$processed, $brandsResolved, $attributesWritten, $groupsResolved, $familiesPropagated];
    }
}
