<?php

declare(strict_types=1);

namespace Condoedge\Ai\Kompo\Traits;

/**
 * Trait for configuring AI Chat components.
 *
 * Loads configuration from:
 *   1. Props passed to component (highest priority)
 *   2. Config file (config/ai.php 'chat' section)
 *   3. Default values (lowest priority)
 *
 * Uses Kompo's store() to persist configuration across AJAX calls.
 */
trait HasAiChatConfig
{
    // Theme and appearance
    protected string $chatTheme;
    protected string $primaryColor;
    protected string $chatMaxHeight;
    protected string $chatMinHeight;

    // Messages configuration
    protected ?string $welcomeTitle;
    protected ?string $welcomeMessage;
    protected array $exampleQuestions;
    protected bool $showTimestamps;
    protected bool $showAvatars;
    protected bool $showTypingIndicator;

    // Response configuration
    protected string $responseStyle;
    protected bool $enableMarkdown;
    protected bool $enableCodeHighlight;

    // Features
    protected bool $enableCopy;
    protected bool $enableFeedback;
    protected bool $showMetrics;
    protected bool $showSuggestions;
    protected int $maxSuggestions;

    // Persistence
    protected bool $persistHistory;
    protected ?string $sessionKey;
    protected int $maxMessages;

    // Input configuration
    protected string $inputPlaceholder;
    protected bool $autoFocus;

    /**
     * Initialize configuration from props and config.
     * Call this in the component's created() method.
     */
    protected function initChatConfig(): void
    {
        $chatConfig = config('ai.chat', []);

        // Theme and appearance
        $this->chatTheme = $this->prop('theme') ?? $chatConfig['theme'] ?? 'modern';
        $this->primaryColor = $this->prop('primary_color') ?? $chatConfig['primary_color'] ?? '#6366f1';
        $this->chatMaxHeight = $this->prop('max_height') ?? $chatConfig['max_height'] ?? '70vh';
        $this->chatMinHeight = $this->prop('min_height') ?? $chatConfig['min_height'] ?? '400px';

        // Welcome screen
        $this->welcomeTitle = $this->prop('welcome_title') ?? $chatConfig['welcome']['title'] ?? 'AI Assistant';
        $this->welcomeMessage = $this->prop('welcome_message') ?? $chatConfig['welcome']['message'] ?? 'Ask me anything about your data.';
        $this->exampleQuestions = $this->prop('example_questions') ?? $chatConfig['example_questions'] ?? [];

        // Display options
        $this->showTimestamps = $this->prop('show_timestamps') ?? $chatConfig['show_timestamps'] ?? false;
        $this->showAvatars = $this->prop('show_avatars') ?? $chatConfig['show_avatars'] ?? true;
        $this->showTypingIndicator = $this->prop('show_typing_indicator') ?? $chatConfig['show_typing_indicator'] ?? true;

        // Response style
        $this->responseStyle = $this->prop('response_style') ?? $chatConfig['response_style'] ?? 'friendly';
        $this->enableMarkdown = $this->prop('enable_markdown') ?? $chatConfig['enable_markdown'] ?? true;
        $this->enableCodeHighlight = $this->prop('enable_code_highlight') ?? $chatConfig['enable_code_highlight'] ?? true;

        // Features
        $this->enableCopy = $this->prop('enable_copy') ?? $chatConfig['enable_copy'] ?? true;
        $this->enableFeedback = $this->prop('enable_feedback') ?? $chatConfig['enable_feedback'] ?? false;
        $this->showMetrics = $this->prop('show_metrics') ?? $chatConfig['show_metrics'] ?? false;
        $this->showSuggestions = $this->prop('show_suggestions') ?? $chatConfig['show_suggestions'] ?? true;
        $this->maxSuggestions = $this->prop('max_suggestions') ?? $chatConfig['max_suggestions'] ?? 3;

        // Persistence
        $this->persistHistory = $this->prop('persist_history') ?? $chatConfig['persist_history'] ?? true;
        $this->sessionKey = $this->prop('session_key') ?? null;
        $this->maxMessages = $this->prop('max_messages') ?? $chatConfig['max_messages'] ?? 50;

        // Input
        $this->inputPlaceholder = $this->prop('input_placeholder') ?? $chatConfig['input_placeholder'] ?? 'Ask a question...';
        $this->autoFocus = $this->prop('auto_focus') ?? $chatConfig['auto_focus'] ?? true;
    }

