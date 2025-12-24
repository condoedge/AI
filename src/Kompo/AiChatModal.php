<?php

declare(strict_types=1);

namespace Condoedge\Ai\Kompo;

use Condoedge\Ai\Kompo\Traits\HasAiChatConfig;
use Condoedge\Ai\Kompo\Traits\HasAiMessages;
use Condoedge\Ai\Kompo\Traits\HasMessageBubbles;
use Condoedge\Ai\Kompo\Traits\HasWelcomeScreen;
use Condoedge\Ai\Services\Chat\AiChatMessage;
use Condoedge\Ai\Services\Chat\AiChatServiceInterface;
use Condoedge\Utils\Kompo\Common\Modal;

/**
 * AI Chat Modal - A beautiful modal dialog with AI chat functionality.
 *
 * Usage:
 *   // Basic - uses config defaults
 *   public function openAiChat()
 *   {
 *       return new AiChatModal();
 *   }
 *
 *   // With props
 *   public function openAiChat()
 *   {
 *       return new AiChatModal([
 *           'welcome_title' => 'My AI Assistant',
 *           'welcome_message' => 'How can I help?',
 *           'example_questions' => ['Question 1', 'Question 2'],
 *       ]);
 *   }
 */
class AiChatModal extends Modal
{
    use HasAiChatConfig;
    use HasAiMessages;
    use HasMessageBubbles;
    use HasWelcomeScreen;

    public $_Title = 'AI Assistant';
    public $noHeaderButtons = true;
    public $class = 'overflow-y-auto mini-scroll max-w-3xl';
    public $style = 'max-height: 95vh;';

    protected AiChatServiceInterface $chatService;

    public function created()
    {
        $this->initChatConfig();
        $this->storeChatConfig();
        $this->chatService = app()->make(AiChatServiceInterface::class);

        // Load conversation history
        $this->loadHistory();
    }

    public function header()
    {
        return _FlexBetween(
            _Flex(
                _Html($this->getHeaderAvatar())->class('mr-3'),
                _Rows(
                    _Html($this->_Title)->class('font-semibold text-gray-800'),
                    _Html($this->chatService->isAvailable() ? 'Online' : 'Offline')
                        ->class('text-xs ' . ($this->chatService->isAvailable() ? 'text-emerald-500' : 'text-gray-400'))
                )
            )->class('items-center'),

            _FlexEnd(
                $this->hasMessages()
                    ? _Link()
                        ->icon('trash')
                        ->class('p-2 rounded-lg text-gray-400 hover:text-red-500 hover:bg-red-50 transition-all duration-200')
                        ->balloon('Clear chat', 'left')
                        ->selfPost('clearChat')
                        ->inPanel('ai-chat-messages')
                    : null,
                _Link()
                    ->icon('x-mark')
                    ->class('p-2 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-all duration-200 ml-1')
                    ->closeModal()
            )->class('items-center')
        )
        ->class("bg-white border-b border-gray-100 px-5 py-4 rounded-t-2xl");
    }

    public function body()
    {
        return _Rows(
            $this->chatContainer(),
            _Rows($this->inputSection()),
        )->class('h-full flex flex-col');
    }

    protected function chatContainer()
    {
        return _Panel(
            $this->renderConversationHistory()
        )
            ->id('ai-chat-messages')
            ->class('flex-1 overflow-y-auto px-5 py-4 bg-gray-50/50 h-full')
            ->style('max-height: 50vh;');
    }

    protected function renderConversationHistory()
    {
        if (!$this->hasMessages()) {
            return $this->renderWelcome();
        }

        $bubbleConfig = $this->getBubbleConfig();
        $messages = [];

        foreach ($this->messages as $message) {
            $messages[] = $this->renderMessageBubble($message, $bubbleConfig);
        }

        return _Rows(
            _Hidden()->onLoad->run($this->scrollToBottomScript()),
            ...$messages
        )->class('gap-y-4');
    }

    protected function renderWelcome()
    {
        return $this->renderWelcomeScreen([
            'title' => $this->welcomeTitle,
            'message' => $this->welcomeMessage,
            'example_questions' => !empty($this->exampleQuestions)
                ? $this->exampleQuestions
                : $this->chatService->getExampleQuestions(),
            'panel_id' => 'ai-chat-messages',
        ]);
    }

