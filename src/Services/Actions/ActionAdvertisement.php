<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Actions;

use Condoedge\Ai\Contracts\AdvertisableActionInterface;
use Condoedge\Ai\Contracts\AiActionInterface;

/**
 * ActionAdvertisement
 *
 * THE single rule for whether an action may be OFFERED to a principal (before any row exists).
 * Every advertisement surface — the markdown link prompt + per-row chips (EntityAutoDiscovery /
 * ResponseEntityEnricher) and the query-side awareness section — consults it, so a mutation is never offered to a user who could not perform it.
 *
 *   - an {@see AdvertisableActionInterface} answers through its row-independent capability check;
 *   - any other action answers through its input-less authorize($user, []).
 *
 * A gate that throws is not advertisable (fail closed). This decides only the OFFER — dispatch
 * and approval still re-run the full authorize() against the resolved row.
 */
final class ActionAdvertisement
{
    public static function allows(AiActionInterface $action, mixed $user): bool
    {
        try {
            if ($action instanceof AdvertisableActionInterface) {
                return (bool) $action->isAdvertisableTo($user);
            }

            return (bool) $action->authorize($user, []);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Drop the MUTATING actions the principal may not be offered from a
     * {@see AiActionRegistry::relevantActions()} shape. Read-only actions pass through unchanged
     * (they are re-gated at render; this filter exists so a write is never put in front of a user
     * who cannot perform it).
     *
     * @param array{entity: array<string, AiActionInterface[]>, generic: AiActionInterface[]} $relevant
     * @return array{entity: array<string, AiActionInterface[]>, generic: AiActionInterface[]}
     */
    public static function filterMutations(array $relevant, mixed $user): array
    {
        $keep = static fn ($action): bool => !$action instanceof AiActionInterface
            || !$action->mutates()
            || self::allows($action, $user);

        $entity = [];
        foreach ((array) ($relevant['entity'] ?? []) as $type => $actions) {
            $kept = array_values(array_filter((array) $actions, $keep));
            if ($kept !== []) {
                $entity[$type] = $kept;
            }
        }

        return [
            'entity' => $entity,
            'generic' => array_values(array_filter((array) ($relevant['generic'] ?? []), $keep)),
        ];
    }

    /**
     * The principal being advertised to: the authenticated user, or null when no auth context is
     * reachable (console/queue/pure unit) — a null principal is never offered a mutation.
     */
    public static function currentUser(): mixed
    {
        if (!function_exists('auth') || !function_exists('app')) {
            return null;
        }

        try {
            if (!app()->bound('auth')) {
                return null;
            }

            return auth()->user();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
