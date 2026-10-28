<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit;

use Condoedge\Ai\AiServiceProvider;
use Condoedge\Ai\Support\AiFeature;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;

/**
 * The master switch `ai.enabled` (env AI_ENABLED, default true), 2026-10-28: a host ships
 * the package to production with the assistant OFF — no chat UI, no routes, no automatic
 * graph sync or file processing — and turns it back on by removing one .env line.
 */
class AiMasterSwitchDisabledTest extends AiMasterSwitchTestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('ai.enabled', false);
        // A host that turned a sub-feature on explicitly still gets it off: the master wins.
        $app['config']->set('ai.auto_sync.enabled', true);
        $app['config']->set('ai.admin.enabled', true);
    }

    /** @test */
    public function no_package_route_is_registered(): void
    {
        $this->assertFalse(AiFeature::enabled());
        $this->assertSame(array_fill_keys(array_keys($this->routes()), false), $this->routes());
    }

    /** @test */
    public function background_features_are_forced_off(): void
    {
        $this->assertFalse((bool) config('ai.auto_sync.enabled'));
        $this->assertFalse((bool) config('ai.file_processing.enabled'));
        $this->assertFalse((bool) config('ai.admin.enabled'));
        $this->assertFalse((bool) config('ai.tasks.enabled'));
    }

    /** @test */
    public function the_env_value_is_read_as_a_boolean(): void
    {
        foreach (['false' => false, '0' => false, 'off' => false, 'true' => true, '1' => true] as $raw => $expected) {
            config(['ai.enabled' => $raw]);
            $this->assertSame($expected, AiFeature::enabled(), (string) $raw);
        }
    }
}
