<?php

namespace Tests\Feature;

use App\Filament\Widgets\AiCostWidget;
use App\Models\EnrichmentLog;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\RequiresDatabase;
use Tests\TestCase;

/**
 * US-025 AC3 — AiCostWidget sums tokens for the last batch of
 * `enrichment_logs` (the group sharing the maximum `created_at`) and prices
 * it via AiSpendGuard::estimateCost().
 */
class AiCostWidgetTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.anthropic.model_fast', 'claude-fast-test');
        config()->set('services.anthropic.model_smart', 'claude-smart-test');
        config()->set('services.anthropic.pricing', [
            'model_fast' => ['input_per_million' => 1.0, 'output_per_million' => 5.0],
            'model_smart' => ['input_per_million' => 3.0, 'output_per_million' => 15.0],
        ]);
    }

    public function test_sums_only_the_last_batch_when_multiple_batches_exist(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $product = Product::factory()->create();

        // Older batch (should be ignored). `created_at` is not mass-assignable,
        // so it is forced after creation to control the batch grouping.
        EnrichmentLog::factory()->create([
            'product_id' => $product->id,
            'model' => 'claude-fast-test',
            'tokens_in' => 1_000_000,
            'tokens_out' => 1_000_000,
        ])->forceFill(['created_at' => Carbon::parse('2026-06-01 10:00:00')])->save();

        // Last batch: 2 rows sharing the same created_at timestamp.
        $lastBatchTimestamp = Carbon::parse('2026-07-01 10:00:00');

        EnrichmentLog::factory()->create([
            'product_id' => $product->id,
            'model' => 'claude-fast-test',
            'tokens_in' => 1_000_000,
            'tokens_out' => 1_000_000,
        ])->forceFill(['created_at' => $lastBatchTimestamp])->save();

        EnrichmentLog::factory()->create([
            'product_id' => $product->id,
            'model' => 'claude-smart-test',
            'tokens_in' => 1_000_000,
            'tokens_out' => 1_000_000,
        ])->forceFill(['created_at' => $lastBatchTimestamp])->save();

        // Last batch cost: fast (1*1 + 5*1 = 6.0) + smart (3*1 + 15*1 = 18.0) = 24.0
        Livewire::test(AiCostWidget::class)
            ->assertSee('$24.0000');
    }

    public function test_shows_zero_cost_without_errors_when_there_are_no_logs(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        Livewire::test(AiCostWidget::class)
            ->assertSee('$0.00')
            ->assertOk();
    }
}
