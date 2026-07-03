<?php

namespace Tests\Feature;

use App\Filament\Resources\Brands\Pages\EditBrand;
use App\Models\Brand;
use App\Models\Product;
use App\Models\User;
use App\Services\Enrichment\BrandResolver;
use App\Services\Enrichment\EnrichmentProposalRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-022 spec "Dimostra" scenario (AC3): an admin adds 'VAILL' as an alias
 * of 'VAILLANT' from the backoffice; at the next deterministic enrichment
 * pass, descriptions containing 'VAILL' resolve correctly to VAILLANT.
 *
 * `BrandResolver` (US-009) already loads `Brand::all(['id','name','aliases'])`
 * on every call, so no resolver changes are needed — this test proves the
 * backoffice and the resolver share the same data source end to end.
 */
class BrandAliasBackofficeResolverTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_alias_added_from_backoffice_is_used_by_brand_resolver(): void
    {
        $admin = User::factory()->create();
        $brand = Brand::factory()->create(['name' => 'VAILLANT', 'aliases' => []]);

        $this->actingAs($admin);

        Livewire::test(EditBrand::class, ['record' => $brand->getRouteKey()])
            ->fillForm(['aliases' => ['VAILL']])
            ->call('save')
            ->assertHasNoFormErrors();

        $product = Product::factory()->create([
            'description_raw' => 'Caldaia a condensazione VAILL 25kW',
            'brand_id' => null,
        ]);

        $resolved = (new BrandResolver(new EnrichmentProposalRecorder))->resolve($product);

        $this->assertTrue($resolved);
        $product->refresh();
        $this->assertSame($brand->id, $product->brand_id);
        $this->assertSame('dictionary', $product->brand_source);
    }
}
