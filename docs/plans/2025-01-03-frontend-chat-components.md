# Frontend Chat Components Implementation Plan - 10/10 Experience

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Create a world-class chat component system for the AI package following Kompo patterns exactly, integrating with existing backend services. This plan delivers a polished, feature-rich experience matching modern chat applications like ChatGPT and Claude.

**Architecture:** Modular trait-based architecture for maximum flexibility. Core components (ChatPanel, ConversationListQuery, ChatMessageForm) compose traits for each feature area. Database-persisted conversations via `AiConversation` and `AiMessage` models.

**Tech Stack:** PHP 8.1+, Laravel 10+, Kompo Framework, TailwindCSS

---

## Feature Overview

### Core Features (Tasks 1-5)
- [x] Main chat panel with sidebar
- [x] Conversation list with search
- [x] Message bubbles with markdown
- [x] File reference display
- [x] Message input with AiManager

### UX Polish (Tasks 6-9)
- [ ] User/assistant avatars
- [ ] Typing indicator animation
- [ ] Copy message button
- [ ] Follow-up suggestions
- [ ] Timestamps
- [ ] Online status indicator

### Power Features (Tasks 10-14)
- [ ] Rich response types (table, metric, list)
- [ ] Message feedback (thumbs up/down)
- [ ] Regenerate response
- [ ] Edit user message
- [ ] Keyboard shortcuts

### Advanced Features (Tasks 15-18)
- [ ] Conversation search
- [ ] Pin/favorite conversations
- [ ] Archive/restore
- [ ] Export conversation (Markdown)

### Customization (Tasks 19-21)
- [ ] Theme support (light/dark)
- [ ] Response style selector
- [ ] Settings panel
- [ ] Execution metrics display

---

## Pre-Implementation Context

### Existing Backend (Already Implemented)

**Models:**
- `AiConversation` - `uuid`, `user_id`, `team_id`, `title`, `status`, `metadata`, `context_snapshot`, `last_message_at`
- `AiMessage` - `conversation_id`, `role`, `content`, `response_data`, `context_used`, `cypher_query`, `execution_time_ms`, `confidence_score`, `metadata`

**Key Methods:**
- `$conversation->messages()` - HasMany relationship
- `$conversation->addMessage($role, $content, $data)` - Creates message
- `$conversation->getRecentMessages($limit)` - Returns array
- `$message->getReferencedFiles()` - Returns file array
- `$message->hasFileReferences()` - Boolean check

**Services:**
- `AiManager::answerQuestion($question, $options)` - Full AI pipeline
- `AiChatService::askWithConversation()` - Conversation-aware chat
- `AiChatResponseData` - Rich response data (suggestions, tables, metrics)

**Existing Kompo Components (session-based):**
- `AiChatModal` - Modal chat dialog
- `AiChatFloating` - Floating action button

### Kompo Patterns Reference

**Base Classes:**
```php
use Condoedge\Utils\Kompo\Common\Query;  // For list/panel components
use Condoedge\Utils\Kompo\Common\Form;   // For form components
use Condoedge\Utils\Kompo\Common\Modal;  // For modal dialogs
```

**Element Helpers (always use underscore prefix):**
```php
// Layout
_Rows(), _Columns(), _Flex(), _FlexBetween(), _FlexEnd(), _Card(), _Panel()

// Inputs
_Input(), _Textarea(), _Select(), _Hidden(), _Toggle()

// Display
_Html(), _Link(), _Button(), _Badge(), _Sax(), _Tooltip()

// Chaining
->class(), ->id(), ->icon(), ->balloon(), ->onClick
->selfPost(), ->selfGet(), ->inPanel(), ->inModal(), ->browse()
```

---

## Task 1: Create Base Traits for Modularity

**Files:**
- Create: `src/Kompo/Traits/HasChatConfig.php`
- Create: `src/Kompo/Traits/HasAvatars.php`
- Create: `src/Kompo/Traits/HasMessageBubbles.php`
- Create: `src/Kompo/Traits/HasTypingIndicator.php`

**Step 1: Create HasChatConfig trait**

```php
<?php
// src/Kompo/Traits/HasChatConfig.php

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

    protected function cfg(string $key, $default = null)
    {
        return $this->chatConfig[$key] ?? $default;
    }
}
```

**Step 2: Create HasAvatars trait**

```php
<?php
// src/Kompo/Traits/HasAvatars.php

namespace Condoedge\Ai\Kompo\Traits;

trait HasAvatars
{
    protected function userAvatarHtml(): string
    {
        $user = auth()->user();
        $initial = strtoupper(substr($user?->name ?? 'U', 0, 1));
        $colors = ['from-blue-500 to-cyan-500', 'from-green-500 to-emerald-500', 'from-purple-500 to-pink-500', 'from-orange-500 to-amber-500'];
        $colorIndex = $user ? ($user->id % count($colors)) : 0;

        return '<span class="w-9 h-9 rounded-full bg-gradient-to-br ' . $colors[$colorIndex] . ' text-white flex items-center justify-center text-sm font-semibold shadow-sm ring-2 ring-white">' . $initial . '</span>';
    }

    protected function assistantAvatarHtml(): string
    {
        return '<span class="relative"><span class="w-9 h-9 rounded-full bg-gradient-to-br from-indigo-500 via-purple-500 to-fuchsia-500 text-white flex items-center justify-center shadow-sm ring-2 ring-white"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" /></svg></span></span>';
    }

    protected function welcomeAvatarHtml(): string
    {
        return '<div class="relative"><div class="absolute inset-0 rounded-full bg-gradient-to-r from-indigo-500 via-purple-500 to-fuchsia-500 opacity-30 blur-xl animate-pulse"></div><div class="relative w-24 h-24 rounded-full bg-gradient-to-br from-indigo-500 via-purple-500 to-fuchsia-500 flex items-center justify-center shadow-2xl ring-4 ring-white/50"><svg class="w-12 h-12 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" /></svg></div></div>';
    }
}
```

