<?php

namespace Tests\Feature;

use App\Jobs\GenerateProductBaseEmbeddingJob;
use App\Models\ProductBase;
use App\Models\ProductEmbedding;
use App\Services\Ai\EmbeddingClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-018 acceptance criteria — product-base embedding generation job:
 *  - The job is dispatched onto the dedicated 'embed' queue.
 *  - Running the job persists a ProductEmbedding row (product_base_id + model)
 *    built from the product-base's description_ai and the embedding vector
 *    returned by the provider.
 *  - Re-running the job for the same product-base/model updates the existing
 *    row instead of creating a duplicate.
 *  - A product-base with an empty description_ai is skipped without calling
 *    the embedding provider.
 *  - A provider error is logged and does not propagate out of handle(),
 *    keeping the rest of the batch running.
 */
class GenerateProductBaseEmbeddingJobTest extends TestCase
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

        $productBase = ProductBase::factory()->create();

        GenerateProductBaseEmbeddingJob::dispatch($productBase->id);

        Queue::assertPushedOn('embed', GenerateProductBaseEmbeddingJob::class);
    }

    public function test_handling_job_creates_product_embedding_from_provider_response(): void
    {
        $productBase = ProductBase::factory()->create([
            'description_ai' => 'Tubo in PVC 32mm',
        ]);

        Http::fake([
            '*' => Http::response(['embedding' => [0.1, 0.2, 0.3, 0.4]]),
        ]);

        (new GenerateProductBaseEmbeddingJob($productBase->id))->handle(app(EmbeddingClient::class));

        $this->assertDatabaseCount('product_embeddings', 1);

        $embedding = ProductEmbedding::query()->where('product_base_id', $productBase->id)->first();

        $this->assertNotNull($embedding);
        $this->assertSame('bge-m3', $embedding->model);
        $this->assertSame('Tubo in PVC 32mm', $embedding->content);
        $this->assertSame(4, $embedding->dimensions);
        $this->assertSame('[0.1,0.2,0.3,0.4]', $embedding->embedding);
    }

    public function test_handling_job_again_updates_existing_embedding_instead_of_duplicating(): void
    {
        $productBase = ProductBase::factory()->create([
            'description_ai' => 'Tubo in PVC 32mm',
        ]);

        Http::fake([
            '*' => Http::sequence()
                ->push(['embedding' => [0.1, 0.2, 0.3, 0.4]])
                ->push(['embedding' => [0.5, 0.6, 0.7, 0.8]]),
        ]);

        $client = app(EmbeddingClient::class);

        (new GenerateProductBaseEmbeddingJob($productBase->id))->handle($client);
        (new GenerateProductBaseEmbeddingJob($productBase->id))->handle($client);

        $this->assertDatabaseCount('product_embeddings', 1);

        $embedding = ProductEmbedding::query()->where('product_base_id', $productBase->id)->first();

        $this->assertSame('[0.5,0.6,0.7,0.8]', $embedding->embedding);
    }

    public function test_job_skips_provider_call_when_description_ai_is_empty(): void
    {
        $productBase = ProductBase::factory()->create([
            'description_ai' => null,
        ]);

        Http::fake();

        (new GenerateProductBaseEmbeddingJob($productBase->id))->handle(app(EmbeddingClient::class));

        Http::assertNothingSent();
        $this->assertDatabaseCount('product_embeddings', 0);
    }

    public function test_job_logs_provider_error_and_does_not_propagate_exception(): void
    {
        $productBase = ProductBase::factory()->create([
            'description_ai' => 'Tubo in PVC 32mm',
        ]);

        Http::fake([
            '*' => Http::response(status: 500),
        ]);

        Log::shouldReceive('error')
            ->once()
            ->with('Failed to generate product-base embedding.', \Mockery::on(
                static fn (array $context): bool => $context['product_base_id'] === $productBase->id
            ));

        (new GenerateProductBaseEmbeddingJob($productBase->id))->handle(app(EmbeddingClient::class));

        $this->assertDatabaseCount('product_embeddings', 0);
    }
}
