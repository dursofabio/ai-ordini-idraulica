<?php

namespace Tests\Feature;

use App\Models\EnrichmentProposal;
use App\Models\Product;
use App\Services\Enrichment\EnrichmentProposalRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-040 TASK-03 — foundation coverage for {@see EnrichmentProposalRecorder}:
 *  - `record()` persists a taxonomy-shaped proposal (brand/family/subfamily)
 *    with `value_id` set and the attribute-only columns left null.
 *  - `record()` persists an attribute-shaped proposal with `attribute_key`,
 *    `value_num`, and `unit` set and `value_id` left null.
 *  - `insertMany()` bulk-inserts multiple rows in one call.
 *
 * Runs against in-memory SQLite via RequiresDatabase, matching the sibling
 * resolver test suites.
 */
class EnrichmentProposalRecorderTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    private function recorder(): EnrichmentProposalRecorder
    {
        return new EnrichmentProposalRecorder;
    }

    public function test_records_a_taxonomy_proposal_with_value_id(): void
    {
        $product = Product::factory()->create();

        $proposal = $this->recorder()->record(
            product: $product,
            field: 'brand',
            origin: 'file',
            status: 'applied',
            confidence: 100,
            valueId: 5,
        );

        $this->assertDatabaseHas(EnrichmentProposal::class, [
            'id' => $proposal->id,
            'product_id' => $product->id,
            'field' => 'brand',
            'origin' => 'file',
            'status' => 'applied',
            'confidence' => 100,
            'value_id' => 5,
            'attribute_key' => null,
            'value_num' => null,
            'value_text' => null,
            'unit' => null,
        ]);
    }

    public function test_records_an_attribute_proposal_with_value_num_and_unit(): void
    {
        $product = Product::factory()->create();

        $proposal = $this->recorder()->record(
            product: $product,
            field: 'attribute',
            origin: 'regex',
            status: 'applied',
            confidence: 100,
            attributeKey: 'diametro',
            valueNum: 32.5,
            unit: 'mm',
        );

        $this->assertDatabaseHas(EnrichmentProposal::class, [
            'id' => $proposal->id,
            'product_id' => $product->id,
            'field' => 'attribute',
            'origin' => 'regex',
            'status' => 'applied',
            'confidence' => 100,
            'attribute_key' => 'diametro',
            'value_num' => 32.5,
            'unit' => 'mm',
            'value_id' => null,
        ]);
    }

    public function test_insert_many_bulk_inserts_multiple_rows(): void
    {
        $products = Product::factory()->count(3)->create();

        $rows = $products->map(fn (Product $product): array => [
            'product_id' => $product->id,
            'field' => 'family',
            'origin' => 'dictionary',
            'status' => 'pending',
            'confidence' => 80,
        ])->all();

        $this->recorder()->insertMany($rows);

        $this->assertDatabaseCount(EnrichmentProposal::class, 3);

        foreach ($products as $product) {
            $this->assertDatabaseHas(EnrichmentProposal::class, [
                'product_id' => $product->id,
                'field' => 'family',
                'origin' => 'dictionary',
                'status' => 'pending',
                'confidence' => 80,
            ]);
        }
    }
}