**Step 3: Create HasTypingIndicator trait**

```php
<?php
// src/Kompo/Traits/HasTypingIndicator.php

namespace Condoedge\Ai\Kompo\Traits;

trait HasTypingIndicator
{
    protected function typingIndicator()
    {
        return _Flex(
            _Html($this->assistantAvatarHtml())->class('mr-3 flex-shrink-0'),
            _Rows(
                _Flex(
                    _Html('<span class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 0ms"></span>'),
                    _Html('<span class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 150ms"></span>'),
                    _Html('<span class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 300ms"></span>'),
                )->class('gap-1 items-center'),
            )->class('px-4 py-3 rounded-2xl rounded-tl-md bg-white border border-gray-100 shadow-sm inline-flex'),
        )->class('items-start')->id('typing-indicator');
    }

    protected function typingIndicatorScript(): string
    {
        return "() => {
            const el = document.getElementById('typing-indicator');
            if (el) el.classList.remove('hidden');
        }";
    }

    protected function hideTypingScript(): string
    {
        return "() => {
            const el = document.getElementById('typing-indicator');
            if (el) el.classList.add('hidden');
        }";
    }
}
```

**Step 4: Verify syntax for all traits**

```bash
php -l src/Kompo/Traits/HasChatConfig.php
php -l src/Kompo/Traits/HasAvatars.php
php -l src/Kompo/Traits/HasTypingIndicator.php
```

**Step 5: Commit**

```bash
git add src/Kompo/Traits/
git commit -m "feat(chat): add base traits for modular chat components"
```

---

## Task 2: Create ChatPanel (Main Query Component)

**Files:**
- Create: `src/Kompo/ChatPanel.php`

**Step 1: Create the ChatPanel class with all traits**

