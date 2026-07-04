<?php

namespace App\Console\Commands;

use App\Jobs\GenerateProductEmbeddingJob;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class CatalogEmbedCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'catalog:embed {--missing : Genera solo per i prodotti privi di embedding}';

    /**
     * @var string
     */
    protected $description = 'Accoda la generazione dell\'embedding vettoriale per i prodotti.';

    public function handle(): int
    {
        if (! $this->option('missing')) {
            $this->error('Specificare --missing per accodare i prodotti privi di embedding.');

            return self::FAILURE;
        }

        $model = config('services.embedding.model');

        $products = Product::query()
            ->where(function (Builder $query): void {
                $query->whereNotNull('product_type')->where('product_type', '!=', '')
                    ->orWhere(function (Builder $query): void {
                        $query->whereNotNull('description_clean')->where('description_clean', '!=', '');
                    });
            })
            ->whereDoesntHave('embedding', function (Builder $query) use ($model): void {
                $query->where('model', $model);
            })
            ->get();

        foreach ($products as $product) {
            GenerateProductEmbeddingJob::dispatch($product->id);
        }

        $this->info("Accodati {$products->count()} prodotti per la generazione dell'embedding.");

        return self::SUCCESS;
    }
}
