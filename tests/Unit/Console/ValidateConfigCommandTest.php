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
                'vector' => [
                    'collection' => 'customers',
                    'embed_fields' => ['name', 'description'],
                ],
                'metadata' => [
                    'aliases' => ['customer', 'client'],
                    'scopes' => [],
                ],
            ],
        ]]);

        $this->artisan('ai:config:validate')
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

        $this->artisan('ai:config:validate')
            ->assertFailed()
            ->expectsOutputToContain('missing');
    }

    /** @test */
    public function it_fails_when_no_entities_configured(): void
    {
        config(['entities' => []]);

        $this->artisan('ai:config:validate')
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
                'vector' => [
                    'collection' => 'customers',
                ],
            ],
        ]]);

        $this->artisan('ai:config:validate')
            ->assertFailed()
            ->expectsOutputToContain('missing graph label');
    }

    /** @test */
    public function it_detects_missing_vector_collection(): void
    {
        config(['entities' => [
            'App\\Models\\Customer' => [
                'graph' => [
                    'label' => 'Customer',
                    'properties' => ['id', 'name'],
                ],
                'vector' => [
                    // Missing 'collection'
                    'embed_fields' => ['name'],
                ],
            ],
        ]]);

        $this->artisan('ai:config:validate')
            ->assertFailed()
            ->expectsOutputToContain('missing vector collection');
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
                'vector' => [
                    'collection' => 'customers',
                    'embed_fields' => ['name'],
                ],
            ],
        ]]);

        $this->artisan('ai:config:validate')
            ->assertSuccessful() // Warnings don't cause failure
            ->expectsOutputToContain('no properties configured');
    }

    /** @test */
    public function it_warns_about_missing_vector_config(): void
    {
        config(['entities' => [
            TestCustomer::class => [
                'graph' => [
                    'label' => 'Customer',
                    'properties' => ['id', 'name'],
                ],
                // Missing 'vector'
            ],
        ]]);

        $this->artisan('ai:config:validate')
            ->assertSuccessful() // Warnings don't cause failure
            ->expectsOutputToContain("won't be searchable");
    }

    /** @test */
    public function it_warns_about_missing_embed_fields(): void
    {
        config(['entities' => [
            TestCustomer::class => [
                'graph' => [
                    'label' => 'Customer',
                    'properties' => ['id', 'name'],
                ],
                'vector' => [
                    'collection' => 'customers',
                    // Missing 'embed_fields'
                ],
            ],
        ]]);

        $this->artisan('ai:config:validate')
            ->assertSuccessful() // Warnings don't cause failure
            ->expectsOutputToContain('no embed fields');
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
                'vector' => [
                    'collection' => 'nonexistent',
                ],
            ],
        ]]);

        $this->artisan('ai:config:validate')
            ->assertFailed()
            ->expectsOutputToContain('class does not exist');
    }
}
