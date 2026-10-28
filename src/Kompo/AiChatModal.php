<?php

namespace Condoedge\Ai\Kompo;

use Condoedge\Ai\Kompo\Support\ChatLayout;

/**
 * AiChatModal - The chat surface rendered inside a modal.
 *
 * This is today's AiChatPanel behaviour: the full chat body (sidebar +
 * MessagesQuery + js) wrapped in modal chrome. The floating button opens it
 * via ->inModal(). The shared body lives on AbstractChatSurface; this shell
 * only supplies the modal sizing.
 *
 * Usage:
 *   // Basic
 *   new AiChatModal()
 *
 *   // With configuration
 *   new AiChatModal(null, [
 *       'welcome_title' => 'Sales Assistant',
 *       'example_questions' => ['Show top customers', 'Monthly revenue'],
 *   ])
 */
class AiChatModal extends AbstractChatSurface
{
    public $style = 'max-height: 95vh; width: 100vw;';

    public $class = 'max-w-5xl ai-chat-modal-form';

    public function created()
    {
        parent::created();

        if (ChatLayout::isCompact()) {
            $this->class = 'max-w-2xl ai-chat-modal-form';
        }
    }
}