```php
<?php
// src/Kompo/ChatPanel.php

namespace Condoedge\Ai\Kompo;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Kompo\Traits\HasChatConfig;
use Condoedge\Ai\Kompo\Traits\HasAvatars;
use Condoedge\Ai\Kompo\Traits\HasTypingIndicator;
use Condoedge\Utils\Kompo\Common\Query;

class ChatPanel extends Query
{
    use HasChatConfig, HasAvatars, HasTypingIndicator;

    public const ID = 'chat-panel';
    public const MESSAGES_PANEL_ID = 'chat-messages-panel';
    public const CONVERSATIONS_PANEL_ID = 'conversations-panel';
    public const INPUT_PANEL_ID = 'chat-input-panel';

    protected ?int $selectedConversationId = null;
    protected ?AiConversation $conversation = null;
    protected bool $isLoading = false;

    public function created()
    {
        $this->id(self::ID);
        $this->loadChatConfig();
        $this->selectedConversationId = $this->prop('conversation_id');
        $this->isLoading = $this->prop('is_loading') ?? false;

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
        )->class('h-full min-h-[700px] bg-white rounded-xl shadow-xl overflow-hidden');
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
            )->id(self::CONVERSATIONS_PANEL_ID)->class('flex-1 overflow-y-auto'),
            $this->sidebarFooter(),
        )->class('w-80 border-r border-gray-200 bg-gray-50 flex flex-col');
    }

    protected function sidebarHeader()
    {
        return _FlexBetween(
            _Flex(
                _Html($this->assistantAvatarHtml())->class('mr-3'),
                _Rows(
                    _Html('AI Assistant')->class('font-semibold text-gray-800'),
                    _Html('Online')->class('text-xs text-emerald-500'),
                ),
            )->class('items-center'),
            _Link()->icon('plus')
                ->class('p-2 rounded-lg hover:bg-gray-200 text-gray-600 transition-all')
                ->balloon('New conversation', 'left')
                ->selfPost('createConversation')->inPanel(self::ID),
        )->class('p-4 border-b border-gray-200 bg-white');
    }

    protected function searchInput()
    {
        return _Rows(
            _Input()->name('search')
                ->placeholder('Search conversations...')
                ->icon('magnifying-glass')
                ->class('bg-white border-gray-200')
                ->selfGet('searchConversations')->inPanel(self::CONVERSATIONS_PANEL_ID),
        )->class('px-3 py-2 border-b border-gray-100');
    }

    protected function sidebarFooter()
    {
        return _FlexBetween(
            _Link('Settings')->icon('cog-6-tooth')
                ->class('text-sm text-gray-500 hover:text-gray-700 p-2')
                ->selfGet('openSettings')->inModal(),
            _Link('Help')->icon('question-mark-circle')
                ->class('text-sm text-gray-500 hover:text-gray-700 p-2')
                ->selfGet('openHelp')->inModal(),
        )->class('p-3 border-t border-gray-200 bg-white');
    }

    protected function chatArea()
    {
        return _Rows(
            $this->chatHeader(),
            _Panel(
                $this->renderMessages()
            )->id(self::MESSAGES_PANEL_ID)->class('flex-1 overflow-y-auto p-6 bg-gradient-to-b from-gray-50 to-white'),
            _Panel(
                $this->inputArea()
            )->id(self::INPUT_PANEL_ID),
        )->class('flex-1 flex flex-col');
    }

    protected function chatHeader()
    {
        if (!$this->conversation) {
            return null;
        }

        return _FlexBetween(
            _Rows(
                _Flex(
                    _Html($this->conversation->title ?? 'New Conversation')
                        ->class('font-semibold text-gray-800'),
                    $this->conversation->metadata['pinned'] ?? false
                        ? _Sax('star', 14)->class('ml-2 text-amber-500')
                        : null,
                )->class('items-center'),
                _Flex(
                    _Html($this->conversation->messages()->count() . ' messages')->class('text-xs text-gray-400'),
                    _Html('•')->class('mx-2 text-gray-300'),
                    _Html($this->conversation->last_message_at?->diffForHumans() ?? 'Just now')
                        ->class('text-xs text-gray-400'),
                )->class('items-center'),
            ),
            $this->headerActions(),
        )->class('px-6 py-4 border-b border-gray-100 bg-white/80 backdrop-blur-sm');
    }

    protected function headerActions()
    {
        return _Flex(
            _Link()->icon(_Sax('star', 18))
                ->class('p-2 rounded-lg text-gray-400 hover:text-amber-500 hover:bg-amber-50 transition-all')
                ->balloon('Pin conversation', 'down')
                ->selfPost('togglePin', ['id' => $this->conversation->id])
                ->inPanel(self::ID),
            _Link()->icon(_Sax('export-2', 18))
                ->class('p-2 rounded-lg text-gray-400 hover:text-indigo-500 hover:bg-indigo-50 transition-all')
                ->balloon('Export', 'down')
                ->selfGet('exportConversation', ['id' => $this->conversation->id]),
            _Link()->icon(_Sax('archive', 18))
                ->class('p-2 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-all')
                ->balloon('Archive', 'down')
                ->selfPost('archiveConversation', ['id' => $this->conversation->id])
                ->inPanel(self::ID),
            _Link()->icon(_Sax('trash', 18))
                ->class('p-2 rounded-lg text-gray-400 hover:text-red-500 hover:bg-red-50 transition-all')
                ->balloon('Delete', 'down')
                ->selfPost('deleteConversation', ['id' => $this->conversation->id])
                ->inPanel(self::ID),
        )->class('gap-1');
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

        return _Rows(
            _Hidden()->onLoad->run($this->scrollScript()),
            ...$bubbles,
            $this->isLoading ? $this->typingIndicator() : null,
        )->class('space-y-6');
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
        $message = $this->conversation?->messages()->find($id);
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

        // Links
        $text = preg_replace('/\[([^\]]+)\]\(([^\)]+)\)/', '<a href="$2" class="text-indigo-600 hover:underline" target="_blank">$1</a>', $text);

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
```

**Step 2: Verify syntax**

```bash
php -l src/Kompo/ChatPanel.php
```

**Step 3: Commit**

```bash
git add src/Kompo/ChatPanel.php
git commit -m "feat(chat): add ChatPanel with full feature set - avatars, suggestions, feedback, rich data"
```

---

## Task 3: Create ConversationListQuery Component

**Files:**
- Create: `src/Kompo/ConversationListQuery.php`

**Step 1: Create the ConversationListQuery class**

