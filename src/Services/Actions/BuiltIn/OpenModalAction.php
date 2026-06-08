<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Actions\BuiltIn;

use Condoedge\Ai\Services\Actions\Results\AiActionResult;

/**
 * open_modal — generative-UI built-in.
 *
 * Renders a link that opens a Kompo modal pre-filled with DEFAULT VALUES the LLM supplied
 * in the link params. The modal route/component is whatever the host wired on the rendering
 * Query (selfGet target); the prefilled values flow through as the ajax payload so the
 * modal opens populated rather than blank.
 *
 * Link grammar (advertised to the LLM):
 *   [Open form](action://open_modal?modal=editUnit&unit_id=123&amount=450)
 *
 * Params:
 *   - modal: the selfGet method name on the chat Query to open the modal (required).
 *   - any other param: passed straight through as prefilled modal input.
 *
 * The actual _Link is built lazily by the renderer (MessagesQuery) because selfGet must be
 * bound to a live Komponent; handle() returns the structured intent + a ready-to-use
 * fallback link when Kompo helpers are available.
 */
class OpenModalAction extends AbstractBuiltInAction
{
    public function key(): string
    {
        return 'open_modal';
    }

    public function label(): string
    {
        return 'Open form';
    }

    public function description(): string
    {
        return 'Open a modal form pre-filled with default values.';
    }

    public function presentation(): string
    {
        return 'modal';
    }

    public function inputSchema(): array
    {
        return [
            'modal' => ['type' => 'string', 'required' => true, 'label' => 'Modal target'],
            'prefill' => ['type' => 'array', 'label' => 'Prefilled values'],
        ];
    }

    /**
     * Returns an AiActionResult whose renderable describes the modal-open intent. The
     * renderer turns this into _Link()->selfGet($modal, $prefill)->inModal(). When Kompo's
     * _Link helper is present we eagerly build a best-effort link so non-Query callers still
     * get something clickable.
     */
    public function handle(array $input, array $context): mixed
    {
        $modal = (string) ($input['modal'] ?? $context['modal'] ?? '');
        $text = $context['text'] ?? $this->label();

        // Prefill = every param except the reserved 'modal', merged with any explicit prefill.
        $prefill = $input['prefill'] ?? [];
        foreach ($input as $k => $v) {
            if ($k !== 'modal' && $k !== 'prefill') {
                $prefill[$k] = $v;
            }
        }

        $intent = [
            'action' => 'open_modal',
            'modal' => $modal,
            'prefill' => $prefill,
            'text' => $text,
        ];

        if ($modal === '') {
            return AiActionResult::error('Cannot open modal: no target specified.');
        }

        if (function_exists('_Link')) {
            $link = _Link($text)->selfGet($modal, $prefill)->inModal();
            return AiActionResult::ok($link, "Opened the {$modal} form.");
        }

        return AiActionResult::ok($intent, "Open the {$modal} form.");
    }
}