    protected function inputSection()
    {
        $inputId = 'ai-chat-input';
        $buttonId = 'ai-send-btn';

        $inputElements = [];

        // Main input
        $inputElements[] = _Input()
            // ->resetAfterChange()
            ->name('question')
            ->placeholder($this->inputPlaceholder)
            ->id($inputId)
            ->class('flex-1 bg-transparent !mb-0 border-0 focus:ring-0 text-gray-800 placeholder-gray-400')
            ->dontSubmitOnEnter()
            ->onEnter->run($this->submitOnEnterScript($buttonId));

        // Send button
        $inputElements[] = _Button()
            ->icon(_Sax('send-1', 20))
            ->id($buttonId)
            ->onClick(fn($q) => 
                    $q->selfPost('addQuestion')
                    ->withAllFormValues()
                    ->inPanel('ai-chat-messages')
                && $q->selfPost('askQuestion')
                    ->withAllFormValues()
                    ->inPanel('ai-chat-messages')
            );

        return _Rows(
            _Flex(...$inputElements)
                ->class('flex items-center gap-2 px-4 py-3 bg-white border border-gray-200 rounded-2xl shadow-sm focus-within:border-indigo-300 focus-within:ring-2 focus-within:ring-indigo-100 transition-all duration-200')
        )->class('p-4 !pb-0 border-t border-gray-100 bg-gradient-to-t from-white via-white to-transparent');
    }

    public function addQuestion()
    {
        $question = request('question');

        if (empty(trim($question))) {
            return $this->renderConversationHistory();
        }

        // Add user message
        $userMessage = AiChatMessage::user($question);
        $this->addMessage($userMessage);

        // Return messages with loading indicator (askQuestion will replace this)
        return $this->renderConversationWithLoading();
    }

    /**
     * Render conversation with loading indicator.
     */
    protected function renderConversationWithLoading()
    {
        $bubbleConfig = $this->getBubbleConfig();
        $messages = [];

        foreach ($this->messages as $message) {
            $messages[] = $this->renderMessageBubble($message, $bubbleConfig);
        }

        // Add loading indicator
        $messages[] = $this->renderLoadingBubble();

        return _Rows(
            _Hidden()->onLoad->run($this->scrollToBottomInstantScript()),
            ...$messages
        )->class('gap-y-4');
    }