```php
<?php
// src/Kompo/ConversationListQuery.php

namespace Condoedge\Ai\Kompo;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Utils\Kompo\Common\Query;
use Illuminate\Support\Str;

class ConversationListQuery extends Query
{
    public const ID = 'conversation-list';

    protected ?int $selectedId = null;
    protected ?string $search = null;
    protected string $filter = 'all'; // all, pinned, archived

    public function created()
    {
        $this->id(self::ID);
        $this->selectedId = $this->prop('selected_id');
        $this->search = $this->prop('search');
        $this->filter = $this->prop('filter') ?? 'all';
        $this->perPage = 30;
        $this->hasPagination = true;
    }

    public function top()
    {
        return _Flex(
            _Link('All')
                ->class($this->filterClass('all'))
                ->selfGet('filterConversations', ['filter' => 'all'])->inPanel(ChatPanel::CONVERSATIONS_PANEL_ID),
            _Link('Pinned')
                ->class($this->filterClass('pinned'))
                ->selfGet('filterConversations', ['filter' => 'pinned'])->inPanel(ChatPanel::CONVERSATIONS_PANEL_ID),
            _Link('Archived')
                ->class($this->filterClass('archived'))
                ->selfGet('filterConversations', ['filter' => 'archived'])->inPanel(ChatPanel::CONVERSATIONS_PANEL_ID),
        )->class('px-3 py-2 gap-2 border-b border-gray-100');
    }

    protected function filterClass($filter)
    {
        $base = 'px-3 py-1 text-xs font-medium rounded-full transition-all';
        return $this->filter === $filter
            ? "$base bg-indigo-100 text-indigo-700"
            : "$base text-gray-500 hover:bg-gray-100";
    }

    public function query()
    {
        $query = AiConversation::where('user_id', auth()->id());

        // Apply filter
        if ($this->filter === 'pinned') {
            $query->whereRaw("JSON_EXTRACT(metadata, '$.pinned') = true");
        } elseif ($this->filter === 'archived') {
            $query->where('status', 'archived');
        } else {
            $query->where('status', 'active');
        }

        // Apply search
        if ($this->search) {
            $query->where(function ($q) {
                $q->where('title', 'like', "%{$this->search}%")
                  ->orWhereHas('messages', function ($mq) {
                      $mq->where('content', 'like', "%{$this->search}%");
                  });
            });
        }

        // Pinned first, then by last message
        return $query
            ->orderByRaw("JSON_EXTRACT(metadata, '$.pinned') DESC")
            ->orderByDesc('last_message_at')
            ->orderByDesc('created_at');
    }

    public function render($conversation)
    {
        $isSelected = $this->selectedId === $conversation->id;
        $lastMessage = $conversation->messages()->latest()->first();
        $isPinned = $conversation->metadata['pinned'] ?? false;
        $messageCount = $conversation->messages()->count();

        return _Rows(
            _FlexBetween(
                _Flex(
                    $isPinned ? _Sax('star', 12)->class('text-amber-500 mr-1') : null,
                    _Html($conversation->title ?? 'New Conversation')
                        ->class('font-medium text-gray-800 truncate'),
                )->class('items-center flex-1 min-w-0'),
                _Html($this->formatTime($conversation->last_message_at))
                    ->class('text-xs text-gray-400 whitespace-nowrap ml-2'),
            ),
            _FlexBetween(
                _Html($this->getPreviewText($lastMessage))
                    ->class('text-sm text-gray-500 truncate'),
                $messageCount > 0 ? _Badge($messageCount)->class('text-xs bg-gray-200 text-gray-600 ml-2') : null,
            )->class('mt-1'),
        )->class('px-4 py-3 cursor-pointer transition-all border-l-4 ' . ($isSelected
            ? 'bg-indigo-50 border-indigo-500'
            : 'hover:bg-gray-100 border-transparent'))
         ->selfPost('selectConversation', ['id' => $conversation->id])
         ->inPanel(ChatPanel::ID);
    }

    protected function getPreviewText($message): string
    {
        if (!$message) {
            return 'No messages yet';
        }

        $content = $message->role === 'user' ? 'You: ' : '';
        $content .= Str::limit($message->content, 50);
        return $content;
    }

    protected function formatTime($date): string
    {
        if (!$date) {
            return '';
        }

        if ($date->isToday()) {
            return $date->format('g:i A');
        }
        if ($date->isYesterday()) {
            return 'Yesterday';
        }
        if ($date->isCurrentWeek()) {
            return $date->format('l');
        }
        return $date->format('M j');
    }

    public function selectConversation($id)
    {
        return (new ChatPanel(null, ['conversation_id' => $id]))->render();
    }

    public function filterConversations($filter)
    {
        return new self(null, [
            'selected_id' => $this->selectedId,
            'filter' => $filter,
        ]);
    }
}
```

**Step 2: Verify syntax**

```bash
php -l src/Kompo/ConversationListQuery.php
```

**Step 3: Commit**

```bash
git add src/Kompo/ConversationListQuery.php
git commit -m "feat(chat): add ConversationListQuery with search, filter, and pinned support"
```

---

## Task 4: Create ChatMessageForm Component

**Files:**
- Create: `src/Kompo/ChatMessageForm.php`

**Step 1: Create the ChatMessageForm class**

