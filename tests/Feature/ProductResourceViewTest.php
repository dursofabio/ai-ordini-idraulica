<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\Pages\ViewProduct;
use App\Jobs\ClassifyProductsBatchJob;
use App\Jobs\GenerateProductEmbeddingJob;
use App\Jobs\RunDeterministicEnrichmentJob;
use App\Models\EnrichmentLog;
use App\Models\Product;
use App\Models\ProductEmbedding;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Support\Icons\Heroicon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\Assert;
use Livewire\Livewire;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-030 acceptance criteria for the product view page:
 *  - AC1: the list exposes a dedicated view action, and the view page loads.
 *  - AC2/AC3: the enrichment history is shown in chronological order with
 *    step/confidence/model/tokens, or an explicit empty state when there is
 *    no enrichment log yet.
 *  - AC4: the product's own embedding status (generated/missing, model,
 *    date) is shown (US-046: embedding lives per-product, not per-base).
 *  - AC5: manually-set brand/family stay visually distinct, as in the
 *    existing list/form.
 */
class ProductResourceViewTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // ProductObserver auto-dispatches GenerateProductEmbeddingJob
        // synchronously (QUEUE_CONNECTION=sync) whenever a Product with a
        // product_type/description_clean/brand_id is created via factory.
        // Pointing the provider at an unreachable host makes that job fail
        // harmlessly instead of hitting the real Ollama service and creating
        // an unwanted embedding row.
        config()->set('services.embedding.base_url', 'https://embedding.test');
    }

    public function test_list_exposes_the_view_action(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create();

        Livewire::test(ListProducts::class)
            ->assertTableActionExists(ViewAction::class, record: $product);
    }

    public function test_view_page_loads_for_an_existing_product(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create();

        Livewire::test(ViewProduct::class, ['record' => $product->getRouteKey()])
            ->assertOk();
    }

    public function test_manual_brand_and_family_are_visually_distinct_in_view_page(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create([
            'brand_source' => 'manual',
            'family_source' => 'ai',
        ]);

        $component = Livewire::test(ViewProduct::class, ['record' => $product->getRouteKey()]);

        $component->assertSchemaComponentExists(
            'brand.name',
            checkComponentUsing: fn (TextEntry $component): bool => $component->getColor(null) === 'info'
                && $component->getIcon(null) === Heroicon::OutlinedLockClosed,
        );

        $component->assertSchemaComponentExists(
            'family.name',
            checkComponentUsing: fn (TextEntry $component): bool => $component->getColor(null) === 'gray'
                && $component->getIcon(null) === null,
        );
    }

    public function test_view_page_shows_generated_embedding_status(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create();
        ProductEmbedding::factory()->create(['product_id' => $product->id, 'model' => 'text-embedding-3-small']);

        Livewire::test(ViewProduct::class, ['record' => $product->getRouteKey()])
            ->assertSchemaStateSet([
                'embedding_status' => 'Generato',
                'embedding.model' => 'text-embedding-3-small',
            ]);
    }

    public function test_view_page_shows_missing_embedding_status_when_product_has_no_embedding(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create();

        Livewire::test(ViewProduct::class, ['record' => $product->getRouteKey()])
            ->assertSchemaStateSet(['embedding_status' => 'Assente']);
    }

    public function test_view_page_shows_enrichment_history_in_chronological_order(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create();

        $first = EnrichmentLog::factory()->create([
            'product_id' => $product->id,
            'step' => 'deterministic',
            'confidence' => 60,
            'model' => null,
            'tokens_in' => null,
            'tokens_out' => null,
            'created_at' => now()->subMinutes(10),
        ]);
        $second = EnrichmentLog::factory()->create([
            'product_id' => $product->id,
            'step' => 'ai',
            'confidence' => 92,
            'model' => 'gpt-4o-mini',
            'tokens_in' => 350,
            'tokens_out' => 120,
            'created_at' => now(),
        ]);

        Livewire::test(ViewProduct::class, ['record' => $product->getRouteKey()])
            ->assertSchemaComponentExists(
                'enrichmentLogs',
                checkComponentUsing: function (RepeatableEntry $component) use ($first, $second): bool {
                    $state = $component->getState();

                    Assert::assertSame([$first->id, $second->id], $state->pluck('id')->all());
                    Assert::assertSame(['deterministic', 'ai'], $state->pluck('step')->all());
                    Assert::assertSame([60, 92], $state->pluck('confidence')->all());
                    Assert::assertSame([null, 'gpt-4o-mini'], $state->pluck('model')->all());
                    Assert::assertSame([null, 350], $state->pluck('tokens_in')->all());
                    Assert::assertSame([null, 120], $state->pluck('tokens_out')->all());

                    return true;
                },
            );
    }

    public function test_view_page_shows_empty_state_when_no_enrichment_logs(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create();

        Livewire::test(ViewProduct::class, ['record' => $product->getRouteKey()])
            ->assertSee('Nessun arricchimento eseguito')
            ->assertSchemaComponentExists(
                'enrichmentLogs',
                checkComponentUsing: fn (RepeatableEntry $component): bool => ! $component->isVisible(),
            );
    }

    public function test_view_page_hides_empty_state_when_enrichment_logs_exist(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create();
        EnrichmentLog::factory()->create(['product_id' => $product->id]);

        Livewire::test(ViewProduct::class, ['record' => $product->getRouteKey()])
            ->assertSchemaComponentExists(
                'enrichmentLogs',
                checkComponentUsing: fn (RepeatableEntry $component): bool => $component->isVisible(),
            )
            ->assertSchemaComponentExists(
                'enrichment_empty_state',
                checkComponentUsing: fn (TextEntry $component): bool => ! $component->isVisible(),
            );
    }

    /**
     * US-031 AC4: the same manual reprocessing actions available from the
     * products table row must also be available (and behave the same) from
     * the product view page header.
     */
    public function test_view_page_relaunch_deterministic_enrichment_dispatches_job(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create();

        Queue::fake();

        Livewire::test(ViewProduct::class, ['record' => $product->getRouteKey()])
            ->callAction('relaunchDeterministicEnrichment')
            ->assertNotified();

        Queue::assertPushed(
            RunDeterministicEnrichmentJob::class,
            static fn (RunDeterministicEnrichmentJob $job): bool => $job->productId === $product->id,
        );
    }

    public function test_view_page_relaunch_ai_classification_dispatches_job_regardless_of_current_status(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create([
            'description_raw' => 'CALDAIA A CONDENSAZIONE 25KW',
            'enrichment_status' => 'enriched',
        ]);

        Queue::fake();

        Livewire::test(ViewProduct::class, ['record' => $product->getRouteKey()])
            ->callAction('relaunchAiClassification')
            ->assertNotified();

        Queue::assertPushed(
            ClassifyProductsBatchJob::class,
            static fn (ClassifyProductsBatchJob $job): bool => $job->productIds === [$product->id],
        );
    }

    public function test_view_page_relaunch_ai_classification_is_disabled_when_description_is_empty(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create([
            'description_raw' => '',
            'description_clean' => null,
        ]);

        Queue::fake();

        Livewire::test(ViewProduct::class, ['record' => $product->getRouteKey()])
            ->assertActionDisabled('relaunchAiClassification');

        Queue::assertNotPushed(ClassifyProductsBatchJob::class);
    }

    public function test_view_page_regenerate_embedding_dispatches_job(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create();

        Queue::fake();

        Livewire::test(ViewProduct::class, ['record' => $product->getRouteKey()])
            ->callAction('regenerateEmbedding')
            ->assertNotified();

        Queue::assertPushed(
            GenerateProductEmbeddingJob::class,
            static fn (GenerateProductEmbeddingJob $job): bool => $job->productId === $product->id,
        );
    }
}
