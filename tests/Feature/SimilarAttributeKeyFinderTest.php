<?php

namespace Tests\Feature;

use App\Models\AttributeDefinition;
use App\Services\Enrichment\SimilarAttributeKeyFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-044 TASK-03 — {@see SimilarAttributeKeyFinder} coverage:
 *  - a key almost identical to an existing one is returned first (closest by
 *    normalized Levenshtein distance).
 *  - the number of returned results respects the `$limit` argument.
 *  - an empty registry returns an empty collection without raising.
 *
 * Runs against in-memory SQLite via RequiresDatabase, matching the sibling
 * registry-backed service test suites.
 */
class SimilarAttributeKeyFinderTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_almost_identical_key_is_returned_as_the_closest_match(): void
    {
        AttributeDefinition::factory()->create(['key' => 'portata_l_min']);
        AttributeDefinition::factory()->create(['key' => 'potenza_kw']);
        AttributeDefinition::factory()->create(['key' => 'diametro']);

        $results = (new SimilarAttributeKeyFinder)->find('portata_lmin');

        $this->assertSame('portata_l_min', $results->first()['key']);
    }

    public function test_returned_definition_carries_type_unit_and_description(): void
    {
        AttributeDefinition::factory()->create([
            'key' => 'portata_l_min',
            'data_type' => 'numeric',
            'canonical_unit' => 'l/min',
            'description' => 'Portata nominale',
        ]);

        $results = (new SimilarAttributeKeyFinder)->find('portata_lmin');

        $this->assertSame([
            'key' => 'portata_l_min',
            'data_type' => 'numeric',
            'canonical_unit' => 'l/min',
            'description' => 'Portata nominale',
        ], $results->first());
    }

    public function test_result_count_respects_the_limit(): void
    {
        AttributeDefinition::factory()->count(10)->create();

        $results = (new SimilarAttributeKeyFinder)->find('chiave_qualsiasi', limit: 3);

        $this->assertCount(3, $results);
    }

    public function test_empty_registry_returns_an_empty_collection(): void
    {
        $results = (new SimilarAttributeKeyFinder)->find('qualsiasi_chiave');

        $this->assertTrue($results->isEmpty());
    }
}
