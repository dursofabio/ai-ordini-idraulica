<?php

namespace Tests\Feature;

use App\Models\AttributeDefinition;
use App\Services\Ai\AttributeVocabulary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-043 TASK-02 — AttributeVocabulary registry vocabulary used to anchor AI
 * extraction:
 *  - toPromptText() renders key, type, canonical unit, and description for
 *    every seeded definition.
 *  - definitionFor() resolves a canonical key and returns null for a key
 *    outside the registry.
 *  - The definitions collection is memoized per instance (a single query
 *    across repeated calls).
 *
 * Runs against in-memory SQLite via RequiresDatabase.
 */
class AttributeVocabularyTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_prompt_text_includes_key_type_canonical_unit_and_description(): void
    {
        AttributeDefinition::factory()->numeric()->create([
            'key' => 'potenza_kw',
            'canonical_unit' => 'kW',
            'description' => 'Potenza nominale dell\'apparecchio, espressa in kilowatt (kW).',
        ]);

        AttributeDefinition::factory()->text()->create([
            'key' => 'materiale',
            'description' => 'Materiale di costruzione del componente.',
        ]);

        $prompt = (new AttributeVocabulary)->toPromptText();

        $this->assertStringContainsString('potenza_kw', $prompt);
        $this->assertStringContainsString('kW', $prompt);
        $this->assertStringContainsString("Potenza nominale dell'apparecchio, espressa in kilowatt (kW).", $prompt);

        $this->assertStringContainsString('materiale', $prompt);
        $this->assertStringContainsString('Materiale di costruzione del componente.', $prompt);
    }

    public function test_definition_for_resolves_canonical_key(): void
    {
        $definition = AttributeDefinition::factory()->numeric()->create(['key' => 'potenza_kw']);

        $resolved = (new AttributeVocabulary)->definitionFor('potenza_kw');

        $this->assertNotNull($resolved);
        $this->assertSame($definition->id, $resolved->id);
    }

    public function test_definition_for_trims_whitespace_before_matching(): void
    {
        AttributeDefinition::factory()->numeric()->create(['key' => 'potenza_kw']);

        $resolved = (new AttributeVocabulary)->definitionFor('  potenza_kw  ');

        $this->assertNotNull($resolved);
        $this->assertSame('potenza_kw', $resolved->key);
    }

    public function test_definition_for_returns_null_for_key_outside_registry(): void
    {
        AttributeDefinition::factory()->numeric()->create(['key' => 'potenza_kw']);

        $this->assertNull((new AttributeVocabulary)->definitionFor('chiave_inesistente'));
    }

    public function test_definitions_are_memoized_after_a_single_query(): void
    {
        AttributeDefinition::factory()->numeric()->create(['key' => 'potenza_kw']);
        AttributeDefinition::factory()->text()->create(['key' => 'materiale']);

        $vocabulary = new AttributeVocabulary;

        DB::enableQueryLog();

        $vocabulary->toPromptText();
        $vocabulary->definitionFor('potenza_kw');
        $vocabulary->definitionFor('materiale');
        $vocabulary->definitionFor('chiave_inesistente');

        $queries = DB::getQueryLog();

        DB::disableQueryLog();

        $this->assertCount(1, $queries);
    }
}
