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
 * Unlike HasChatTheme which adds spaces around values for CSS class concatenation,
 * settings values are returned as-is since they may be booleans, integers, or strings.
 *
 * @property-read string $welcome_title Access welcomeTitle() via property
 * @property-read string $welcome_message Access welcomeMessage() via property
 * @property-read string $input_placeholder Access inputPlaceholder() via property
 * @property-read bool $show_timestamps Access showTimestamps() via property
 * @property-read bool $show_avatars Access showAvatars() via property
 * @property-read bool $show_typing Access showTyping() via property
 * @property-read bool $show_suggestions Access showSuggestions() via property
 * @property-read bool $show_metrics Access showMetrics() via property
 * @property-read bool $enable_copy Access enableCopy() via property
 * @property-read bool $enable_feedback Access enableFeedback() via property
 * @property-read bool $enable_edit Access enableEdit() via property
 * @property-read bool $enable_regenerate Access enableRegenerate() via property
 * @property-read int $max_suggestions Access maxSuggestions() via property
 * @property-read string $response_style Access responseStyle() via property
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
     * Magic getter for snake_case property access to settings.
     *
     * Allows accessing settings methods via snake_case properties:
     * - $this->welcome_title -> $this->settings()->welcomeTitle()
     * - $this->show_avatars -> $this->settings()->showAvatars()
     *
     * Note: Unlike HasChatTheme, values are returned without surrounding spaces
     * since settings values are not CSS classes.
     *
     * @param string $property The property name in snake_case
     * @return mixed The setting value, or null if not found
     */
    public function __get($property)
    {
        // Convert snake_case property to camelCase method name
        $snakeMethod = \Illuminate\Support\Str::snake($property);
        $camelMethod = \Illuminate\Support\Str::camel($snakeMethod);

        // First try settings methods
        if (method_exists($this->settings(), $camelMethod)) {
            return $this->settings()->$camelMethod();
        }

        // Then try this class methods
        if (method_exists($this, $camelMethod)) {
            return $this->$camelMethod();
        }

        return null;
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

    // Shorthand methods for common settings access

    /**
     * Check if avatars should be shown.
     */
    protected function showAvatars(): bool
    {
        return $this->settings()->showAvatars();
    }

    /**
     * Check if timestamps should be shown.
     */
    protected function showTimestamps(): bool
    {
        return $this->settings()->showTimestamps();
    }

    /**
     * Check if typing indicator should be shown.
     */
    protected function showTyping(): bool
    {
        return $this->settings()->showTyping();
    }

    /**
     * Check if suggestions should be shown.
     */
    protected function showSuggestions(): bool
    {
        return $this->settings()->showSuggestions();
    }

    /**
     * Check if metrics should be shown.
     */
    protected function showMetrics(): bool
    {
        return $this->settings()->showMetrics();
    }

    /**
     * Check if copy button is enabled.
     */
    protected function enableCopy(): bool
    {
        return $this->settings()->enableCopy();
    }

    /**
     * Check if feedback buttons are enabled.
     */
    protected function enableFeedback(): bool
    {
        return $this->settings()->enableFeedback();
    }

    /**
     * Check if message editing is enabled.
     */
    protected function enableEdit(): bool
    {
        return $this->settings()->enableEdit();
    }

    /**
     * Check if response regeneration is enabled.
     */
    protected function enableRegenerate(): bool
    {
        return $this->settings()->enableRegenerate();
    }

    /**
     * Get the welcome title.
     */
    protected function welcomeTitle(): string
    {
        return $this->settings()->welcomeTitle();
    }

    /**
     * Get the welcome message.
     */
    protected function welcomeMessage(): string
    {
        return $this->settings()->welcomeMessage();
    }

    /**
     * Get the input placeholder text.
     */
    protected function inputPlaceholder(): string
    {
        return $this->settings()->inputPlaceholder();
    }

    /**
     * Get the example questions.
     *
     * @return array<int, string>
     */
    protected function exampleQuestions(): array
    {
        return $this->settings()->exampleQuestions();
    }

    /**
     * Get the maximum number of suggestions.
     */
    protected function maxSuggestions(): int
    {
        return $this->settings()->maxSuggestions();
    }

    /**
     * Get the response style preference.
     */
    protected function responseStyle(): string
    {
        return $this->settings()->responseStyle();
    }
}
