<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\ProductEmbedding;
use App\Services\Ai\EmbeddingClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Generates the vector embedding for a single product's description via
 * {@see EmbeddingClient} and persists it to `product_embeddings`, keyed by
 * (product_id, model) so re-running the job for the same model updates the
 * existing row instead of creating a duplicate.
 */
class GenerateProductEmbeddingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public int $productId,
    ) {
        $this->onQueue('embeddings');
    }

    public function handle(EmbeddingClient $client): void
    {
        $product = Product::query()->findOrFail($this->productId);

        $content = trim((string) ($product->description_clean ?? $product->description_raw));

        $embedding = $client->embed($content);

        $model = config('services.embedding.model');

        ProductEmbedding::query()->updateOrCreate(
            [
                'product_id' => $product->id,
                'model' => $model,
            ],
            [
                'content' => $content,
                'dimensions' => count($embedding),
                'embedding' => '['.implode(',', $embedding).']',
            ],
        );
    }
}
