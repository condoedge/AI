<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Kompo;

use Condoedge\Ai\Services\Actions\ActionDispatcher;
use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Actions\PendingActionToken;
use Condoedge\Ai\Services\Response\ActionLinkHandler;
use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Condoedge\Ai\Tests\Unit\Services\Actions\Stubs\CapturingAction;
use Condoedge\Ai\Tests\Unit\Services\Actions\Stubs\MutatingSpyAction;
use Orchestra\Testbench\TestCase;
use Mockery;

/**
 * MessagesQuery action-link rendering now flows through the AiActionRegistry + ActionDispatcher
 * (the old entityActionLink/genericActionLink/extractActionLinkProxies proxy path was removed).
 *
 * These tests exercise that new substrate at the seam the Query depends on:
 *  - resolver-driven element creation via ActionLinkHandler (gracefully drops failures),
 *  - the server-side approval round-trip used by MessagesQuery::approveAiAction.
 */
class MessagesQueryActionLinksTest extends TestCase
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

    private function handler(AiActionRegistry $registry): ActionLinkHandler
    {
        $discovery = Mockery::mock(EntityAutoDiscovery::class);
        return new ActionLinkHandler($discovery, new ActionDispatcher($registry));
    }

    /** @test */
    public function it_renders_entity_action_links_through_the_registry(): void
    {
        $registry = new AiActionRegistry();
        $registry->registerEntity('Person', new CapturingAction(
            'profile',
            fn($input, $context) => 'profile-element:' . $context->entityId,
        ));

        $elements = $this->handler($registry)
            ->createElements('[John](entity://Person/152463/profile)');

        $this->assertSame(['profile-element:152463'], $elements);
    }

    /** @test */
    public function it_drops_failing_resolvers_without_throwing(): void
    {
        $registry = new AiActionRegistry();
        $registry->registerGeneric(new CapturingAction(
            'boom',
            function () {
                throw new \RuntimeException('resolver failed');
            },
        ));

        // ActionDispatcher::run catches the throwable → AiActionResult isError → handler drops it.
        $elements = $this->handler($registry)->createElements('[X](action://boom)');

        $this->assertSame([], $elements);
    }

    /** @test */
    public function approval_round_trip_reauthorizes_before_running_a_mutating_action(): void
    {
        $ran = false;
        $registry = new AiActionRegistry();
        $registry->registerGeneric(new MutatingSpyAction(
            'archive',
            authorized: true,
            onHandle: function () use (&$ran) { $ran = true; return 'archived'; },
        ));
        $dispatcher = new ActionDispatcher($registry);

        // Render-time: deferred, never executed.
        $deferred = $dispatcher->dispatch($registry->getGeneric('archive'), [], ['user_id' => 5]);
        $this->assertFalse($ran);
        $this->assertTrue($deferred->renderable['pending']);

        // Approval-time: re-authorized server-side, then executed.
        $approved = $dispatcher->approve($deferred->renderable['token'], ['user_id' => 5]);
        $this->assertTrue($ran);
        $this->assertFalse($approved->isError);
        $this->assertSame('archived', $approved->renderable);
    }

    /** @test */
    public function approval_is_rejected_for_a_forged_token(): void
    {
        $registry = new AiActionRegistry();
        $registry->registerGeneric(new MutatingSpyAction(
            'archive',
            authorized: true,
            onHandle: fn() => 'archived',
        ));
        $dispatcher = new ActionDispatcher($registry);

        $forged = PendingActionToken::issue('archive', null, [], 5)->encode() . 'tampered';
        $result = $dispatcher->approve($forged, ['user_id' => 5]);

        $this->assertTrue($result->isError);
    }
}
