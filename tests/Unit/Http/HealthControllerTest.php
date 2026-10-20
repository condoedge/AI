<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Http;

use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Mockery;
use Orchestra\Testbench\TestCase;

/**
 * /api/ai/health must not be an unauthenticated leak: it is guarded by configurable
 * middleware (ai.health.middleware, default ['api', 'auth']) and never echoes raw
 * exception text (DSNs, hosts, credentials) back to the caller.
 */
class HealthControllerTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function defineRoutes($router): void
    {
        require __DIR__ . '/../../../routes/api.php';
    }

    /** @test */
    public function failing_checks_do_not_leak_exception_text(): void
    {
        $secret = 'bolt://neo4j:SuperSecretPassword@10.0.0.5:7687 refused';

        $graph = Mockery::mock(GraphStoreInterface::class);
        $graph->shouldReceive('getSchema')->andThrow(new \RuntimeException($secret));
        $vector = Mockery::mock(VectorStoreInterface::class);
        $vector->shouldReceive('listCollections')->andThrow(new \RuntimeException($secret));

        $this->app->instance(GraphStoreInterface::class, $graph);
        $this->app->instance(VectorStoreInterface::class, $vector);

        Log::spy();

        $response = (new HealthController())();

        $this->assertSame(503, $response->getStatusCode());
        $body = $response->getContent();
        $this->assertStringNotContainsString('SuperSecretPassword', $body);
        $this->assertStringNotContainsString('refused', $body);

        $data = $response->getData(true);
        $this->assertSame('unhealthy', $data['services']['neo4j']['status']);
        $this->assertSame('unhealthy', $data['services']['qdrant']['status']);
        $this->assertArrayNotHasKey('error', $data['services']['neo4j']);
        $this->assertArrayNotHasKey('error', $data['services']['qdrant']);

        // The detail is still available to operators — in the log, not the response.
        Log::shouldHaveReceived('warning')->twice();
    }

    /** @test */
    public function health_route_uses_the_default_auth_middleware(): void
    {
        $route = Route::getRoutes()->match(request()->create('/api/ai/health', 'GET'));

        $this->assertSame(['api', 'auth'], $route->gatherMiddleware());
    }

    /** @test */
    public function health_route_middleware_is_configurable(): void
    {
        config(['ai.health.middleware' => ['api', 'auth:sanctum', 'can:viewAiHealth']]);

        // Re-register the routes with the new config (the route file reads it at load time).
        Route::setRoutes(new \Illuminate\Routing\RouteCollection());
        require __DIR__ . '/../../../routes/api.php';

        $route = Route::getRoutes()->match(request()->create('/api/ai/health', 'GET'));

        $this->assertSame(['api', 'auth:sanctum', 'can:viewAiHealth'], $route->gatherMiddleware());
    }
}
