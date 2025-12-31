<?php
// src/Kompo/ChatMessageForm.php

namespace Condoedge\Ai\Kompo;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Kompo\Traits\HasChatSettings;
use Condoedge\Ai\Kompo\Traits\HasChatTheme;
use Condoedge\Ai\Services\Chat\AiChatServiceInterface;
use Condoedge\Utils\Kompo\Common\Form;

/**
 * Chat Message Form - Input form for sending messages in the chat.
 *
 * After submitting, refreshes the parent panel to show the new message.
 *
 * Usage:
 *   new ChatMessageForm(null, [
 *       'conversation_id' => $conversationId,
 *       'panel_id' => AiChatPanel::MESSAGES_PANEL_ID,
 *   ])
 */
class ChatMessageForm extends Form
{
    use HasChatSettings, HasChatTheme;

    public $id = 'chat-message-form';
    public $class = 'w-full';

    protected ?int $conversationId = null;
    protected ?AiConversation $conversation = null;
    protected string $panelId;
    protected string $responseStyle = 'friendly';

    public function created()
    {

        $this->conversationId = $this->prop('conversation_id');
        $this->panelId = $this->prop('panel_id') ?? AiChatPanel::MESSAGES_PANEL_ID;
        $this->responseStyle = $this->prop('response_style') ?? $this->settings()->responseStyle() ?? 'friendly';

        if ($this->conversationId) {
            $this->conversation = AiConversation::where('user_id', auth()->id())
                ->find($this->conversationId);
        }

        $this->store([
            'conversation_id' => $this->conversationId,
            'panel_id' => $this->panelId,
            'response_style' => $this->responseStyle,
        ]);
    }

    public function render()
    {
        return _Rows(
            _Flex(
                _Textarea()->name('message')
                    ->placeholder($this->settings()->inputPlaceholder())
                    ->id('chat-message-input')
                    ->class('flex-1 bg-transparent !mb-0 border-0 focus:ring-0 text-gray-800 placeholder-gray-400 resize-none min-h-[44px] max-h-[200px]')
                    ->rows(1)
                    ->attr(['oninput' => "this.style.height='auto';this.style.height=this.scrollHeight+'px'"]),
                $this->responseStyleSelector(),
                _Button()->icon(_Sax('send-1', 20))->id('chat-send-btn')
                    ->class('p-3 rounded-xl bg-gradient-to-r ' . $this->theme()->primaryGradient() . ' text-white shadow-lg hover:shadow-xl hover:scale-105 active:scale-95 transition-all flex-shrink-0')
                    ->selfPost('sendMessage')->withAllFormValues()
                    ->refresh($this->panelId)
                    ->refresh('chat-message-form'),
            )->class('flex items-end gap-3 px-4 py-3 bg-white/90 border border-gray-200/70 rounded-2xl shadow-sm focus-within:border-indigo-300 focus-within:ring-2 focus-within:ring-indigo-100 backdrop-blur-sm transition-all'),
            $this->quickActions(),
        )->class('p-4 border-t border-gray-100/70 bg-gradient-to-t from-white via-white to-transparent');
    }

    protected function responseStyleSelector()
    {
        if (!$this->cfg('show_style_selector')) {
            return null;
        }

        return _Select()->name('style')
            ->options([
                'friendly' => 'Friendly',
                'professional' => 'Professional',
                'concise' => 'Concise',
                'detailed' => 'Detailed',
            ])
            ->default($this->responseStyle)
            ->class('w-32 text-sm border-0 bg-gray-100/50 rounded-lg focus:ring-1 focus:ring-indigo-200')
            ->balloon('Response style', 'up');
    }

    protected function quickActions()
    {
        $actions = $this->cfg('quick_actions') ?? [];
        if (empty($actions)) {
            return null;
        }

        $buttons = array_map(fn($action) =>
            _Link($action['label'])
                ->icon($action['icon'] ?? null)
                ->class('px-3 py-1.5 text-xs font-medium text-gray-500 bg-gray-100/70 rounded-lg hover:bg-indigo-100 hover:text-indigo-700 transition-all')
                ->selfPost('quickAction', ['action' => $action['value']])
                ->refresh($this->panelId),
            array_slice($actions, 0, 4)
        );

        return _Flex(...$buttons)->class('mt-2 gap-2 flex-wrap');
    }

    public function sendMessage()
    {
        $message = trim(request('message') ?? '');
        $style = request('style') ?? $this->responseStyle;

        if (empty($message) || !$this->conversation) {
            return;
        }

        // Call AI service with conversation context
        // Note: askWithConversation() handles:
        // - Storing user message with context metadata
        // - Processing through ConversationContextManager (entity extraction, reference resolution)
        // - Storing assistant response with all metadata
        // - Error handling and error message storage
        $response = app(AiChatServiceInterface::class)->askWithConversation(
            $message,
            $this->conversation,
            [
                'style' => $style,
                'user' => auth()->user(), // SECURITY: Pass authenticated user for file access control
            ]
        );

        // Response is already stored by askWithConversation()
        // Response structure: answer, data, suggestions, sources, cypher_query
        // No additional processing needed - the conversation will be refreshed
        // via the panel refresh triggered by the button click
    }

    public function quickAction($action)
    {
        $actions = [
            'summarize' => 'Summarize our conversation so far',
            'clarify' => 'Can you clarify your last response?',
            'examples' => 'Can you provide some examples?',
            'alternatives' => 'What are some alternative approaches?',
        ];

        $message = $actions[$action] ?? $action;
        request()->merge(['message' => $message]);

        return $this->sendMessage();
    }

    public function rules()
    {
        return [
            'message' => 'required|string|max:10000',
        ];
    }

    public function validationMessages()
    {
        return [
            'message.required' => 'Please enter a message',
            'message.max' => 'Message is too long (max 10,000 characters)',
        ];
    }
}
