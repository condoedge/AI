<?php

namespace Condoedge\Ai\Kompo;

use Condoedge\Ai\Services\Chat\AiChatMessage;
use Condoedge\Ai\Services\Chat\AiChatServiceInterface;
use Condoedge\Utils\Kompo\Common\Modal;

/**
 * AI Chat Modal - Opens a chat dialog with AI assistant.
 *
 * Usage:
 *   // Basic
 *   return new AiChatModal();
 *
 *   // With props
 *   return new AiChatModal(null, [
 *       'welcome_title' => 'My Assistant',
 *       'example_questions' => ['Q1', 'Q2'],
 *   ]);
 */
class AiChatModal extends Modal
{
    protected $_Title = 'AI Assistant';
    public $noHeaderButtons = true;
    public $class = 'overflow-y-auto mini-scroll max-w-3xl';
    public $style = 'max-height: 95vh;';

    protected AiChatServiceInterface $chatService;
    protected array $messages = [];

    // Config (loaded from props or config/ai.php)
    protected string $welcomeTitle;
    protected string $welcomeMessage;
    protected array $exampleQuestions;
    protected string $inputPlaceholder;
    protected bool $showTimestamps;
    protected bool $showAvatars;
    protected bool $enableCopy;
    protected bool $showSuggestions;
    protected int $maxSuggestions;
    protected int $maxMessages;

    public function created()
    {
        $this->chatService = app(AiChatServiceInterface::class);
        $this->loadConfig();
        $this->loadHistory();
    }

    protected function loadConfig()
    {
        $cfg = config('ai.chat', []);

        $this->welcomeTitle = $this->prop('welcome_title') ?? $cfg['welcome']['title'] ?? 'AI Assistant';
        $this->welcomeMessage = $this->prop('welcome_message') ?? $cfg['welcome']['message'] ?? 'Ask me anything about your data.';
        $this->exampleQuestions = $this->prop('example_questions') ?? $cfg['example_questions'] ?? [];
        $this->inputPlaceholder = $this->prop('input_placeholder') ?? $cfg['input_placeholder'] ?? 'Ask a question...';
        $this->showTimestamps = $this->prop('show_timestamps') ?? $cfg['show_timestamps'] ?? false;
        $this->showAvatars = $this->prop('show_avatars') ?? $cfg['show_avatars'] ?? true;
        $this->enableCopy = $this->prop('enable_copy') ?? $cfg['enable_copy'] ?? true;
        $this->showSuggestions = $this->prop('show_suggestions') ?? $cfg['show_suggestions'] ?? true;
        $this->maxSuggestions = $this->prop('max_suggestions') ?? $cfg['max_suggestions'] ?? 3;
        $this->maxMessages = $this->prop('max_messages') ?? $cfg['max_messages'] ?? 50;
    }

    public function header()
    {
        return _FlexBetween(
            _Flex(
                _Html($this->avatarHtml())->class('mr-3'),
                _Rows(
                    _Html($this->_Title)->class('font-semibold text-gray-800'),
                    _Html($this->chatService->isAvailable() ? 'Online' : 'Offline')
                        ->class('text-xs ' . ($this->chatService->isAvailable() ? 'text-emerald-500' : 'text-gray-400'))
                )
            )->class('items-center'),
            _FlexEnd(
                $this->hasMessages()
                    ? _Link()->icon('trash')
                        ->class('p-2 rounded-lg text-gray-400 hover:text-red-500 hover:bg-red-50 transition-all')
                        ->balloon('Clear chat', 'left')
                        ->selfPost('clearChat')->inPanel('ai-chat-messages')
                    : null,
                _Link()->icon('x-mark')
                    ->class('p-2 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-all ml-1')
                    ->closeModal()
            )->class('items-center')
        )->class('bg-white border-b border-gray-100 px-5 py-4 rounded-t-2xl');
    }

    public function body()
    {
        return _Rows(
            _Panel($this->renderMessages())->id('ai-chat-messages')
                ->class('flex-1 overflow-y-auto px-5 py-4 bg-gray-50/50')
                ->style('max-height: 50vh;'),
            $this->inputSection()
        )->class('h-full flex flex-col');
    }

    protected function renderMessages()
    {
        if (!$this->hasMessages()) {
            return $this->renderWelcome();
        }

        $bubbles = [];
        foreach ($this->messages as $msg) {
            $bubbles[] = $msg->isUser()
                ? $this->userBubble($msg)
                : $this->assistantBubble($msg);
        }

        return _Rows(
            _Hidden()->onLoad->run($this->scrollScript()),
            ...$bubbles
        )->class('gap-y-4');
    }

