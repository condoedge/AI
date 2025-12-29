<?php
// src/Kompo/AiChatPanel.php

namespace Condoedge\Ai\Kompo;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Services\Chat\AiChatServiceInterface;
use Condoedge\Ai\Kompo\Traits\HasChatConfig;
use Condoedge\Ai\Kompo\Traits\HasAvatars;
use Condoedge\Ai\Kompo\Traits\HasTypingIndicator;
use Condoedge\Utils\Kompo\Common\Query;

/**
 * AI Chat Panel - Full-featured chat interface with conversation management.
 *
 * Features: Conversation list, search, pin/archive, feedback, regenerate, edit,
 * export, file references, rich data display, response style selector.
 *
 * Usage:
 *   // Basic
 *   new AiChatPanel()
 *
 *   // With configuration
 *   new AiChatPanel(null, [
 *       'welcome_title' => 'Sales Assistant',
 *       'example_questions' => ['Show top customers', 'Monthly revenue'],
 *   ])
 */
class AiChatPanel extends Query
{
    use HasChatConfig, HasAvatars, HasTypingIndicator;

    public const ID = 'chat-panel';
    public const MESSAGES_PANEL_ID = 'chat-messages-panel';
    public const CONVERSATIONS_PANEL_ID = 'conversations-panel';
    public const INPUT_PANEL_ID = 'chat-input-panel';

    protected ?int $selectedConversationId = null;
    protected ?AiConversation $conversation = null;
    protected bool $isLoading = false;
    protected bool $isOnline = true;

    public function created()
    {
        $this->id(self::ID);
        $this->loadChatConfig();
        $this->selectedConversationId = $this->prop('conversation_id');
        $this->isLoading = $this->prop('is_loading') ?? false;

        // Check AI service availability
        try {
            $chatService = app(AiChatServiceInterface::class);
            $this->isOnline = $chatService->isAvailable();
        } catch (\Exception $e) {
            $this->isOnline = false;
        }

        if ($this->selectedConversationId) {
            $this->conversation = AiConversation::where('user_id', auth()->id())
                ->find($this->selectedConversationId);
        }
    }

    public function query()
    {
        return AiConversation::where('user_id', auth()->id())->limit(0);
    }

    public function render($item = null)
    {
        return $this->mainLayout();
    }

    protected function mainLayout()
    {
        return _Columns(
            $this->sidebar(),
            $this->chatArea(),
        )->class('h-full min-h-[700px] bg-gradient-to-br from-white via-gray-50/50 to-indigo-50/30 rounded-2xl shadow-2xl overflow-hidden ring-1 ring-gray-200/50');
    }

    protected function sidebar()
    {
        return _Rows(
            $this->sidebarHeader(),
            $this->searchInput(),
            _Panel(
                new ConversationListQuery(null, [
                    'selected_id' => $this->selectedConversationId,
                ])
            )->id(self::CONVERSATIONS_PANEL_ID)->class('flex-1 overflow-y-auto mini-scroll'),
            $this->sidebarFooter(),
        )->class('w-80 border-r border-gray-200/70 bg-gradient-to-b from-gray-50 via-white to-gray-50/80 flex flex-col backdrop-blur-sm');
    }

    protected function sidebarHeader()
    {
        $statusClass = $this->isOnline
            ? 'text-emerald-500'
            : 'text-gray-400';
        $statusText = $this->isOnline ? 'Online' : 'Offline';
        $statusDot = $this->isOnline
            ? '<span class="absolute -bottom-0.5 -right-0.5 w-3 h-3 bg-emerald-500 rounded-full border-2 border-white animate-pulse"></span>'
            : '<span class="absolute -bottom-0.5 -right-0.5 w-3 h-3 bg-gray-400 rounded-full border-2 border-white"></span>';

        return _FlexBetween(
            _Flex(
                _Html('<span class="relative">' . $this->assistantAvatarHtml() . $statusDot . '</span>')->class('mr-3'),
                _Rows(
                    _Html('AI Assistant')->class('font-semibold text-gray-800'),
                    _Html($statusText)->class('text-xs ' . $statusClass),
                ),
            )->class('items-center'),
            _Link()->icon('plus')
                ->class('p-2.5 rounded-xl bg-gradient-to-r from-indigo-500 to-purple-500 text-white shadow-lg hover:shadow-xl hover:scale-105 transition-all duration-200')
                ->balloon('New conversation', 'left')
                ->selfPost('createConversation')->inPanel(self::ID),
        )->class('p-4 border-b border-gray-200/70 bg-white/80 backdrop-blur-sm');
    }

