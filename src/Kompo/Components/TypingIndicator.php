<?php

declare(strict_types=1);

namespace Condoedge\Ai\Kompo\Components;

use Condoedge\Ai\Kompo\Traits\HasChatTheme;

class TypingIndicator
{
    use HasChatTheme;

    protected string $style = 'dots';
    protected string $message = '';
    protected bool $showAvatar = true;

    public function __construct(array $options = [])
    {
        $this->style = $options['style'] ?? 'dots';
        $this->message = $options['message'] ?? '';
        $this->showAvatar = $options['show_avatar'] ?? true;
    }

    public function render()
    {
        $avatar = $this->showAvatar ? $this->assistantAvatarHtml() : '';

        return _Flex(
            $avatar ? _Html($avatar)->class('mr-3 flex-shrink-0 self-start') : null,
            _Rows(
                $this->getAnimationHtml(),
                $this->message ? _Html($this->message)->class('text-xs text-gray-400 mt-2') : null,
            )->class('px-5 py-4 rounded-2xl rounded-tl-md bg-white border border-gray-100 shadow-sm'),
        )->class('items-start animate-fade-in');
    }

    protected function getAnimationHtml()
    {
        return match($this->style) {
            'wave' => $this->waveAnimation(),
            'pulse' => $this->pulseAnimation(),
            'brain' => $this->brainAnimation(),
            default => $this->dotsAnimation(),
        };
    }

    protected function dotsAnimation()
    {
        $gradient = $this->theme()->primaryGradient();

        return _Html('
            <div class="ai-typing-dots flex items-center gap-1.5 h-6">
                <span class="w-2.5 h-2.5 rounded-full bg-gradient-to-r ' . $gradient . ' animate-typing-dot-1"></span>
                <span class="w-2.5 h-2.5 rounded-full bg-gradient-to-r ' . $gradient . ' animate-typing-dot-2"></span>
                <span class="w-2.5 h-2.5 rounded-full bg-gradient-to-r ' . $gradient . ' animate-typing-dot-3"></span>
            </div>
        ');
    }

    protected function waveAnimation()
    {
        $gradient = $this->theme()->primaryGradient();

        return _Html('
            <div class="ai-typing-wave flex items-end gap-1 h-6">
                <span class="w-1 bg-gradient-to-t ' . $gradient . ' rounded-full animate-wave-bar-1"></span>
                <span class="w-1 bg-gradient-to-t ' . $gradient . ' rounded-full animate-wave-bar-2"></span>
                <span class="w-1 bg-gradient-to-t ' . $gradient . ' rounded-full animate-wave-bar-3"></span>
                <span class="w-1 bg-gradient-to-t ' . $gradient . ' rounded-full animate-wave-bar-4"></span>
                <span class="w-1 bg-gradient-to-t ' . $gradient . ' rounded-full animate-wave-bar-5"></span>
            </div>
        ');
    }

    protected function pulseAnimation()
    {
        $gradient = $this->theme()->primaryGradient();

        return _Html('
            <div class="ai-typing-pulse relative flex items-center justify-center w-10 h-6">
                <span class="absolute w-4 h-4 rounded-full bg-gradient-to-r ' . $gradient . ' animate-typing-pulse-inner"></span>
                <span class="absolute w-6 h-6 rounded-full bg-gradient-to-r ' . $gradient . ' opacity-30 animate-typing-pulse-outer"></span>
                <span class="absolute w-8 h-8 rounded-full bg-gradient-to-r ' . $gradient . ' opacity-10 animate-typing-pulse-glow"></span>
            </div>
        ');
    }

    protected function brainAnimation()
    {
        $primary = $this->theme()->primaryText();

        return _Html('
            <div class="ai-typing-brain flex items-center gap-2 h-6">
                <svg class="w-5 h-5 ' . $primary . ' animate-thinking-brain" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 2C8.5 2 5.5 4.5 5 8c-2 .5-3.5 2.3-3.5 4.5 0 2.5 2 4.5 4.5 4.5h12c2.5 0 4.5-2 4.5-4.5 0-2.2-1.5-4-3.5-4.5-.5-3.5-3.5-6-7-6z"/>
                </svg>
                <div class="flex gap-0.5">
                    <span class="w-1 h-1 rounded-full bg-amber-400 animate-sparkle-1"></span>
                    <span class="w-1 h-1 rounded-full bg-amber-400 animate-sparkle-2"></span>
                    <span class="w-1 h-1 rounded-full bg-amber-400 animate-sparkle-3"></span>
                </div>
            </div>
        ');
    }

    protected function assistantAvatarHtml(): string
    {
        return '
            <div class="ai-avatar-animated relative">
                <div class="w-9 h-9 rounded-full bg-gradient-to-br from-violet-500 via-purple-500 to-fuchsia-500 flex items-center justify-center shadow-lg">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
                    </svg>
                </div>
            </div>
        ';
    }
}