    protected function renderWelcome()
    {
        $elements = [
            _Rows(
                _Html($this->welcomeAvatarHtml())->class('mb-4'),
                _Html($this->welcomeTitle)->class('text-2xl font-bold text-gray-800 mb-2 text-center'),
                _Html($this->welcomeMessage)->class('text-gray-500 text-center max-w-md')
            )->class('text-center mb-8'),
        ];

        if (!empty($this->exampleQuestions)) {
            $questions = array_map(fn($q) =>
                _Link($q)->icon('chat-bubble-left-ellipsis')
                    ->class('w-full p-4 text-left rounded-xl border border-gray-200 bg-white hover:border-indigo-300 hover:bg-indigo-50/30 transition-all flex items-center gap-3')
                    ->selfPost('askQuestion', ['question' => $q])->inPanel('ai-chat-messages'),
                $this->exampleQuestions
            );

            $elements[] = _Rows(
                _Html('Try asking:')->class('text-sm font-medium text-gray-400 mb-3 text-center'),
                _Rows(...$questions)->class('space-y-2 w-full max-w-md')
            )->class('w-full flex flex-col items-center');
        }

        return _Rows(...$elements)->class('flex flex-col items-center justify-center py-8 px-4');
    }

    protected function userBubble(AiChatMessage $msg)
    {
        return _Rows(
            _FlexEnd(
                $this->showAvatars ? _Html($this->userAvatarHtml())->class('ml-3 order-2 flex-shrink-0') : null,
                _Rows(
                    _Html(e($msg->content))->class('whitespace-pre-wrap'),
                    $this->showTimestamps ? _Html($msg->getFormattedTime())->class('text-xs opacity-70 mt-1') : null
                )->class('px-4 py-3 rounded-2xl rounded-tr-md max-w-xs bg-level1 text-white shadow-md')
            )->class('items-end gap-0')
        )->class('mb-4');
    }

    protected function assistantBubble(AiChatMessage $msg)
    {
        $content = [_Html($this->renderMarkdown($msg->content))->class('prose prose-sm max-w-none')];

        // Suggestions
        if ($this->showSuggestions && $msg->responseData?->hasSuggestions()) {
            $chips = array_map(fn($s) =>
                _Link($s)->class('inline-flex items-center px-3 py-1.5 text-sm rounded-full bg-gray-100 text-gray-700 hover:bg-indigo-100 hover:text-indigo-700 transition-all cursor-pointer')
                    ->selfPost('askQuestion', ['question' => $s])->inPanel('ai-chat-messages'),
                array_slice($msg->responseData->suggestions, 0, $this->maxSuggestions)
            );
            $content[] = _Rows(
                _Html('Related questions:')->class('text-xs font-medium text-gray-400 mb-2'),
                _Flex(...$chips)->class('flex-wrap gap-2')
            )->class('mt-4 pt-3 border-t border-gray-100');
        }

        // Copy button
        if ($this->enableCopy) {
            $content[] = _Flex(
                _Link()->icon('clipboard-document')
                    ->class('p-1.5 rounded-lg hover:bg-gray-100 text-gray-400 hover:text-gray-600')
                    ->balloon('Copy', 'up')
                    ->onClick->run("navigator.clipboard.writeText(" . json_encode($msg->content) . ")")
            )->class('mt-2 opacity-0 group-hover:opacity-100 transition-all');
        }

        return _Rows(
            _Flex(
                $this->showAvatars ? _Html($this->assistantAvatarHtml())->class('mr-3 flex-shrink-0') : null,
                _Rows(...$content)->class('group px-4 py-3 rounded-2xl rounded-tl-md max-w-2xl bg-white border border-gray-100 shadow-sm')
            )->class('items-start gap-0')
        )->class('mb-4');
    }

    protected function inputSection()
    {
        return _Rows(
            _Flex(
                _Input()->name('question')->placeholder($this->inputPlaceholder)->id('ai-input')
                    ->class('flex-1 bg-transparent !mb-0 border-0 focus:ring-0 text-gray-800 placeholder-gray-400')
                    ->dontSubmitOnEnter()
                    ->onEnter->run("() => document.getElementById('ai-send-btn')?.click()"),
                _Button()->icon(_Sax('send-1', 20))->id('ai-send-btn')
                    ->selfPost('askQuestion')->withAllFormValues()->inPanel('ai-chat-messages')
            )->class('flex items-center gap-2 px-4 py-3 bg-white border border-gray-200 rounded-2xl shadow-sm focus-within:border-indigo-300 focus-within:ring-2 focus-within:ring-indigo-100 transition-all')
        )->class('p-4 !pb-0 border-t border-gray-100 bg-gradient-to-t from-white via-white to-transparent');
    }

