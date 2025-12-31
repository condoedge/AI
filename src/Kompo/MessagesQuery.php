<?php

namespace Condoedge\Ai\Kompo;

use Condoedge\Ai\Kompo\Modals\EditMessageModal;
use Condoedge\Ai\Kompo\Modals\FilePreviewModal;
use Condoedge\Ai\Kompo\Traits\HasAvatars;
use Condoedge\Ai\Kompo\Traits\HasChatSettings;
use Condoedge\Ai\Kompo\Traits\HasChatTheme;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Services\UI\SafeMarkdownRenderer;
use Condoedge\Utils\Kompo\Common\Query;

class MessagesQuery extends Query
{
    use HasChatTheme, HasAvatars, HasChatSettings;

    public const ID = 'chat-messages-panel';
    protected $class = '!static flex-1 overflow-y-auto p-6 bg-gradient-to-b ';

    protected $noItemsText = '';

    protected $conversation;

    public function created()
    {
        $this->id(self::ID);

        $this->conversation = AiConversation::where('user_id', auth()->id())
            ->find($this->prop('conversation_id') ?? session('selected_conversation_id'));

        $this->class = '!static flex-1 overflow-y-auto p-6 bg-gradient-to-b ' . $this->theme()->heroBackground() . ' mini-scroll';
    }

