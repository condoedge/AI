<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Settings;

/**
 * Contract for chat settings implementations.
 *
 * Settings provide configuration values for chat UI behavior and features.
 * Implementations should return appropriate default values that can be
 * overridden through configuration or user preferences.
 */
interface ChatSettingsInterface
{
    /**
     * Get the welcome title displayed to new users.
     */
    public function welcomeTitle(): string;

    /**
     * Get the welcome message displayed below the title.
     */
    public function welcomeMessage(): string;

    /**
     * Get example questions shown to help users get started.
     *
     * @return array<int, string>
     */
    public function exampleQuestions(): array;

    /**
     * Get the placeholder text for the chat input field.
     */
    public function inputPlaceholder(): string;

    /**
     * Whether to show timestamps on messages.
     */
    public function showTimestamps(): bool;

    /**
     * Whether to show user/assistant avatars.
     */
    public function showAvatars(): bool;

    /**
     * Whether to show typing indicator during responses.
     */
    public function showTyping(): bool;

    /**
     * Whether to show suggested follow-up questions.
     */
    public function showSuggestions(): bool;

    /**
     * Whether to show response metrics (tokens, latency, etc.).
     */
    public function showMetrics(): bool;

    /**
     * Whether to enable the copy message button.
     */
    public function enableCopy(): bool;

    /**
     * Whether to enable feedback buttons on responses.
     */
    public function enableFeedback(): bool;

    /**
     * Whether to enable editing of user messages.
     */
    public function enableEdit(): bool;

    /**
     * Whether to enable regenerating responses.
     */
    public function enableRegenerate(): bool;

    /**
     * Maximum number of suggestions to display.
     */
    public function maxSuggestions(): int;

    /**
     * The response style preference (e.g., 'concise', 'detailed', 'balanced').
     */
    public function responseStyle(): string;

    /**
     * The typing animation style (e.g., 'dots', 'wave', 'pulse', 'brain').
     */
    public function typingAnimationStyle(): string;

    /**
     * Whether to enable message animations.
     */
    public function enableAnimations(): bool;

    /**
     * Get animation speed: 'slow', 'normal', 'fast', 'none'.
     */
    public function animationSpeed(): string;

    /**
     * Convert all settings to an array for serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