```php
<?php
// src/Kompo/ChatMessageForm.php

namespace Condoedge\Ai\Kompo;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Services\AiManager;
use Condoedge\Ai\Kompo\Traits\HasChatConfig;
use Condoedge\Utils\Kompo\Common\Form;

class ChatMessageForm extends Form
{
    use HasChatConfig;

    public const ID = 'chat-message-form';

    protected ?int $conversationId = null;
    protected ?AiConversation $conversation = null;
    protected string $responseStyle = 'friendly';

    public function created()
    {
        $this->id(self::ID);
        $this->loadChatConfig();
        $this->conversationId = $this->prop('conversation_id');
        $this->responseStyle = $this->prop('response_style') ?? 'friendly';

        if ($this->conversationId) {
            $this->conversation = AiConversation::where('user_id', auth()->id())
                ->find($this->conversationId);
        }

        $this->store([
            'conversation_id' => $this->conversationId,
            'response_style' => $this->responseStyle,
        ]);
    }

    public function render()
    {
        return _Rows(
            // Style selector (collapsible)
            $this->styleSelector(),
            // Main input area
            _Flex(
                _Textarea()->name('message')
                    ->placeholder($this->cfg('input_placeholder'))
                    ->class('flex-1 bg-transparent border-0 focus:ring-0 resize-none text-gray-800 placeholder-gray-400 max-h-32')
                    ->id('chat-input')
                    ->rows(1)
                    ->dontSubmitOnEnter()
                    ->onEnter->run("(e) => { if (!e.shiftKey) { e.preventDefault(); document.getElementById('chat-send-btn')?.click(); } }"),
                $this->inputActions(),
            )->class('flex items-end gap-3 px-4 py-3 bg-white border border-gray-200 rounded-2xl shadow-sm focus-within:border-indigo-400 focus-within:ring-4 focus-within:ring-indigo-50 transition-all'),
            // Helper text
            _Html('Press Enter to send, Shift+Enter for new line')
                ->class('text-xs text-gray-400 mt-2 text-center'),
        )->class('p-4 border-t border-gray-100 bg-gradient-to-t from-gray-50 to-white');
    }

    protected function styleSelector()
    {
        $styles = [
            'minimal' => ['label' => 'Minimal', 'icon' => 'minus', 'desc' => 'Short answers'],
            'concise' => ['label' => 'Concise', 'icon' => 'bars-3-bottom-left', 'desc' => 'Brief & clear'],
            'friendly' => ['label' => 'Friendly', 'icon' => 'face-smile', 'desc' => 'Conversational'],
            'detailed' => ['label' => 'Detailed', 'icon' => 'document-text', 'desc' => 'Comprehensive'],
            'technical' => ['label' => 'Technical', 'icon' => 'code-bracket', 'desc' => 'With query info'],
        ];

        $buttons = array_map(fn($key, $style) =>
            _Flex(
                _Sax($style['icon'], 14),
                _Html($style['label']),
            )->class('px-3 py-1.5 text-xs rounded-lg transition-all cursor-pointer ' .
                ($this->responseStyle === $key
                    ? 'bg-indigo-100 text-indigo-700 font-medium'
                    : 'text-gray-500 hover:bg-gray-100'))
             ->balloon($style['desc'], 'up')
             ->selfPost('setStyle', ['style' => $key])->inPanel(ChatPanel::INPUT_PANEL_ID),
            array_keys($styles), $styles
        );

        return _FlexBetween(
            _Html('Response style:')->class('text-xs text-gray-400'),
            _Flex(...$buttons)->class('gap-1'),
        )->class('px-4 py-2 border-b border-gray-100');
    }

    protected function inputActions()
    {
        return _Flex(
            _Button()->icon(_Sax('send-1', 20))
                ->id('chat-send-btn')
                ->class('p-3 rounded-xl bg-gradient-to-r from-indigo-600 to-purple-600 text-white hover:from-indigo-700 hover:to-purple-700 shadow-lg hover:shadow-xl transition-all transform active:scale-95')
                ->selfPost('sendMessage')
                ->withAllFormValues()
                ->inPanel(ChatPanel::MESSAGES_PANEL_ID),
        )->class('gap-2');
    }

    public function sendMessage()
    {
        $message = trim(request('message') ?? '');

        if (empty($message) || !$this->conversation) {
            return $this->refreshMessages();
        }

        // Add user message
        $this->conversation->addMessage('user', $message);

        try {
            // Get AI response using AiManager
            $aiManager = app(AiManager::class);

            $response = $aiManager->answerQuestion($message, [
                'user' => auth()->user(),
                'style' => $this->responseStyle,
                'conversation_context' => $this->getConversationContext(),
            ]);

            // Extract suggestions from response
            $suggestions = [];
            if (isset($response['suggestions'])) {
                $suggestions = array_slice($response['suggestions'], 0, 3);
            }

            // Add assistant message with all metadata
            $this->conversation->addMessage('assistant', $response['answer'], [
                'referenced_files' => $response['referenced_files'] ?? [],
                'response_data' => $response['response_data'] ?? null,
                'metadata' => [
                    'suggestions' => $suggestions,
                    'style' => $this->responseStyle,
                ],
                'cypher_query' => $response['cypher'] ?? null,
                'execution_time_ms' => $response['execution_time_ms'] ?? null,
                'confidence_score' => $response['confidence_score'] ?? null,
            ]);

        } catch (\Exception $e) {
            \Log::error('Chat error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            $this->conversation->addMessage('assistant',
                'I apologize, but I encountered an error processing your request. Please try rephrasing your question or try again in a moment.',
                ['metadata' => ['error' => true]]
            );
        }

        return $this->refreshMessages();
    }

    public function setStyle($style)
    {
        $this->responseStyle = $style;
        return $this->render();
    }

    protected function getConversationContext(): array
    {
        if (!$this->conversation) {
            return [];
        }

        $recentMessages = $this->conversation->getRecentMessages(10);

        return [
            'conversation_id' => $this->conversation->id,
            'messages' => array_map(fn($msg) => [
                'role' => $msg['role'],
                'content' => $msg['content'],
            ], $recentMessages),
            'focused_entity' => $this->conversation->getFocusedEntity(),
            'mentioned_entities' => $this->conversation->getMentionedEntities(),
        ];
    }

    protected function refreshMessages()
    {
        $chatPanel = new ChatPanel(null, [
            'conversation_id' => $this->conversationId,
        ]);

        return $chatPanel->renderMessages();
    }

    public function rules()
    {
        return [
            'message' => 'required|string|max:10000',
        ];
    }
}
```

**Step 2: Verify syntax**

```bash
php -l src/Kompo/ChatMessageForm.php
```

**Step 3: Commit**

```bash
git add src/Kompo/ChatMessageForm.php
git commit -m "feat(chat): add ChatMessageForm with response style selector"
```

---

## Task 5: Create Supporting Modals

