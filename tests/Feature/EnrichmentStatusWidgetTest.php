<?php

namespace Tests\Feature;

use App\Filament\Widgets\EnrichmentStatusWidget;
use App\Models\Product;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-025 AC2 — EnrichmentStatusWidget counts articles per `enrichment_status`
 * value, enumerating whatever statuses are actually present in the data.
 */
class EnrichmentStatusWidgetTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    public function test_shows_a_count_for_each_status_present_in_mixed_data(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        Product::factory()->count(2)->create(['enrichment_status' => 'pending']);
        Product::factory()->count(3)->create(['enrichment_status' => 'enriched']);
        Product::factory()->create(['enrichment_status' => 'needs_review']);
        Product::factory()->count(4)->create(['enrichment_status' => 'done']);

        Livewire::test(EnrichmentStatusWidget::class)->assertOk();

        $countsByLabel = $this->statCountsByLabel();
        ksort($countsByLabel);

        $this->assertSame([
            'Done' => '4',
            'Enriched' => '3',
            'Needs Review' => '1',
            'Pending' => '2',
        ], $countsByLabel);
    }

    public function test_shows_a_single_stat_when_only_one_status_is_present(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        Product::factory()->count(5)->create(['enrichment_status' => 'pending']);

        Livewire::test(EnrichmentStatusWidget::class)->assertOk();

        $this->assertSame(['Pending' => '5'], $this->statCountsByLabel());
    }

    /**
     * Invokes the widget's protected getStats() directly and reads each
     * Stat's label/value, so the test asserts the exact status -> count
     * pairing instead of matching loose single-character substrings in the
     * rendered HTML.
     *
     * @return array<string, string>
     */
    private function statCountsByLabel(): array
    {
        $widget = new EnrichmentStatusWidget;

        $method = new ReflectionMethod($widget, 'getStats');
        $method->setAccessible(true);

        /** @var array<int, Stat> $stats */
        $stats = $method->invoke($widget);

        $result = [];

        foreach ($stats as $stat) {
            $result[(string) $stat->getLabel()] = (string) $stat->getValue();
        }

        return $result;
    }
}
