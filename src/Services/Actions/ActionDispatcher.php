<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Actions;

use Condoedge\Ai\Contracts\AiActionInterface;
use Condoedge\Ai\Services\Actions\Results\AiActionResult;

/**
 * ActionDispatcher
 *
 * The validate → authorize → execute-or-defer brain for the action layer. Given a resolved
 * {@see AiActionInterface}, raw link params, and a render context, it:
 *
 *   1. Filters params against the action's inputSchema (drops forbidden/unknown params),
 *   2. Re-checks authorize() server-side at RENDER time,
 *   3. If the action mutates(): does NOT execute. Instead it issues a signed
 *      {@see PendingActionToken} (key + validated input + user) and returns a Pending-kind
 *      AiActionResult — the presenter turns this into a confirmation card whose confirm
 *      round-trips back through approve().
 *   4. Otherwise (read-only): executes handle() immediately and returns its result.
 *
 * approve() is the server-side second gate for mutating actions: it decodes + verifies the
 * token, confirms it belongs to the user, RE-RUNS authorize(), and only THEN calls handle().
 * No mutating action is ever executed during render — only after this round-trip.
 */
class ActionDispatcher
{
    /**
     * In-process fallback record of consumed nonces, used only when no cache store is reachable
     * (pure unit tests / unbooted container). Cross-request single-use rides Laravel's Cache.
     *
     * @var array<string, true>
     */
    private static array $consumedNonces = [];

    public function __construct(
        private AiActionRegistry $registry,
    ) {
    }

    public function registry(): AiActionRegistry
    {
        return $this->registry;
    }

    /**
     * Resolve the authenticated user, tolerating a missing/unbooted container (pure unit
     * tests) — returns null rather than throwing a BindingResolutionException.
     */
    private function currentUser(): mixed
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

    /**
     * Claim a token's single-use nonce, returning false if it was already consumed (a replay).
     *
     * Prefers Laravel's atomic Cache::add() so single-use holds across requests; falls back to a
     * per-process record when no cache store is reachable (pure unit tests) so replay is still
     * blocked within a request — the same container-tolerant guard pattern as currentUser().
     */
    private function claimNonce(string $nonce): bool
    {
        if ($nonce === '') {
            // Nonce-less token: it cannot be single-use protected, so it is never claimable
            // (decode() already rejects one; this is defense in depth).
            return false;
        }

        $key = 'ai.actions.nonce.' . $nonce;

        if (function_exists('app')) {
            try {
                if (app()->bound('cache')) {
                    $cache = app('cache');
                    // add() is atomic: true only if the key was absent → first claim wins.
                    return (bool) $cache->add($key, true, PendingActionToken::nonceLifetime());
                }
            } catch (\Throwable $e) {
                // Cache not available — fall through to the in-process guard.
            }
        }

        if (isset(self::$consumedNonces[$key])) {
            return false;
        }
        self::$consumedNonces[$key] = true;

        return true;
    }

    /**
     * Resolve + dispatch an action for rendering.
     *
     * @param array<string, mixed> $rawParams  Params parsed from the link query string.
     * @param array<string, mixed> $context    Render context (entity_id, entity_type, text, result_set, user…).
     * @return AiActionResult|null  Null when the action cannot be resolved (caller degrades).
     */
    public function dispatch(AiActionInterface $action, array $rawParams, array $context): ?AiActionResult
    {
        $input = $this->registry->filterParams($action, $this->seedEntityId($rawParams, $context));
        $user = $context['user'] ?? $this->currentUser();

        // RENDER-time authorization gate. A denied action renders nothing actionable.
        if (!$action->authorize($user, $input)) {
            return AiActionResult::error('You are not authorized to perform this action.');
        }

        if ($action->mutates()) {
            return $this->defer($action, $input, $user, $context);
        }

        return $this->run($action, $input, $context, $user);
    }

