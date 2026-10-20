<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Actions;

use Condoedge\Ai\Services\Actions\ActionDispatcher;
use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Actions\ModelMethodMutationAction;
use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Condoedge\Ai\Services\Response\ActionLinkHandler;
use Orchestra\Testbench\TestCase;
use Mockery;

/**
 * End-to-end: an entity:// mutation link wired the host way (a ModelMethodMutationAction placed in
 * entity_actions config) routes through ActionLinkHandler -> ActionDispatcher and DEFERS — it
 * renders an approval card and never executes the wrapped domain method at render time. The
 * approval round-trip then re-authorizes server-side and runs the method exactly once.
 *
 * No live model, no real write: the action wraps an in-memory fake whose method just records that
 * it ran. This proves the link path, the entity-id seeding (path id -> filtered input -> token),
 * and the approval gate together.
 */
class MutationActionLinkFlowTest extends TestCase
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

    /** @test */
    public function entity_mutation_link_defers_to_an_approval_card_and_does_not_write_inline(): void
    {
        $target = new FakeApprovable(42);

        $action = new ModelMethodMutationAction(
            key: 'approve_reservation',
            label: 'Approve reservation',
            finder: fn ($id) => (int) $id === 42 ? $target : null,
            mutator: fn ($m, array $input) => $m->approve(),
            authorizer: fn ($user, $model, array $input) => true,
            entityType: 'Reservation',
        );

        config()->set('ai.entity_actions', ['Reservation' => ['approve_reservation' => $action]]);
        config()->set('ai.generic_actions', []);

        // A1/A2: a mutation never authorizes for a null principal — act as a real user.
        $this->actingAs(new \Illuminate\Auth\GenericUser(['id' => 7]));

        $registry = new AiActionRegistry();
        $dispatcher = new ActionDispatcher($registry);
        $handler = new ActionLinkHandler(Mockery::mock(EntityAutoDiscovery::class), $dispatcher);

        $elements = $handler->createElements('[Approve](entity://Reservation/42/approve_reservation)');

        // The link rendered an approval card (offline => structured array), NOT an inline result.
        $this->assertCount(1, $elements);
        $card = $elements[0];
        $this->assertIsArray($card);
        $this->assertSame('approval_card', $card['type']);
        $this->assertNotEmpty($card['token']);
        $this->assertSame(0, $target->approvals, 'a mutation must NEVER run at render time');

        // Approving re-authorizes server-side, then runs the wrapped method exactly once.
        $approved = $dispatcher->approve($card['token']);
        $this->assertFalse($approved->isError);
        $this->assertSame(1, $target->approvals, 'the wrapped domain method runs once, only after approval');
    }
}

/**
 * In-memory stand-in: approve() only records that the existing method was called — never a write.
 */
class FakeApprovable
{
    public int $approvals = 0;

    public function __construct(public int $id)
    {
    }

    public function approve(): void
    {
        $this->approvals++;
    }
}