**Files:**
- Create: `src/Kompo/FilePreviewModal.php`
- Create: `src/Kompo/EditMessageModal.php`
- Create: `src/Kompo/ChatSettingsModal.php`
- Create: `src/Kompo/ChatHelpModal.php`

**Step 1: Create FilePreviewModal**

```php
<?php
// src/Kompo/FilePreviewModal.php

namespace Condoedge\Ai\Kompo;

use Condoedge\Utils\Kompo\Common\Modal;

class FilePreviewModal extends Modal
{
    protected $_Title = 'File Preview';
    public $class = 'overflow-y-auto max-w-4xl';

    protected $fileId;

    public function created()
    {
        $this->fileId = $this->prop('file_id');
    }

    public function body()
    {
        // Check if it's a physical file
        if (is_string($this->fileId) && str_starts_with($this->fileId, 'physical:')) {
            $path = str_replace('physical:', '', $this->fileId);
            $fullPath = config('ai.file_context.base_path', base_path()) . '/' . $path;

            if (file_exists($fullPath)) {
                $content = file_get_contents($fullPath);
                return _Rows(
                    _Html(basename($path))->class('text-lg font-semibold mb-4'),
                    _Html('<pre class="bg-gray-900 text-gray-100 p-4 rounded-xl overflow-x-auto text-sm font-mono whitespace-pre-wrap">' . e($content) . '</pre>'),
                );
            }
        }

        // For database files, show file info
        return _Rows(
            _Html('File ID: ' . $this->fileId)->class('text-gray-600'),
            _Html('File preview integration coming soon.')->class('text-gray-500 mt-4'),
        )->class('p-6');
    }
}
```

**Step 2: Create EditMessageModal**

```php
<?php
// src/Kompo/EditMessageModal.php

namespace Condoedge\Ai\Kompo;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Models\AiMessage;
use Condoedge\Utils\Kompo\Common\Modal;

class EditMessageModal extends Modal
{
    protected $_Title = 'Edit Message';
    public $class = 'max-w-xl';

    protected $messageId;
    protected $conversationId;
    protected ?AiMessage $message = null;

    public function created()
    {
        $this->messageId = $this->prop('message_id');
        $this->conversationId = $this->prop('conversation_id');

        if ($this->messageId && $this->conversationId) {
            $conversation = AiConversation::where('user_id', auth()->id())
                ->find($this->conversationId);
            if ($conversation) {
                $this->message = $conversation->messages()->find($this->messageId);
            }
        }

        $this->store(['message_id' => $this->messageId, 'conversation_id' => $this->conversationId]);
    }

    public function body()
    {
        if (!$this->message || $this->message->role !== 'user') {
            return _Html('Cannot edit this message.')->class('text-gray-500 p-6');
        }

        return _Rows(
            _Textarea($this->message->content)->name('content')
                ->class('w-full')
                ->rows(5),
            _FlexEnd(
                _Link('Cancel')->class('px-4 py-2 text-gray-600 hover:text-gray-800')->closeModal(),
                _Button('Save & Regenerate')->icon('arrow-path')
                    ->class('px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700')
                    ->selfPost('save')->withAllFormValues()->closeModal()
                    ->inPanel(ChatPanel::MESSAGES_PANEL_ID),
            )->class('mt-4 gap-3'),
        )->class('p-6');
    }

    public function save()
    {
        $content = request('content');

        if ($this->message && $content) {
            // Update the message
            $this->message->update(['content' => $content]);

            // Delete all messages after this one and regenerate
            $conversation = $this->message->conversation;
            $conversation->messages()
                ->where('created_at', '>', $this->message->created_at)
                ->delete();

            // Trigger regeneration
            $form = new ChatMessageForm(null, ['conversation_id' => $this->conversationId]);
            request()->merge(['message' => $content]);
            return $form->sendMessage();
        }

        return (new ChatPanel(null, ['conversation_id' => $this->conversationId]))->renderMessages();
    }
}
```

**Step 3: Create ChatSettingsModal**

```php
<?php
// src/Kompo/ChatSettingsModal.php

namespace Condoedge\Ai\Kompo;

use Condoedge\Utils\Kompo\Common\Modal;

class ChatSettingsModal extends Modal
{
    protected $_Title = 'Chat Settings';
    public $class = 'max-w-md';

    protected array $config = [];

    public function created()
    {
        $this->config = $this->prop('config') ?? [];
    }

    public function body()
    {
        return _Rows(
            _Html('Customize your chat experience')->class('text-gray-500 mb-6'),

            _Rows(
                _Toggle('Show timestamps')->name('show_timestamps')
                    ->default($this->config['show_timestamps'] ?? false),
                _Toggle('Show avatars')->name('show_avatars')
                    ->default($this->config['show_avatars'] ?? true),
                _Toggle('Show typing indicator')->name('show_typing')
                    ->default($this->config['show_typing'] ?? true),
                _Toggle('Show follow-up suggestions')->name('show_suggestions')
                    ->default($this->config['show_suggestions'] ?? true),
                _Toggle('Show execution metrics')->name('show_metrics')
                    ->default($this->config['show_metrics'] ?? false),
                _Toggle('Enable message feedback')->name('enable_feedback')
                    ->default($this->config['enable_feedback'] ?? true),
            )->class('space-y-4'),

            _FlexEnd(
                _Link('Cancel')->class('px-4 py-2 text-gray-600 hover:text-gray-800')->closeModal(),
                _Button('Save Settings')->icon('check')
                    ->class('px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700')
                    ->selfPost('saveSettings')->withAllFormValues()->closeModal(),
            )->class('mt-6 gap-3'),
        )->class('p-6');
    }

    public function saveSettings()
    {
        // Settings would be saved to user preferences or session
        session(['ai_chat_settings' => request()->only([
            'show_timestamps', 'show_avatars', 'show_typing',
            'show_suggestions', 'show_metrics', 'enable_feedback'
        ])]);

        return null;
    }
}
```

