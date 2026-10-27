<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Fixtures;

use Condoedge\Ai\Contracts\AudienceResolverInterface;
use Condoedge\Ai\DTOs\ResolvedAudience;

/** An audience resolver that answers whatever the test set last. */
class FixedAudienceResolver implements AudienceResolverInterface
{
    public static ?ResolvedAudience $next = null;

    public function resolve(mixed $user): ?ResolvedAudience
    {
        return self::$next;
    }
}
