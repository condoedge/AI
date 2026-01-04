<?php
// src/Kompo/Modals/EditMessageModal.php

namespace Condoedge\Ai\Kompo\Modals;

use Condoedge\Ai\Kompo\MessagesQuery;
use Condoedge\Ai\Kompo\Traits\HasChatSettings;
use Condoedge\Ai\Kompo\Traits\HasChatTheme;
use Condoedge\Ai\Kompo\Traits\HasStagedMessageRendering;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Models\AiMessage;
use Condoedge\Ai\Kompo\AiChatPanel;
use Condoedge\Ai\Services\Chat\RegenerateMessageService;
use Condoedge\Utils\Kompo\Common\Modal;

/**
 * Edit Message Modal - Allows users to edit their previous messages.
 */
class EditMessageModal extends Modal
{
    use HasChatTheme, HasChatSettings, HasStagedMessageRendering;

    protected $_Title = 'ai.edit.title';
    public $class = 'overflow-hidden max-w-2xl rounded-2xl';

    protected $noHeaderButtons = true;

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
            _Html(__('ai.edit.instruction'))
                ->class('text-sm text-gray-500 mb-4'),
            _Textarea()->name('content')
                ->value($this->message->content)
                ->class('w-full min-h-[150px] p-4 border border-gray-200 rounded-xl focus:border-indigo-300 focus:ring-2 focus:ring-indigo-100 transition-all resize-none')
                ->placeholder(__('ai.edit.placeholder')),
            _Flex(
                _Html(__('ai.edit.original'))->class('text-xs font-medium text-gray-400'),
                _Html($this->message->created_at->diffForHumans())->class('text-xs text-gray-400'),
            )->class('mt-2 items-center gap-2'),
        )->class('p-6');
    }

    protected function modalActions()
    {
        return _FlexBetween(
            _Link(__('ai.common.cancel'))
                ->class('px-4 py-2 text-gray-500 hover:text-gray-700 transition-all')
                ->closeModal(),
            _Flex(
                _Link(__('ai.edit.delete-message'))->icon('trash')
                    ->class('px-4 py-2 text-red-500 hover:text-red-700 hover:bg-red-50 rounded-xl transition-all')
                    // 1. Close modal and animate removal immediately
                    ->closeModal()
                    ->run('() => {
                        const messageId = ' . (int)$this->messageId . ';
                        // Remove the message and all after it with animation
                        removeMessageAndAfter(messageId);
                    }')
                    // 2. Tell server to delete (response goes to staging, we ignore it)
                    ->selfPost('deleteMessageWithAnimation'),
                _Button(__('ai.edit.save-regenerate'))->icon('arrow-path')
                    ->class('px-4 py-2 text-white rounded-xl shadow-lg transition-all ' . $this->theme()->primaryGradient())
                    // 1. Close modal and start JS animations immediately
                    ->onClick(fn($e) => $e
                        ->closeModal()
                        ->run('() => {
                            const messageId = ' . (int)$this->messageId . ';
                            const contentEl = document.querySelector("[name=content]");
                            if (!contentEl) return;
                            const newContent = contentEl.value.trim();
                            if (!newContent) return;
                            // Update the user message content in place
                            updateMessageContent(messageId, newContent);
                            // Remove all messages after this one, then show typing
                            removeMessagesAfter(messageId).then(() => {
                                showTypingAfterMessage(messageId);
                            });
                        }') && $e                    
                        // 2. Send to server, response goes to staging panel
                        ->selfPost('updateMessageAndGetResponse')->withAllFormValues()
                            ->inPanel('temp-message-staging')
                            ->run('() => {
                                setTimeout(() => {
                                    processServerResponse();
                                }, 100);
                            }')
                    ),
            )->class('gap-3'),
        )->class('px-6 py-4 border-t border-gray-200 bg-gray-50');
    }

    protected function notFoundState()
    {
        return _Rows(
            _Html('<svg class="w-16 h-16 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>')->class('mb-4'),
            _Html(__('ai.edit.not-found'))->class('text-lg font-semibold text-gray-700 mb-2'),
            _Html(__('ai.edit.not-found-desc'))
                ->class('text-sm text-gray-500 text-center max-w-sm'),
            _Link(__('ai.common.close'))->class('mt-6 px-4 py-2 bg-gray-200 text-gray-700 rounded-xl hover:bg-gray-300 transition-all')->closeModal(),
        )->class('flex flex-col items-center justify-center p-8');
    }

    public function updateMessage()
    {
        $content = trim(request('content') ?? '');

        if (empty($content) || !$this->message) {
            return;
        }

        // Update the message content
        $this->message->update(['content' => $content]);

        // Delete all messages after this one (will be regenerated)
        // Using ID for reliable ordering (same as RegenerateMessageService)
        $conversation = $this->message->conversation;
        $conversation->messages()
            ->where('id', '>', $this->message->id)
            ->delete();

        // Regenerate AI response using the service
        try {
            $service = app(RegenerateMessageService::class);
            $service->regenerateFromMessage(
                $conversation,
                $this->message->fresh(), // Fresh to get updated content
                auth()->user(),
                ['style' => $this->settings()->responseStyle()]
            );
        } catch (\Throwable $e) {
            \Log::error('Edit message regeneration failed: ' . $e->getMessage());
        }
    }

    /**
     * Update message and return rendered assistant response for JS injection
     * Follows the ChatMessageForm pattern with staging panel
     */
    public function updateMessageAndGetResponse()
    {
        $content = trim(request('content') ?? '');

        if (empty($content) || !$this->message) {
            return _Html('')->class('hidden');
        }

        // Update the message content
        $this->message->update(['content' => $content]);

        // Delete all messages after this one
        $conversation = $this->message->conversation;
        $conversation->messages()
            ->where('id', '>', $this->message->id)
            ->delete();

        // Regenerate AI response using the service
        try {
            $service = app(RegenerateMessageService::class);
            $service->regenerateFromMessage(
                $conversation,
                $this->message->fresh(),
                auth()->user(),
                ['style' => $this->settings()->responseStyle()]
            );

            // Get the new assistant response
            $assistantMessage = $conversation->messages()
                ->where('role', 'assistant')
                ->orderBy('created_at', 'desc')
                ->first();

            if (!$assistantMessage) {
                return _Html('')->class('hidden');
            }

            // Use shared staged rendering (content + action bar + proxies)
            return $this->renderStagedAssistantResponse($assistantMessage, $this->message);
        } catch (\Throwable $e) {
            \Log::error('Edit message regeneration failed: ' . $e->getMessage());
            return _Html(__('ai.chat.error-message'))->class('text-red-500 text-sm');
        }
    }

    /**
     * Delete message - just performs the delete, JS handles animation
     */
    public function deleteMessageWithAnimation()
    {
        if (!$this->message) {
            return _Html('')->class('hidden');
        }

        // Delete this message and all subsequent messages
        $conversation = $this->message->conversation;
        $conversation->messages()
            ->where('id', '>=', $this->message->id)
            ->delete();

        return _Html('deleted')->class('hidden');
    }

    public function deleteMessage()
    {
        if (!$this->message) {
            return;
        }

        // Delete this message and all subsequent messages
        // Using ID for reliable ordering (same as updateMessage)
        $conversation = $this->message->conversation;
        $conversation->messages()
            ->where('id', '>=', $this->message->id)
            ->delete();
    }

    /**
     * Get conversation for action methods (used by HasStagedMessageRendering trait).
     */
    protected function getConversationForAction()
    {
        return $this->message?->conversation;
    }
}