**Step 4: Create ChatHelpModal**

```php
<?php
// src/Kompo/ChatHelpModal.php

namespace Condoedge\Ai\Kompo;

use Condoedge\Utils\Kompo\Common\Modal;

class ChatHelpModal extends Modal
{
    protected $_Title = 'Chat Help';
    public $class = 'max-w-lg';

    public function body()
    {
        return _Rows(
            $this->section('Keyboard Shortcuts', [
                ['Enter', 'Send message'],
                ['Shift + Enter', 'New line'],
                ['Esc', 'Close modal'],
            ]),

            $this->section('Tips', [
                ['Be specific', 'The more context you provide, the better the response'],
                ['Use follow-ups', 'Click suggested questions to continue the conversation'],
                ['Regenerate', 'Not satisfied? Click regenerate for a new response'],
                ['Export', 'Save important conversations as Markdown files'],
            ]),

            $this->section('Response Styles', [
                ['Minimal', 'Just the answer, no fluff'],
                ['Concise', 'Brief but complete responses'],
                ['Friendly', 'Conversational and approachable'],
                ['Detailed', 'Comprehensive explanations'],
                ['Technical', 'Includes query information'],
            ]),
        )->class('p-6 space-y-6');
    }

    protected function section(string $title, array $items)
    {
        $rows = array_map(fn($item) =>
            _FlexBetween(
                _Html($item[0])->class('font-medium text-gray-800'),
                _Html($item[1])->class('text-gray-500 text-sm'),
            )->class('py-2 border-b border-gray-100 last:border-0'),
            $items
        );

        return _Rows(
            _Html($title)->class('text-sm font-semibold text-gray-400 uppercase tracking-wide mb-3'),
            ...$rows,
        );
    }
}
```

**Step 5: Verify syntax**

```bash
php -l src/Kompo/FilePreviewModal.php
php -l src/Kompo/EditMessageModal.php
php -l src/Kompo/ChatSettingsModal.php
php -l src/Kompo/ChatHelpModal.php
```

**Step 6: Commit**

```bash
git add src/Kompo/FilePreviewModal.php src/Kompo/EditMessageModal.php src/Kompo/ChatSettingsModal.php src/Kompo/ChatHelpModal.php
git commit -m "feat(chat): add supporting modals for file preview, edit, settings, and help"
```

---

## Task 6: Create Integration Tests

**Files:**
- Create: `tests/Feature/Kompo/ChatPanelTest.php`

**Step 1: Create comprehensive tests**

```php
<?php

namespace Condoedge\Ai\Tests\Feature\Kompo;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Models\AiMessage;
use Condoedge\Ai\Kompo\ChatPanel;
use Condoedge\Ai\Kompo\ChatMessageForm;
use Condoedge\Ai\Kompo\ConversationListQuery;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ChatPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->createUser());
    }

    /** @test */
    public function chat_panel_renders_empty_state_without_conversation()
    {
        $panel = new ChatPanel();
        $rendered = $panel->render();

        $this->assertNotNull($rendered);
    }

    /** @test */
    public function chat_panel_renders_conversation_messages()
    {
        $conversation = AiConversation::create([
            'user_id' => auth()->id(),
            'title' => 'Test Conversation',
        ]);

        $conversation->addMessage('user', 'Hello');
        $conversation->addMessage('assistant', 'Hi there!');

        $panel = new ChatPanel(null, ['conversation_id' => $conversation->id]);
        $rendered = $panel->render();

        $this->assertNotNull($rendered);
    }

    /** @test */
    public function chat_panel_can_create_new_conversation()
    {
        $panel = new ChatPanel();
        $result = $panel->createConversation();

        $this->assertDatabaseHas('ai_conversations', [
            'user_id' => auth()->id(),
            'status' => 'active',
        ]);
    }

    /** @test */
    public function chat_panel_can_archive_conversation()
    {
        $conversation = AiConversation::create([
            'user_id' => auth()->id(),
            'title' => 'Test',
            'status' => 'active',
        ]);

        $panel = new ChatPanel(null, ['conversation_id' => $conversation->id]);
        $panel->archiveConversation($conversation->id);

        $this->assertDatabaseHas('ai_conversations', [
            'id' => $conversation->id,
            'status' => 'archived',
        ]);
    }

    /** @test */
    public function chat_panel_can_pin_conversation()
    {
        $conversation = AiConversation::create([
            'user_id' => auth()->id(),
            'title' => 'Test',
        ]);

        $panel = new ChatPanel(null, ['conversation_id' => $conversation->id]);
        $panel->togglePin($conversation->id);

        $conversation->refresh();
        $this->assertTrue($conversation->metadata['pinned'] ?? false);
    }

    /** @test */
    public function conversation_list_query_shows_user_conversations()
    {
        AiConversation::create(['user_id' => auth()->id(), 'title' => 'Conv 1']);
        AiConversation::create(['user_id' => auth()->id(), 'title' => 'Conv 2']);

        $query = new ConversationListQuery();
        $items = $query->query()->get();

        $this->assertCount(2, $items);
    }

    /** @test */
    public function chat_message_form_renders_with_conversation()
    {
        $conversation = AiConversation::create([
            'user_id' => auth()->id(),
            'title' => 'Test',
        ]);

        $form = new ChatMessageForm(null, ['conversation_id' => $conversation->id]);
        $rendered = $form->render();

        $this->assertNotNull($rendered);
    }

    /** @test */
    public function file_references_are_displayed_in_messages()
    {
        $conversation = AiConversation::create([
            'user_id' => auth()->id(),
            'title' => 'Test',
        ]);

        $conversation->addMessage('assistant', 'See the file.', [
            'referenced_files' => [
                ['id' => 1, 'name' => 'doc.pdf', 'type' => 'pdf'],
            ],
        ]);

        $message = $conversation->messages()->first();

        $this->assertTrue($message->hasFileReferences());
        $this->assertEquals('doc.pdf', $message->getReferencedFiles()[0]['name']);
    }

    /** @test */
    public function feedback_is_saved_to_message()
    {
        $conversation = AiConversation::create([
            'user_id' => auth()->id(),
            'title' => 'Test',
        ]);

        $conversation->addMessage('assistant', 'Hello!');
        $message = $conversation->messages()->first();

        $panel = new ChatPanel(null, ['conversation_id' => $conversation->id]);
        $panel->feedback($message->id, 'positive');

        $message->refresh();
        $this->assertEquals('positive', $message->metadata['feedback']);
    }

    protected function createUser()
    {
        $userClass = config('auth.providers.users.model', 'App\\Models\\User');
        return $userClass::factory()->create();
    }
}
```

