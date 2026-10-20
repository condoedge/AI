<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Console;

use Condoedge\Ai\Tests\Fixtures\TestCustomer;
use Condoedge\Ai\Tests\TestCase;
use Symfony\Component\Console\Exception\CommandNotFoundException;

/**
 * Guards the contract between ai:setup and the config validation command.
 *
 * ai:setup invokes the validation step by the name 'ai:validate-config'. If the
 * validation command is registered under a different signature, that step throws
 * CommandNotFoundException and the whole setup aborts before ingesting anything.
 */
class SetupCommandValidatesConfigTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('ai.llm.default', 'openai');
        $app['config']->set('ai.llm.openai.api_key', 'test-api-key-placeholder-for-testing-that-is-long-enough');
        $app['config']->set('ai.embedding.default', 'openai');
        $app['config']->set('ai.embedding.openai.api_key', 'test-api-key-placeholder-for-testing-that-is-long-enough');

        // Registering the package's console commands resolves services that build
        // AiLogger, whose channel falls back to logging.default. Testbench leaves
        // that null, so pin an explicit channel for the AiLogger constructor.
        $app['config']->set('ai.log_channel', 'null');
        $app['config']->set('logging.default', 'null');
        $app['config']->set('logging.channels.null', ['driver' => 'monolog', 'handler' => \Monolog\Handler\NullHandler::class]);
    }

    /** @test */
    public function validate_command_resolves_under_the_name_setup_calls(): void
    {
        // A minimal valid entity config so the command can run to completion once resolved.
        config(['entities' => [
            TestCustomer::class => [
                'graph' => ['label' => 'Customer', 'properties' => ['id', 'name']],
            ],
        ]]);

        // Resolving 'ai:validate-config' must not throw CommandNotFoundException.
        // Before the rename the command was registered as 'ai:config:validate',
        // so this call aborts ai:setup's validation step in production.
        try {
            $this->artisan('ai:validate-config')->assertSuccessful();
        } catch (CommandNotFoundException $e) {
            $this->fail(
                "ai:setup calls 'ai:validate-config'; the validation command must be "
                . "registered under that exact name. " . $e->getMessage()
            );
        }
    }

    /** @test */
    public function setup_dry_run_lists_the_validate_config_step(): void
    {
        // The dry run walks every configured step label. Its presence documents that
        // ai:setup wires the validation step into its plan.
        $this->artisan('ai:setup', ['--dry-run' => true])
            ->expectsOutputToContain('Validate configuration')
            ->assertSuccessful();
    }
}