    protected function searchInput()
    {
        return _Rows(
            _Input()->name('search')
                ->placeholder('Search conversations...')
                ->icon('magnifying-glass')
                ->class('bg-white/80 border-gray-200/50 rounded-xl shadow-sm focus:ring-2 focus:ring-indigo-200 focus:border-indigo-300 transition-all')
                ->selfGet('searchConversations')->inPanel(self::CONVERSATIONS_PANEL_ID),
        )->class('px-3 py-3 border-b border-gray-100/70');
    }

    protected function sidebarFooter()
    {
        return _FlexBetween(
            _Link('Settings')->icon('cog-6-tooth')
                ->class('text-sm text-gray-500 hover:text-indigo-600 p-2 rounded-lg hover:bg-indigo-50 transition-all')
                ->selfGet('openSettings')->inModal(),
            _Link('Help')->icon('question-mark-circle')
                ->class('text-sm text-gray-500 hover:text-indigo-600 p-2 rounded-lg hover:bg-indigo-50 transition-all')
                ->selfGet('openHelp')->inModal(),
        )->class('p-3 border-t border-gray-200/70 bg-white/80 backdrop-blur-sm');
    }

    protected function chatArea()
    {
        return _Rows(
            $this->chatHeader(),
            _Panel(
                $this->renderMessages()
            )->id(self::MESSAGES_PANEL_ID)->class('flex-1 overflow-y-auto p-6 bg-gradient-to-b from-indigo-50/30 via-white to-purple-50/20 mini-scroll'),
            _Panel(
                $this->inputArea()
            )->id(self::INPUT_PANEL_ID),
        )->class('flex-1 flex flex-col bg-gradient-to-br from-white to-gray-50/50');
    }

    protected function chatHeader()
    {
        if (!$this->conversation) {
            return null;
        }

        $isPinned = $this->conversation->metadata['pinned'] ?? false;
        $messageCount = $this->conversation->messages()->count();

        return _FlexBetween(
            _Rows(
                _Flex(
                    _Html($this->conversation->title ?? 'New Conversation')
                        ->class('font-semibold text-gray-800 text-lg'),
                    $isPinned
                        ? _Html('<span class="ml-2 px-2 py-0.5 bg-amber-100 text-amber-700 text-xs rounded-full font-medium flex items-center gap-1"><svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg> Pinned</span>')
                        : null,
                )->class('items-center'),
                _Flex(
                    _Html('<span class="inline-flex items-center px-2 py-0.5 bg-gray-100 text-gray-600 text-xs rounded-full">' . $messageCount . ' messages</span>'),
                    _Html('•')->class('mx-2 text-gray-300'),
                    _Html($this->conversation->last_message_at?->diffForHumans() ?? 'Just now')
                        ->class('text-xs text-gray-400'),
                )->class('items-center mt-1'),
            ),
            $this->headerActions(),
        )->class('px-6 py-4 border-b border-gray-100/70 bg-white/90 backdrop-blur-md shadow-sm');
    }

