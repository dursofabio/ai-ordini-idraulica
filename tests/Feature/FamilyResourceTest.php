<?php

namespace Tests\Feature;

use App\Filament\Resources\Families\Pages\CreateFamily;
use App\Filament\Resources\Families\Pages\EditFamily;
use App\Filament\Resources\Families\Pages\ListFamilies;
use App\Models\Family;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-022 acceptance criteria for the Family backoffice resource (AC2):
 * `FamilyResource` allows full CRUD on `name`, `slug`, and `aliases`.
 */
class FamilyResourceTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_list_shows_families(): void
    {
        $admin = User::factory()->create();
        $family = Family::factory()->create();

        $this->actingAs($admin);

        Livewire::test(ListFamilies::class)
            ->assertCanSeeTableRecords([$family]);
    }

    public function test_can_create_family_with_name_slug_and_aliases(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin);

        Livewire::test(CreateFamily::class)
            ->fillForm([
                'name' => 'Riscaldamento',
                'slug' => 'riscaldamento',
                'aliases' => ['RISC'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $family = Family::where('slug', 'riscaldamento')->firstOrFail();
        $this->assertSame('Riscaldamento', $family->name);
        $this->assertSame(['RISC'], $family->aliases);
    }

    public function test_can_edit_family_name_slug_and_aliases(): void
    {
        $admin = User::factory()->create();
        $family = Family::factory()->create([
            'name' => 'Old Name',
            'slug' => 'old-name',
            'aliases' => ['OLD'],
        ]);

        $this->actingAs($admin);

        Livewire::test(EditFamily::class, ['record' => $family->getRouteKey()])
            ->fillForm([
                'name' => 'New Name',
                'slug' => 'new-name',
                'aliases' => ['NEW'],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $family->refresh();

        $this->assertSame('New Name', $family->name);
        $this->assertSame('new-name', $family->slug);
        $this->assertSame(['NEW'], $family->aliases);
    }
}
