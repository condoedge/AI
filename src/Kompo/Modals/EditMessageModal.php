<?php
// src/Kompo/Modals/EditMessageModal.php

namespace Condoedge\Ai\Kompo\Modals;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Models\AiMessage;
use Condoedge\Ai\Kompo\AiChatPanel;
use Condoedge\Utils\Kompo\Common\Modal;

/**
 * Edit Message Modal - Allows users to edit their previous messages.
 */
class EditMessageModal extends Modal
{
    protected $_Title = 'Edit Message';
    public $class = 'overflow-hidden max-w-2xl rounded-2xl';

    protected ?int $messageId = null;
    protected ?int $conversationId = null;
    protected ?AiMessage $message = null;

    public function created()
    {
        $this->messageId = $this->prop('message_id');
        $this->conversationId = $this->prop('conversation_id');

        if ($this->messageId && $this->conversationId) {
            // Only allow editing own messages
            $conversation = AiConversation::where('user_id', auth()->id())
                ->find($this->conversationId);

            if ($conversation) {
                $this->message = $conversation->messages()
                    ->where('id', $this->messageId)
                    ->where('role', 'user')  // Only user messages can be edited
                    ->first();
            }
        }

        $this->store([
            'message_id' => $this->messageId,
            'conversation_id' => $this->conversationId,
        ]);
    }

    public function body()
    {
        if (!$this->message) {
            return $this->notFoundState();
        }

        return _Rows(
            $this->editForm(),
            $this->modalActions(),
        )->class('h-full flex flex-col');
    }

    protected function editForm()
    {
        return _Rows(
            _Html('Edit your message below. The AI will regenerate its response based on your updated message.')
                ->class('text-sm text-gray-500 mb-4'),
            _Textarea()->name('content')
                ->value($this->message->content)
                ->class('w-full min-h-[150px] p-4 border border-gray-200 rounded-xl focus:border-indigo-300 focus:ring-2 focus:ring-indigo-100 transition-all resize-none')
                ->placeholder('Enter your message...'),
            _Flex(
                _Html('Original:')->class('text-xs font-medium text-gray-400'),
                _Html($this->message->created_at->diffForHumans())->class('text-xs text-gray-400'),
            )->class('mt-2 items-center gap-2'),
        )->class('p-6');
    }

    protected function modalActions()
    {
        return _FlexBetween(
            _Link('Cancel')
                ->class('px-4 py-2 text-gray-500 hover:text-gray-700 transition-all')
                ->closeModal(),
            _Flex(
                _Link('Delete Message')->icon('trash')
                    ->class('px-4 py-2 text-red-500 hover:text-red-700 hover:bg-red-50 rounded-xl transition-all')
                    ->selfPost('deleteMessage')
                    ->refresh(AiChatPanel::MESSAGES_PANEL_ID)
                    ->closeModal(),
                _Button('Save & Regenerate')->icon('arrow-path')
                    ->class('px-4 py-2 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl hover:from-indigo-700 hover:to-purple-700 shadow-lg transition-all')
                    ->selfPost('updateMessage')
                    ->refresh(AiChatPanel::MESSAGES_PANEL_ID)
                    ->closeModal(),
            )->class('gap-3'),
        )->class('px-6 py-4 border-t border-gray-200 bg-gray-50');
    }

    protected function notFoundState()
    {
        return _Rows(
            _Html('<svg class="w-16 h-16 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>')->class('mb-4'),
            _Html('Message not found')->class('text-lg font-semibold text-gray-700 mb-2'),
            _Html('This message may have been deleted or you don\'t have permission to edit it.')
                ->class('text-sm text-gray-500 text-center max-w-sm'),
            _Link('Close')->class('mt-6 px-4 py-2 bg-gray-200 text-gray-700 rounded-xl hover:bg-gray-300 transition-all')->closeModal(),
        )->class('flex flex-col items-center justify-center p-8');
    }

    public function updateMessage()
    {
        $content = trim(request('content') ?? '');

        if (empty($content) || !$this->message) {
            return;
        }

        // Update the message
        $this->message->update(['content' => $content]);

        // Delete any assistant messages after this one (they'll be regenerated)
        $conversation = $this->message->conversation;
        $conversation->messages()
            ->where('created_at', '>', $this->message->created_at)
            ->delete();

        // Trigger regeneration by calling the chat form's sendMessage
        // This is handled by the panel refresh
    }

    public function deleteMessage()
    {
        if (!$this->message) {
            return;
        }

        // Delete this message and all subsequent messages
        $conversation = $this->message->conversation;
        $conversation->messages()
            ->where('created_at', '>=', $this->message->created_at)
            ->delete();
    }
}