    public function top()
    {
        $content = null;

        if (!$this->conversation) {
            $content = $this->emptyState();
        }

        if ($this->conversation && $this->conversation->messages()->count() === 0) {
            $content = $this->welcomeState();
        }

        return _Rows(
            $this->chatHeader(),
            $content,
        );
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
                ->refresh(self::ID),
            _Link()->icon(_Sax('export-2', 18))
                ->class('p-2.5 rounded-xl text-gray-400 ' . $this->theme()->linkHover() . ' transition-all duration-200')
                ->balloon('Export', 'down')
                ->href('ai.export-chat', ['id' => $this->conversation->id])
                ->attr(['download' => 'conversation-' . $this->conversation->id . '.md']),
            _Link()->icon(_Sax('archive', 18))
                ->class('p-2.5 rounded-xl text-gray-400 ' . $this->theme()->linkHover() . ' transition-all duration-200')
                ->balloon('Archive', 'down')
                ->selfPost('archiveConversation', ['id' => $this->conversation->id])
                ->refresh(self::ID),
            _DeleteLink()->icon(_Sax('trash', 18))
                ->class('p-2.5 rounded-xl text-gray-400 hover:text-red-500 hover:bg-red-50 transition-all duration-200')
                ->balloon('Delete', 'down')
                ->selfPost('deleteConversation', ['id' => $this->conversation->id])
                ->refresh(self::ID),
        )->class('gap-1 bg-gray-50/50 rounded-2xl p-1');
    }

    
    public function query()
    {
        return $this->conversation?->messages()
            ->orderBy('created_at');
    }
    

    public function render($message)
    {
        $bubble = $message->role === 'user'
            ? $this->userBubble($message)
            : $this->assistantBubble($message);

        return _Rows(
            _Hidden()->onLoad->run($this->scrollScript()),
            $bubble,
        );
    }

    protected function userBubble($message)
    {
        return _Rows(
            _FlexEnd(
                _Rows(
                    _Html(e($message->content))->class('whitespace-pre-wrap'),
                    $this->settings()->showTimestamps()
                        ? _Html($message->created_at->format('g:i A'))->class('text-xs opacity-60 mt-2')
                        : null,
                )->class('group relative px-4 py-3 rounded-2xl rounded-tr-md max-w-xl bg-gradient-to-r ' . $this->theme()->primaryGradient() . ' text-white shadow-md'),
                $this->settings()->showAvatars()
                    ? _Html($this->userAvatarHtml())->class('ml-3 flex-shrink-0')
                    : null,
            )->class('items-end'),
            // Edit button on hover
            $this->settings()->enableEdit() ? _FlexEnd(
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
        if ($this->settings()->showSuggestions() && !empty($suggestions)) {
            $content[] = $this->renderSuggestions($suggestions);
        }

        // Metrics (execution time, confidence)
        if ($this->settings()->showMetrics() && $message->execution_time_ms) {
            $content[] = $this->renderMetrics($message);
        }

        // Action bar (copy, feedback, regenerate)
        $content[] = $this->messageActionBar($message);

        return _Rows(
            _Flex(
                $this->settings()->showAvatars()
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
        if ($this->settings()->enableCopy()) {
            $actions[] = _Link()->icon(_Sax('copy', 16))
                ->class('p-1.5 rounded-lg hover:bg-gray-100 text-gray-400 hover:text-gray-600 transition-all')
                ->balloon('Copy', 'up')
                ->onClick->run("navigator.clipboard.writeText(" . json_encode($message->content) . "); \$vlNotify.success('Copied to clipboard');");
        }

        // Feedback buttons
        if ($this->settings()->enableFeedback()) {
            $feedback = $message->metadata['feedback'] ?? null;
            $actions[] = _Link()->icon(_Sax('like-1', 16))
                ->class('p-1.5 rounded-lg transition-all ' . ($feedback === 'positive'
                    ? 'bg-emerald-100 text-emerald-600'
                    : 'hover:bg-emerald-50 text-gray-400 hover:text-emerald-600'))
                ->balloon('Helpful', 'up')
                ->selfPost('feedback', ['id' => $message->id, 'type' => 'positive'])
                ->refresh();

            $actions[] = _Link()->icon(_Sax('dislike', 16))
                ->class('p-1.5 rounded-lg transition-all ' . ($feedback === 'negative'
                    ? 'bg-red-100 text-red-600'
                    : 'hover:bg-red-50 text-gray-400 hover:text-red-600'))
                ->balloon('Not helpful', 'up')
                ->selfPost('feedback', ['id' => $message->id, 'type' => 'negative'])
                ->refresh();
        }

        // Regenerate button
        if ($this->settings()->enableRegenerate()) {
            $actions[] = _Link()->icon(_Sax('refresh', 16))
                ->class('p-1.5 rounded-lg text-gray-400 ' . $this->theme()->linkHover() . ' transition-all')
                ->balloon('Regenerate', 'up')
                ->selfPost('regenerate', ['id' => $message->id])
                ->refresh();
        }

        return _Flex(...$actions)
            ->class('mt-3 pt-2 border-t border-gray-50 gap-1 opacity-0 group-hover:opacity-100 transition-opacity');
    }

    protected function renderSuggestions(array $suggestions)
    {
        $chips = array_map(fn($s) =>
            _Link($s)
                ->class('inline-flex items-center px-3 py-1.5 text-sm rounded-full bg-gray-100 text-gray-700 ' . $this->theme()->primaryLightBgHover() . ' transition-all cursor-pointer')
                ->selfPost('askSuggestion', ['question' => $s])->refresh(),
            array_slice($suggestions, 0, $this->settings()->maxSuggestions())
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
        )->class('mt-4 p-4 ' . $this->theme()->primaryLightBg() . ' rounded-xl items-center gap-4');
    }

    protected function renderListData($data)
    {
        $items = $data['items'] ?? [];
        if (empty($items)) {
            return null;
        }

        $badgeColors = $this->theme()->activeBadge();
        $listHtml = '<ul class="mt-4 space-y-2">' . implode('', array_map(fn($item) =>
            '<li class="flex items-start gap-3 p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors">' .
            '<span class="w-6 h-6 rounded-full ' . $badgeColors . ' flex items-center justify-center text-xs font-medium flex-shrink-0">' . ($item['icon'] ?? '•') . '</span>' .
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
            _Sax($icon, 16)->class($this->theme()->primaryText()),
            _Html($file['name'])->class('text-sm text-gray-700 font-medium'),
        )->class('inline-flex items-center gap-2 px-3 py-2 rounded-lg ' . $this->theme()->primaryLightBg() . ' ' . $this->theme()->primaryLightBgHover() . ' cursor-pointer transition-all')
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
                ->class('px-6 py-3 bg-gradient-to-r ' . $this->theme()->primaryGradient() . ' text-white rounded-xl shadow-lg hover:shadow-xl transition-all')
                ->selfPost('createConversation')
                ->refresh()
                ->run('() => {
                    setTimeout(() => {
                        $("#conversation-list .vlQueryWrapper>div:first-child>div:first-child").click();
                    }, 500);
                }'),
        )->class('flex flex-col items-center justify-center h-full py-16');
    }

    protected function welcomeState()
    {
        $elements = [
            _Html($this->welcomeAvatarHtml())->class('mb-6'),
            _Html($this->settings()->welcomeTitle())->class('text-2xl font-bold text-gray-800 mb-3'),
            _Html($this->settings()->welcomeMessage())->class('text-gray-500 text-center max-w-md mb-8'),
        ];

        // Example questions
        $examples = $this->settings()->exampleQuestions();
        if (!empty($examples)) {
            $questionButtons = array_map(fn($q) =>
                _Link($q)->icon('chat-bubble-left-ellipsis')
                    ->class('w-full p-4 text-left rounded-xl border border-gray-200 bg-white ' . $this->theme()->primaryLightBgHover() . ' transition-all flex items-center gap-3 group')
                    ->selfPost('askSuggestion', ['question' => $q])->refresh(),
                $examples
            );

            $elements[] = _Rows(
                _Html('Try asking:')->class('text-sm font-medium text-gray-400 mb-4'),
                _Rows(...$questionButtons)->class('space-y-3 w-full max-w-md'),
            )->class('w-full flex flex-col items-center');
        }

        return _Rows(...$elements)->class('flex flex-col items-center justify-center py-16 px-4');
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
    }

    public function askSuggestion($question)
    {
        request()->merge(['message' => $question]);
        $form = new ChatMessageForm(null, ['conversation_id' => $this->conversation->id]);
        $form->sendMessage()
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

    // ========== HELPERS ==========

    protected function renderMarkdown(string $text): string
    {
        $renderer = new SafeMarkdownRenderer([
            'activeBadge' => $this->theme()->activeBadge(),
            'primaryText' => $this->theme()->primaryText(),
        ]);

        return $renderer->render($text);
    }

    protected function scrollScript(): string
    {
        return "() => {
            const c = document.getElementById('" . self::ID . "');
            if (c) {
                setTimeout(() => c.scrollTo({ top: c.scrollHeight, behavior: 'smooth' }), 100);
            }
        }";
    }
}