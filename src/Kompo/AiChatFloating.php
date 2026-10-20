<?php

namespace Condoedge\Ai\Kompo;

use Condoedge\Ai\Kompo\Traits\HasChatTheme;
use Condoedge\Ai\Kompo\Traits\HasMethodsAsProperties;
use Condoedge\Utils\Kompo\Common\Form;

/**
 * AI Chat Floating Button - A floating action button that opens the AI chat.
 *
 * Features ultra-visual design with animations and gradient effects.
 *
 * The surface it opens is configurable via config('ai.chat.surface', 'modal'):
 *   - 'modal'  => AiChatModal opened in a modal overlay
 *   - 'drawer' => AiChatDrawer opened in a slide-over
 *   - 'screen' => navigates to the routed AiChatScreen full page
 */
class AiChatFloating extends Form
{
    use HasChatTheme, HasMethodsAsProperties;

    public function render()
    {
        return _Rows(
            // Glow effect
            _Html('')->class('absolute inset-0 rounded-full animate-ping opacity-30' . $this->theme_gradient),
            // Outer glow ring
            _Html('')->class('absolute inset-[-4px] rounded-full blur-lg ' . $this->theme_gradient),
            $this->openButton()
        )->class("fixed bottom-10 right-10 z-50 group");
    }

    /**
     * The FAB. Its open action targets the configured surface: a modal/drawer
     * overlay for those surfaces, or a route navigation for the full screen.
     */
    protected function openButton()
    {
        $link = _Link($this->chatIcon('w-8 h-8'))
            ->class($this->themeAccentGradient() . " text-white/80 bg-opacity/80 p-2 rounded-full relative inline-flex items-center justify-center hover:shadow-2xl transform hover:scale-110 active:scale-95 transition-all duration-300 ease-out");

        return match ($this->surface()) {
            'screen' => $link->redirect('ai.chat.screen'),
            'drawer' => $link->selfGet('openChat')->inDrawer(),
            default => $link->selfGet('openChat')->inModal(),
        };
    }

    /**
     * Resolve the surface to open. Returns the new surface instance for modal/drawer.
     * (The 'screen' surface is reached by route navigation, not by this method.)
     *
     * The host's page_context prop (if any) is forwarded to the opened surface as the same
     * array prop, so the surface threads it on to the chat pipeline. Absent => nothing forwarded.
     */
    public function openChat()
    {
        $props = [];
        $page = $this->prop('page_context');
        if (is_array($page) && !empty($page)) {
            $props['page_context'] = $page;
        }

        return match ($this->surface()) {
            'drawer' => new AiChatDrawer(null, $props),
            default => new AiChatModal(null, $props),
        };
    }

    /**
     * Backward-compatible alias for the original open method.
     */
    public function openChatModal()
    {
        return $this->openChat();
    }

    /**
     * The configured chat surface (modal|drawer|screen). Container-tolerant so the
     * component can be instantiated in pure unit tests without a bound config.
     */
    protected function surface(): string
    {
        $surface = app()->bound('config')
            ? config('ai.chat.surface', 'modal')
            : 'modal';

        return in_array($surface, ['modal', 'drawer', 'screen'], true) ? $surface : 'modal';
    }

    protected function chatIcon(string $sizeClass): string
    {
        return '<svg class="' . $sizeClass . ' drop-shadow-sm" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" /></svg>';
    }
}
