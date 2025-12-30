<?php
// src/Kompo/ConversationListQuery.php

namespace Condoedge\Ai\Kompo;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Kompo\Traits\HasChatTheme;
use Condoedge\Utils\Kompo\Common\Query;
use Illuminate\Support\Str;

class ConversationListQuery extends Query
{
    use HasChatTheme;
    public const ID = 'conversation-list';

    public $itemsWrapperClass = '[&>div>.vlNoItems]:px-6 [&>div>.vlNoItems]:pb-4 overflow-y-auto mini-scroll';

    protected ?int $selectedId = null;

    public function created()
    {
        $this->id(self::ID);
        
        $this->selectedId = $this->prop('selected_id');
    }

    public function top()
    {
        return _Rows(
            _Rows(
                _Input()->name('search', false)
                    ->placeholder('Search conversations...')
                    ->class('bg-white/80 border-gray-200/50 rounded-xl shadow-sm ' . $this->theme()->primaryRing() . ' ' . $this->theme()->primaryBorder() . ' transition-all')
                    ->filter(),
            )->class('px-3 pb-1 pt-4 border-b border-gray-100/70'),

            _ButtonGroup()
                ->noInputWrapper()
                ->containerClass('px-3 py-2 gap-2 flex border-none')
                ->commonClass('text-center px-3 py-1 text-xs font-medium rounded-full transition-all !rounded-2xl !border-none focus:!shadow-none')
                ->selectedClass($this->theme()->activeBadge(), $this->theme()->inactiveBadge())
                ->options([
                    'all' => __('All'),
                    'pinned' => __('Pinned'),
                    'archived' => __('Archived'),
                ])->default('all')
                ->name('filter', false)->filter(),
        );
    }

    public function query()
    {
        return AiConversation::where('user_id', auth()->id())
            ->forFilter(request('filter', 'all'))
            ->when(request('search'), fn($q, $search) => $q->search($search))
            ->orderByRaw("JSON_EXTRACT(metadata, '$.pinned') DESC")
            ->orderByRaw('IFNULL(last_message_at, created_at) DESC');
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
            ? $this->theme()->selectedBg() . ' ' . $this->theme()->selectedBorder()
            : 'hover:bg-gray-100 border-transparent'))
         ->selfPost('selectConversation', ['id' => $conversation->id])
         ->inPanel(AiChatPanel::ID . '-wrapper');
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
        return new AiChatPanel(null, ['conversation_id' => $id]);
    }
}
