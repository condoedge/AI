<?php
// tests/Unit/Services/Analytics/QueryAnalyticsTest.php

namespace Condoedge\Ai\Tests\Unit\Services\Analytics;

use Condoedge\Ai\Models\AiQueryLog;
use Condoedge\Ai\Services\Analytics\QueryAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase;

class QueryAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private QueryAnalytics $analytics;

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
        $this->loadMigrationsFrom(__DIR__ . '/../../../../database/migrations');
    }

    public function setUp(): void
    {
        parent::setUp();
        $this->analytics = new QueryAnalytics();
    }

    /** @test */
    public function it_calculates_success_rate(): void
    {
        AiQueryLog::create(['question' => 'Q1', 'status' => 'success']);
        AiQueryLog::create(['question' => 'Q2', 'status' => 'success']);
        AiQueryLog::create(['question' => 'Q3', 'status' => 'failed', 'error_message' => 'err']);

        $rate = $this->analytics->getSuccessRate(7);

        $this->assertEquals(66.67, round($rate, 2));
    }

    /** @test */
    public function it_calculates_average_execution_time(): void
    {
        AiQueryLog::create(['question' => 'Q1', 'status' => 'success', 'execution_time_ms' => 100]);
        AiQueryLog::create(['question' => 'Q2', 'status' => 'success', 'execution_time_ms' => 200]);

        $avg = $this->analytics->getAverageExecutionTime(7);

        $this->assertEquals(150, $avg);
    }

    /** @test */
    public function it_returns_dashboard_stats(): void
    {
        AiQueryLog::create(['question' => 'Q1', 'status' => 'success']);

        $stats = $this->analytics->getDashboardStats();

        $this->assertArrayHasKey('success_rate_7d', $stats);
        $this->assertArrayHasKey('avg_execution_time_7d', $stats);
        $this->assertArrayHasKey('total_queries_today', $stats);
        $this->assertArrayHasKey('failed_queries_today', $stats);
    }
}
