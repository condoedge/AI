<?php

declare(strict_types=1);

namespace Condoedge\Ai\Contracts;

/**
 * An action that can answer, WITHOUT a target row, whether it may be OFFERED to a principal.
 *
 * Advertisement (the link-grammar prompt, the query-side action awareness,
 * per-row chips) happens before any row is resolved. For actions whose authorize() needs a row
 * (e.g. {@see \Condoedge\Ai\Services\Actions\ModelMethodMutationAction}), an input-less
 * authorize($user, []) cannot answer that question — this row-independent capability check does.
 *
 * It only decides whether the action is OFFERED; dispatch and approval still run the full
 * authorize() against the resolved row.
 */
interface AdvertisableActionInterface
{
    /**
     * Whether the action may be offered to this principal at all (row-independent, fail closed).
     */
    public function isAdvertisableTo(mixed $user): bool;
}
