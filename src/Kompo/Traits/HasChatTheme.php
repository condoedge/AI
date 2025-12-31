<?php

declare(strict_types=1);

namespace Condoedge\Ai\Kompo\Traits;

use Condoedge\Ai\Services\UI\ChatThemeInterface;
use Condoedge\Ai\Services\UI\ChatThemeFactoryInterface;

/**
 * Provides theme access for chat components.
 *
 * Components using this trait can access theme colors via the theme() method.
 * The theme is resolved from the service container and cached.
 *
 * Access theme values via: $this->theme()->methodName()
 */
trait HasChatTheme
{
    protected ?ChatThemeInterface $chatTheme = null;

    /**
     * Get the theme instance.
     */
    protected function theme(): ChatThemeInterface
    {
        if ($this->chatTheme === null) {
            $themeName = $this->prop('ui_theme') ?? null;
            $overrides = $this->prop('ui_colors') ?? [];

            if ($themeName || !empty($overrides)) {
                $this->chatTheme = app(ChatThemeFactoryInterface::class)->create($themeName, $overrides);
            } else {
                $this->chatTheme = app(ChatThemeInterface::class);
            }
        }
        return $this->chatTheme;
    }

    /**
     * Get the main hex color for UI elements like toggles.
     */
    protected function mainHexColor(): string
    {
        return method_exists($this->theme(), 'mainHexColor')
            ? $this->theme()->mainHexColor()
            : '#4f46e5';
    }
}
