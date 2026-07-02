<?php

namespace Tests\Feature;

use App\Services\Enrichment\AiSpendGuard;
use Tests\TestCase;

/**
 * US-016 acceptance criteria — configurable AI spend cap:
 *  - estimateCost() applies the pricing configured for model_fast/model_smart.
 *  - spend() accumulates correctly across successive calls for the same runId.
 *  - remainingBudget()/capExceeded() respect a configured cap, and return
 *    null/false respectively when no cap is configured.
 */
class AiSpendGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');

        config()->set('services.anthropic.model_fast', 'claude-fast-test');
        config()->set('services.anthropic.model_smart', 'claude-smart-test');
        config()->set('services.anthropic.pricing', [
            'model_fast' => ['input_per_million' => 1.0, 'output_per_million' => 5.0],
            'model_smart' => ['input_per_million' => 3.0, 'output_per_million' => 15.0],
        ]);
        config()->set('services.anthropic.batch_cost_cap', null);
    }

    public function test_estimate_cost_applies_configured_pricing_for_fast_model(): void
    {
        $guard = new AiSpendGuard;

        // 1,000,000 input tokens * $1.0 + 1,000,000 output tokens * $5.0 = $6.0
        $cost = $guard->estimateCost('claude-fast-test', 1_000_000, 1_000_000);

        $this->assertEqualsWithDelta(6.0, $cost, 0.0001);
    }

    public function test_estimate_cost_applies_configured_pricing_for_smart_model(): void
    {
        $guard = new AiSpendGuard;

        // 1,000,000 input tokens * $3.0 + 1,000,000 output tokens * $15.0 = $18.0
        $cost = $guard->estimateCost('claude-smart-test', 1_000_000, 1_000_000);

        $this->assertEqualsWithDelta(18.0, $cost, 0.0001);
    }

    public function test_spend_accumulates_across_successive_calls_for_the_same_run(): void
    {
        $guard = new AiSpendGuard;
        $runId = 'run-accumulate';

        $firstTotal = $guard->spend($runId, 1.5);
        $secondTotal = $guard->spend($runId, 2.5);

        $this->assertEqualsWithDelta(1.5, $firstTotal, 0.0001);
        $this->assertEqualsWithDelta(4.0, $secondTotal, 0.0001);
    }

    public function test_remaining_budget_and_cap_exceeded_are_null_and_false_without_a_configured_cap(): void
    {
        $guard = new AiSpendGuard;
        $runId = 'run-no-cap';

        $guard->spend($runId, 1000.0);

        $this->assertNull($guard->remainingBudget($runId));
        $this->assertFalse($guard->capExceeded($runId));
    }

    public function test_remaining_budget_and_cap_exceeded_respect_a_configured_cap(): void
    {
        config()->set('services.anthropic.batch_cost_cap', 10.0);

        $guard = new AiSpendGuard;
        $runId = 'run-with-cap';

        $guard->spend($runId, 4.0);

        $this->assertEqualsWithDelta(6.0, $guard->remainingBudget($runId), 0.0001);
        $this->assertFalse($guard->capExceeded($runId));

        $guard->spend($runId, 6.0);

        $this->assertEqualsWithDelta(0.0, $guard->remainingBudget($runId), 0.0001);
        $this->assertTrue($guard->capExceeded($runId));
    }
}
