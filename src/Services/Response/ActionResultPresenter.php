<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Response;

use Condoedge\Ai\Kompo\Actions\ApprovalCard;
use Condoedge\Ai\Services\Actions\Results\AiActionResult;

/**
 * The ONE presentation seam for AiActionResults, shared by both transports: parsed
 * action:// / entity:// markdown links ({@see ActionLinkHandler}, which delegates here) and
 * persisted native-turn invocations (MessageBubbleRenderer). Extracted from
 * ActionLinkHandler::renderResult() so the two render paths cannot drift.
 *
 * - Approval-required (deferred) results render an {@see ApprovalCard} instead of executing.
 * - Export errors (e.g. phpspreadsheet/dompdf absent) render a VISIBLE honest-failure notice
 *   instead of vanishing — a silently inert download link is a worse UX than a clear message.
 * - Other errors render nothing (the caller already logs / degrades, and a generic action
 *   failure must not leak its internal reason into the chat bubble) — UNLESS the caller flags
 *   the invocation as a persisted MUTATING one ($noticeWhenUnavailable): the assistant's prose
 *   may have promised a confirmation card that render-time authorize() now denies, and silence
 *   next to that promise reads as a broken UI, so a neutral "no longer available" notice is
 *   rendered instead (never the internal reason).
 * - Plain renderables (Kompo elements) pass straight through.
 */
class ActionResultPresenter
{
    /**
     * Turn an AiActionResult into the element to drop into the message.
     *
     * @param callable|null $confirmWiring  fn($link, string $token) confirm wiring forwarded to
     *                                      the ApprovalCard (null keeps the card's default
     *                                      token-only selfPost round-trip).
     * @param bool $noticeWhenUnavailable   Render the neutral unavailable-notice for a
     *                                      non-export error (persisted mutating invocations).
     */
    public function present(
        ?AiActionResult $result,
        string $actionKey = '',
        ?callable $confirmWiring = null,
        bool $noticeWhenUnavailable = false
    ): mixed {
        if ($result === null) {
            return null;
        }

        if ($result->isError) {
            // HONEST FAILURE: only the export actions surface their reason to the user; every
            // other action keeps failing closed (no inert link, no leaked internals).
            if ($this->isExportAction($actionKey)) {
                return $this->renderExportFailure($result->summaryForLlm);
            }

            return $noticeWhenUnavailable ? $this->renderUnavailableNotice() : null;
        }

        $renderable = $result->renderable;

        // Deferred mutating action → confirmation card, NEVER inline execution.
        if (is_array($renderable) && !empty($renderable['pending'])) {
            return ApprovalCard::make($renderable, $confirmWiring);
        }

        return $renderable;
    }

    /**
     * Static done-note for an invocation already confirmed on a previous render (a resolution
     * marker is present in the message metadata) — dispatch is skipped entirely and nothing
     * is re-offered.
     */
    public function approvedNote(): mixed
    {
        $message = $this->translate('ai.actions.approved-note', 'This action was approved.');

        if (function_exists('_Html')) {
            return _Html('✓ ' . e($message))->class('cdge-action-approved text-sm text-emerald-600');
        }

        // No Kompo runtime (unit context) — return the note text so the marker is observable.
        return '✓ ' . $message;
    }

    /**
     * The built-in export actions whose failure (missing host library) must be shown, not hidden.
     */
    private function isExportAction(string $actionKey): bool
    {
        return str_starts_with($actionKey, 'export_');
    }

    /**
     * Render an export failure as a small, visible inline notice carrying the user-facing reason.
     */
    private function renderExportFailure(string $message): mixed
    {
        $message = trim($message) !== ''
            ? $message
            : 'This export is unavailable in the current environment.';

        if (function_exists('_Html')) {
            return _Html(e($message))->class('cdge-export-error text-sm text-red-600');
        }

        // No Kompo runtime (unit context) — return the message text so the failure is never silent.
        return $message;
    }

    /**
     * Neutral notice for a persisted mutating invocation whose render-time dispatch was denied
     * (permission lost, row deleted). Deliberately carries NO internal reason.
     */
    private function renderUnavailableNotice(): mixed
    {
        $message = $this->translate('ai.actions.no-longer-available', 'This action is no longer available.');

        if (function_exists('_Html')) {
            return _Html(e($message))->class('cdge-action-unavailable text-sm text-gray-500');
        }

        return $message;
    }

    /**
     * Translate with an explicit fallback: an unbooted translator (pure unit context) returns
     * the raw key, which must never be shown to a user.
     */
    private function translate(string $key, string $fallback): string
    {
        if (function_exists('__')) {
            $translated = __($key);
            if (is_string($translated) && $translated !== $key) {
                return $translated;
            }
        }

        return $fallback;
    }
}
