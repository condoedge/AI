<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Actions;

use Condoedge\Ai\Services\Actions\ActionDispatcher;
use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Condoedge\Ai\Services\Response\ActionLinkHandler;
use Condoedge\Ai\Tests\Unit\Services\Actions\Stubs\CapturingAction;
use Orchestra\Testbench\TestCase;
use Mockery;

/**
 * ActionLinkHandler param parsing: the link grammar now carries an optional query string
 * that is parsed into an assoc array and FILTERED against the action's inputSchema()
 * (forbidden/unknown params dropped). Param-less links keep working (backward-compat).
 *
 * We assert on the params that reach the action's handle() by registering a capturing
 * action, rather than on rendered Kompo output (which needs a live boot).
 */
class ActionLinkHandlerParamsTest extends TestCase
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

    private function makeHandler(AiActionRegistry $registry): ActionLinkHandler
    {
        $discovery = Mockery::mock(EntityAutoDiscovery::class);
        return new ActionLinkHandler($discovery, new ActionDispatcher($registry));
    }

    /** @test */
    public function it_parses_query_params_and_drops_forbidden_ones(): void
    {
        $captured = null;
        $registry = new AiActionRegistry();
        $registry->registerGeneric(new CapturingAction('charge', function ($input, $context) use (&$captured) {
            $captured = $input;
            return 'ok';
        }, ['unit_id' => ['type' => 'int'], 'amount' => ['type' => 'float']]));

        $handler = $this->makeHandler($registry);

        $elements = $handler->createElements(
            '[Charge](action://charge?unit_id=123&amount=450&evil=DROP)'
        );

        $this->assertSame(['unit_id' => 123, 'amount' => 450.0], $captured);
        $this->assertArrayNotHasKey('evil', (array) $captured);
        $this->assertSame(['ok'], $elements);
    }

    /** @test */
    public function it_parses_entity_link_query_params(): void
    {
        $captured = ['input' => null, 'context' => null];
        $registry = new AiActionRegistry();
        $action = new CapturingAction('profile', function ($input, $context) use (&$captured) {
            $captured['input'] = $input;
            $captured['context'] = $context;
            return 'rendered';
        }, ['field' => ['type' => 'string']]);
        $registry->registerEntity('Person', $action);

        $handler = $this->makeHandler($registry);

        $elements = $handler->createElements(
            '[John](entity://Person/152463/profile?field=email&forbidden=x)'
        );

        $this->assertSame(['field' => 'email'], $captured['input']);
        $this->assertSame('Person', $captured['context']->entityType);
        $this->assertSame('152463', $captured['context']->entityId);
        $this->assertSame('John', $captured['context']->text);
        $this->assertSame(['rendered'], $elements);
    }

    /** @test */
    public function param_less_links_still_work_backward_compatible(): void
    {
        $called = false;
        $registry = new AiActionRegistry();
        $registry->registerGeneric(new CapturingAction('settings', function () use (&$called) {
            $called = true;
            return 'settings-link';
        }, []));

        $handler = $this->makeHandler($registry);

        $elements = $handler->createElements('[Settings](action://settings)');

        $this->assertTrue($called);
        $this->assertSame(['settings-link'], $elements);
    }

    /** @test */
    public function unknown_action_renders_nothing(): void
    {
        config()->set('ai.entity_actions', []);
        config()->set('ai.generic_actions', []);
        $registry = new AiActionRegistry();
        $handler = $this->makeHandler($registry);

        $elements = $handler->createElements('[X](action://does_not_exist?a=1)');

        $this->assertSame([], $elements);
    }

    /** @test */
    public function it_strips_links_including_query_strings_to_plain_text(): void
    {
        $registry = new AiActionRegistry();
        $handler = $this->makeHandler($registry);

        $stripped = $handler->stripLinks(
            'See [John](entity://Person/1/profile?field=x) and [Settings](action://settings?a=1).'
        );

        $this->assertSame('See John and Settings.', $stripped);
    }
}
