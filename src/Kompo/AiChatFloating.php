<?php

declare(strict_types=1);

namespace Condoedge\Ai\Kompo;

use Kompo\Form;

/**
 * AI Chat Floating Button - A floating action button that opens the AI chat.
 *
 * Usage:
 *   // Basic usage - just include in your layout
 *   new AiChatFloating()
 *
 *   // With custom position and appearance
 *   new AiChatFloating([
 *       'position' => 'bottom-left',
 *       'theme' => 'solid',
 *   ])
 *
 *   // With label and modal customization
 *   (new AiChatFloating())->withLabel('Need help?')->modalConfig([
 *       'welcome_title' => 'How can I help?',
 *       'example_questions' => ['Question 1', 'Question 2'],
 *   ])
 */
class AiChatFloating extends Form
{
    protected const POSITIONS = [
        'bottom-right' => 'bottom-6 right-6',
        'bottom-left' => 'bottom-6 left-6',
        'top-right' => 'top-6 right-6',
        'top-left' => 'top-6 left-6',
    ];

    protected const SIZES = [
        'sm' => ['button' => 'w-12 h-12', 'icon' => 'w-5 h-5'],
        'md' => ['button' => 'w-14 h-14', 'icon' => 'w-6 h-6'],
        'lg' => ['button' => 'w-16 h-16', 'icon' => 'w-7 h-7'],
    ];

    protected const THEMES = [
        'gradient' => 'bg-gradient-to-r from-indigo-500 via-purple-500 to-fuchsia-500 text-white hover:from-indigo-600 hover:via-purple-600 hover:to-fuchsia-600',
        'solid' => 'bg-indigo-600 text-white hover:bg-indigo-700',
        'outline' => 'bg-white text-indigo-600 border-2 border-indigo-600 hover:bg-indigo-50',
        'dark' => 'bg-gray-900 text-white hover:bg-gray-800',
    ];

    // Configuration
    protected string $position = 'bottom-right';
    protected string $size = 'lg';
    protected string $theme = 'gradient';
    protected ?string $label = null;
    protected bool $pulse = false;
    protected array $modalConfig = [];

    public function created()
    {
        $this->position = $this->prop('position') ?? $this->position;
        $this->size = $this->prop('size') ?? $this->size;
        $this->theme = $this->prop('theme') ?? $this->theme;
        $this->label = $this->prop('label') ?? $this->label;
        $this->pulse = $this->prop('pulse') ?? $this->pulse;
        $this->modalConfig = $this->prop('modal_config') ?? $this->modalConfig;

        // Store only what's needed for AJAX
        $this->store(['modal_config' => $this->modalConfig]);
    }

    public function render()
    {
        $positionClass = self::POSITIONS[$this->position] ?? self::POSITIONS['bottom-right'];
        $sizeConfig = self::SIZES[$this->size] ?? self::SIZES['lg'];
        $themeClass = self::THEMES[$this->theme] ?? self::THEMES['gradient'];

        $buttonClass = $this->label
            ? "px-6 py-4 {$themeClass}"
            : "{$sizeConfig['button']} {$themeClass}";

        return _Rows(
            $this->pulse ? $this->pulseRing() : null,
            $this->floatingButton($buttonClass, $sizeConfig['icon']),
        )
        ->class("fixed {$positionClass} z-50");
    }

    protected function pulseRing()
    {
        return _Html('')
            ->class('absolute inset-0 rounded-full bg-indigo-500 animate-ping opacity-25');
    }

    protected function floatingButton(string $buttonClass, string $iconSize)
    {
        $content = [_Html($this->chatIcon($iconSize))];

        if ($this->label) {
            $content[] = _Html($this->label)->class('ml-2 font-medium whitespace-nowrap');
        }

        return _Link(...$content)
            ->class("relative inline-flex items-center justify-center rounded-full shadow-xl hover:shadow-2xl transform hover:scale-105 active:scale-95 transition-all duration-300 {$buttonClass}")
            ->selfGet('openChatModal')
            ->inModal();
    }

    public function openChatModal()
    {
        return new AiChatModal(null, $this->modalConfig);
    }

    protected function chatIcon(string $sizeClass): string
    {
        return <<<HTML
            <svg class="{$sizeClass}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" />
            </svg>
        HTML;
    }

    // Fluent configuration methods

    public function position(string $position): self
    {
        $this->position = $position;
        return $this;
    }

    public function size(string $size): self
    {
        $this->size = $size;
        return $this;
    }

    public function theme(string $theme): self
    {
        $this->theme = $theme;
        return $this;
    }

    public function withLabel(string $label): self
    {
        $this->label = $label;
        return $this;
    }

    public function withPulse(bool $pulse = true): self
    {
        $this->pulse = $pulse;
        return $this;
    }

    public function modalConfig(array $config): self
    {
        $this->modalConfig = $config;
        $this->store(['modal_config' => $this->modalConfig]);
        return $this;
    }
}
