<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Response;

use Condoedge\Ai\Kompo\Actions\ApprovalCard;
use Condoedge\Ai\Kompo\Support\SuggestionSubmit;
use Condoedge\Ai\Services\Actions\ActionKind;
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
 * - Follow-up question results render the suggestion chip whose click sends the question
 *   as the user's next message (nothing is posted or executed at render).
 * - Plain renderables (Kompo elements) pass straight through.
 */
class ActionResultPresenter
{
    /** Marks a follow-up question chip (the bubble renders those in a block of their own). */
    public const FOLLOW_UP_CLASS = 'cdge-follow-up';

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

        if ($result->kind === ActionKind::Export) {
            return $this->renderDownload($result->payload);
        }

        if ($result->kind === ActionKind::FollowUp) {
            return $this->renderFollowUp($result->payload);
        }

        $renderable = $result->renderable;

        // Deferred mutating action → confirmation card, NEVER inline execution.
        if (is_array($renderable) && !empty($renderable['pending'])) {
            return ApprovalCard::make($renderable, $confirmWiring);
        }

        return $renderable;
    }

    /**
     * A follow-up question chip: a suggestion whose click sends its question as the user's
     * next message — client-side only. The element carries ONE interaction, the welcome
     * suggestions' own script ({@see SuggestionSubmit::fillAndSendJs()}: fill the composer,
     * click the real send button), so the click goes through the typed-send path and
     * nothing is posted or executed at render.
     *
     * Encoding: the question enters the script JSON-hex-encoded (SuggestionSubmit) and the
     * label is HTML-escaped (Kompo link labels render through v-html client-side) — the
     * text can neither break out of the script nor inject markup. The bubble styles the
     * chip ({@see \Condoedge\Ai\Kompo\Support\MessageBubbleRenderer::followUpBlock()});
     * FOLLOW_UP_CLASS only marks it.
     */
    private function renderFollowUp(mixed $payload): mixed
    {
        $question = is_array($payload) && is_string($payload['question'] ?? null) ? trim($payload['question']) : '';
        if ($question === '') {
            return null;
        }
        $label = is_string($payload['label'] ?? null) && trim($payload['label']) !== '' ? trim($payload['label']) : $question;

        if (!function_exists('_Link')) {
            // No Kompo runtime (unit context): the structured intent.
            return ['type' => 'follow_up', 'question' => $question, 'label' => $label];
        }

        return _Link(e($label))->icon('chat-bubble-left-ellipsis')->class(self::FOLLOW_UP_CLASS)
            ->onClick(fn ($e) => $e->run(SuggestionSubmit::fillAndSendJs($question)));
    }

    /**
     * Export payloads contain raw bytes, which must never be returned directly into a chat
     * render tree that will be JSON encoded. Convert them to a base64 data URI at the presenter
     * boundary while leaving AiActionResult::export() byte-compatible for non-chat exporters.
     */
    private function renderDownload(mixed $payload): mixed
    {
        if (!is_array($payload) || !array_key_exists('content', $payload)) {
            return $this->renderExportFailure('The generated export could not be prepared for download.');
        }

        $mime = $this->safeMime((string) ($payload['mime'] ?? 'application/octet-stream'));
        $filename = $this->safeDownloadFilename((string) ($payload['filename'] ?? 'download'));
        $href = 'data:' . $mime . ';base64,' . base64_encode((string) $payload['content']);

        if (function_exists('_Link')) {
            $link = _Link($filename)->attr([
                'download' => $filename,
            ]);

            // Kompo's Link target is the public href property. Generic attrs are not
            // enough here, and ->href($href) would route-normalize the data URI.
            if (is_object($link) && property_exists($link, 'href')) {
                $link->href = $href;
            }

            if (is_object($link) && method_exists($link, 'icon')) {
                $link = $link->icon('export-2');
            }

            if (is_object($link) && method_exists($link, 'class')) {
                $link = $link->class('cdge-export-download');
            }

            return $link;
        }

        // No Kompo runtime (unit context): keep the payload JSON-safe and intentionally omit
        // the raw binary content key.
        return [
            'type' => 'download',
            'href' => $href,
            'mime' => $mime,
            'filename' => $filename,
        ];
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

    private function safeMime(string $mime): string
    {
        $mime = trim($mime);

        return preg_match('/^[A-Za-z0-9.+-]+\/[A-Za-z0-9.+-]+$/', $mime) === 1
            ? $mime
            : 'application/octet-stream';
    }

    private function safeDownloadFilename(string $filename): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_. -]+/', '_', $filename) ?: 'download';
        $clean = trim($clean, " ._\t\n\r\0\x0B");

        return $clean !== '' ? $clean : 'download';
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
