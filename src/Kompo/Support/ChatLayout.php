<?php

namespace Condoedge\Ai\Kompo\Support;

/**
 * The chat layout a host picks with ai.chat.layout:
 *   - 'full'    => conversation sidebar + chat, user-adjustable settings and theme
 *   - 'compact' => the chat column only, settings and theme locked to the host config
 */
final class ChatLayout
{
    public static function isCompact(): bool
    {
        return app()->bound('config') && config('ai.chat.layout', 'full') === 'compact';
    }
}
