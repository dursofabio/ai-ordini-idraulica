<?php

namespace Tests\Feature;

use App\Filament\Resources\Subfamilies\Pages\CreateSubfamily;
use App\Filament\Resources\Subfamilies\Pages\EditSubfamily;
use App\Filament\Resources\Subfamilies\Pages\ListSubfamilies;
use App\Models\Family;
use App\Models\Subfamily;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-022 acceptance criteria for the Subfamily backoffice resource (AC2):
 * `SubfamilyResource` allows full CRUD on `name`, `slug`, `aliases`, and the
 * nullable `family_id` relationship.
 */
class SubfamilyResourceTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_list_shows_subfamilies(): void
    {
        $admin = User::factory()->create();
        $subfamily = Subfamily::factory()->create();

        $this->actingAs($admin);

        Livewire::test(ListSubfamilies::class)
            ->assertCanSeeTableRecords([$subfamily]);
    }

    public function test_can_create_subfamily_with_name_slug_aliases_and_family(): void
    {
        $admin = User::factory()->create();
        $family = Family::factory()->create();

        $this->actingAs($admin);

        Livewire::test(CreateSubfamily::class)
            ->fillForm([
                'name' => 'Valvole',
                'slug' => 'valvole',
                'aliases' => ['VLV'],
                'family_id' => $family->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $subfamily = Subfamily::where('slug', 'valvole')->firstOrFail();
        $this->assertSame('Valvole', $subfamily->name);
        $this->assertSame(['VLV'], $subfamily->aliases);
        $this->assertSame($family->id, $subfamily->family_id);
    }

    public function test_can_create_subfamily_without_family(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin);

        Livewire::test(CreateSubfamily::class)
            ->fillForm([
                'name' => 'Senza Famiglia',
                'slug' => 'senza-famiglia',
                'aliases' => [],
                'family_id' => null,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $subfamily = Subfamily::where('slug', 'senza-famiglia')->firstOrFail();
        $this->assertNull($subfamily->family_id);
    }

    public function test_can_edit_subfamily_name_slug_aliases_and_family(): void
    {
        $admin = User::factory()->create();
        $originalFamily = Family::factory()->create();
        $newFamily = Family::factory()->create();
        $subfamily = Subfamily::factory()->create([
            'name' => 'Old Name',
            'slug' => 'old-name',
            'aliases' => ['OLD'],
            'family_id' => $originalFamily->id,
        ]);

        $this->actingAs($admin);

        Livewire::test(EditSubfamily::class, ['record' => $subfamily->getRouteKey()])
            ->fillForm([
                'name' => 'New Name',
                'slug' => 'new-name',
                'aliases' => ['NEW'],
                'family_id' => $newFamily->id,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $subfamily->refresh();

        $this->assertSame('New Name', $subfamily->name);
        $this->assertSame('new-name', $subfamily->slug);
        $this->assertSame(['NEW'], $subfamily->aliases);
        $this->assertSame($newFamily->id, $subfamily->family_id);
    }
}
