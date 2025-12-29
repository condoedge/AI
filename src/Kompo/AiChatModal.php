<?php
// src/Kompo/AiChatModal.php

namespace Condoedge\Ai\Kompo;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Services\Chat\AiChatServiceInterface;
use Condoedge\Ai\Kompo\Traits\HasChatConfig;
use Condoedge\Ai\Kompo\Traits\HasAvatars;
use Condoedge\Ai\Kompo\Traits\HasTypingIndicator;
use Condoedge\Utils\Kompo\Common\Modal;

/**
 * AI Chat Modal - Quick chat modal with database persistence.
 *
 * This is a modal version of the chat, ideal for floating buttons.
 * Uses same backend as AiChatPanel but in a compact modal format.
 *
 * Usage:
 *   // Basic
 *   new AiChatModal()
 *
 *   // With configuration
 *   new AiChatModal(null, [
 *       'welcome_title' => 'Sales Assistant',
 *       'example_questions' => ['Show top customers'],
 *   ])
 */
class AiChatModal extends Modal
{
    use HasChatConfig, HasAvatars, HasTypingIndicator;

    protected $_Title = 'AI Assistant';
    public $noHeaderButtons = true;
    public $class = 'overflow-hidden max-w-3xl rounded-2xl';
    public $style = 'max-height: 90vh;';

    public const MESSAGES_PANEL_ID = 'ai-modal-messages';
    public const INPUT_PANEL_ID = 'ai-modal-input';

    protected ?AiConversation $conversation = null;
    protected bool $isOnline = true;
    protected bool $isLoading = false;

    public function created()
    {
        $this->loadChatConfig();
        $this->isLoading = $this->prop('is_loading') ?? false;

        // Check AI service availability
        try {
            $chatService = app(AiChatServiceInterface::class);
            $this->isOnline = $chatService->isAvailable();
        } catch (\Exception $e) {
            $this->isOnline = false;
        }

        // Get or create conversation for this modal session
        $conversationId = $this->prop('conversation_id') ?? session('ai_modal_conversation_id');
        if ($conversationId) {
            $this->conversation = AiConversation::where('user_id', auth()->id())->find($conversationId);
        }

        if (!$this->conversation) {
            $this->conversation = AiConversation::create([
                'user_id' => auth()->id(),
                'team_id' => currentTeamId(),
                'title' => 'Quick Chat',
                'status' => 'active',
            ]);
            session(['ai_modal_conversation_id' => $this->conversation->id]);
        }

        $this->store(['conversation_id' => $this->conversation->id]);
    }

    public function header()
    {
        $statusClass = $this->isOnline ? 'text-emerald-500' : 'text-gray-400';
        $statusText = $this->isOnline ? 'Online' : 'Offline';
        $statusDot = $this->isOnline
            ? '<span class="absolute -bottom-0.5 -right-0.5 w-3 h-3 bg-emerald-500 rounded-full border-2 border-white animate-pulse"></span>'
            : '<span class="absolute -bottom-0.5 -right-0.5 w-3 h-3 bg-gray-400 rounded-full border-2 border-white"></span>';

        return _FlexBetween(
            _Flex(
                _Html('<span class="relative">' . $this->assistantAvatarHtml() . $statusDot . '</span>')->class('mr-3'),
                _Rows(
                    _Html($this->_Title)->class('font-semibold text-gray-800'),
                    _Html($statusText)->class('text-xs ' . $statusClass),
                )
            )->class('items-center'),
            _FlexEnd(
                _Link()->icon('arrow-path')
                    ->class('p-2 rounded-xl text-gray-400 hover:text-indigo-500 hover:bg-indigo-50 transition-all')
                    ->balloon('New chat', 'left')
                    ->selfPost('newChat')->inPanel(self::MESSAGES_PANEL_ID),
                _Link()->icon('x-mark')
                    ->class('p-2 rounded-xl text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-all ml-1')
                    ->closeModal()
            )->class('items-center')
        )->class('bg-gradient-to-r from-white to-indigo-50/30 border-b border-gray-100/70 px-5 py-4 rounded-t-2xl');
    }

    public function body()
    {
        return _Rows(
            _Panel($this->renderMessages())->id(self::MESSAGES_PANEL_ID)
                ->class('flex-1 overflow-y-auto px-5 py-4 bg-gradient-to-b from-indigo-50/20 via-white to-purple-50/20 mini-scroll')
                ->style('max-height: 50vh;'),
            _Panel($this->inputSection())->id(self::INPUT_PANEL_ID)
        )->class('h-full flex flex-col');
    }

