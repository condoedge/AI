<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Security;

use Condoedge\Ai\Contracts\RowScopeResolverInterface;
use Condoedge\Ai\Services\Security\RowScope;
use Condoedge\Ai\Tests\TestCase;

class RowScopeTest extends TestCase
{
    /** @test */
    public function no_resolver_narrows_nobody(): void
    {
        config()->set('ai.security.row_scope_resolver', null);

        $this->assertNull(RowScope::idsFor((object) ['id' => 1]));
    }

    /** @test */
    public function the_resolver_ids_come_back_as_integers(): void
    {
        $this->bindResolver(fn () => ['8', 9, 'x']);

        $this->assertSame([8, 9], RowScope::idsFor((object) ['id' => 1]));
    }

    /** @test */
    public function a_user_the_resolver_does_not_narrow_stays_on_the_team(): void
    {
        $this->bindResolver(fn () => null);

        $this->assertNull(RowScope::idsFor((object) ['id' => 1]));
    }

    /** @test */
    public function a_failing_resolver_narrows_to_nothing(): void
    {
        $this->bindResolver(fn () => throw new \RuntimeException('db down'));

        $this->assertSame([], RowScope::idsFor((object) ['id' => 1]));
    }

    /** @test */
    public function a_resolver_that_is_not_the_contract_narrows_to_nothing(): void
    {
        $this->app->instance('test.row_scope', new \stdClass());
        config()->set('ai.security.row_scope_resolver', 'test.row_scope');

        $this->assertSame([], RowScope::idsFor((object) ['id' => 1]));
    }

    /** @test A turn asks once per label offered and per query binding: the host resolves each user once per request or job. */
    public function a_user_is_resolved_once_per_request_or_job(): void
    {
        $calls = [];
        $this->bindResolver(function ($user) use (&$calls) {
            $calls[] = $user->id;
            return [$user->id + 100];
        });
        $ann = (object) ['id' => 1];

        RowScope::idsFor($ann);
        $this->assertSame([101], RowScope::idsFor($ann));
        $this->assertSame([102], RowScope::idsFor((object) ['id' => 2]), 'another user is resolved on their own');
        $this->assertSame([1, 2], $calls);

        $this->app->forgetScopedInstances();
        RowScope::idsFor($ann);
        $this->assertSame([1, 2, 1], $calls, 'the next request or job resolves again');
    }

    private function bindResolver(\Closure $ids): void
    {
        $this->app->instance('test.row_scope', new class ($ids) implements RowScopeResolverInterface {
            public function __construct(private \Closure $ids) {}
            public function rowScopeIds(mixed $user): ?array { return ($this->ids)($user); }
        });
        config()->set('ai.security.row_scope_resolver', 'test.row_scope');
    }
}
