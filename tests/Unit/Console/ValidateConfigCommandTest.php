<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Console;

use Condoedge\Ai\Tests\Fixtures\TestCustomer;
use Condoedge\Ai\Tests\TestCase;

class ValidateConfigCommandTest extends TestCase
{
    /**
     * Define environment setup for these tests.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return void
     */
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Set up minimal API keys for testing (command should work even with placeholder keys)
        $app['config']->set('ai.llm.default', 'openai');
        $app['config']->set('ai.llm.openai.api_key', 'test-api-key-placeholder-for-testing-that-is-long-enough');
        $app['config']->set('ai.embedding.default', 'openai');
        $app['config']->set('ai.embedding.openai.api_key', 'test-api-key-placeholder-for-testing-that-is-long-enough');
    }

    /** @test */
    public function it_validates_entity_config_structure(): void
    {
        // Set up valid config for success test using an existing test fixture class
        config(['entities' => [
            TestCustomer::class => [
                'graph' => [
                    'label' => 'Customer',
                    'properties' => ['id', 'name', 'email'],
                ],
                'metadata' => [
                    'aliases' => ['customer', 'client'],
                    'scopes' => [],
                ],
            ],
        ]]);

        $this->artisan('ai:validate-config')
            ->assertSuccessful();
    }

    /** @test */
    public function it_detects_missing_required_fields(): void
    {
        // Test with invalid config - missing 'graph' key
        config(['entities' => [
            'App\\Models\\Customer' => [
                // Missing 'graph' key
            ],
        ]]);

        $this->artisan('ai:validate-config')
            ->assertFailed()
            ->expectsOutputToContain('missing');
    }

    /** @test */
    public function it_fails_when_no_entities_configured(): void
    {
        config(['entities' => []]);

        $this->artisan('ai:validate-config')
            ->assertFailed()
            ->expectsOutputToContain('No entities configured');
    }

    /** @test */
    public function it_detects_missing_graph_label(): void
    {
        config(['entities' => [
            'App\\Models\\Customer' => [
                'graph' => [
                    // Missing 'label'
                    'properties' => ['id', 'name'],
                ],
            ],
        ]]);

        $this->artisan('ai:validate-config')
            ->assertFailed()
            ->expectsOutputToContain('missing graph label');
    }

    /** @test */
    public function it_warns_about_missing_properties(): void
    {
        config(['entities' => [
            TestCustomer::class => [
                'graph' => [
                    'label' => 'Customer',
                    // Missing 'properties'
                ],
            ],
        ]]);

        $this->artisan('ai:validate-config')
            ->assertSuccessful() // Warnings don't cause failure
            ->expectsOutputToContain('no properties configured');
    }

    /** @test */
    public function it_does_not_ask_for_a_vector_block(): void
    {
        // Records are written to the graph only: an entity without a 'vector' block is complete.
        config(['entities' => [
            TestCustomer::class => [
                'graph' => [
                    'label' => 'Customer',
                    'properties' => ['id', 'name'],
                ],
            ],
        ]]);

        $this->artisan('ai:validate-config')
            ->assertSuccessful()
            ->expectsOutputToContain('All configurations are valid!')
            ->doesntExpectOutputToContain('vector');
    }

    /** @test */
    public function it_accepts_valid_computed_with_and_casts(): void
    {
        config(['entities' => [
            TestCustomer::class => [
                'graph' => [
                    'label' => 'Customer',
                    'properties' => ['id', 'name'],
                    'computed' => [
                        'summary' => 'getEmbeddingText',      // method
                        'shout' => fn ($m) => $m->name,       // callable: not checkable, accepted
                    ],
                    'with' => ['orders'],
                    'casts' => ['status' => 'string', 'lifetime_value' => 'decimal'],
                ],
            ],
        ]]);

        $this->artisan('ai:validate-config')
            ->assertSuccessful()
            ->doesntExpectOutputToContain('computed')
            ->doesntExpectOutputToContain('graph.with')
            ->doesntExpectOutputToContain('graph.casts');
    }

    /** @test */
    public function it_warns_about_missing_computed_accessor_relation_and_unknown_cast(): void
    {
        config(['entities' => [
            TestCustomer::class => [
                'graph' => [
                    'label' => 'Customer',
                    'properties' => ['id', 'name'],
                    'computed' => ['due_amount' => 'real_due_amount'],
                    'with' => ['orders', 'invoiceDetails.taxDetails'],
                    'casts' => ['status' => 'uuid'],
                ],
            ],
        ]]);

        $this->artisan('ai:validate-config')
            ->assertSuccessful() // warnings, not errors
            ->expectsOutputToContain("computed 'due_amount'")
            ->expectsOutputToContain("graph.with 'invoiceDetails.taxDetails'")
            ->expectsOutputToContain("graph.casts 'status'");
    }

    /** @test */
    public function it_reports_nonexistent_class(): void
    {
        config(['entities' => [
            'App\\Models\\NonExistentModel' => [
                'graph' => [
                    'label' => 'NonExistent',
                    'properties' => ['id'],
                ],
            ],
        ]]);

        $this->artisan('ai:validate-config')
            ->assertFailed()
            ->expectsOutputToContain('class does not exist');
    }
}
