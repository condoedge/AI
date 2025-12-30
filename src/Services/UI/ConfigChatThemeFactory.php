<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\UI;

use Condoedge\Ai\Services\UI\Themes\IndigoTheme;
use Condoedge\Ai\Services\UI\Themes\GreenTheme;
use Condoedge\Ai\Services\UI\Themes\ConfigTheme;

/**
 * Factory that resolves themes from config/ai.php
 *
 * This is the default factory - reads theme selection from config.
 */
class ConfigChatThemeFactory implements ChatThemeFactoryInterface
{
    protected array $themes = [
        'indigo' => IndigoTheme::class,
        'green' => GreenTheme::class,
        'config' => ConfigTheme::class,
    ];

    public function create(?string $themeName = null, array $overrides = []): ChatThemeInterface
    {
        $config = config('ai.ui', []);
        $themeName = $themeName ?? $config['theme'] ?? 'indigo';
        $configOverrides = $config['colors'] ?? [];
        $mergedOverrides = array_merge($configOverrides, $overrides);

        // Custom theme class
        if (class_exists($themeName) && is_subclass_of($themeName, ChatThemeInterface::class)) {
            return new $themeName($mergedOverrides);
        }

        // Built-in theme
        if (isset($this->themes[$themeName])) {
            return new ($this->themes[$themeName])($mergedOverrides);
        }

        // Fallback
        return new IndigoTheme($mergedOverrides);
    }

    public function register(string $name, string $themeClass): self
    {
        if (!is_subclass_of($themeClass, ChatThemeInterface::class)) {
            throw new \InvalidArgumentException("Theme class must implement ChatThemeInterface");
        }
        $this->themes[$name] = $themeClass;
        return $this;
    }

    public function available(): array
    {
        return array_keys($this->themes);
    }

    public function has(string $name): bool
    {
        return isset($this->themes[$name]) ||
               (class_exists($name) && is_subclass_of($name, ChatThemeInterface::class));
    }
}
