<?php

namespace Tests\Feature;

use App\Models\Family;
use App\Models\Product;
use App\Models\ProductBase;
use App\Models\Subfamily;
use App\Services\Enrichment\FamilyPropagationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-012 acceptance criteria — propagation of the prevailing family/subfamily
 * within a product_base group to variants without a classification:
 *  - Variants with a null family_id receive the group's prevailing family_id.
 *  - Propagated variants get family_source = 'propagated'.
 *  - Variants already classified with family_source 'file'/'ai'/'manual' are
 *    never overwritten, regardless of the group's prevailing value.
 *  - The resolver is idempotent: re-running it does not alter variants that
 *    were already propagated or already classified.
 *  - family and subfamily propagation are independent of one another.
 *
 * Runs against in-memory SQLite via RequiresDatabase, matching the
 * GroupingResolverTest/BrandResolverTest pattern.
 */
class FamilyPropagationResolverTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_propagates_the_prevailing_family_id_to_the_variant_with_the_highest_count(): void
    {
        $base = ProductBase::factory()->create();
        $majorityFamily = Family::factory()->create();
        $minorityFamily = Family::factory()->create();

        Product::factory()->count(2)->create([
            'product_base_id' => $base->id,
            'family_id' => $majorityFamily->id,
            'family_source' => 'file',
        ]);
        Product::factory()->create([
            'product_base_id' => $base->id,
            'family_id' => $minorityFamily->id,
            'family_source' => 'file',
        ]);
        $unclassified = Product::factory()->create([
            'product_base_id' => $base->id,
            'family_id' => null,
        ]);

        (new FamilyPropagationResolver)->resolve($base->fresh());

        $unclassified->refresh();
        $this->assertSame($majorityFamily->id, $unclassified->family_id);
        $this->assertSame('propagated', $unclassified->family_source);
    }

    public function test_tie_break_between_two_equally_frequent_family_ids_is_deterministic(): void
    {
        $base = ProductBase::factory()->create();
        $familyA = Family::factory()->create();
        $familyB = Family::factory()->create();
        $expectedWinner = min($familyA->id, $familyB->id);

        Product::factory()->create([
            'product_base_id' => $base->id,
            'family_id' => $familyA->id,
            'family_source' => 'file',
        ]);
        Product::factory()->create([
            'product_base_id' => $base->id,
            'family_id' => $familyB->id,
            'family_source' => 'file',
        ]);
        $unclassified = Product::factory()->create([
            'product_base_id' => $base->id,
            'family_id' => null,
        ]);

        (new FamilyPropagationResolver)->resolve($base->fresh());

        $unclassified->refresh();
        $this->assertSame($expectedWinner, $unclassified->family_id);

        // Re-running against a freshly built equivalent group must select
        // the same winner every time (deterministic, not run-order dependent).
        $otherBase = ProductBase::factory()->create();
        Product::factory()->create([
            'product_base_id' => $otherBase->id,
            'family_id' => $familyA->id,
            'family_source' => 'file',
        ]);
        Product::factory()->create([
            'product_base_id' => $otherBase->id,
            'family_id' => $familyB->id,
            'family_source' => 'file',
        ]);
        $otherUnclassified = Product::factory()->create([
            'product_base_id' => $otherBase->id,
            'family_id' => null,
        ]);

        (new FamilyPropagationResolver)->resolve($otherBase->fresh());

        $otherUnclassified->refresh();
        $this->assertSame($expectedWinner, $otherUnclassified->family_id);
    }

    public function test_demo_propagates_family_to_the_one_unclassified_variant_out_of_four(): void
    {
        $base = ProductBase::factory()->create();
        $family = Family::factory()->create();

        $classified = Product::factory()->count(3)->create([
            'product_base_id' => $base->id,
            'family_id' => $family->id,
            'family_source' => 'file',
        ]);
        $unclassified = Product::factory()->create([
            'product_base_id' => $base->id,
            'family_id' => null,
        ]);

        $updated = (new FamilyPropagationResolver)->resolve($base->fresh());

        $this->assertSame(1, $updated);
        $unclassified->refresh();
        $this->assertSame($family->id, $unclassified->family_id);
        $this->assertSame('propagated', $unclassified->family_source);

        foreach ($classified as $product) {
            $product->refresh();
            $this->assertSame($family->id, $product->family_id);
            $this->assertSame('file', $product->family_source);
        }
    }

    public function test_does_not_overwrite_a_variant_already_classified_with_file_ai_or_manual_source(): void
    {
        $base = ProductBase::factory()->create();
        $prevailingFamily = Family::factory()->create();
        $existingFamily = Family::factory()->create();

        Product::factory()->count(2)->create([
            'product_base_id' => $base->id,
            'family_id' => $prevailingFamily->id,
            'family_source' => 'file',
        ]);

        foreach (['file', 'ai', 'manual'] as $source) {
            $alreadyClassified = Product::factory()->create([
                'product_base_id' => $base->id,
                'family_id' => $existingFamily->id,
                'family_source' => $source,
            ]);

            (new FamilyPropagationResolver)->resolve($base->fresh());

            $alreadyClassified->refresh();
            $this->assertSame($existingFamily->id, $alreadyClassified->family_id);
            $this->assertSame($source, $alreadyClassified->family_source);
        }
    }

    public function test_re_running_resolve_after_a_first_propagation_updates_nothing_further(): void
    {
        $base = ProductBase::factory()->create();
        $family = Family::factory()->create();

        Product::factory()->count(2)->create([
            'product_base_id' => $base->id,
            'family_id' => $family->id,
            'family_source' => 'file',
        ]);
        $unclassified = Product::factory()->create([
            'product_base_id' => $base->id,
            'family_id' => null,
        ]);

        $firstRun = (new FamilyPropagationResolver)->resolve($base->fresh());
        $unclassified->refresh();
        $stateAfterFirstRun = [$unclassified->family_id, $unclassified->family_source];

        $secondRun = (new FamilyPropagationResolver)->resolve($base->fresh());

        $this->assertSame(1, $firstRun);
        $this->assertSame(0, $secondRun);
        $unclassified->refresh();
        $this->assertSame($stateAfterFirstRun, [$unclassified->family_id, $unclassified->family_source]);
    }

    public function test_group_without_any_classified_variant_produces_no_writes(): void
    {
        $base = ProductBase::factory()->create();

        $variants = Product::factory()->count(3)->create([
            'product_base_id' => $base->id,
            'family_id' => null,
        ]);

        $updated = (new FamilyPropagationResolver)->resolve($base->fresh());

        $this->assertSame(0, $updated);
        foreach ($variants as $variant) {
            $variant->refresh();
            $this->assertNull($variant->family_id);
        }
    }

    public function test_propagates_subfamily_independently_of_family_when_only_subfamily_has_a_majority(): void
    {
        $base = ProductBase::factory()->create();
        $subfamily = Subfamily::factory()->create();

        Product::factory()->count(2)->create([
            'product_base_id' => $base->id,
            'family_id' => null,
            'subfamily_id' => $subfamily->id,
            'subfamily_source' => 'file',
        ]);
        $unclassified = Product::factory()->create([
            'product_base_id' => $base->id,
            'family_id' => null,
            'subfamily_id' => null,
        ]);

        $updated = (new FamilyPropagationResolver)->resolve($base->fresh());

        $this->assertSame(1, $updated);
        $unclassified->refresh();
        $this->assertSame($subfamily->id, $unclassified->subfamily_id);
        $this->assertSame('propagated', $unclassified->subfamily_source);
        $this->assertNull($unclassified->family_id);
    }
}
