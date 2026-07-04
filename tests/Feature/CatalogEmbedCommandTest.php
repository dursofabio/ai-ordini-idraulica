<?php

namespace Tests\Feature;

use App\Jobs\GenerateProductEmbeddingJob;
use App\Models\Product;
use App\Models\ProductEmbedding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-046 acceptance criteria — `catalog:embed --missing`:
 *  - A product that already has an embedding for the configured model is
 *    not re-queued.
 *  - A product with product_type or description_clean set but no embedding
 *    is queued.
 *  - A product with neither product_type nor description_clean is not
 *    queued.
 */
class CatalogEmbedCommandTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.embedding.model', 'bge-m3');

        // ProductObserver auto-dispatches GenerateProductEmbeddingJob
        // synchronously (QUEUE_CONNECTION=sync) whenever a Product with a
        // product_type/description_clean/brand_id is created via factory.
        // Pointing the provider at an unreachable host makes that job fail
        // harmlessly instead of hitting the real Ollama service and creating
        // an embedding that collides with the one created explicitly below.
        config()->set('services.embedding.base_url', 'https://embedding.test');
    }

    public function test_it_queues_only_products_missing_an_embedding(): void
    {
        // Create fixtures with a real (unfaked) queue connection first, so the
        // ProductObserver's own dispatches on `created` don't pollute the
        // assertions below — only the command's dispatches are under test.
        $withEmbedding = Product::factory()->create(['product_type' => 'Ha già un embedding']);
        ProductEmbedding::factory()->create([
            'product_id' => $withEmbedding->id,
            'model' => 'bge-m3',
        ]);

        $missingEmbedding = Product::factory()->create(['product_type' => 'Manca embedding']);

        $noContent = Product::factory()->create([
            'product_type' => null,
            'description_clean' => null,
        ]);

        Queue::fake();

        $this->artisan('catalog:embed', ['--missing' => true])->assertSuccessful();

        Queue::assertPushed(GenerateProductEmbeddingJob::class, 1);
        Queue::assertPushed(
            GenerateProductEmbeddingJob::class,
            static fn (GenerateProductEmbeddingJob $job): bool => $job->productId === $missingEmbedding->id,
        );
        Queue::assertNotPushed(
            GenerateProductEmbeddingJob::class,
            static fn (GenerateProductEmbeddingJob $job): bool => $job->productId === $withEmbedding->id,
        );
        Queue::assertNotPushed(
            GenerateProductEmbeddingJob::class,
            static fn (GenerateProductEmbeddingJob $job): bool => $job->productId === $noContent->id,
        );
    }
}
