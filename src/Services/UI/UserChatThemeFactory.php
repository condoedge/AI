<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\UI;

use Condoedge\Ai\Models\AiUserSetting;
use Condoedge\Ai\Services\UI\Themes\IndigoTheme;
use Condoedge\Ai\Services\UI\Themes\GreenTheme;
use Condoedge\Ai\Services\UI\Themes\ConfigTheme;

/**
 * Factory that resolves themes from user database settings.
 *
 * Falls back to config if user has no preference set.
 */
class UserChatThemeFactory implements ChatThemeFactoryInterface
{
    public $allowUserOverrides = true;

    protected array $themes = [
        'indigo' => IndigoTheme::class,
        'green' => GreenTheme::class,
        // 'config' => ConfigTheme::class,
    ];

    public function create(?string $themeName = null, array $overrides = []): ChatThemeInterface
    {
        // Get user settings if authenticated
        // IMPORTANT: Check if auth is available before calling - prevents
        // "Target class [auth] does not exist" during early container resolution
        $userSettings = null;
        if ($this->isAuthAvailable() && auth()->check()) {
            $userSettings = AiUserSetting::forUser(auth()->id());
        }

        // Priority: runtime param > user setting > config
        $themeName = $themeName
            ?? $userSettings?->getThemeName()
            ?? config('ai.ui.theme', 'indigo');

        // Merge overrides: config < user < runtime
        $configOverrides = config('ai.ui.colors', []);
        $userOverrides = $userSettings?->getColorOverrides() ?? [];
        $mergedOverrides = array_merge($configOverrides, $userOverrides, $overrides);

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

    /**
     * Check if authentication service is available.
     *
     * During early container resolution (e.g., before AuthServiceProvider),
     * the 'auth' binding may not exist yet. This prevents the
     * "Target class [auth] does not exist" error.
     */
    private function isAuthAvailable(): bool
    {
        return app()->bound('auth');
    }
}