    /**
     * Store configuration for persistence across AJAX calls.
     */
    protected function storeChatConfig(): void
    {
        $this->store([
            'theme' => $this->chatTheme,
            'primary_color' => $this->primaryColor,
            'max_height' => $this->chatMaxHeight,
            'min_height' => $this->chatMinHeight,
            'welcome_title' => $this->welcomeTitle,
            'welcome_message' => $this->welcomeMessage,
            'example_questions' => $this->exampleQuestions,
            'show_timestamps' => $this->showTimestamps,
            'show_avatars' => $this->showAvatars,
            'show_typing_indicator' => $this->showTypingIndicator,
            'response_style' => $this->responseStyle,
            'enable_markdown' => $this->enableMarkdown,
            'enable_code_highlight' => $this->enableCodeHighlight,
            'enable_copy' => $this->enableCopy,
            'enable_feedback' => $this->enableFeedback,
            'show_metrics' => $this->showMetrics,
            'show_suggestions' => $this->showSuggestions,
            'max_suggestions' => $this->maxSuggestions,
            'persist_history' => $this->persistHistory,
            'session_key' => $this->sessionKey,
            'max_messages' => $this->maxMessages,
            'input_placeholder' => $this->inputPlaceholder,
            'auto_focus' => $this->autoFocus,
        ]);
    }

    /**
     * Set the chat theme.
     * Options: modern, minimal, gradient, glassmorphism
     */
    public function theme(string $theme): self
    {
        $this->initChatConfig();
        $this->chatTheme = $theme;
        $this->storeChatConfig();

        return $this;
    }

    /**
     * Set the primary brand color.
     */
    public function primaryColor(string $color): self
    {
        $this->initChatConfig();
        $this->primaryColor = $color;
        $this->storeChatConfig();
        
        return $this;
    }

    /**
     * Set the maximum height of the chat container.
     */
    public function maxHeight(string $height): self
    {
        $this->initChatConfig();
        $this->chatMaxHeight = $height;
        $this->storeChatConfig();

        return $this;
    }

    /**
     * Set the minimum height of the chat container.
     */
    public function minHeight(string $height): self
    {
        $this->initChatConfig();
        $this->chatMinHeight = $height;
        $this->storeChatConfig();

        return $this;
    }

    /**
     * Set the welcome title shown when chat is empty.
     */
    public function welcomeTitle(string $title): self
    {
        $this->initChatConfig();
        $this->welcomeTitle = $title;
        $this->storeChatConfig();

        return $this;
    }

    /**
     * Set the welcome message shown when chat is empty.
     */
    public function welcomeMessage(string $message): self
    {
        $this->initChatConfig();
        $this->welcomeMessage = $message;
        $this->storeChatConfig();

        return $this;
    }

    /**
     * Set example questions for the welcome screen.
     */
    public function exampleQuestions(array $questions): self
    {
        $this->initChatConfig();
        $this->exampleQuestions = $questions;
        $this->storeChatConfig();

        return $this;
    }

    /**
     * Show or hide message timestamps.
     */
    public function showTimestamps(bool $show = true): self
    {
        $this->initChatConfig();
        $this->showTimestamps = $show;
        $this->storeChatConfig();

        return $this;
    }

    /**
     * Show or hide user/assistant avatars.
     */
    public function showAvatars(bool $show = true): self
    {
        $this->initChatConfig();
        $this->showAvatars = $show;
        $this->storeChatConfig();

        return $this;
    }

    /**
     * Show or hide typing indicator during AI response.
     */
    public function showTypingIndicator(bool $show = true): self
    {
        $this->initChatConfig();
        $this->showTypingIndicator = $show;
        $this->storeChatConfig();

        return $this;
    }

    /**
     * Set the response style (minimal, concise, friendly, detailed, technical).
     */
    public function responseStyle(string $style): self
    {
        $this->initChatConfig();
        $this->responseStyle = $style;
        $this->storeChatConfig();

        return $this;
    }

    /**
     * Enable or disable markdown rendering.
     */
    public function enableMarkdown(bool $enable = true): self
    {
        $this->initChatConfig();
        $this->enableMarkdown = $enable;
        $this->storeChatConfig();

        return $this;
    }

    /**
     * Enable or disable code syntax highlighting.
     */
    public function enableCodeHighlight(bool $enable = true): self
    {
        $this->initChatConfig();
        $this->enableCodeHighlight = $enable;
        $this->storeChatConfig();

        return $this;
    }

    /**
     * Enable or disable copy message button.
     */
    public function enableCopy(bool $enable = true): self
    {
        $this->initChatConfig();
        $this->enableCopy = $enable;
        $this->storeChatConfig();

        return $this;
    }

    /**
     * Enable or disable feedback buttons (thumbs up/down).
     */
    public function enableFeedback(bool $enable = true): self
    {
        $this->initChatConfig();
        $this->enableFeedback = $enable;
        $this->storeChatConfig();

        return $this;
    }

    /**
     * Show or hide execution metrics.
     */
    public function showMetrics(bool $show = true): self
    {
        $this->initChatConfig();
        $this->showMetrics = $show;
        $this->storeChatConfig();

        return $this;
    }

    /**
     * Show or hide follow-up suggestions.
     */
    public function showSuggestions(bool $show = true): self
    {
        $this->initChatConfig();
        $this->showSuggestions = $show;
        $this->storeChatConfig();

        return $this;
    }

