<?php

namespace Tests\Feature;

use App\Models\AttributeDefinition;
use App\Services\Ai\AttributeDefinitionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-048 TASK-02 — AttributeDefinitionCatalog, the closed registry vocabulary
 * used to anchor the natural-language query parser:
 *  - toPromptText() renders key, type, canonical unit, and description for
 *    every seeded definition.
 *  - find() resolves a key case-insensitively and after trimming, and
 *    returns null for a key outside the registry.
 *  - An empty registry still produces valid (non-erroring) prompt text.
 *
 * Runs against in-memory SQLite via RequiresDatabase.
 */
class AttributeDefinitionCatalogTest extends TestCase
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

        $prompt = (new AttributeDefinitionCatalog)->toPromptText();

        $this->assertStringContainsString('potenza_kw', $prompt);
        $this->assertStringContainsString('numerico', $prompt);
        $this->assertStringContainsString('kW', $prompt);
        $this->assertStringContainsString("Potenza nominale dell'apparecchio, espressa in kilowatt (kW).", $prompt);

        $this->assertStringContainsString('materiale', $prompt);
        $this->assertStringContainsString('testo libero', $prompt);
        $this->assertStringContainsString('Materiale di costruzione del componente.', $prompt);
    }

    public function test_find_resolves_key_case_insensitively_and_trims_whitespace(): void
    {
        $definition = AttributeDefinition::factory()->numeric()->create(['key' => 'attacco_pollici']);

        $resolved = (new AttributeDefinitionCatalog)->find('  ATTACCO_Pollici  ');

        $this->assertNotNull($resolved);
        $this->assertSame($definition->id, $resolved->id);
    }

    public function test_find_returns_null_for_key_outside_registry(): void
    {
        AttributeDefinition::factory()->numeric()->create(['key' => 'attacco_pollici']);

        $this->assertNull((new AttributeDefinitionCatalog)->find('chiave_inesistente'));
    }

    public function test_empty_registry_produces_valid_prompt_text_without_error(): void
    {
        $prompt = (new AttributeDefinitionCatalog)->toPromptText();

        $this->assertIsString($prompt);
        $this->assertSame('', $prompt);
    }
}