    // Action methods
    public function askQuestion()
    {
        $question = trim(request('question') ?? '');
        if (empty($question)) {
            return $this->renderMessages();
        }

        // Add user message
        $this->addMessage(AiChatMessage::user($question));

        try {
            $response = count($this->messages) > 1
                ? $this->chatService->askWithHistory($question, $this->messages, ['style' => 'friendly'])
                : $this->chatService->ask($question, ['style' => 'friendly']);
            $this->addMessage($response);
        } catch (\Exception $e) {
            \Log::error('AI Chat error', ['error' => $e->getMessage()]);
            $this->addMessage(AiChatMessage::assistant('I encountered an error. Please try again.'));
        }

        return $this->renderMessages();
    }

    public function clearChat()
    {
        $this->messages = [];
        session()->forget($this->sessionKey());
        return $this->renderMessages();
    }

    // History management
    protected function loadHistory()
    {
        $history = session($this->sessionKey(), []);
        $this->messages = array_map(fn($d) => AiChatMessage::fromArray($d), $history);
        if (count($this->messages) > $this->maxMessages) {
            $this->messages = array_slice($this->messages, -$this->maxMessages);
            $this->saveHistory();
        }
    }

    protected function saveHistory()
    {
        session([$this->sessionKey() => array_map(fn($m) => $m->toArray(), $this->messages)]);
    }

    protected function addMessage(AiChatMessage $msg)
    {
        $this->messages[] = $msg;
        if (count($this->messages) > $this->maxMessages) {
            $this->messages = array_slice($this->messages, -$this->maxMessages);
        }
        $this->saveHistory();
    }

    protected function hasMessages(): bool
    {
        return !empty($this->messages);
    }

    protected function sessionKey(): string
    {
        $userId = auth()->id() ?? 'guest';
        return "ai_chat_{$userId}";
    }

    // Helpers
    protected function renderMarkdown(string $text): string
    {
        $text = e($text);
        $text = preg_replace('/```(\w+)?\n(.*?)\n```/s', '<pre class="bg-gray-900 text-gray-100 p-3 rounded-lg overflow-x-auto text-sm my-2"><code>$2</code></pre>', $text);
        $text = preg_replace('/`([^`]+)`/', '<code class="bg-gray-100 text-indigo-600 px-1.5 py-0.5 rounded text-sm">$1</code>', $text);
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);
        $text = preg_replace('/^- (.+)$/m', '<li class="ml-4 list-disc">$1</li>', $text);
        return nl2br($text);
    }

    protected function scrollScript(): string
    {
        return "() => { const c = document.getElementById('ai-chat-messages'); if (c) c.scrollTop = c.scrollHeight; }";
    }

    // Avatar HTML
    protected function avatarHtml(): string
    {
        return '<span class="relative"><span class="w-10 h-10 rounded-full bg-gradient-to-br from-indigo-500 via-purple-500 to-fuchsia-500 flex items-center justify-center shadow-md"><svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 3.104v5.714a2.25 2.25 0 01-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 014.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0112 15a9.065 9.065 0 00-6.23-.693L5 14.5m14.8.8l1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0112 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.61L5 14.5" /></svg></span><span class="absolute -bottom-0.5 -right-0.5 w-3.5 h-3.5 bg-emerald-500 rounded-full border-2 border-white"></span></span>';
    }

    protected function welcomeAvatarHtml(): string
    {
        return '<div class="relative"><div class="absolute inset-0 rounded-full bg-gradient-to-r from-indigo-500 via-purple-500 to-fuchsia-500 opacity-30 blur-lg"></div><div class="relative w-20 h-20 rounded-full bg-gradient-to-br from-indigo-500 via-purple-500 to-fuchsia-500 flex items-center justify-center shadow-lg"><svg class="w-10 h-10 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.75 3.104v5.714a2.25 2.25 0 01-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 014.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0112 15a9.065 9.065 0 00-6.23-.693L5 14.5m14.8.8l1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0112 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.61L5 14.5" /></svg></div><div class="absolute -bottom-1 -right-1 w-6 h-6 bg-emerald-500 rounded-full border-2 border-white flex items-center justify-center"><span class="w-2 h-2 bg-white rounded-full"></span></div></div>';
    }

    protected function userAvatarHtml(): string
    {
        $initial = strtoupper(substr(auth()->user()?->name ?? 'U', 0, 1));
        return '<span class="w-9 h-9 rounded-full bg-gradient-to-br from-indigo-500 to-purple-600 text-white flex items-center justify-center text-sm font-semibold shadow-sm">' . $initial . '</span>';
    }

    protected function assistantAvatarHtml(): string
    {
        return '<span class="w-9 h-9 rounded-full bg-gradient-to-r from-indigo-500 to-purple-600 text-white flex items-center justify-center shadow-sm"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 3.104v5.714a2.25 2.25 0 01-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 014.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0112 15a9.065 9.065 0 00-6.23-.693L5 14.5m14.8.8l1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0112 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.61L5 14.5" /></svg></span>';
    }
}
