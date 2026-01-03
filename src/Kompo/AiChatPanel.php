<?php
// src/Kompo/AiChatPanel.php

namespace Condoedge\Ai\Kompo;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Services\Chat\AiChatServiceInterface;
use Condoedge\Ai\Kompo\Traits\HasChatSettings;
use Condoedge\Ai\Kompo\Traits\HasChatTheme;
use Condoedge\Ai\Kompo\Traits\HasAvatars;
use Condoedge\Ai\Kompo\Modals\FilePreviewModal;
use Condoedge\Ai\Kompo\Modals\EditMessageModal;
use Condoedge\Ai\Kompo\Modals\ChatSettingsModal;
use Condoedge\Ai\Kompo\Modals\ChatHelpModal;
use Condoedge\Ai\Kompo\Traits\HasMethodsAsProperties;
use Condoedge\Ai\Services\UI\SafeMarkdownRenderer;
use Condoedge\Utils\Kompo\Common\Form;

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
class AiChatPanel extends Form
{
    use HasChatSettings, HasChatTheme, HasAvatars, HasMethodsAsProperties;

    public $style = 'max-height: 95vh;';

    public const ID = 'chat-panel';
    public const MESSAGES_PANEL_ID = 'chat-messages-panel';
    public const INPUT_PANEL_ID = 'chat-input-panel';

    protected ?int $selectedConversationId = null;
    protected ?AiConversation $conversation = null;
    protected bool $isOnline = true;

    public function created()
    {
        $this->id(self::ID);
        $this->selectedConversationId = session('selected_conversation_id');

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

    public function render()
    {
        return _Panel(
            $this->mainLayout()
        )->class('h-full')->id(self::ID . '-wrapper');
    }

    protected function mainLayout()
    {
        return _Flex(
            $this->sidebar(),
            $this->chatArea(),
        )->class('!items-start h-full min-h-[700px] bg-gradient-to-br from-white via-gray-50/50 to-gray-50/30 rounded-2xl shadow-2xl overflow-hidden ring-1 ring-gray-200/50')
            ->style('height: 95vh;');
    }

    protected function sidebar()
    {
        return _Rows(
            $this->sidebarHeader(),
            new ConversationListQuery([
                'selected_id' => $this->selectedConversationId,
            ]),
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
                ->class('p-2.5 rounded-xl bg-gradient-to-r ' . $this->theme()->primaryGradient() . ' text-white shadow-lg hover:shadow-xl hover:scale-105 transition-all duration-200')
                ->balloon('New conversation', 'left')
                ->selfPost('createConversation')
                ->refresh(),
        )->class('p-4 border-b border-gray-200/70 bg-white/80 backdrop-blur-sm');
    }

    protected function sidebarFooter()
    {
        return _FlexBetween(
            _Link('Settings')->icon('cog-6-tooth')
                ->class('text-sm text-gray-500 ' . $this->theme()->linkHover() . ' p-2 rounded-lg transition-all')
                ->selfGet('openSettings')->inModal(),
            _Link('Help')->icon('question-mark-circle')
                ->class('text-sm text-gray-500 ' . $this->theme()->linkHover() . ' p-2 rounded-lg transition-all')
                ->selfGet('openHelp')->inModal(),
        )->class('p-3 border-t border-gray-200/70 bg-white/80 backdrop-blur-sm');
    }

    protected function chatArea()
    {
        return _Rows(
            new MessagesQuery(),
        )->class('flex-1 flex flex-col bg-gradient-to-br from-white to-gray-50/50 h-full');
    }


    // ========== ACTION METHODS ==========

    public function createConversation()
    {
        $conversation = AiConversation::create([
            'user_id' => auth()->id(),
            'team_id' => currentTeamId(),
            'status' => 'active',
            'title' => 'New Conversation',
        ]);

        session(['selected_conversation_id' => $conversation->id]);
    }

    public function openSettings()
    {
        return new ChatSettingsModal();
    }

    public function openHelp()
    {
        return new ChatHelpModal();
    }

}
