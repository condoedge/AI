<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Settings;

/**
 * Base abstract class for chat settings implementations.
 *
 * Provides common functionality for managing chat UI configuration
 * including settings overrides and array conversion. Concrete implementations
 * should define default values for each setting method.
 *
 * Settings values can be of mixed types (string, bool, int, array) depending
 * on the specific setting being configured.
 */
abstract class AbstractChatSettings implements ChatSettingsInterface
{
    /**
     * Setting overrides that take precedence over defaults.
     *
     * @var array<string, mixed>
     */
    protected array $settings = [];

    /**
     * Create a new settings instance with optional overrides.
     *
     * @param array<string, mixed> $settings Setting overrides keyed by setting name
     */
    public function __construct(array $settings = [])
    {
        $this->settings = $settings;
    }

    /**
     * Get a setting value, using the override if set or falling back to default.
     *
     * @param string $key     The setting key to retrieve
     * @param mixed  $default The default value if no override exists
     *
     * @return mixed The setting value (override or default)
     */
    protected function get(string $key, mixed $default): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    /**
     * Convert all settings to an array for serialization.
     *
     * Includes all 15 configurable settings:
     * - Content: welcome_title, welcome_message, example_questions, input_placeholder
     * - Display: show_timestamps, show_avatars, show_typing, show_suggestions, show_metrics
     * - Features: enable_copy, enable_feedback, enable_edit, enable_regenerate
     * - Limits: max_suggestions
     * - Behavior: response_style
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'welcome_title' => $this->welcomeTitle(),
            'welcome_message' => $this->welcomeMessage(),
            'example_questions' => $this->exampleQuestions(),
            'input_placeholder' => $this->inputPlaceholder(),
            'show_timestamps' => $this->showTimestamps(),
            'show_avatars' => $this->showAvatars(),
            'show_typing' => $this->showTyping(),
            'show_suggestions' => $this->showSuggestions(),
            'show_metrics' => $this->showMetrics(),
            'enable_copy' => $this->enableCopy(),
            'enable_feedback' => $this->enableFeedback(),
            'enable_edit' => $this->enableEdit(),
            'enable_regenerate' => $this->enableRegenerate(),
            'max_suggestions' => $this->maxSuggestions(),
            'response_style' => $this->responseStyle(),
        ];
    }
}