    /**
     * Seed the entity:// path id into the raw params under {@see ModelMethodMutationAction::ID_PARAM}
     * so an action that declares it in inputSchema() keeps it after filtering — and thus carries it
     * through the approval token (which stores input, not the link path) to re-resolve the exact
     * target in authorize()/handle(). An explicit query param of the same name wins; an action that
     * does NOT declare the id in its schema drops it (no behavior change for existing actions).
     *
     * @param array<string, mixed> $rawParams
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function seedEntityId(array $rawParams, array $context): array
    {
        $id = $context['entity_id'] ?? null;
        if ($id !== null && $id !== '' && !array_key_exists(ModelMethodMutationAction::ID_PARAM, $rawParams)) {
            $rawParams[ModelMethodMutationAction::ID_PARAM] = $id;
        }

        return $rawParams;
    }

    /**
     * Build the deferred-approval result for a mutating action. Persists nothing here — the
     * signed token is returned for the renderer to stamp onto the message metadata + wire
     * into the confirmation round-trip.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $context
     */
    private function defer(AiActionInterface $action, array $input, mixed $user, array $context): AiActionResult
    {
        $entityType = $context['entity_type'] ?? null;
        $userId = is_object($user) && method_exists($user, 'getKey') ? $user->getKey() : ($context['user_id'] ?? null);

        $token = PendingActionToken::issue(
            $action->key(),
            is_string($entityType) ? $entityType : null,
            $input,
            $userId,
        )->encode();

        return AiActionResult::pending(
            $token,
            'This will ' . strtolower($action->label()) . '. Confirm?',
            'Proposed (requires approval): ' . $action->label(),
            [
                'action_key' => $action->key(),
                'label' => $action->label(),
                'text' => $context['text'] ?? $action->label(),
            ],
        );
    }

    /**
     * Execute a (read-only OR freshly re-authorized) action's handle() with a typed context.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $context
     */
    private function run(AiActionInterface $action, array $input, array $context, mixed $user): AiActionResult
    {
        $ctx = ActionContext::fromArray(['user' => $user] + $context, $input);

        try {
            return $action->handle($input, $ctx);
        } catch (\Throwable $e) {
            return AiActionResult::error('The action failed to run: ' . $e->getMessage());
        }
    }

    /**
     * Server-side approval gate for a deferred mutating action.
     *
     * Decodes + verifies the signed token, confirms it belongs to the acting user, RE-RUNS
     * authorize() against the validated input, and only then executes handle(). Returns an
     * isError result (never throws) when the token is invalid/forged, ownership fails, or
     * authorization is denied — so the UI can show a clear message without mutating anything.
     *
     * @param array<string, mixed> $context  Render/approval context (user, result_set, entity_id…).
     */
    public function approve(string $token, array $context = []): AiActionResult
    {
        $decoded = PendingActionToken::decode($token);
        if ($decoded === null) {
            return AiActionResult::error('This action request is invalid or has been tampered with.');
        }

        $user = $context['user'] ?? $this->currentUser();
        $userId = is_object($user) && method_exists($user, 'getKey')
            ? $user->getKey()
            : ($context['user_id'] ?? null);

        // Reject a userless token whenever the approver IS authenticated: belongsTo(null) matches
        // anyone, so a token minted with no user binding must not be confirmable by a logged-in user.
        if ($decoded->userId === null && $userId !== null) {
            return AiActionResult::error('This action request is not bound to a user and cannot be confirmed.');
        }

        if (!$decoded->belongsTo($userId)) {
            return AiActionResult::error('This action request does not belong to the current user.');
        }

        $action = $decoded->entityType !== null
            ? $this->registry->getEntity($decoded->entityType, $decoded->actionKey)
            : $this->registry->getGeneric($decoded->actionKey);

        if ($action === null) {
            return AiActionResult::error('This action is no longer available.');
        }

        // SECOND, fresh server-side authorization pass — the whole point of approval gating.
        if (!$action->authorize($user, $decoded->input)) {
            return AiActionResult::error('You are no longer authorized to perform this action.');
        }

        // SINGLE-USE: claim the token's nonce before running. A replayed (already-confirmed) token
        // fails here, so a non-idempotent mutation ("charge $450") cannot fire twice. Claimed AFTER
        // authorize so a denied attempt does not burn the token.
        if (!$this->claimNonce($decoded->nonce)) {
            return AiActionResult::error('This action has already been confirmed.');
        }

        $context['entity_type'] = $decoded->entityType ?? ($context['entity_type'] ?? null);

        return $this->run($action, $decoded->input, $context, $user);
    }
}
