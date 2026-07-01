<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * US-002 acceptance criterion: the queues `import`, `enrich`, `embed` and
 * `default` must be defined in Horizon's supervisor configuration on the
 * redis connection. This test reads config('horizon') directly, so it needs
 * no live services.
 */
class HorizonQueueConfigurationTest extends TestCase
{
    public function test_required_queues_are_configured_in_horizon_defaults(): void
    {
        $supervisors = config('horizon.defaults');

        $this->assertIsArray($supervisors);
        $this->assertNotEmpty($supervisors, 'Horizon must define at least one default supervisor.');

        $allQueues = [];
        foreach ($supervisors as $supervisor) {
            foreach ((array) ($supervisor['queue'] ?? []) as $queue) {
                $allQueues[] = $queue;
            }
        }

        foreach (['import', 'enrich', 'embed', 'default'] as $expected) {
            $this->assertContains(
                $expected,
                $allQueues,
                "The '{$expected}' queue must be configured in Horizon supervisors."
            );
        }
    }

    public function test_supervisor_uses_redis_connection(): void
    {
        $supervisors = config('horizon.defaults');

        foreach ($supervisors as $name => $supervisor) {
            $this->assertSame(
                'redis',
                $supervisor['connection'] ?? null,
                "Supervisor '{$name}' must run on the redis connection."
            );
        }
    }
}
