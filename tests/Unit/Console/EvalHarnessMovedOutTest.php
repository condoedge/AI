<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Console;

use Condoedge\Ai\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

/**
 * Phase 4.4/4.6: the eval harness lives in the dev-only condoedge/ai-eval
 * package, and the hand-copied pipelines (ai:test-question /
 * ai:test-conversation) are gone — the production package registers none of them.
 */
class EvalHarnessMovedOutTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('ai.llm.default', 'openai');
        $app['config']->set('ai.llm.openai.api_key', 'test-api-key-placeholder-for-testing-that-is-long-enough');
        $app['config']->set('ai.embedding.default', 'openai');
        $app['config']->set('ai.embedding.openai.api_key', 'test-api-key-placeholder-for-testing-that-is-long-enough');
    }

    /** @test */
    public function the_production_package_registers_no_eval_or_hand_copied_pipeline_command(): void
    {
        $commands = Artisan::all();

        foreach (['ai:eval', 'ai:test-question', 'ai:test-conversation'] as $name) {
            $this->assertArrayNotHasKey($name, $commands, "{$name} must not be registered by condoedge/ai");
        }
        $this->assertArrayHasKey('ai:enhance', $commands);
        $this->assertNull(config('ai.eval'), 'eval config moved to ai-eval');
    }
}
