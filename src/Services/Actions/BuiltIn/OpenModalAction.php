<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Actions\BuiltIn;

use Condoedge\Ai\Services\Actions\ActionContext;
use Condoedge\Ai\Services\Actions\Results\AiActionResult;

/**
 * open_modal — generative-UI built-in.
 *
 * Returns an open-modal INTENT (modal target + prefilled values the LLM supplied). The
 * presenter turns it into _Link()->selfGet($modal, $prefill)->inModal(); the modal
 * route/component is whatever the host wired on the rendering Query.
 *
 * Link grammar (advertised to the LLM):
 *   [Open form](action://open_modal?modal=editOrder&order_id=123&amount=450)
 *
 * Params:
 *   - modal: the selfGet method name on the chat Query to open the modal (required).
 *   - prefill: explicit prefilled values; any other param folds into prefill too.
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

    public function inputSchema(): array
    {
        return [
            'modal' => ['type' => 'string', 'required' => true, 'label' => 'Modal target'],
            'prefill' => ['type' => 'array', 'label' => 'Prefilled values'],
        ];
    }

    public function handle(array $input, ActionContext $context): AiActionResult
    {
        $modal = (string) ($input['modal'] ?? '');
        $text = $context->text ?? $this->label();

        if ($modal === '') {
            return AiActionResult::error('Cannot open modal: no target specified.');
        }

        // Prefill = every param except the reserved 'modal', merged with any explicit prefill.
        $prefill = $input['prefill'] ?? [];
        foreach ($input as $k => $v) {
            if ($k !== 'modal' && $k !== 'prefill') {
                $prefill[$k] = $v;
            }
        }

        return AiActionResult::element([
            'action' => 'open_modal',
            'modal' => $modal,
            'prefill' => $prefill,
            'text' => $text,
        ], "Open the {$modal} form.");
    }
}
