<?php

namespace Tests\Feature;

use App\Jobs\GenerateProductEmbeddingJob;
use App\Models\Product;
use App\Models\ProductEmbedding;
use App\Services\Ai\EmbeddingClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-046 acceptance criteria — single-product embedding generation job:
 *  - The job is dispatched onto the dedicated 'embed' queue.
 *  - Running the job persists a ProductEmbedding row (product_id + model)
 *    built from the product's composed embedding content and the vector
 *    returned by the provider.
 *  - Re-running the job for the same product/model updates the existing
 *    row instead of creating a duplicate.
 *  - A product with no embeddable content is skipped without calling the
 *    embedding provider.
 *  - A provider error is logged and does not propagate out of handle(),
 *    keeping the rest of the batch running.
 *  - Two products composing identical content reuse the same vector: only
 *    one call reaches the embedding provider (AC3, dedup by content hash).
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

    public function test_job_is_dispatched_onto_embed_queue(): void
    {
        Queue::fake();

        $product = Product::factory()->create();

        GenerateProductEmbeddingJob::dispatch($product->id);

        Queue::assertPushedOn('embed', GenerateProductEmbeddingJob::class);
    }

    public function test_timeout_exceeds_the_worst_case_embedding_http_call_chain(): void
    {
        // The provider's own retry policy (retry_times x timeout, e.g. 2 x
        // 120s) can legitimately run past the default 60s worker timeout;
        // the job must declare enough headroom that the worker never kills
        // it mid-request and forces an unnecessary retry.
        $job = new GenerateProductEmbeddingJob(1);

        $this->assertGreaterThan(
            config('services.embedding.retry_times') * config('services.embedding.timeout'),
            $job->timeout,
        );
    }

    public function test_handling_job_creates_product_embedding_from_provider_response(): void
    {
        $product = Product::factory()->create([
            'product_type' => 'Tubo PVC',
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
        $this->assertSame($product->composeEmbeddingContent(), $embedding->content);
        $this->assertSame(hash('sha256', $embedding->content), $embedding->content_hash);
        $this->assertSame(4, $embedding->dimensions);
        $this->assertSame('[0.1,0.2,0.3,0.4]', $embedding->embedding);
    }

    public function test_handling_job_again_updates_existing_embedding_instead_of_duplicating(): void
    {
        $product = Product::factory()->create([
            'product_type' => 'Tubo PVC',
        ]);

        Http::fake([
            '*' => Http::sequence()
                ->push(['embedding' => [0.1, 0.2, 0.3, 0.4]])
                ->push(['embedding' => [0.5, 0.6, 0.7, 0.8]]),
        ]);

        $client = app(EmbeddingClient::class);

        (new GenerateProductEmbeddingJob($product->id))->handle($client);

        // Force the second run to call the provider again instead of reusing
        // the first run's own row via the content-hash dedup lookup.
        ProductEmbedding::query()->where('product_id', $product->id)->delete();

        (new GenerateProductEmbeddingJob($product->id))->handle($client);

        $this->assertDatabaseCount('product_embeddings', 1);

        $embedding = ProductEmbedding::query()->where('product_id', $product->id)->first();

        $this->assertSame('[0.5,0.6,0.7,0.8]', $embedding->embedding);
    }

    public function test_job_skips_provider_call_when_product_has_no_embeddable_content(): void
    {
        $product = Product::factory()->create([
            'product_type' => null,
            'description_clean' => null,
        ]);

        Http::fake();

        (new GenerateProductEmbeddingJob($product->id))->handle(app(EmbeddingClient::class));

        Http::assertNothingSent();
        $this->assertDatabaseCount('product_embeddings', 0);
    }

    public function test_job_logs_provider_error_and_does_not_propagate_exception(): void
    {
        $product = Product::factory()->create([
            'product_type' => 'Tubo PVC',
        ]);

        Http::fake([
            '*' => Http::response(status: 500),
        ]);

        Log::shouldReceive('error')
            ->once()
            ->with('Failed to generate product embedding.', \Mockery::on(
                static fn (array $context): bool => $context['product_id'] === $product->id
            ));

        (new GenerateProductEmbeddingJob($product->id))->handle(app(EmbeddingClient::class));

        $this->assertDatabaseCount('product_embeddings', 0);
    }

    public function test_two_products_with_identical_content_reuse_the_same_vector_without_a_duplicate_call(): void
    {
        $productA = Product::factory()->create([
            'product_type' => 'Caldaia',
            'brand_id' => null,
        ]);
        $productB = Product::factory()->create([
            'product_type' => 'Caldaia',
            'brand_id' => null,
        ]);

        Http::fake([
            '*' => Http::response(['embedding' => [0.1, 0.2, 0.3, 0.4]]),
        ]);

        $client = app(EmbeddingClient::class);

        (new GenerateProductEmbeddingJob($productA->id))->handle($client);
        (new GenerateProductEmbeddingJob($productB->id))->handle($client);

        Http::assertSentCount(1);

        $this->assertDatabaseCount('product_embeddings', 2);

        $embeddingA = ProductEmbedding::query()->where('product_id', $productA->id)->first();
        $embeddingB = ProductEmbedding::query()->where('product_id', $productB->id)->first();

        $this->assertSame($embeddingA->content_hash, $embeddingB->content_hash);
        $this->assertSame($embeddingA->embedding, $embeddingB->embedding);
    }
}
