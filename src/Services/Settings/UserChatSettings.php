<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Settings;

use Condoedge\Ai\Models\AiUserSetting;

/**
 * User-aware chat settings with priority-based resolution.
 *
 * Resolves settings values using a priority chain:
 * 1. Authenticated user's database settings (AiUserSetting.chat_settings)
 * 2. Session-based settings (ai_chat_settings)
 * 3. Application configuration (ai.chat.*)
 * 4. Hardcoded defaults
 *
 * This allows for flexible configuration at multiple levels while ensuring
 * sensible defaults are always available.
 */
class UserChatSettings extends AbstractChatSettings
{
    /**
     * Get a setting value using the priority chain.
     *
     * Resolution order:
     * 1. Constructor overrides
     * 2. User DB settings (direct columns, if authenticated)
     * 3. Session settings
     * 4. Config file settings
     * 5. Default value
     *
     * @param string $key     The setting key to retrieve
     * @param mixed  $default The default value if no setting found
     *
     * @return mixed The resolved setting value
     */
    protected function get(string $key, mixed $default): mixed
    {
        // Check constructor overrides first (from parent)
        if (isset($this->settings[$key])) {
            return $this->settings[$key];
        }

        // 1. Check user DB direct columns (if authenticated)
        if (auth()->check()) {
            $userSetting = AiUserSetting::forUser(auth()->id());

            // Read from direct model property if it exists and is not null
            if ($userSetting->getAttribute($key) !== null) {
                return $userSetting->getAttribute($key);
            }
        }

        // 2. Check session
        $sessionSettings = session('ai_chat_settings', []);
        if (isset($sessionSettings[$key])) {
            return $sessionSettings[$key];
        }

        // 3. Check config
        $configValue = config("ai.chat.{$key}");
        if ($configValue !== null) {
            return $configValue;
        }

        // 4. Return default
        return $default;
    }

    /**
     * Get the welcome title displayed to new users.
     *
     * @return string The welcome title
     */
    public function welcomeTitle(): string
    {
        return $this->get('welcome_title', 'AI Assistant');
    }

    /**
     * Get the welcome message displayed below the title.
     *
     * @return string The welcome message
     */
    public function welcomeMessage(): string
    {
        return $this->get('welcome_message', 'Ask me anything about your data.');
    }

    /**
     * Get example questions shown to help users get started.
     *
     * @return array<int, string> List of example questions
     */
    public function exampleQuestions(): array
    {
        return $this->get('example_questions', []);
    }

    /**
     * Get the placeholder text for the chat input field.
     *
     * @return string The input placeholder text
     */
    public function inputPlaceholder(): string
    {
        return $this->get('input_placeholder', __('ai.chat.input-placeholder'));
    }

    /**
     * Whether to show timestamps on messages.
     *
     * @return bool True if timestamps should be displayed
     */
    public function showTimestamps(): bool
    {
        return $this->get('show_timestamps', false);
    }

    /**
     * Whether to show user/assistant avatars.
     *
     * @return bool True if avatars should be displayed
     */
    public function showAvatars(): bool
    {
        return $this->get('show_avatars', true);
    }

    /**
     * Whether to show typing indicator during responses.
     *
     * @return bool True if typing indicator should be shown
     */
    public function showTyping(): bool
    {
        return $this->get('show_typing', true);
    }

    /**
     * Whether to show suggested follow-up questions.
     *
     * @return bool True if suggestions should be displayed
     */
    public function showSuggestions(): bool
    {
        return $this->get('show_suggestions', true);
    }

    /**
     * Whether to show response metrics (tokens, latency, etc.).
     *
     * @return bool True if metrics should be displayed
     */
    public function showMetrics(): bool
    {
        return $this->get('show_metrics', false);
    }

    /**
     * Whether to enable the copy message button.
     *
     * @return bool True if copy is enabled
     */
    public function enableCopy(): bool
    {
        return $this->get('enable_copy', true);
    }

    /**
     * Whether to enable feedback buttons on responses.
     *
     * @return bool True if feedback is enabled
     */
    public function enableFeedback(): bool
    {
        return $this->get('enable_feedback', true);
    }

    /**
     * Whether to enable editing of user messages.
     *
     * @return bool True if editing is enabled
     */
    public function enableEdit(): bool
    {
        return $this->get('enable_edit', true);
    }

    /**
     * Whether to enable regenerating responses.
     *
     * @return bool True if regenerate is enabled
     */
    public function enableRegenerate(): bool
    {
        return $this->get('enable_regenerate', true);
    }

    /**
     * Maximum number of suggestions to display.
     *
     * @return int The maximum number of suggestions
     */
    public function maxSuggestions(): int
    {
        return $this->get('max_suggestions', 3);
    }

    /**
     * The response style preference.
     *
     * Common values: 'concise', 'detailed', 'balanced', 'friendly'
     *
     * @return string The response style
     */
    public function responseStyle(): string
    {
        return $this->get('response_style', 'friendly');
    }
}
