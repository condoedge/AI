<?php

namespace Condoedge\Ai\Kompo\Traits;

trait HasChatConfig
{
    protected array $chatConfig = [];

    protected function loadChatConfig(): void
    {
        $defaults = config('ai.chat', []);

        $this->chatConfig = [
            'welcome_title' => $this->prop('welcome_title') ?? $defaults['welcome']['title'] ?? 'AI Assistant',
            'welcome_message' => $this->prop('welcome_message') ?? $defaults['welcome']['message'] ?? 'Ask me anything about your data.',
            'example_questions' => $this->prop('example_questions') ?? $defaults['example_questions'] ?? [],
            'input_placeholder' => $this->prop('input_placeholder') ?? $defaults['input_placeholder'] ?? 'Ask a question...',
            'show_timestamps' => $this->prop('show_timestamps') ?? $defaults['show_timestamps'] ?? false,
            'show_avatars' => $this->prop('show_avatars') ?? $defaults['show_avatars'] ?? true,
            'show_typing' => $this->prop('show_typing') ?? $defaults['show_typing_indicator'] ?? true,
            'show_suggestions' => $this->prop('show_suggestions') ?? $defaults['show_suggestions'] ?? true,
            'show_metrics' => $this->prop('show_metrics') ?? $defaults['show_metrics'] ?? false,
            'enable_copy' => $this->prop('enable_copy') ?? $defaults['enable_copy'] ?? true,
            'enable_feedback' => $this->prop('enable_feedback') ?? $defaults['enable_feedback'] ?? true,
            'enable_edit' => $this->prop('enable_edit') ?? true,
            'enable_regenerate' => $this->prop('enable_regenerate') ?? true,
            'max_suggestions' => $this->prop('max_suggestions') ?? $defaults['max_suggestions'] ?? 3,
            'theme' => $this->prop('theme') ?? $defaults['theme'] ?? 'modern',
            'response_style' => $this->prop('response_style') ?? 'friendly',
        ];
    }

    protected function cfg(string $key, mixed $default = null): mixed
    {
        return $this->chatConfig[$key] ?? $default;
    }
}
