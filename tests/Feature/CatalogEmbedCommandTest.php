<?php

namespace Tests\Feature;

use App\Jobs\GenerateProductBaseEmbeddingJob;
use App\Models\ProductBase;
use App\Models\ProductEmbedding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-018 acceptance criteria — `catalog:embed --missing`:
 *  - A product-base that already has an embedding for the configured model
 *    is not re-queued.
 *  - A product-base with description_ai set but no embedding is queued.
 *  - A product-base with an empty description_ai is not queued.
 */
class CatalogEmbedCommandTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.embedding.model', 'bge-m3');

        // ProductBaseObserver auto-dispatches GenerateProductBaseEmbeddingJob
        // synchronously (QUEUE_CONNECTION=sync) whenever a ProductBase with a
        // description_ai is created via factory. Pointing the provider at an
        // unreachable host makes that job fail harmlessly instead of hitting
        // the real Ollama service and creating an embedding that collides
        // with the one created explicitly below.
        config()->set('services.embedding.base_url', 'https://embedding.test');
    }

    public function test_it_queues_only_product_bases_missing_an_embedding(): void
    {
        // Create fixtures with a real (unfaked) queue connection first, so the
        // ProductBaseObserver's own dispatches on `created` don't pollute the
        // assertions below — only the command's dispatches are under test.
        $withEmbedding = ProductBase::factory()->create(['description_ai' => 'Ha già un embedding']);
        ProductEmbedding::factory()->create([
            'product_base_id' => $withEmbedding->id,
            'model' => 'bge-m3',
        ]);

        $missingEmbedding = ProductBase::factory()->create(['description_ai' => 'Manca embedding']);

        $emptyDescription = ProductBase::factory()->create(['description_ai' => null]);

        Queue::fake();

        $this->artisan('catalog:embed', ['--missing' => true])->assertSuccessful();

        Queue::assertPushed(GenerateProductBaseEmbeddingJob::class, 1);
        Queue::assertPushed(
            GenerateProductBaseEmbeddingJob::class,
            static fn (GenerateProductBaseEmbeddingJob $job): bool => $job->productBaseId === $missingEmbedding->id,
        );
        Queue::assertNotPushed(
            GenerateProductBaseEmbeddingJob::class,
            static fn (GenerateProductBaseEmbeddingJob $job): bool => $job->productBaseId === $withEmbedding->id,
        );
        Queue::assertNotPushed(
            GenerateProductBaseEmbeddingJob::class,
            static fn (GenerateProductBaseEmbeddingJob $job): bool => $job->productBaseId === $emptyDescription->id,
        );
    }
}
