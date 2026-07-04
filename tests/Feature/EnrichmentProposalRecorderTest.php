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

    /**
     * US-044 AC1: a first out-of-registry key creates the `attribute_definition`
     * proposal row with `data_type` inferred as `numeric` (a `value_num` was
     * present) and `unit` kept as read.
     */
    public function test_records_a_numeric_attribute_definition_proposal(): void
    {
        $product = Product::factory()->create();

        $this->recorder()->recordAttributeDefinitionProposal($product, 'portata_lmin', [
            'value_num' => 12.5,
            'unit' => 'l/min',
            'confidence' => 80,
        ]);

        $this->assertDatabaseHas(EnrichmentProposal::class, [
            'product_id' => $product->id,
            'field' => 'attribute_definition',
            'attribute_key' => 'portata_lmin',
            'data_type' => 'numeric',
            'unit' => 'l/min',
            'value_text' => null,
            'origin' => 'ai',
            'status' => 'pending',
            'confidence' => 80,
        ]);
    }

    /**
     * US-044 AC1: a value carried in `value_text` (no `value_num`) infers
     * `data_type = 'text'`.
     */
    public function test_records_a_textual_attribute_definition_proposal(): void
    {
        $product = Product::factory()->create();

        $this->recorder()->recordAttributeDefinitionProposal($product, 'finitura_superficie', [
            'value_text' => 'satinata',
            'confidence' => 75,
        ]);

        $this->assertDatabaseHas(EnrichmentProposal::class, [
            'product_id' => $product->id,
            'field' => 'attribute_definition',
            'attribute_key' => 'finitura_superficie',
            'data_type' => 'text',
            'unit' => null,
            'value_text' => null,
            'status' => 'pending',
        ]);
    }

    /**
     * US-044 AC4: a second occurrence of the same key, while the first
     * proposal is still `pending`, does not create a second row.
     */
    public function test_repeated_key_while_pending_does_not_create_a_second_proposal(): void
    {
        $firstProduct = Product::factory()->create();
        $secondProduct = Product::factory()->create();

        $recorder = $this->recorder();
        $recorder->recordAttributeDefinitionProposal($firstProduct, 'portata_lmin', [
            'value_num' => 12.5,
            'unit' => 'l/min',
            'confidence' => 80,
        ]);
        $recorder->recordAttributeDefinitionProposal($secondProduct, 'portata_lmin', [
            'value_num' => 9.0,
            'unit' => 'l/min',
            'confidence' => 60,
        ]);

        $this->assertDatabaseCount(EnrichmentProposal::class, 1);
    }

    /**
     * US-044 AC4: once the only prior proposal for a key has been discarded,
     * a new occurrence of the same key creates a fresh proposal — the
     * discard does not permanently block the key.
     */
    public function test_discarded_prior_proposal_does_not_block_a_new_one_for_the_same_key(): void
    {
        $product = Product::factory()->create();
        EnrichmentProposal::factory()->create([
            'product_id' => $product->id,
            'field' => 'attribute_definition',
            'attribute_key' => 'portata_lmin',
            'status' => 'discarded',
        ]);

        $this->recorder()->recordAttributeDefinitionProposal($product, 'portata_lmin', [
            'value_num' => 12.5,
            'unit' => 'l/min',
            'confidence' => 80,
        ]);

        $this->assertDatabaseCount(EnrichmentProposal::class, 2);
        $this->assertDatabaseHas(EnrichmentProposal::class, [
            'product_id' => $product->id,
            'field' => 'attribute_definition',
            'attribute_key' => 'portata_lmin',
            'status' => 'pending',
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