    /**
     * Render beautiful loading bubble with animated typing dots.
     */
    protected function renderLoadingBubble()
    {
        $loadingHtml = <<<'HTML'
            <div class="ai-typing-indicator flex items-center gap-1.5 py-1">
                <span class="ai-dot"></span>
                <span class="ai-dot"></span>
                <span class="ai-dot"></span>
            </div>
            <style>
                .ai-typing-indicator .ai-dot {
                    width: 8px;
                    height: 8px;
                    background: linear-gradient(135deg, #6366f1 0%, #a855f7 100%);
                    border-radius: 50%;
                    animation: ai-bounce 1.4s ease-in-out infinite;
                }
                .ai-typing-indicator .ai-dot:nth-child(1) { animation-delay: 0s; }
                .ai-typing-indicator .ai-dot:nth-child(2) { animation-delay: 0.2s; }
                .ai-typing-indicator .ai-dot:nth-child(3) { animation-delay: 0.4s; }
                @keyframes ai-bounce {
                    0%, 60%, 100% {
                        transform: translateY(0);
                        opacity: 0.4;
                    }
                    30% {
                        transform: translateY(-10px);
                        opacity: 1;
                    }
                }
            </style>
        HTML;

        return _Flex(
            _Html($this->getLoadingAvatar())->class('mr-3 flex-shrink-0'),
            _Rows(
                _Html($loadingHtml)
            )->class('px-4 py-3 rounded-2xl rounded-tl-md bg-white border border-gray-100 shadow-sm min-w-[60px]')
        )->class('items-start');
    }

    /**
     * Get avatar for loading bubble.
     */
    protected function getLoadingAvatar(): string
    {
        return <<<'HTML'
            <span class="w-9 h-9 rounded-full bg-gradient-to-br from-indigo-500 via-purple-500 to-fuchsia-500 flex items-center justify-center shadow-sm animate-pulse">
                <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 3.104v5.714a2.25 2.25 0 01-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 014.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0112 15a9.065 9.065 0 00-6.23-.693L5 14.5m14.8.8l1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0112 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.61L5 14.5" />
                </svg>
            </span>
        HTML;
    }

    /**
     * Instant scroll to bottom (no delay).
     */
    protected function scrollToBottomInstantScript(): string
    {
        return <<<'JS'
            () => {
                const container = document.getElementById('ai-chat-messages');
                if (container) {
                    container.scrollTop = container.scrollHeight;
                }
            }
        JS;
    }
    
    public function askQuestion()
    {
        $question = request('question');

        if (empty(trim($question))) {
            return $this->renderConversationHistory();
        }

        try {
            // Use askWithHistory if there's conversation history
            if ($this->hasMessages() && count($this->messages) > 1) {
                $response = $this->chatService->askWithHistory(
                    $question,
                    $this->messages,
                    $this->getServiceConfig()
                );
            } else {
                $response = $this->chatService->ask($question, $this->getServiceConfig());
            }
            $this->addMessage($response);
        } catch (\Exception $e) {
            \Log::error('AI Chat Modal error', ['error' => $e->getMessage()]);
            $errorMessage = AiChatMessage::assistant(
                'I encountered an error processing your request. Please try again.',
                \Condoedge\Ai\Services\Chat\AiChatResponseData::error($e->getMessage())
            );
            $this->addMessage($errorMessage);
        }

        // Return with instant scroll
        return $this->renderConversationHistoryWithInstantScroll();
    }

    /**
     * Render conversation history with instant scroll.
     */
    protected function renderConversationHistoryWithInstantScroll()
    {
        if (!$this->hasMessages()) {
            return $this->renderWelcome();
        }

        $bubbleConfig = $this->getBubbleConfig();
        $messages = [];

        foreach ($this->messages as $message) {
            $messages[] = $this->renderMessageBubble($message, $bubbleConfig);
        }

        return _Rows(
            _Hidden()->onLoad->run($this->scrollToBottomInstantScript()),
            ...$messages
        )->class('gap-y-4');
    }
    protected function submitOnEnterScript(string $buttonId): string
    {
        return <<<JS
            () => {
                const btn = document.getElementById('{$buttonId}');
                if (btn && !btn.disabled) btn.click();
            }
        JS;
    }

    protected function clearInputScript(string $inputId): string
    {
        return <<<JS
            () => {
                const input = document.getElementById('{$inputId}');
                if (input) {
                    input.value = '';
                    input.dispatchEvent(new Event('input'));
                }
            }
        JS;
    }

    /**
     * Get configuration for message bubbles.
     */
    protected function getBubbleConfig(): array
    {
        return [
            'show_avatar' => $this->showAvatars,
            'show_timestamp' => $this->showTimestamps,
            'show_metrics' => $this->showMetrics,
            'enable_copy' => $this->enableCopy,
            'enable_feedback' => $this->enableFeedback,
            'enable_markdown' => $this->enableMarkdown,
            'show_suggestions' => $this->showSuggestions,
            'max_suggestions' => $this->maxSuggestions,
            'panel_id' => 'ai-chat-messages',
        ];
    }

    public function clearChat()
    {
        $this->clearHistory();
        return $this->renderConversationHistory();
    }

    public function submitFeedback()
    {
        $messageId = request('message_id');
        $feedback = request('feedback');

        \Log::info('AI Chat feedback', [
            'message_id' => $messageId,
            'feedback' => $feedback,
            'user_id' => auth()->id(),
        ]);

        return null;
    }

    protected function getHeaderAvatar(): string
    {
        return <<<'HTML'
            <span class="relative">
                <span class="w-10 h-10 rounded-full bg-gradient-to-br from-indigo-500 via-purple-500 to-fuchsia-500 flex items-center justify-center shadow-md">
                    <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 3.104v5.714a2.25 2.25 0 01-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 014.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0112 15a9.065 9.065 0 00-6.23-.693L5 14.5m14.8.8l1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0112 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.61L5 14.5" />
                    </svg>
                </span>
                <span class="absolute -bottom-0.5 -right-0.5 w-3.5 h-3.5 bg-emerald-500 rounded-full border-2 border-white"></span>
            </span>
        HTML;
    }

    protected function scrollToBottomScript(): string
    {
        return <<<'JS'
            () => {
                const container = document.getElementById('ai-chat-messages');
                if (container) {
                    setTimeout(() => {
                        container.scrollTop = container.scrollHeight;
                    }, 100);
                }
            }
        JS;
    }
}