    /**
     * Set maximum number of suggestions to show.
     */
    public function maxSuggestions(int $max): self
    {
        $this->initChatConfig();
        $this->maxSuggestions = $max;
        $this->storeChatConfig();

        return $this;
    }

    /**
     * Enable or disable conversation history persistence.
     */
    public function persistHistory(bool $persist = true): self
    {
        $this->initChatConfig();
        $this->persistHistory = $persist;
        $this->storeChatConfig();

        return $this;
    }

    /**
     * Set custom session key for history storage.
     */
    public function sessionKey(string $key): self
    {
        $this->initChatConfig();
        $this->sessionKey = $key;
        $this->storeChatConfig();

        return $this;
    }

    /**
     * Set maximum messages to keep in history.
     */
    public function maxMessages(int $max): self
    {
        $this->initChatConfig();
        $this->maxMessages = $max;
        $this->storeChatConfig();

        return $this;
    }

    /**
     * Set input placeholder text.
     */
    public function inputPlaceholder(string $placeholder): self
    {
        $this->initChatConfig();
        $this->inputPlaceholder = $placeholder;
        $this->storeChatConfig();

        return $this;
    }

    /**
     * Auto-focus input on load.
     */
    public function autoFocus(bool $focus = true): self
    {
        $this->initChatConfig();
        $this->autoFocus = $focus;
        $this->storeChatConfig();
        
        return $this;
    }

    /**
     * Get CSS variables for theming.
     */
    protected function getThemeStyles(): string
    {
        $themes = [
            'modern' => [
                '--ai-chat-bg' => '#ffffff',
                '--ai-chat-border' => '#e5e7eb',
                '--ai-chat-shadow' => '0 4px 6px -1px rgb(0 0 0 / 0.1)',
                '--ai-user-bg' => $this->primaryColor,
                '--ai-user-text' => '#ffffff',
                '--ai-assistant-bg' => '#f3f4f6',
                '--ai-assistant-text' => '#1f2937',
                '--ai-input-bg' => '#ffffff',
                '--ai-input-border' => '#d1d5db',
            ],
            'minimal' => [
                '--ai-chat-bg' => '#ffffff',
                '--ai-chat-border' => 'transparent',
                '--ai-chat-shadow' => 'none',
                '--ai-user-bg' => '#f3f4f6',
                '--ai-user-text' => '#1f2937',
                '--ai-assistant-bg' => 'transparent',
                '--ai-assistant-text' => '#1f2937',
                '--ai-input-bg' => '#f9fafb',
                '--ai-input-border' => '#e5e7eb',
            ],
            'gradient' => [
                '--ai-chat-bg' => 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
                '--ai-chat-border' => 'transparent',
                '--ai-chat-shadow' => '0 10px 40px -10px rgba(102, 126, 234, 0.5)',
                '--ai-user-bg' => 'rgba(255,255,255,0.2)',
                '--ai-user-text' => '#ffffff',
                '--ai-assistant-bg' => 'rgba(255,255,255,0.95)',
                '--ai-assistant-text' => '#1f2937',
                '--ai-input-bg' => 'rgba(255,255,255,0.95)',
                '--ai-input-border' => 'transparent',
            ],
            'glassmorphism' => [
                '--ai-chat-bg' => 'rgba(255,255,255,0.7)',
                '--ai-chat-border' => 'rgba(255,255,255,0.3)',
                '--ai-chat-shadow' => '0 8px 32px 0 rgba(31, 38, 135, 0.15)',
                '--ai-chat-backdrop' => 'blur(10px)',
                '--ai-user-bg' => $this->primaryColor,
                '--ai-user-text' => '#ffffff',
                '--ai-assistant-bg' => 'rgba(255,255,255,0.8)',
                '--ai-assistant-text' => '#1f2937',
                '--ai-input-bg' => 'rgba(255,255,255,0.8)',
                '--ai-input-border' => 'rgba(255,255,255,0.3)',
            ],
        ];

        $theme = $themes[$this->chatTheme] ?? $themes['modern'];
        $css = '';

        foreach ($theme as $var => $value) {
            $css .= "{$var}: {$value}; ";
        }

        return $css;
    }

    /**
     * Get the full session key for history storage.
     */
    protected function getSessionKey(): string
    {
        if ($this->sessionKey) {
            return $this->sessionKey;
        }

        $userId = auth()->id() ?? 'guest';
        $prefix = config('ai.chat.session_key_prefix', 'ai_chat_history');
        return "{$prefix}_{$userId}";
    }

    /**
     * Get configuration array for service.
     */
    protected function getServiceConfig(): array
    {
        return [
            'style' => $this->responseStyle,
            'include_suggestions' => $this->showSuggestions,
            'include_metrics' => $this->showMetrics,
            'max_suggestions' => $this->maxSuggestions,
        ];
    }
}
