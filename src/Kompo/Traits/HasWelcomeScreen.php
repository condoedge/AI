<?php

declare(strict_types=1);

namespace Condoedge\Ai\Kompo\Traits;

/**
 * Trait for rendering the AI chat welcome screen.
 * Must be used on a Kompo component.
 */
trait HasWelcomeScreen
{
    /**
     * Render the welcome screen.
     */
    protected function renderWelcomeScreen(array $config)
    {
        $elements = [
            $this->renderWelcomeHeader($config),
        ];

        if ($config['show_capabilities'] ?? true) {
            $elements[] = $this->renderWelcomeCapabilities();
        }

        if (!empty($config['example_questions'])) {
            $elements[] = $this->renderWelcomeExampleQuestions(
                $config['example_questions'],
                $config['panel_id'] ?? 'ai-chat-messages'
            );
        }

        return _Rows(...$elements)
            ->class('flex flex-col items-center justify-center py-8 px-4');
    }

    /**
     * Render welcome header with avatar, title and message.
     */
    protected function renderWelcomeHeader(array $config)
    {
        return _Rows(
            // Avatar
            _Html($this->getWelcomeAnimatedAvatar())->class('mb-4'),

            // Title
            _Html($config['title'] ?? 'AI Assistant')
                ->class('text-2xl font-bold text-gray-800 mb-2 text-center'),

            // Message
            _Html($config['message'] ?? 'Ask me anything about your data.')
                ->class('text-gray-500 text-center max-w-md leading-relaxed')
        )->class('text-center mb-8');
    }

    /**
     * Render capability cards.
     */
    protected function renderWelcomeCapabilities()
    {
        $capabilities = [
            ['icon' => '🔍', 'title' => 'Search', 'desc' => 'Find information quickly'],
            ['icon' => '📊', 'title' => 'Analyze', 'desc' => 'Get insights from data'],
            ['icon' => '💡', 'title' => 'Suggest', 'desc' => 'Receive recommendations'],
        ];

        $items = array_map(function ($cap) {
            return _Rows(
                _Html($cap['icon'])->class('text-2xl mb-2'),
                _Html($cap['title'])->class('font-semibold text-gray-800 text-sm'),
                _Html($cap['desc'])->class('text-xs text-gray-500')
            )->class('text-center p-4 bg-white rounded-xl border border-gray-100 shadow-sm hover:shadow-md hover:border-indigo-200 transition-all duration-300');
        }, $capabilities);

        return _Flex(...$items)
            ->class('gap-4 mb-8 flex-wrap justify-center');
    }

    /**
     * Render example question buttons.
     */
    protected function renderWelcomeExampleQuestions(array $questions, string $panelId)
    {
        $questionElements = array_map(function ($question) use ($panelId) {
            return _Link($question)
                ->icon('chat-bubble-left-ellipsis')
                ->class('w-full p-4 text-left rounded-xl border border-gray-200 bg-white hover:border-indigo-300 hover:bg-indigo-50/30 hover:shadow-md transition-all duration-300 flex items-center gap-3 group')
                ->selfPost('askQuestion', ['question' => $question])
                ->inPanel($panelId);
        }, $questions);

        return _Rows(
            _Html('Try asking:')
                ->class('text-sm font-medium text-gray-400 mb-3 text-center'),
            _Rows(...$questionElements)
                ->class('space-y-2 w-full max-w-md')
        )->class('w-full flex flex-col items-center');
    }

    /**
     * Get animated avatar for welcome screen.
     */
    protected function getWelcomeAnimatedAvatar(): string
    {
        return <<<'HTML'
            <div class="relative">
                <div class="absolute inset-0 rounded-full bg-gradient-to-r from-indigo-500 via-purple-500 to-fuchsia-500 opacity-30 blur-lg"></div>
                <div class="relative w-20 h-20 rounded-full bg-gradient-to-br from-indigo-500 via-purple-500 to-fuchsia-500 flex items-center justify-center shadow-lg">
                    <svg class="w-10 h-10 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.75 3.104v5.714a2.25 2.25 0 01-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 014.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0112 15a9.065 9.065 0 00-6.23-.693L5 14.5m14.8.8l1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0112 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.61L5 14.5" />
                    </svg>
                </div>
                <div class="absolute -bottom-1 -right-1 w-6 h-6 bg-emerald-500 rounded-full border-2 border-white flex items-center justify-center">
                    <span class="w-2 h-2 bg-white rounded-full"></span>
                </div>
            </div>
        HTML;
    }
}
