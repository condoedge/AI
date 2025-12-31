<?php

declare(strict_types=1);

namespace Condoedge\Ai\Kompo\Traits;

use Condoedge\Ai\Services\Settings\ChatSettingsInterface;

/**
 * Provides settings access for chat components.
 *
 * Components using this trait can access chat settings via the settings() method.
 * The settings instance is resolved from the service container and cached.
 *
 * Access settings via: $this->settings()->methodName()
 * Access by key via: $this->cfg('key_name')
 */
trait HasChatSettings
{
    protected ?ChatSettingsInterface $chatSettings = null;

    /**
     * Get the settings instance.
     *
     * The settings are resolved from the service container on first access
     * and cached for subsequent calls within the same request.
     */
    protected function settings(): ChatSettingsInterface
    {
        if ($this->chatSettings === null) {
            $this->chatSettings = app(ChatSettingsInterface::class);
        }
        return $this->chatSettings;
    }

    /**
     * Get a configuration value by key with optional default.
     *
     * Provides backward compatibility and a convenient way to access
     * settings values by their array key.
     *
     * @param string $key The setting key (e.g., 'show_avatars', 'welcome_title')
     * @param mixed $default Default value if key doesn't exist
     * @return mixed The setting value or default
     */
    protected function cfg(string $key, mixed $default = null): mixed
    {
        return $this->settings()->toArray()[$key] ?? $default;
    }
}