    protected function renderMessages()
    {
        if (!$this->conversation) {
            return $this->renderWelcome();
        }

        $messages = $this->conversation->messages()->orderBy('created_at')->get();

        if ($messages->isEmpty()) {
            return $this->renderWelcome();
        }

        $bubbles = $messages->map(fn($msg) =>
            $msg->role === 'user' ? $this->userBubble($msg) : $this->assistantBubble($msg)
        )->all();

        $elements = [
            _Hidden()->onLoad->run($this->scrollScript()),
            ...$bubbles,
        ];

        if ($this->isLoading) {
            $elements[] = $this->typingIndicator();
        }

        return _Rows(...$elements)->class('gap-y-4');
    }

    protected function renderWelcome()
    {
        $elements = [
            _Rows(
                _Html($this->welcomeAvatarHtml())->class('mb-4'),
                _Html($this->cfg('welcome_title'))->class('text-2xl font-bold text-gray-800 mb-2 text-center'),
                _Html($this->cfg('welcome_message'))->class('text-gray-500 text-center max-w-md')
            )->class('text-center mb-8'),
        ];

        $examples = $this->cfg('example_questions');
        if (!empty($examples)) {
            $questions = array_map(fn($q) =>
                _Link($q)->icon('chat-bubble-left-ellipsis')
                    ->class('w-full p-4 text-left rounded-xl border border-gray-200 bg-white/80 hover:border-indigo-300 hover:bg-indigo-50/50 transition-all flex items-center gap-3 backdrop-blur-sm')
                    ->selfPost('askQuestion', ['question' => $q])->inPanel(self::MESSAGES_PANEL_ID),
                array_slice($examples, 0, 3)
            );

            $elements[] = _Rows(
                _Html('Try asking:')->class('text-sm font-medium text-gray-400 mb-3 text-center'),
                _Rows(...$questions)->class('space-y-2 w-full max-w-md')
            )->class('w-full flex flex-col items-center');
        }

        return _Rows(...$elements)->class('flex flex-col items-center justify-center py-8 px-4');
    }

    protected function userBubble($message)
    {
        return _Rows(
            _FlexEnd(
                _Rows(
                    _Html(e($message->content))->class('whitespace-pre-wrap'),
                    $this->cfg('show_timestamps')
                        ? _Html($message->created_at->format('g:i A'))->class('text-xs opacity-70 mt-1')
                        : null
                )->class('px-4 py-3 rounded-2xl rounded-tr-md max-w-xs bg-gradient-to-r from-indigo-600 to-purple-600 text-white shadow-lg'),
                $this->cfg('show_avatars')
                    ? _Html($this->userAvatarHtml())->class('ml-3 flex-shrink-0')
                    : null,
            )->class('items-end gap-0')
        )->class('mb-4');
    }

    protected function assistantBubble($message)
    {
        $content = [_Html($this->renderMarkdown($message->content))->class('prose prose-sm max-w-none')];

        // File references
        if ($message->hasFileReferences()) {
            $content[] = $this->renderFileReferences($message->getReferencedFiles());
        }

        // Suggestions
        $suggestions = $message->metadata['suggestions'] ?? [];
        if ($this->cfg('show_suggestions') && !empty($suggestions)) {
            $chips = array_map(fn($s) =>
                _Link($s)->class('inline-flex items-center px-3 py-1.5 text-sm rounded-full bg-gray-100 text-gray-700 hover:bg-indigo-100 hover:text-indigo-700 transition-all cursor-pointer')
                    ->selfPost('askQuestion', ['question' => $s])->inPanel(self::MESSAGES_PANEL_ID),
                array_slice($suggestions, 0, $this->cfg('max_suggestions'))
            );
            $content[] = _Rows(
                _Html('Related questions:')->class('text-xs font-medium text-gray-400 mb-2'),
                _Flex(...$chips)->class('flex-wrap gap-2')
            )->class('mt-4 pt-3 border-t border-gray-100');
        }

        // Copy button
        if ($this->cfg('enable_copy')) {
            $content[] = _Flex(
                _Link()->icon(_Sax('copy', 16))
                    ->class('p-1.5 rounded-lg hover:bg-gray-100 text-gray-400 hover:text-gray-600 transition-all')
                    ->balloon('Copy', 'up')
                    ->onClick->run("navigator.clipboard.writeText(" . json_encode($message->content) . "); \$vlNotify.success('Copied!');")
            )->class('mt-2 opacity-0 group-hover:opacity-100 transition-all');
        }

        return _Rows(
            _Flex(
                $this->cfg('show_avatars')
                    ? _Html($this->assistantAvatarHtml())->class('mr-3 flex-shrink-0')
                    : null,
                _Rows(...$content)->class('group px-4 py-3 rounded-2xl rounded-tl-md max-w-2xl bg-white border border-gray-100 shadow-sm hover:shadow-md transition-all')
            )->class('items-start gap-0')
        )->class('mb-4');
    }

