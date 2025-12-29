<?php

namespace Condoedge\Ai\Kompo;

use Condoedge\Utils\Kompo\Common\Form;

/**
 * AI Chat Floating Button - A floating action button that opens the AI chat.
 *
 * Features ultra-visual design with animations and gradient effects.
 *
 * Usage:
 *   // Basic - just include in your layout
 *   new AiChatFloating()
 *
 *   // With custom position and label
 *   new AiChatFloating(null, [
 *       'position' => 'bottom-left',
 *       'label' => 'Ask AI',
 *       'pulse' => true,
 *   ])
 */
class AiChatFloating extends Form
{
    protected string $position;
    protected string $size;
    protected string $theme;
    protected ?string $label;
    protected bool $pulse;
    protected array $modalConfig;

    public function created()
    {
        $this->position = $this->prop('position') ?? 'bottom-right';
        $this->size = $this->prop('size') ?? 'lg';
        $this->theme = $this->prop('theme') ?? 'gradient';
        $this->label = $this->prop('label');
        $this->pulse = $this->prop('pulse') ?? false;
        $this->modalConfig = $this->prop('modal_config') ?? [];

        $this->store(['modal_config' => $this->modalConfig]);
    }

    public function render()
    {
        $positions = [
            'bottom-right' => 'bottom-6 right-6',
            'bottom-left' => 'bottom-6 left-6',
            'top-right' => 'top-6 right-6',
            'top-left' => 'top-6 left-6',
        ];

        $sizes = [
            'sm' => ['button' => 'w-12 h-12', 'icon' => 'w-5 h-5'],
            'md' => ['button' => 'w-14 h-14', 'icon' => 'w-6 h-6'],
            'lg' => ['button' => 'w-16 h-16', 'icon' => 'w-7 h-7'],
        ];

        $themes = [
            'gradient' => 'bg-gradient-to-r from-indigo-600 via-purple-600 to-fuchsia-600 text-white hover:from-indigo-700 hover:via-purple-700 hover:to-fuchsia-700 shadow-lg shadow-indigo-500/30',
            'solid' => 'bg-indigo-600 text-white hover:bg-indigo-700 shadow-lg shadow-indigo-500/30',
            'outline' => 'bg-white/90 backdrop-blur-sm text-indigo-600 border-2 border-indigo-400 hover:bg-indigo-50 hover:border-indigo-500 shadow-lg',
            'dark' => 'bg-gray-900 text-white hover:bg-gray-800 shadow-lg shadow-gray-900/30',
        ];

        $positionClass = $positions[$this->position] ?? $positions['bottom-right'];
        $sizeConfig = $sizes[$this->size] ?? $sizes['lg'];
        $themeClass = $themes[$this->theme] ?? $themes['gradient'];

        $buttonClass = $this->label
            ? "px-6 py-4 rounded-2xl gap-2 {$themeClass}"
            : "rounded-full {$sizeConfig['button']} {$themeClass}";

        $content = [_Html($this->chatIcon($sizeConfig['icon']))];
        if ($this->label) {
            $content[] = _Html($this->label)->class('font-semibold whitespace-nowrap');
        }

        return _Rows(
            // Glow effect
            $this->pulse
                ? _Html('')->class('absolute inset-0 rounded-full bg-gradient-to-r from-indigo-500 to-purple-500 animate-ping opacity-30')
                : null,
            // Outer glow ring
            _Html('')->class('absolute inset-[-4px] rounded-full bg-gradient-to-r from-indigo-500/20 via-purple-500/20 to-fuchsia-500/20 blur-lg'),
            // Main button
            _Link(...$content)
                ->class("relative inline-flex items-center justify-center hover:shadow-2xl transform hover:scale-110 active:scale-95 transition-all duration-300 ease-out {$buttonClass}")
                ->selfGet('openChatModal')->inModal()
        )->class("fixed {$positionClass} z-50 group");
    }

    public function openChatModal()
    {
        return new AiChatModal(null, $this->modalConfig);
    }

    protected function chatIcon(string $sizeClass): string
    {
        return '<svg class="' . $sizeClass . ' drop-shadow-sm" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" /></svg>';
    }
}
