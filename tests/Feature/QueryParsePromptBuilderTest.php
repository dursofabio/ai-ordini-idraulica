<?php

namespace Tests\Feature;

use App\Models\AttributeDefinition;
use App\Services\Ai\AttributeDefinitionCatalog;
use App\Services\Ai\QueryParsePromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-048 TASK-05 — QueryParsePromptBuilder payload:
 *  - The prompt embeds the closed registry vocabulary.
 *  - The prompt embeds the original query text.
 *  - The prompt instructs the model on the expected JSON schema and forbids
 *    inventing filters for uncertain mentions.
 *
 * Runs against in-memory SQLite via RequiresDatabase.
 */
class QueryParsePromptBuilderTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_prompt_includes_the_registry_vocabulary(): void
    {
        AttributeDefinition::factory()->numeric()->create([
            'key' => 'attacco_pollici',
            'canonical_unit' => '"',
            'description' => 'Dimensione dell\'attacco filettato, espressa in pollici (").',
        ]);

        $payload = (new QueryParsePromptBuilder)->build('tubo inox 1 pollice', new AttributeDefinitionCatalog, 'claude-test-model');

        $prompt = $payload['messages'][0]['content'];

        $this->assertStringContainsString('attacco_pollici', $prompt);
        $this->assertStringContainsString('Dimensione dell\'attacco filettato', $prompt);
    }

    public function test_prompt_includes_the_original_query(): void
    {
        $payload = (new QueryParsePromptBuilder)->build('tubo inox 1 pollice', new AttributeDefinitionCatalog, 'claude-test-model');

        $prompt = $payload['messages'][0]['content'];

        $this->assertStringContainsString('tubo inox 1 pollice', $prompt);
    }

    public function test_prompt_instructs_on_json_schema_and_forbids_invented_filters(): void
    {
        $payload = (new QueryParsePromptBuilder)->build('tubo inox 1 pollice', new AttributeDefinitionCatalog, 'claude-test-model');

        $prompt = $payload['messages'][0]['content'];

        $this->assertStringContainsString('"recognized_text"', $prompt);
        $this->assertStringContainsString('"attributes"', $prompt);
        $this->assertStringContainsString('ESCLUSIVAMENTE le chiavi del registro', $prompt);
        $this->assertStringContainsString('NON creare un filtro', $prompt);
    }

    public function test_payload_uses_the_given_model_and_role(): void
    {
        $payload = (new QueryParsePromptBuilder)->build('tubo inox', new AttributeDefinitionCatalog, 'claude-fast-model');

        $this->assertSame('claude-fast-model', $payload['model']);
        $this->assertSame('user', $payload['messages'][0]['role']);
    }
}