    protected function headerActions()
    {
        $isPinned = $this->conversation->metadata['pinned'] ?? false;

        return _Flex(
            _Link()->icon(_Sax('star', 18))
                ->class('p-2.5 rounded-xl transition-all duration-200 ' . ($isPinned
                    ? 'bg-amber-100 text-amber-600 shadow-sm'
                    : 'text-gray-400 hover:text-amber-500 hover:bg-amber-50'))
                ->balloon($isPinned ? 'Unpin' : 'Pin conversation', 'down')
                ->selfPost('togglePin', ['id' => $this->conversation->id])
                ->inPanel(self::ID),
            _Link()->icon(_Sax('export-2', 18))
                ->class('p-2.5 rounded-xl text-gray-400 hover:text-indigo-500 hover:bg-indigo-50 transition-all duration-200')
                ->balloon('Export', 'down')
                ->selfGet('exportConversation', ['id' => $this->conversation->id]),
            _Link()->icon(_Sax('archive', 18))
                ->class('p-2.5 rounded-xl text-gray-400 hover:text-purple-500 hover:bg-purple-50 transition-all duration-200')
                ->balloon('Archive', 'down')
                ->selfPost('archiveConversation', ['id' => $this->conversation->id])
                ->inPanel(self::ID),
            _Link()->icon(_Sax('trash', 18))
                ->class('p-2.5 rounded-xl text-gray-400 hover:text-red-500 hover:bg-red-50 transition-all duration-200')
                ->balloon('Delete', 'down')
                ->selfPost('deleteConversation', ['id' => $this->conversation->id])
                ->inPanel(self::ID),
        )->class('gap-1 bg-gray-50/50 rounded-2xl p-1');
    }

    public function renderMessages()
    {
        if (!$this->conversation) {
            return $this->emptyState();
        }

        $messages = $this->conversation->messages()->orderBy('created_at')->get();

        if ($messages->isEmpty()) {
            return $this->welcomeState();
        }

        $bubbles = $messages->map(fn($msg) => $this->renderMessageBubble($msg))->all();

        $elements = [
            _Hidden()->onLoad->run($this->scrollScript()),
            ...$bubbles,
        ];

        if ($this->isLoading) {
            $elements[] = $this->typingIndicator();
        }

        return _Rows(...$elements)->class('space-y-6');
    }

    protected function renderMessageBubble($message)
    {
        return $message->role === 'user'
            ? $this->userBubble($message)
            : $this->assistantBubble($message);
    }

    protected function userBubble($message)
    {
        return _Rows(
            _FlexEnd(
                _Rows(
                    _Html(e($message->content))->class('whitespace-pre-wrap'),
                    $this->cfg('show_timestamps')
                        ? _Html($message->created_at->format('g:i A'))->class('text-xs opacity-60 mt-2')
                        : null,
                )->class('group relative px-4 py-3 rounded-2xl rounded-tr-md max-w-xl bg-gradient-to-r from-indigo-600 to-purple-600 text-white shadow-md'),
                $this->cfg('show_avatars')
                    ? _Html($this->userAvatarHtml())->class('ml-3 flex-shrink-0')
                    : null,
            )->class('items-end'),
            // Edit button on hover
            $this->cfg('enable_edit') ? _FlexEnd(
                _Link('Edit')->icon('pencil')
                    ->class('opacity-0 group-hover:opacity-100 text-xs text-gray-400 hover:text-gray-600 mt-1 transition-opacity')
                    ->selfGet('editMessage', ['id' => $message->id])->inModal(),
            ) : null,
        )->class('group');
    }

    protected function assistantBubble($message)
    {
        $content = [];

        // Main content
        $content[] = _Html($this->renderMarkdown($message->content))->class('prose prose-sm max-w-none');

        // Rich data display (tables, metrics, lists)
        if ($responseData = $message->response_data) {
            $content[] = $this->renderRichData($responseData);
        }

        // File references
        if ($message->hasFileReferences()) {
            $content[] = $this->renderFileReferences($message->getReferencedFiles());
        }

        // Follow-up suggestions
        $suggestions = $message->metadata['suggestions'] ?? [];
        if ($this->cfg('show_suggestions') && !empty($suggestions)) {
            $content[] = $this->renderSuggestions($suggestions);
        }

        // Metrics (execution time, confidence)
        if ($this->cfg('show_metrics') && $message->execution_time_ms) {
            $content[] = $this->renderMetrics($message);
        }

        // Action bar (copy, feedback, regenerate)
        $content[] = $this->messageActionBar($message);

        return _Rows(
            _Flex(
                $this->cfg('show_avatars')
                    ? _Html($this->assistantAvatarHtml())->class('mr-3 flex-shrink-0 self-start')
                    : null,
                _Rows(...$content)
                    ->class('group px-5 py-4 rounded-2xl rounded-tl-md max-w-2xl bg-white border border-gray-100 shadow-sm hover:shadow-md transition-shadow'),
            )->class('items-start'),
        );
    }