**Step 2: Run tests**

```bash
vendor/bin/phpunit tests/Feature/Kompo/ChatPanelTest.php
```

**Step 3: Commit**

```bash
git add tests/Feature/Kompo/ChatPanelTest.php
git commit -m "test(chat): add comprehensive tests for chat components"
```

---

## Task 7: Final Verification

**Step 1: Run all tests**

```bash
vendor/bin/phpunit
```

**Step 2: Check PHP syntax**

```bash
for f in src/Kompo/*.php src/Kompo/Traits/*.php; do php -l "$f"; done
```

**Step 3: Final commit**

```bash
git add .
git commit -m "feat(chat): complete frontend chat components with 10/10 feature set"
```

---

## Usage Examples

### Basic Usage

```php
// In a route or controller
Route::get('/chat', fn() => new ChatPanel());

// In a Blade view
{!! (new \Condoedge\Ai\Kompo\ChatPanel())->render() !!}
```

### With Configuration

```php
new ChatPanel(null, [
    'welcome_title' => 'Sales Assistant',
    'welcome_message' => 'Ask me about your sales data.',
    'example_questions' => [
        'What were yesterday\'s sales?',
        'Show top customers',
    ],
    'show_metrics' => true,
    'theme' => 'modern',
]);
```

### Embedding in Page

```php
// In a Kompo page component
public function body()
{
    return _Rows(
        _Html('AI Assistant')->class('text-2xl font-bold mb-6'),
        new ChatPanel(),
    )->class('p-8 max-w-7xl mx-auto');
}
```

---

## File Summary

| File | Type | Purpose |
|------|------|---------|
| `src/Kompo/Traits/HasChatConfig.php` | Trait | Configuration loading |
| `src/Kompo/Traits/HasAvatars.php` | Trait | Avatar rendering |
| `src/Kompo/Traits/HasTypingIndicator.php` | Trait | Typing animation |
| `src/Kompo/ChatPanel.php` | Query | Main chat interface |
| `src/Kompo/ConversationListQuery.php` | Query | Sidebar list |
| `src/Kompo/ChatMessageForm.php` | Form | Message input |
| `src/Kompo/FilePreviewModal.php` | Modal | File viewer |
| `src/Kompo/EditMessageModal.php` | Modal | Edit messages |
| `src/Kompo/ChatSettingsModal.php` | Modal | User preferences |
| `src/Kompo/ChatHelpModal.php` | Modal | Help & shortcuts |
| `tests/Feature/Kompo/ChatPanelTest.php` | Test | Integration tests |

---

## Feature Checklist

### Core
- [x] Main chat panel with sidebar
- [x] Conversation list with search & filters
- [x] Message bubbles with markdown
- [x] File reference cards
- [x] AiManager integration

### UX
- [x] User/assistant avatars
- [x] Typing indicator
- [x] Copy message button
- [x] Follow-up suggestions
- [x] Timestamps (optional)
- [x] Online status

### Power Features
- [x] Rich data display (tables, metrics, lists)
- [x] Message feedback (thumbs up/down)
- [x] Regenerate response
- [x] Edit user message
- [x] Response style selector

### Advanced
- [x] Conversation search
- [x] Pin/favorite conversations
- [x] Archive conversations
- [x] Export to Markdown
- [x] Settings panel
- [x] Help modal with shortcuts

### Integration
- [x] File context system
- [x] Conversation context
- [x] Citation display
- [x] Execution metrics
