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
                ->selfGet('filterConversations', ['filter' => 'all'])->inPanel(AiChatPanel::CONVERSATIONS_PANEL_ID),
            _Link('Pinned')
                ->class($this->filterClass('pinned'))
                ->selfGet('filterConversations', ['filter' => 'pinned'])->inPanel(AiChatPanel::CONVERSATIONS_PANEL_ID),
            _Link('Archived')
                ->class($this->filterClass('archived'))
                ->selfGet('filterConversations', ['filter' => 'archived'])->inPanel(AiChatPanel::CONVERSATIONS_PANEL_ID),
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
         ->inPanel(AiChatPanel::ID);
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
        return (new AiChatPanel(null, ['conversation_id' => $id]))->render();
    }

    public function filterConversations($filter)
    {
        return new self(null, [
            'selected_id' => $this->selectedId,
            'filter' => $filter,
        ]);
    }
}
