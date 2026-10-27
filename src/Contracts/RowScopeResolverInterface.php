<?php

declare(strict_types=1);

namespace Condoedge\Ai\Contracts;

/**
 * Narrows a user's data below their team (e.g. a client to the accounts they belong to).
 * The ids must lie inside the user's teams; the package filters on them, it does not check them.
 */
interface RowScopeResolverInterface
{
    /** @return list<int>|null null = not narrowed (team scope only); [] = no data at all. */
    public function rowScopeIds(mixed $user): ?array;
}