    protected function messageActionBar($message)
    {
        $actions = [];

        // Copy button
        if ($this->cfg('enable_copy')) {
            $actions[] = _Link()->icon(_Sax('copy', 16))
                ->class('p-1.5 rounded-lg hover:bg-gray-100 text-gray-400 hover:text-gray-600 transition-all')
                ->balloon('Copy', 'up')
                ->onClick->run("navigator.clipboard.writeText(" . json_encode($message->content) . "); \$vlNotify.success('Copied to clipboard');");
        }

        // Feedback buttons
        if ($this->cfg('enable_feedback')) {
            $feedback = $message->metadata['feedback'] ?? null;
            $actions[] = _Link()->icon(_Sax('like-1', 16))
                ->class('p-1.5 rounded-lg transition-all ' . ($feedback === 'positive'
                    ? 'bg-emerald-100 text-emerald-600'
                    : 'hover:bg-emerald-50 text-gray-400 hover:text-emerald-600'))
                ->balloon('Helpful', 'up')
                ->selfPost('feedback', ['id' => $message->id, 'type' => 'positive'])
                ->inPanel(self::MESSAGES_PANEL_ID);

            $actions[] = _Link()->icon(_Sax('dislike', 16))
                ->class('p-1.5 rounded-lg transition-all ' . ($feedback === 'negative'
                    ? 'bg-red-100 text-red-600'
                    : 'hover:bg-red-50 text-gray-400 hover:text-red-600'))
                ->balloon('Not helpful', 'up')
                ->selfPost('feedback', ['id' => $message->id, 'type' => 'negative'])
                ->inPanel(self::MESSAGES_PANEL_ID);
        }

        // Regenerate button
        if ($this->cfg('enable_regenerate')) {
            $actions[] = _Link()->icon(_Sax('refresh', 16))
                ->class('p-1.5 rounded-lg hover:bg-indigo-50 text-gray-400 hover:text-indigo-600 transition-all')
                ->balloon('Regenerate', 'up')
                ->selfPost('regenerate', ['id' => $message->id])
                ->inPanel(self::MESSAGES_PANEL_ID);
        }

        return _Flex(...$actions)
            ->class('mt-3 pt-2 border-t border-gray-50 gap-1 opacity-0 group-hover:opacity-100 transition-opacity');
    }

    protected function renderSuggestions(array $suggestions)
    {
        $chips = array_map(fn($s) =>
            _Link($s)
                ->class('inline-flex items-center px-3 py-1.5 text-sm rounded-full bg-gray-100 text-gray-700 hover:bg-indigo-100 hover:text-indigo-700 transition-all cursor-pointer')
                ->selfPost('askSuggestion', ['question' => $s])->inPanel(self::MESSAGES_PANEL_ID),
            array_slice($suggestions, 0, $this->cfg('max_suggestions'))
        );

        return _Rows(
            _Html('Follow-up questions:')->class('text-xs font-medium text-gray-400 mb-2'),
            _Flex(...$chips)->class('flex-wrap gap-2'),
        )->class('mt-4 pt-3 border-t border-gray-100');
    }

    protected function renderRichData($responseData)
    {
        if (!is_array($responseData)) {
            return null;
        }

        $type = $responseData['type'] ?? 'text';

        return match($type) {
            'table' => $this->renderTableData($responseData),
            'metric' => $this->renderMetricData($responseData),
            'list' => $this->renderListData($responseData),
            default => null,
        };
    }

