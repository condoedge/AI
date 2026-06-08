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
 *   3. If the action requiresApproval(): does NOT execute. Instead it issues a signed
 *      {@see PendingActionToken} (key + validated input + user) and returns an AiActionResult
 *      describing the deferred action — the renderer turns this into a confirmation card whose
 *      confirm round-trips back through approve().
 *   4. Otherwise (read-only): executes handle() immediately and returns its result.
 *
 * approve() is the server-side second gate for mutating actions: it decodes + verifies the
 * token, confirms it belongs to the user, RE-RUNS authorize(), and only THEN calls handle().
 * No mutating action is ever executed during render — only after this round-trip.
 */
class ActionDispatcher
{
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
     * Resolve + dispatch an action for rendering.
     *
     * @param array<string, mixed> $rawParams  Params parsed from the link query string.
     * @param array<string, mixed> $context    Render context (entity_id, entity_type, text, result_set, user…).
     * @return AiActionResult|null  Null when the action cannot be resolved (caller degrades).
     */
    public function dispatch(AiActionInterface $action, array $rawParams, array $context): ?AiActionResult
    {
        $input = $this->registry->filterParams($action, $rawParams);
        $user = $context['user'] ?? $this->currentUser();

        // RENDER-time authorization gate. A denied action renders nothing actionable.
        if (!$action->authorize($user, $input)) {
            return AiActionResult::error('You are not authorized to perform this action.');
        }

        if ($action->requiresApproval()) {
            return $this->defer($action, $input, $user, $context);
        }

        return $this->run($action, $input, $context);
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

        return AiActionResult::ok(
            [
                'pending' => true,
                'token' => $token,
                'action_key' => $action->key(),
                'label' => $action->label(),
                'text' => $context['text'] ?? $action->label(),
                'confirm_message' => 'This will ' . strtolower($action->label()) . '. Confirm?',
            ],
            'Proposed (requires approval): ' . $action->label()
        );
    }

    /**
     * Execute a (read-only OR freshly re-authorized) action's handle().
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $context
     */
    private function run(AiActionInterface $action, array $input, array $context): AiActionResult
    {
        try {
            $result = $action->handle($input, $context);
        } catch (\Throwable $e) {
            return AiActionResult::error('The action failed to run: ' . $e->getMessage());
        }

        if ($result instanceof AiActionResult) {
            return $result;
        }

        return AiActionResult::ok($result, $action->label());
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

        $context['entity_type'] = $decoded->entityType ?? ($context['entity_type'] ?? null);

        return $this->run($action, $decoded->input, $context);
    }
}
