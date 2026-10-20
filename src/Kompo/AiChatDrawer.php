<?php

namespace Condoedge\Ai\Kompo;

/**
 * AiChatDrawer - The chat surface rendered inside a slide-over drawer.
 *
 * Same shared body as the modal (sidebar + MessagesQuery + js), but with
 * drawer chrome: full viewport height, flush to the edge (no outer rounding).
 * The floating button opens it via ->inDrawer(). The shared body lives on
 * AbstractChatSurface; this shell only supplies the slide-over sizing.
 *
 * Usage:
 *   new AiChatDrawer()
 *
 *   new AiChatDrawer(null, [
 *       'welcome_title' => 'Sales Assistant',
 *   ])
 */
class AiChatDrawer extends AbstractChatSurface
{
    public $style = 'height: 100vh;';

    public $class = 'w-full max-w-3xl';

    protected function surfaceStyle(): string
    {
        return 'height: 100vh;';
    }

    protected function surfaceClass(): string
    {
        return '!items-start h-full min-h-[700px] bg-gradient-to-br from-white via-gray-50/50 to-gray-50/30 shadow-2xl overflow-hidden ring-1 ring-gray-200/50';
    }
}
