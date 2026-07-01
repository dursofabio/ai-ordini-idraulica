<?php

namespace App\Jobs;

use App\Models\ProductBase;
use App\Models\ProductEmbedding;
use App\Services\Ai\EmbeddingClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generates the vector embedding for a single product-base's `description_ai`
 * via {@see EmbeddingClient} and persists it to `product_embeddings`, keyed by
 * (product_base_id, model) so re-running the job for the same model updates
 * the existing row instead of creating a duplicate.
 *
 * Runs on the dedicated `embed` queue. Provider failures are caught and
 * logged so one failing product-base doesn't interrupt the rest of the batch.
 */
class GenerateProductBaseEmbeddingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public int $productBaseId,
    ) {
        $this->onQueue('embed');
    }

    public function handle(EmbeddingClient $client): void
    {
        $productBase = ProductBase::query()->findOrFail($this->productBaseId);

        $content = trim((string) $productBase->description_ai);

        if ($content === '') {
            Log::info('Skipping embedding generation: description_ai is empty.', [
                'product_base_id' => $productBase->id,
            ]);

            return;
        }

        $model = config('services.embedding.model');

        try {
            $embedding = $client->embed($content);
        } catch (Throwable $e) {
            Log::error('Failed to generate product-base embedding.', [
                'product_base_id' => $productBase->id,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        ProductEmbedding::query()->updateOrCreate(
            [
                'product_base_id' => $productBase->id,
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
