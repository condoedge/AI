<?php

declare(strict_types=1);

namespace Condoedge\Ai\Contracts;

use Condoedge\Ai\DTOs\ResolvedAudience;

/**
 * The host's answer to "who is asking": the audience key of ai.audiences.map the user
 * belongs to right now, and their role within it. Named in ai.audiences.resolver.
 */
interface AudienceResolverInterface
{
    /** Null when the user belongs to no audience (they then read only the documents written for everyone). */
    public function resolve(mixed $user): ?ResolvedAudience;
}
