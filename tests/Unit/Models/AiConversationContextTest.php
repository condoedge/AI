<?php
// tests/Unit/Models/AiConversationContextTest.php

namespace Condoedge\Ai\Tests\Unit\Models;

use Condoedge\Ai\Models\AiConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase;

class AiConversationContextTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Don't load the full AI service provider to avoid API key requirements
     */
    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Set up in-memory SQLite database for testing
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        // Run migrations for the AI tables
        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');
    }

    /** @test */
    public function it_stores_context_snapshot_as_json(): void
    {
        $conversation = AiConversation::create([
            'user_id' => 1,
            'title' => 'Test conversation',
        ]);

        $conversation->updateContextSnapshot([
            'focused_entity' => 'Customer',
            'mentioned_entities' => ['Customer', 'Order'],
            'active_filters' => ['team_id' => 5],
        ]);

        $conversation->refresh();

        $this->assertEquals('Customer', $conversation->context_snapshot['focused_entity']);
        $this->assertContains('Order', $conversation->context_snapshot['mentioned_entities']);
    }

    /** @test */
    public function it_gets_focused_entity_from_snapshot(): void
    {
        $conversation = AiConversation::create([
            'user_id' => 1,
            'context_snapshot' => [
                'focused_entity' => 'Customer',
                'last_query_type' => 'count',
            ],
        ]);

        $this->assertEquals('Customer', $conversation->getFocusedEntity());
        $this->assertEquals('count', $conversation->getLastQueryType());
    }

    /** @test */
    public function it_returns_null_for_empty_context(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);

        $this->assertNull($conversation->getFocusedEntity());
        $this->assertNull($conversation->getLastQueryType());
    }
}
