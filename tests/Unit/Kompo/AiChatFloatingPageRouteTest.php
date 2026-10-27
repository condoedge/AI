<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Kompo;

use Condoedge\Ai\Kompo\AiChatFloating;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Orchestra\Testbench\TestCase;

/**
 * The chat knows which page it was opened from without each view wiring it: the
 * floating button records the route it is rendered on in its page context (kept for the
 * request that opens the chat). A host's own page context wins.
 */
class AiChatFloatingPageRouteTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [\Kompo\KompoServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('ai.chat.capture_page_route', true);
    }

    private function onPage(string $routeName, array $headers = []): void
    {
        $request = Request::create('/my-campaign/products', 'GET', server: array_combine(
            array_map(static fn ($h) => 'HTTP_' . strtoupper(str_replace('-', '_', $h)), array_keys($headers)),
            array_values($headers)
        ) ?: []);
        $route = (new RoutingRoute(['GET'], '/my-campaign/products', fn () => 'ok'))->name($routeName);
        $request->setRouteResolver(fn () => $route);
        $this->app->instance('request', $request);
    }

    private function floating(array $props = []): AiChatFloating
    {
        $floating = new AiChatFloating(null, $props);
        $floating->created();

        return $floating;
    }

    /** @test */
    public function the_route_the_button_is_rendered_on_becomes_the_page_context(): void
    {
        $this->onPage('campaign.page.products');

        $this->assertSame(['route' => 'campaign.page.products', 'page_name' => ''], $this->floating()->prop('page_context'));
    }

    /** @test The model reads the page's own name (its navigation label), never the route. */
    public function a_listed_page_carries_its_navigation_label_as_its_name(): void
    {
        config(['ai.navigation.pages' => ['products' => ['route' => 'campaign.page.products', 'label' => 'Produits']]]);
        $this->onPage('campaign.page.products');

        $this->assertSame(['route' => 'campaign.page.products', 'page_name' => 'Produits'], $this->floating()->prop('page_context'));
    }

    /** @test */
    public function the_hosts_page_context_wins(): void
    {
        $this->onPage('campaign.page.products');
        $host = ['route' => 'orders.show', 'entity_type' => 'Order', 'entity_id' => 9];

        $this->assertSame($host, $this->floating(['page_context' => $host])->prop('page_context'));
    }

    /** @test */
    public function it_is_opt_in(): void
    {
        config(['ai.chat.capture_page_route' => false]);
        $this->onPage('campaign.page.products');

        $this->assertEmpty($this->floating()->prop('page_context'));
    }

    /** @test */
    public function the_request_that_opens_the_chat_is_not_the_page(): void
    {
        $this->onPage('_kompo', ['X-Kompo-Info' => 'x']);

        $this->assertEmpty($this->floating()->prop('page_context'));
    }
}
