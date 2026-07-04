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
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generates the vector embedding for a single product via
 * {@see Product::composeEmbeddingContent()} and persists it to
 * `product_embeddings`, keyed by (product_id, model) so re-running the job
 * for the same product/model updates the existing row instead of creating a
 * duplicate.
 *
 * Variants of the same model compose identical content (same `product_type`
 * + brand), so before calling the provider this job looks for an existing
 * embedding with the same `model` + content hash and reuses its vector
 * instead of paying for a duplicate embedding call (US-046 AC3).
 *
 * Runs on the dedicated `embed` queue. Provider failures are caught and
 * logged so one failing product doesn't interrupt the rest of the batch.
 */
class GenerateProductEmbeddingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * The embedding HTTP client retries up to `EMBEDDING_TIMEOUT` (120s by
     * default) twice, so a worst-case call chain can run well past the
     * default 60s worker timeout — long enough for the worker to kill this
     * job mid-request and force a retry that was never actually needed.
     */
    public int $timeout = 300;

    public function __construct(
        public int $productId,
    ) {
        $this->onQueue('embed');
    }

    public function handle(EmbeddingClient $client): void
    {
        $product = Product::query()->findOrFail($this->productId);

        $content = trim($product->composeEmbeddingContent());

        if ($content === '') {
            Log::info('Skipping embedding generation: no embeddable content.', [
                'product_id' => $product->id,
            ]);

            return;
        }

        $model = config('services.embedding.model');
        $contentHash = hash('sha256', $content);

        $reusable = ProductEmbedding::query()
            ->where('model', $model)
            ->where('content_hash', $contentHash)
            ->first();

        if ($reusable !== null) {
            ProductEmbedding::query()->updateOrCreate(
                [
                    'product_id' => $product->id,
                    'model' => $model,
                ],
                [
                    'content' => $content,
                    'content_hash' => $contentHash,
                    'dimensions' => $reusable->dimensions,
                    'embedding' => $reusable->embedding,
                ],
            );

            return;
        }

        try {
            $embedding = $client->embed($content);
        } catch (Throwable $e) {
            Log::error('Failed to generate product embedding.', [
                'product_id' => $product->id,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        ProductEmbedding::query()->updateOrCreate(
            [
                'product_id' => $product->id,
                'model' => $model,
            ],
            [
                'content' => $content,
                'content_hash' => $contentHash,
                'dimensions' => count($embedding),
                'embedding' => '['.implode(',', $embedding).']',
            ],
        );
    }
}
