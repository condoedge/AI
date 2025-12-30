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

    public function __get($property)
    {
        // The idea is that the we can use properties in snake_case to access theme methods in camelCase. $this->primary_gradient -> $this->theme()->primaryGradient()
        $snakeMethod = \Illuminate\Support\Str::snake($property);
        $camelMethod = \Illuminate\Support\Str::camel($snakeMethod);

        // Delegate unknown method calls to the theme instance if possible
        if (method_exists($this->theme(), $camelMethod)) {
            return ' ' . $this->theme()->$camelMethod() . ' ';
        }

        if (method_exists($this, $camelMethod)) {
            return ' ' . $this->$camelMethod() . ' ';
        }

        return null;
    }

    // Shorthand methods for common theme accesses
    protected function themeGradient(): string { return 'bg-gradient-to-r ' . $this->theme()->primaryGradient(); }
    protected function themeLightGradient(): string { return 'bg-gradient-to-r ' . $this->theme()->primaryLightGradient(); }
    protected function themeSolid(): string { return $this->theme()->primarySolid(); }
    protected function themeText(): string { return $this->theme()->primaryText(); }
    protected function themeLightBg(): string { return $this->theme()->primaryLightBg(); }
    protected function themeRing(): string { return $this->theme()->primaryRing(); }
    protected function themeBorder(): string { return $this->theme()->primaryBorder(); }
    protected function themeShadow(): string { return $this->theme()->primaryShadow(); }
    protected function themeAccentGradient(): string { return 'bg-gradient-to-br ' . $this->theme()->accentGradient(); }
    protected function themeSelected(): string { return $this->theme()->selectedBg() . ' ' . $this->theme()->selectedBorder(); }
    protected function themeActiveBadge(): string { return $this->theme()->activeBadge(); }

    protected function mainHexColor(): string { return method_exists($this->theme(), 'mainHexColor') ? $this->theme()->mainHexColor() : '#4f46e5'; }
}
