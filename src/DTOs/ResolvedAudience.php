<?php

declare(strict_types=1);

namespace Condoedge\Ai\DTOs;

/**
 * The audience a user belongs to (a key of ai.audiences.map) and their role in it.
 */
final class ResolvedAudience
{
    public function __construct(
        public readonly string $key,
        public readonly ?string $role = null,
    ) {
    }
}
