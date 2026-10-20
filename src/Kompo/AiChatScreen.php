<?php

namespace Condoedge\Ai\Kompo;

/**
 * AiChatScreen - The chat surface as a routed, full-page view.
 *
 * Same shared body as the modal/drawer (sidebar + MessagesQuery + js), rendered
 * as a standalone page (see routes/web.php: Route::get('/ai/chat', AiChatScreen::class)).
 * The shell only swaps the chrome to fill the page instead of floating as a card.
 *
 * Usage (host route is registered by the package):
 *   GET /ai/chat
 */
class AiChatScreen extends AbstractChatSurface
{
    public $style = 'min-height: 100vh; width: 100%;';

    public $class = 'w-full';

    protected function surfaceStyle(): string
    {
        return 'height: 100vh;';
    }

    protected function surfaceClass(): string
    {
        return '!items-start h-full min-h-[700px] bg-gradient-to-br from-white via-gray-50/50 to-gray-50/30 overflow-hidden';
    }
}
