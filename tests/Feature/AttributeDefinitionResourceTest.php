<?php

namespace Tests\Feature;

use App\Filament\Resources\AttributeDefinitions\Pages\CreateAttributeDefinition;
use App\Filament\Resources\AttributeDefinitions\Pages\EditAttributeDefinition;
use App\Filament\Resources\AttributeDefinitions\Pages\ListAttributeDefinitions;
use App\Models\AttributeDefinition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * The registry of attribute keys (US-042) had no direct admin management
 * beyond the AI-proposal review queue: existing rows could not be listed,
 * edited, or deleted from the backoffice. `AttributeDefinitionResource`
 * closes that gap with full CRUD.
 */
class AttributeDefinitionResourceTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_list_shows_attribute_definitions(): void
    {
        $admin = User::factory()->create();
        $definition = AttributeDefinition::factory()->numeric()->create();

        $this->actingAs($admin);

        Livewire::test(ListAttributeDefinitions::class)
            ->assertCanSeeTableRecords([$definition]);
    }

    public function test_can_create_numeric_attribute_definition(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin);

        Livewire::test(CreateAttributeDefinition::class)
            ->fillForm([
                'key' => 'portata_lmin',
                'data_type' => 'numeric',
                'canonical_unit' => 'l/min',
                'accepted_units' => ['l/min' => 1, 'lt/min' => 1],
                'description' => 'Portata espressa in litri al minuto.',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('attribute_definitions', [
            'key' => 'portata_lmin',
            'data_type' => 'numeric',
            'canonical_unit' => 'l/min',
        ]);

        $definition = AttributeDefinition::where('key', 'portata_lmin')->firstOrFail();
        $this->assertSame(['l/min' => 1, 'lt/min' => 1], $definition->accepted_units);
    }

    public function test_can_create_text_attribute_definition_without_unit_fields(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin);

        Livewire::test(CreateAttributeDefinition::class)
            ->fillForm([
                'key' => 'colore_ral',
                'data_type' => 'text',
                'description' => 'Codice colore RAL.',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('attribute_definitions', [
            'key' => 'colore_ral',
            'data_type' => 'text',
            'canonical_unit' => null,
        ]);
    }

    public function test_cannot_create_attribute_definition_with_duplicate_key(): void
    {
        $admin = User::factory()->create();
        AttributeDefinition::factory()->numeric()->create(['key' => 'potenza_kw']);

        $this->actingAs($admin);

        Livewire::test(CreateAttributeDefinition::class)
            ->fillForm([
                'key' => 'potenza_kw',
                'data_type' => 'numeric',
            ])
            ->call('create')
            ->assertHasFormErrors(['key']);
    }

    public function test_can_edit_attribute_definition(): void
    {
        $admin = User::factory()->create();
        $definition = AttributeDefinition::factory()->numeric()->create([
            'key' => 'pressione_bar',
            'description' => 'Old description',
        ]);

        $this->actingAs($admin);

        Livewire::test(EditAttributeDefinition::class, ['record' => $definition->getRouteKey()])
            ->fillForm([
                'description' => 'Pressione di esercizio, in bar.',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $definition->refresh();
        $this->assertSame('Pressione di esercizio, in bar.', $definition->description);
    }

    public function test_editing_attribute_definition_can_keep_its_own_key(): void
    {
        $admin = User::factory()->create();
        $definition = AttributeDefinition::factory()->numeric()->create(['key' => 'tensione_volt']);

        $this->actingAs($admin);

        Livewire::test(EditAttributeDefinition::class, ['record' => $definition->getRouteKey()])
            ->fillForm([
                'key' => 'tensione_volt',
            ])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_can_delete_attribute_definition(): void
    {
        $admin = User::factory()->create();
        $definition = AttributeDefinition::factory()->numeric()->create();

        $this->actingAs($admin);

        Livewire::test(EditAttributeDefinition::class, ['record' => $definition->getRouteKey()])
            ->callAction('delete');

        $this->assertDatabaseMissing('attribute_definitions', ['id' => $definition->id]);
    }
}
