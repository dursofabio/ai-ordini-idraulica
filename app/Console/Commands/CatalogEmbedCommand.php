<?php

namespace App\Console\Commands;

use App\Jobs\GenerateProductBaseEmbeddingJob;
use App\Models\ProductBase;
use Illuminate\Console\Command;

class CatalogEmbedCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'catalog:embed {--missing : Genera solo per i prodotti-base privi di embedding}';

    /**
     * @var string
     */
    protected $description = 'Accoda la generazione dell\'embedding vettoriale per i prodotti-base.';

    public function handle(): int
    {
        if (! $this->option('missing')) {
            $this->error('Specificare --missing per accodare i prodotti-base privi di embedding.');

            return self::FAILURE;
        }

        $model = config('services.embedding.model');

        $productBases = ProductBase::query()
            ->whereNotNull('description_ai')
            ->where('description_ai', '!=', '')
            ->whereDoesntHave('embedding', function ($query) use ($model): void {
                $query->where('model', $model);
            })
            ->get();

        foreach ($productBases as $productBase) {
            GenerateProductBaseEmbeddingJob::dispatch($productBase->id);
        }

        $this->info("Accodati {$productBases->count()} prodotti-base per la generazione dell'embedding.");

        return self::SUCCESS;
    }
}