    protected function renderFileReferences(array $files)
    {
        if (empty($files)) {
            return null;
        }

        $cards = array_map(fn($file) =>
            _Flex(
                _Sax('document', 14)->class('text-indigo-500'),
                _Html($file['name'] ?? 'File')->class('text-sm text-gray-700'),
            )->class('inline-flex items-center gap-2 px-2.5 py-1.5 rounded-lg bg-indigo-50 text-sm'),
            array_slice($files, 0, 3)
        );

        return _Flex(...$cards)->class('flex-wrap gap-2 mt-3');
    }

    protected function inputSection()
    {
        return _Rows(
            _Flex(
                _Input()->name('question')
                    ->placeholder($this->cfg('input_placeholder'))
                    ->id('ai-modal-input')
                    ->class('flex-1 bg-transparent !mb-0 border-0 focus:ring-0 text-gray-800 placeholder-gray-400')
                    ->dontSubmitOnEnter()
                    ->onEnter->run("() => document.getElementById('ai-modal-send-btn')?.click()"),
                _Button()->icon(_Sax('send-1', 20))->id('ai-modal-send-btn')
                    ->class('p-3 rounded-xl bg-gradient-to-r from-indigo-600 to-purple-600 text-white shadow-lg hover:shadow-xl hover:scale-105 active:scale-95 transition-all')
                    ->selfPost('askQuestion')->withAllFormValues()->inPanel(self::MESSAGES_PANEL_ID)
            )->class('flex items-center gap-2 px-4 py-3 bg-white/90 border border-gray-200/70 rounded-2xl shadow-sm focus-within:border-indigo-300 focus-within:ring-2 focus-within:ring-indigo-100 backdrop-blur-sm transition-all')
        )->class('p-4 !pb-0 border-t border-gray-100/70 bg-gradient-to-t from-white via-white to-transparent');
    }

    // Action methods
    public function askQuestion()
    {
        $question = trim(request('question') ?? '');
        if (empty($question) || !$this->conversation) {
            return $this->renderMessages();
        }

        // Add user message
        $this->conversation->addMessage('user', $question);

        try {
            $chatService = app(AiChatServiceInterface::class);

            // Get conversation context
            $recentMessages = $this->conversation->getRecentMessages(10);

            $response = $chatService->askWithHistory($question, array_map(fn($m) => [
                'role' => $m['role'],
                'content' => $m['content'],
            ], $recentMessages), ['style' => 'friendly']);

            // Add assistant message
            $this->conversation->addMessage('assistant', $response->content, [
                'referenced_files' => $response->responseData?->referencedFiles ?? [],
                'metadata' => [
                    'suggestions' => $response->responseData?->suggestions ?? [],
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('AI Chat Modal error', ['error' => $e->getMessage()]);
            $this->conversation->addMessage('assistant',
                'I encountered an error. Please try again.',
                ['metadata' => ['error' => true]]
            );
        }

        return $this->renderMessages();
    }

    public function newChat()
    {
        // Create new conversation
        $this->conversation = AiConversation::create([
            'user_id' => auth()->id(),
            'team_id' => currentTeamId(),
            'title' => 'Quick Chat',
            'status' => 'active',
        ]);
        session(['ai_modal_conversation_id' => $this->conversation->id]);

        return $this->renderMessages();
    }

    // Helpers
    protected function renderMarkdown(string $text): string
    {
        $text = e($text);
        $text = preg_replace('/```(\w+)?\n(.*?)\n```/s', '<pre class="bg-gray-900 text-gray-100 p-3 rounded-xl overflow-x-auto text-sm my-2 font-mono"><code>$2</code></pre>', $text);
        $text = preg_replace('/`([^`]+)`/', '<code class="bg-indigo-50 text-indigo-700 px-1.5 py-0.5 rounded text-sm font-mono">$1</code>', $text);
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong class="font-semibold">$1</strong>', $text);
        $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);
        $text = preg_replace('/^- (.+)$/m', '<li class="ml-4 list-disc">$1</li>', $text);

        // Links - validate URLs to prevent XSS
        $text = preg_replace_callback('/\[([^\]]+)\]\(([^\)]+)\)/', function($matches) {
            $label = $matches[1];
            $url = $matches[2];
            if (preg_match('/^(https?:|mailto:|tel:|#)/', $url) || filter_var($url, FILTER_VALIDATE_URL)) {
                return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" class="text-indigo-600 hover:underline" target="_blank">' . $label . '</a>';
            }
            return '[' . $label . '](' . $url . ')';
        }, $text);

        return nl2br($text);
    }

    protected function scrollScript(): string
    {
        return "() => { const c = document.getElementById('" . self::MESSAGES_PANEL_ID . "'); if (c) setTimeout(() => c.scrollTop = c.scrollHeight, 100); }";
    }
}
