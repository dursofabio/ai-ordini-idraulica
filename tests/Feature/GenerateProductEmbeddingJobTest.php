<?php

namespace Tests\Feature;

use App\Jobs\GenerateProductEmbeddingJob;
use App\Models\Product;
use App\Models\ProductEmbedding;
use App\Services\Ai\EmbeddingClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-017 acceptance criteria — product embedding generation job:
 *  - The job is dispatched onto the dedicated 'embeddings' queue.
 *  - Running the job persists a ProductEmbedding row (product_id + model)
 *    built from the product's description and the embedding vector
 *    returned by the provider.
 *  - Re-running the job for the same product/model updates the existing
 *    row instead of creating a duplicate.
 */
class GenerateProductEmbeddingJobTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.embedding', [
            'base_url' => 'https://embedding.test',
            'model' => 'bge-m3',
            'api_key' => null,
            'dimensions' => 4,
            'timeout' => 120,
            'retry_times' => 2,
            'retry_delay_ms' => 1,
        ]);
    }

    public function test_job_is_dispatched_onto_embeddings_queue(): void
    {
        Queue::fake();

        $product = Product::factory()->create();

        GenerateProductEmbeddingJob::dispatch($product->id);

        Queue::assertPushedOn('embeddings', GenerateProductEmbeddingJob::class);
    }

    public function test_handling_job_creates_product_embedding_from_provider_response(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'Tubo in PVC 32mm',
            'description_clean' => null,
        ]);

        Http::fake([
            '*' => Http::response(['embedding' => [0.1, 0.2, 0.3, 0.4]]),
        ]);

        (new GenerateProductEmbeddingJob($product->id))->handle(app(EmbeddingClient::class));

        $this->assertDatabaseCount('product_embeddings', 1);

        $embedding = ProductEmbedding::query()->where('product_id', $product->id)->first();

        $this->assertNotNull($embedding);
        $this->assertSame('bge-m3', $embedding->model);
        $this->assertSame('Tubo in PVC 32mm', $embedding->content);
        $this->assertSame(4, $embedding->dimensions);
        $this->assertSame('[0.1,0.2,0.3,0.4]', $embedding->embedding);
    }

    public function test_handling_job_again_updates_existing_embedding_instead_of_duplicating(): void
    {
        $product = Product::factory()->create([
            'description_raw' => 'Tubo in PVC 32mm',
            'description_clean' => null,
        ]);

        Http::fake([
            '*' => Http::sequence()
                ->push(['embedding' => [0.1, 0.2, 0.3, 0.4]])
                ->push(['embedding' => [0.5, 0.6, 0.7, 0.8]]),
        ]);

        $client = app(EmbeddingClient::class);

        (new GenerateProductEmbeddingJob($product->id))->handle($client);
        (new GenerateProductEmbeddingJob($product->id))->handle($client);

        $this->assertDatabaseCount('product_embeddings', 1);

        $embedding = ProductEmbedding::query()->where('product_id', $product->id)->first();

        $this->assertSame('[0.5,0.6,0.7,0.8]', $embedding->embedding);
    }
}