    protected function renderTableData($data)
    {
        $headers = $data['headers'] ?? [];
        $rows = $data['rows'] ?? [];

        if (empty($headers) || empty($rows)) {
            return null;
        }

        $headerHtml = '<tr>' . implode('', array_map(fn($h) =>
            '<th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider bg-gray-50">' . e($h) . '</th>',
            $headers)) . '</tr>';

        $rowsHtml = implode('', array_map(fn($row) =>
            '<tr class="hover:bg-gray-50">' . implode('', array_map(fn($cell) =>
                '<td class="px-4 py-2 text-sm text-gray-700 border-t border-gray-100">' . e($cell) . '</td>',
                $row)) . '</tr>',
            array_slice($rows, 0, 10)));

        return _Html('<div class="mt-4 overflow-hidden rounded-lg border border-gray-200"><table class="min-w-full">' . $headerHtml . $rowsHtml . '</table></div>');
    }

    protected function renderMetricData($data)
    {
        return _Flex(
            _Html($data['icon'] ?? '📊')->class('text-3xl'),
            _Rows(
                _Html($data['label'] ?? 'Value')->class('text-sm text-gray-500'),
                _Flex(
                    _Html($data['value'] ?? '-')->class('text-2xl font-bold text-gray-800'),
                    isset($data['trend']) ? _Badge($data['trend'])
                        ->class(str_contains($data['trend'], '+') ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700') : null,
                )->class('items-center gap-2'),
            ),
        )->class('mt-4 p-4 bg-gradient-to-r from-indigo-50 to-purple-50 rounded-xl items-center gap-4');
    }

    protected function renderListData($data)
    {
        $items = $data['items'] ?? [];
        if (empty($items)) {
            return null;
        }

        $listHtml = '<ul class="mt-4 space-y-2">' . implode('', array_map(fn($item) =>
            '<li class="flex items-start gap-3 p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors">' .
            '<span class="w-6 h-6 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center text-xs font-medium flex-shrink-0">' . ($item['icon'] ?? '•') . '</span>' .
            '<div><div class="font-medium text-gray-800">' . e($item['title'] ?? '') . '</div>' .
            '<div class="text-sm text-gray-500">' . e($item['description'] ?? '') . '</div></div></li>',
            array_slice($items, 0, 5))) . '</ul>';

        return _Html($listHtml);
    }

    protected function renderMetrics($message)
    {
        return _Flex(
            _Html('⚡ ' . $message->execution_time_ms . 'ms')->class('text-xs text-gray-400'),
            $message->confidence_score ? _Html('🎯 ' . round($message->confidence_score * 100) . '%')->class('text-xs text-gray-400') : null,
        )->class('mt-2 gap-3');
    }

    protected function renderFileReferences(array $files)
    {
        if (empty($files)) {
            return null;
        }

        $cards = array_map(fn($file) => $this->fileReferenceCard($file), $files);

        return _Rows(
            _Html('📎 Referenced Files:')->class('text-xs font-medium text-gray-400 mb-2'),
            _Flex(...$cards)->class('flex-wrap gap-2'),
        )->class('mt-4 pt-3 border-t border-gray-100');
    }

    protected function fileReferenceCard(array $file)
    {
        $icon = match($file['type'] ?? 'file') {
            'pdf' => 'document-text',
            'image', 'png', 'jpg' => 'photo',
            'spreadsheet', 'xlsx', 'csv' => 'table-cells',
            default => 'document',
        };

        return _Flex(
            _Sax($icon, 16)->class('text-indigo-500'),
            _Html($file['name'])->class('text-sm text-gray-700 font-medium'),
        )->class('inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-gradient-to-r from-indigo-50 to-purple-50 hover:from-indigo-100 hover:to-purple-100 cursor-pointer transition-all border border-indigo-100')
         ->balloon('Click to view', 'up')
         ->selfGet('viewFile', ['id' => $file['id']])->inModal();
    }

    protected function emptyState()
    {
        return _Rows(
            _Html($this->welcomeAvatarHtml())->class('mb-6'),
            _Html('Select a conversation')->class('text-2xl font-bold text-gray-800 mb-3'),
            _Html('Choose an existing conversation from the sidebar or start a new one.')
                ->class('text-gray-500 text-center max-w-md mb-8'),
            _Button('Start New Chat')->icon('plus')
                ->class('px-6 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl hover:from-indigo-700 hover:to-purple-700 shadow-lg hover:shadow-xl transition-all')
                ->selfPost('createConversation')->inPanel(self::ID),
        )->class('flex flex-col items-center justify-center h-full py-16');
    }

    protected function welcomeState()
    {
        $elements = [
            _Html($this->welcomeAvatarHtml())->class('mb-6'),
            _Html($this->cfg('welcome_title'))->class('text-2xl font-bold text-gray-800 mb-3'),
            _Html($this->cfg('welcome_message'))->class('text-gray-500 text-center max-w-md mb-8'),
        ];

        // Example questions
        $examples = $this->cfg('example_questions');
        if (!empty($examples)) {
            $questionButtons = array_map(fn($q) =>
                _Link($q)->icon('chat-bubble-left-ellipsis')
                    ->class('w-full p-4 text-left rounded-xl border border-gray-200 bg-white hover:border-indigo-300 hover:bg-indigo-50/50 transition-all flex items-center gap-3 group')
                    ->selfPost('askSuggestion', ['question' => $q])->inPanel(self::MESSAGES_PANEL_ID),
                $examples
            );

            $elements[] = _Rows(
                _Html('Try asking:')->class('text-sm font-medium text-gray-400 mb-4'),
                _Rows(...$questionButtons)->class('space-y-3 w-full max-w-md'),
            )->class('w-full flex flex-col items-center');
        }

        return _Rows(...$elements)->class('flex flex-col items-center justify-center py-16 px-4');
    }

    protected function inputArea()
    {
        if (!$this->conversation) {
            return null;
        }

        return new ChatMessageForm(null, [
            'conversation_id' => $this->conversation->id,
            'response_style' => $this->cfg('response_style'),
        ]);
    }

    // ========== ACTION METHODS ==========

    public function createConversation()
    {
        $conversation = AiConversation::create([
            'user_id' => auth()->id(),
            'team_id' => currentTeamId(),
            'status' => 'active',
        ]);

        $this->selectedConversationId = $conversation->id;
        $this->conversation = $conversation;

        return $this->mainLayout();
    }

    public function selectConversation($id)
    {
        $this->selectedConversationId = $id;
        $this->conversation = AiConversation::where('user_id', auth()->id())->find($id);

        return $this->mainLayout();
    }

    public function deleteConversation($id)
    {
        AiConversation::where('user_id', auth()->id())->where('id', $id)->delete();

        $this->selectedConversationId = null;
        $this->conversation = null;

        return $this->mainLayout();
    }

    public function archiveConversation($id)
    {
        AiConversation::where('user_id', auth()->id())
            ->where('id', $id)
            ->update(['status' => 'archived']);

        $this->selectedConversationId = null;
        $this->conversation = null;

        return $this->mainLayout();
    }

    public function togglePin($id)
    {
        $conversation = AiConversation::where('user_id', auth()->id())->find($id);
        if ($conversation) {
            $metadata = $conversation->metadata ?? [];
            $metadata['pinned'] = !($metadata['pinned'] ?? false);
            $conversation->update(['metadata' => $metadata]);
        }

        return $this->mainLayout();
    }

    public function searchConversations()
    {
        $search = request('search');

        return new ConversationListQuery(null, [
            'selected_id' => $this->selectedConversationId,
            'search' => $search,
        ]);
    }

    public function feedback($id, $type)
    {
        $message = $this->conversation?->messages()->find($id);
        if ($message) {
            $metadata = $message->metadata ?? [];
            $metadata['feedback'] = $type;
            $message->update(['metadata' => $metadata]);
        }

        return $this->renderMessages();
    }

    public function regenerate($id)
    {
        if (!$this->conversation) {
            return $this->renderMessages();
        }

        $message = $this->conversation->messages()->find($id);
        if (!$message || $message->role !== 'assistant') {
            return $this->renderMessages();
        }

        // Find the user message before this
        $userMessage = $this->conversation->messages()
            ->where('created_at', '<', $message->created_at)
            ->where('role', 'user')
            ->orderByDesc('created_at')
            ->first();

        if ($userMessage) {
            // Delete the old assistant message
            $message->delete();

            // Re-ask the question
            $form = new ChatMessageForm(null, ['conversation_id' => $this->conversation->id]);
            request()->merge(['message' => $userMessage->content]);
            return $form->sendMessage();
        }

        return $this->renderMessages();
    }

    public function askSuggestion($question)
    {
        request()->merge(['message' => $question]);
        $form = new ChatMessageForm(null, ['conversation_id' => $this->conversation->id]);
        return $form->sendMessage();
    }

    public function exportConversation($id)
    {
        $conversation = AiConversation::where('user_id', auth()->id())->find($id);
        if (!$conversation) {
            return;
        }

        $markdown = "# " . ($conversation->title ?? 'Conversation') . "\n\n";
        $markdown .= "Exported: " . now()->format('F j, Y g:i A') . "\n\n---\n\n";

        foreach ($conversation->messages as $msg) {
            $role = $msg->role === 'user' ? '**You**' : '**AI Assistant**';
            $time = $msg->created_at->format('g:i A');
            $markdown .= "{$role} ({$time}):\n\n{$msg->content}\n\n---\n\n";
        }

        return response($markdown)
            ->header('Content-Type', 'text/markdown')
            ->header('Content-Disposition', 'attachment; filename="conversation-' . $conversation->id . '.md"');
    }

    public function viewFile($id)
    {
        return new FilePreviewModal(null, ['file_id' => $id]);
    }

    public function editMessage($id)
    {
        return new EditMessageModal(null, [
            'message_id' => $id,
            'conversation_id' => $this->conversation?->id,
        ]);
    }

    public function openSettings()
    {
        return new ChatSettingsModal(null, ['config' => $this->chatConfig]);
    }

    public function openHelp()
    {
        return new ChatHelpModal();
    }

    // ========== HELPERS ==========

    protected function renderMarkdown(string $text): string
    {
        $text = e($text);

        // Code blocks with syntax highlighting class
        $text = preg_replace(
            '/```(\w+)?\n(.*?)\n```/s',
            '<pre class="bg-gray-900 text-gray-100 p-4 rounded-xl overflow-x-auto text-sm my-3 font-mono"><code class="language-$1">$2</code></pre>',
            $text
        );

        // Inline code
        $text = preg_replace(
            '/`([^`]+)`/',
            '<code class="bg-indigo-50 text-indigo-700 px-1.5 py-0.5 rounded text-sm font-mono">$1</code>',
            $text
        );

        // Bold
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong class="font-semibold">$1</strong>', $text);

        // Italic
        $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);

        // Lists
        $text = preg_replace('/^- (.+)$/m', '<li class="ml-4 list-disc text-gray-700">$1</li>', $text);
        $text = preg_replace('/^(\d+)\. (.+)$/m', '<li class="ml-4 list-decimal text-gray-700">$2</li>', $text);

        // Headers
        $text = preg_replace('/^### (.+)$/m', '<h4 class="text-lg font-semibold text-gray-800 mt-4 mb-2">$1</h4>', $text);
        $text = preg_replace('/^## (.+)$/m', '<h3 class="text-xl font-semibold text-gray-800 mt-4 mb-2">$1</h3>', $text);

        // Links - validate URLs to prevent XSS
        $text = preg_replace_callback('/\[([^\]]+)\]\(([^\)]+)\)/', function($matches) {
            $label = $matches[1];
            $url = $matches[2];
            // Only allow http(s), mailto, tel, and anchor links
            if (preg_match('/^(https?:|mailto:|tel:|#)/', $url) || filter_var($url, FILTER_VALIDATE_URL)) {
                return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" class="text-indigo-600 hover:underline" target="_blank">' . $label . '</a>';
            }
            return '[' . $label . '](' . $url . ')'; // Return as plain text if invalid
        }, $text);

        // Citations [1], [2] etc
        $text = preg_replace('/\[(\d+)\]/', '<sup class="text-indigo-600 font-medium">[$1]</sup>', $text);

        return nl2br($text);
    }

    protected function scrollScript(): string
    {
        return "() => {
            const c = document.getElementById('" . self::MESSAGES_PANEL_ID . "');
            if (c) {
                setTimeout(() => c.scrollTo({ top: c.scrollHeight, behavior: 'smooth' }), 100);
            }
        }";
    }
}
