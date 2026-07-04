<?php

namespace Tests\Feature;

use App\Jobs\GenerateProductEmbeddingJob;
use App\Models\Brand;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-046 acceptance criteria — automatic embedding regeneration at the
 * single-product level:
 *  - Updating product_type, description_clean, or brand_id on an existing
 *    product dispatches GenerateProductEmbeddingJob with the correct id.
 *  - Saving the model without changing any of the three trigger fields does
 *    not dispatch any job.
 *  - Creating a product with product_type, description_clean, or brand_id
 *    already set dispatches the job (created fires on create too).
 */
class ProductObserverTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_updating_product_type_dispatches_embedding_job(): void
    {
        $product = Product::factory()->create(['product_type' => 'Tubo PVC']);

        Queue::fake();

        $product->update(['product_type' => 'Tubo PE']);

        Queue::assertPushed(
            GenerateProductEmbeddingJob::class,
            static fn (GenerateProductEmbeddingJob $job): bool => $job->productId === $product->id,
        );
    }

    public function test_updating_description_clean_dispatches_embedding_job(): void
    {
        $product = Product::factory()->create(['description_clean' => 'Vecchia descrizione']);

        Queue::fake();

        $product->update(['description_clean' => 'Nuova descrizione']);

        Queue::assertPushed(
            GenerateProductEmbeddingJob::class,
            static fn (GenerateProductEmbeddingJob $job): bool => $job->productId === $product->id,
        );
    }

    public function test_updating_brand_id_dispatches_embedding_job(): void
    {
        $brand = Brand::factory()->create();
        $product = Product::factory()->create(['brand_id' => null]);

        Queue::fake();

        $product->update(['brand_id' => $brand->id]);

        Queue::assertPushed(
            GenerateProductEmbeddingJob::class,
            static fn (GenerateProductEmbeddingJob $job): bool => $job->productId === $product->id,
        );
    }

    public function test_saving_without_changing_trigger_fields_does_not_dispatch_job(): void
    {
        $product = Product::factory()->create([
            'product_type' => 'Tubo PVC',
            'description_clean' => 'Descrizione invariata',
        ]);

        Queue::fake();

        $product->update(['costo' => 12.5]);

        Queue::assertNotPushed(GenerateProductEmbeddingJob::class);
    }

    public function test_creating_product_with_product_type_dispatches_job(): void
    {
        Queue::fake();

        $product = Product::factory()->create(['product_type' => 'Tubo PVC']);

        Queue::assertPushed(
            GenerateProductEmbeddingJob::class,
            static fn (GenerateProductEmbeddingJob $job): bool => $job->productId === $product->id,
        );
    }

    public function test_creating_product_with_none_of_the_trigger_fields_does_not_dispatch_job(): void
    {
        Queue::fake();

        Product::factory()->create([
            'product_type' => null,
            'description_clean' => null,
            'brand_id' => null,
        ]);

        Queue::assertNotPushed(GenerateProductEmbeddingJob::class);
    }
}
