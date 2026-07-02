<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductBases\Pages\EditProductBase;
use App\Filament\Resources\ProductBases\Pages\ListProductBases;
use App\Filament\Resources\ProductBases\RelationManagers\ProductsRelationManager;
use App\Jobs\GenerateProductBaseEmbeddingJob;
use App\Models\Product;
use App\Models\ProductBase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-021 acceptance criteria for the product-base backoffice resource:
 *  - AC3: an admin can edit `title` and `description_ai` from the Edit page
 *    and the changes persist to the database.
 *  - AC4: the "Rigenera embedding" action dispatches
 *    GenerateProductBaseEmbeddingJob unconditionally (regardless of whether
 *    description_ai changed), both from the Edit page header action and from
 *    the table row action.
 */
class ProductBaseResourceTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_editing_title_and_description_ai_persists_changes(): void
    {
        $admin = User::factory()->create();
        $productBase = ProductBase::factory()->create([
            'title' => 'Vecchio titolo',
            'description_ai' => 'Vecchia descrizione',
        ]);

        $this->actingAs($admin);

        Livewire::test(EditProductBase::class, ['record' => $productBase->getRouteKey()])
            ->fillForm([
                'title' => 'Nuovo titolo',
                'description_ai' => 'Nuova descrizione',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $productBase->refresh();

        $this->assertSame('Nuovo titolo', $productBase->title);
        $this->assertSame('Nuova descrizione', $productBase->description_ai);
    }

    public function test_regenerate_embedding_action_dispatches_job_from_edit_page(): void
    {
        $admin = User::factory()->create();
        $productBase = ProductBase::factory()->create();

        Queue::fake();

        $this->actingAs($admin);

        Livewire::test(EditProductBase::class, ['record' => $productBase->getRouteKey()])
            ->callAction('regenerateEmbedding');

        Queue::assertPushed(
            GenerateProductBaseEmbeddingJob::class,
            static fn (GenerateProductBaseEmbeddingJob $job): bool => $job->productBaseId === $productBase->id,
        );
    }

    public function test_regenerate_embedding_action_dispatches_job_even_when_description_ai_unchanged(): void
    {
        $admin = User::factory()->create();
        $productBase = ProductBase::factory()->create([
            'description_ai' => 'Descrizione invariata',
        ]);

        Queue::fake();

        $this->actingAs($admin);

        $component = Livewire::test(EditProductBase::class, ['record' => $productBase->getRouteKey()]);

        $component->callAction('regenerateEmbedding');
        $component->callAction('regenerateEmbedding');

        $productBase->refresh();

        $this->assertSame('Descrizione invariata', $productBase->description_ai);
        Queue::assertPushed(GenerateProductBaseEmbeddingJob::class, 2);
    }

    public function test_regenerate_embedding_action_dispatches_job_from_table_row_action(): void
    {
        $admin = User::factory()->create();
        $productBase = ProductBase::factory()->create();

        Queue::fake();

        $this->actingAs($admin);

        Livewire::test(ListProductBases::class)
            ->callTableAction('regenerateEmbedding', $productBase);

        Queue::assertPushed(
            GenerateProductBaseEmbeddingJob::class,
            static fn (GenerateProductBaseEmbeddingJob $job): bool => $job->productBaseId === $productBase->id,
        );
    }

    public function test_list_table_shows_products_count(): void
    {
        $admin = User::factory()->create();
        $productBase = ProductBase::factory()->create();
        Product::factory()->count(3)->create(['product_base_id' => $productBase->id]);

        $this->actingAs($admin);

        Livewire::test(ListProductBases::class)
            ->assertTableColumnStateSet('products_count', 3, $productBase);
    }

    public function test_list_table_can_be_sorted_by_title(): void
    {
        $admin = User::factory()->create();
        $productBase1 = ProductBase::factory()->create(['title' => 'Zeta']);
        $productBase2 = ProductBase::factory()->create(['title' => 'Alpha']);

        $this->actingAs($admin);

        Livewire::test(ListProductBases::class)
            ->sortTable('title')
            ->assertCanSeeTableRecords([$productBase2, $productBase1], inOrder: true)
            ->sortTable('title', 'desc')
            ->assertCanSeeTableRecords([$productBase1, $productBase2], inOrder: true);
    }

    public function test_edit_page_renders_products_relation_manager(): void
    {
        $admin = User::factory()->create();
        $productBase = ProductBase::factory()->create();
        $products = Product::factory()->count(2)->create(['product_base_id' => $productBase->id]);

        $this->actingAs($admin);

        Livewire::test(EditProductBase::class, ['record' => $productBase->getRouteKey()])
            ->assertSeeLivewire(ProductsRelationManager::class);

        Livewire::test(ProductsRelationManager::class, [
            'ownerRecord' => $productBase,
            'pageClass' => EditProductBase::class,
        ])
            ->assertCanSeeTableRecords($products);
    }
}
