<?php

declare(strict_types=1);

namespace Condoedge\Ai\Kompo\Actions;

/**
 * ApprovalCard
 *
 * Renders the CONFIRMATION primitive for an APPROVAL-REQUIRED (mutating) action. A mutating
 * action is NEVER executed inline — instead the assistant renders this card, which carries a
 * signed {@see \Condoedge\Ai\Services\Actions\PendingActionToken}. Clicking "Confirm"
 * round-trips back to the host Query (MessagesQuery::approveAiAction), which re-decodes the
 * token, RE-RUNS the action's authorize() server-side, and only THEN executes handle().
 *
 * This class is a thin Kompo-element factory (static builder) rather than a full Komponent,
 * so it can be composed into any message bubble and unit-tested for its structure without a
 * live Kompo boot. The confirm wiring is supplied by the caller (the Query that owns the
 * round-trip method), keeping the card framework-light.
 */
class ApprovalCard
{
    /** Panel the approval result is injected into after confirm. */
    public const PANEL_ID = 'ai-approval-result';

    /**
     * Panel id for one card's approval result — unique per token so multiple pending actions
     * in one bubble don't collide. Shared with external confirm wiring (e.g. the bubble
     * renderer's invocation cards) so the round-trip lands in the card's own panel.
     */
    public static function panelIdFor(string $token): string
    {
        return self::PANEL_ID . '-' . substr(hash('crc32b', $token), 0, 8);
    }

    /**
     * Build the approval card element.
     *
     * @param array<string, mixed> $pending  The deferred-action payload from ActionDispatcher::defer():
     *                                        ['token','label','text','confirm_message',...].
     * @param callable|null $confirmWiring    fn($link, string $token): mixed — applies the
     *                                        server round-trip (e.g. selfPost('approveAiAction', [...])
     *                                        ->refresh()) to the confirm button. When null, the
     *                                        confirm button is rendered inert (structure only).
     * @return mixed  A Kompo element (when helpers are available) or the structured payload.
     */
    public static function make(array $pending, ?callable $confirmWiring = null): mixed
    {
        $token = (string) ($pending['token'] ?? '');
        $label = (string) ($pending['label'] ?? 'Confirm action');
        $message = (string) ($pending['confirm_message'] ?? 'Are you sure you want to proceed?');

        // Pure-logic / non-Kompo context: return the structured intent so callers/tests can
        // assert on it without booting the framework.
        if (!function_exists('_Rows') || !function_exists('_Link') || !function_exists('_Html')) {
            return [
                'type' => 'approval_card',
                'token' => $token,
                'label' => $label,
                'message' => $message,
            ];
        }

        $confirm = _Link(__('ai.actions.confirm') ?: 'Confirm')
            ->class('px-4 py-2 text-sm font-medium text-white bg-amber-600 hover:bg-amber-700 rounded-lg transition-all');

        if ($confirmWiring !== null) {
            $confirm = $confirmWiring($confirm, $token) ?: $confirm;
        } else {
            // Default round-trip: post the signed token to the enclosing chat Query's
            // approveAiAction(), which re-authorizes server-side before executing. Kompo
            // resolves selfPost against the Komponent rendering this element. The result is
            // injected into the per-card panel below (inPanel set once, further down).
            $confirm = $confirm->selfPost('approveAiAction', ['token' => $token]);
        }

        $cancel = _Link(__('ai.actions.cancel') ?: 'Cancel')
            ->class('px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100 rounded-lg transition-all')
            ->removeSelf();

        // Unique panel id per token so multiple pending actions in one bubble don't collide.
        $panelId = self::panelIdFor($token);
        $confirm = $confirmWiring === null ? $confirm->inPanel($panelId) : $confirm;

        return _Rows(
            _Flex(
                _Html('⚠️')->class('text-lg'),
                _Html($message)->class('text-sm font-medium text-amber-800'),
            )->class('items-center gap-2'),
            _Flex($confirm, $cancel)->class('gap-2 mt-3'),
            _Panel()->id($panelId)->class('mt-2'),
        )->class('mt-3 p-4 rounded-xl border border-amber-200 bg-amber-50')
         ->attr(['data-pending-action' => $token]);
    }
}
