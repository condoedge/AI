<?php
// src/Kompo/AiChatPanel.php

namespace Condoedge\Ai\Kompo;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Services\Chat\AiChatServiceInterface;
use Condoedge\Ai\Kompo\Traits\HasChatSettings;
use Condoedge\Ai\Kompo\Traits\HasChatTheme;
use Condoedge\Ai\Kompo\Traits\HasAvatars;
use Condoedge\Ai\Kompo\Traits\HasConversationCreation;
use Condoedge\Ai\Kompo\Traits\HasMessageActions;
use Condoedge\Ai\Kompo\Modals\ChatSettingsModal;
use Condoedge\Ai\Kompo\Modals\ChatHelpModal;
use Condoedge\Ai\Kompo\Modals\EditMessageModal;
use Condoedge\Ai\Kompo\Traits\HasMethodsAsProperties;
use Condoedge\Utils\Kompo\Common\Form;
use Illuminate\Support\Facades\Log;

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
    use HasChatSettings, HasChatTheme, HasAvatars, HasMethodsAsProperties, HasConversationCreation, HasMessageActions;

    public $style = 'max-height: 95vh; width: 100vw;';

    public $class = 'max-w-5xl';

    public const ID = 'chat-panel';

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
            _Rows(
                // Hidden staging panel for server response - stable location outside Query
                _Panel(
                    // We need this temp hidden because if not it won't exist the panel itself
                    _Hidden(),
                )->id('temp-message-staging')->class('opacity-0 absolute top-0'),

                _Hidden()->onLoad->run('() => {' . $this->js() . '}'),
            ),

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
        $statusText = $this->isOnline ? __('ai.common.online') : __('ai.common.offline');
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
                ->balloon(__('ai.chat.new-conversation-tooltip'), 'left')
                ->selfPost('createConversation')
                ->refresh(),
        )->class('p-4 border-b border-gray-200/70 bg-white/80 backdrop-blur-sm');
    }

    protected function sidebarFooter()
    {
        return _FlexBetween(
            _Link(__('ai.chat.settings'))->icon('cog-6-tooth')
                ->class('text-sm text-gray-500 ' . $this->theme()->linkHover() . ' p-2 rounded-lg transition-all')
                ->selfGet('openSettings')->inModal(),
            _Link(__('ai.chat.help'))->icon('question-mark-circle')
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


    // ========== JAVASCRIPT ==========

    /**
     * Include JavaScript for chat scroll and message injection
     * Must be on parent component so JS is available when child components need it
     */
    public function js()
    {
        $scrollJs = file_get_contents(__DIR__ . '/../../resources/js/chat-scroll.js');
        $injectorJs = file_get_contents(__DIR__ . '/../../resources/js/chat-message-injector.js');

        // Generate theme-aware HTML templates
        $userBubbleTemplate = $this->userBubblePlaceholderHtml();
        $typingIndicatorTemplate = $this->typingIndicatorHtml();
        $userAvatarHtml = $this->settings()->showAvatars() ? $this->userAvatarHtml() : '';

        // Replace placeholders in JS with actual templates
        // Using backtick strings in JS, so only escape backticks and ${
        $injectorJs = str_replace('$USER_BUBBLE_TEMPLATE', $this->escapeForBackticks($userBubbleTemplate), $injectorJs);
        $injectorJs = str_replace('$TYPING_INDICATOR_TEMPLATE', $this->escapeForBackticks($typingIndicatorTemplate), $injectorJs);
        $injectorJs = str_replace('$USER_AVATAR_HTML', $this->escapeForBackticks($userAvatarHtml), $injectorJs);
        $injectorJs = str_replace('$DOT_COLOR', $this->escapeForBackticks($this->theme()->primarySolid()), $injectorJs);

        // Pass typing animation style and all animation HTML templates for JS regenerate indicator
        $injectorJs = str_replace('$TYPING_STYLE', $this->escapeForBackticks($this->settings()->typingAnimationStyle()), $injectorJs);
        $injectorJs = str_replace('$DOTS_ANIMATION_HTML', $this->escapeForBackticks($this->dotsAnimationHtml()), $injectorJs);
        $injectorJs = str_replace('$WAVE_ANIMATION_HTML', $this->escapeForBackticks($this->waveAnimationHtml()), $injectorJs);
        $injectorJs = str_replace('$PULSE_ANIMATION_HTML', $this->escapeForBackticks($this->pulseAnimationHtml()), $injectorJs);
        $injectorJs = str_replace('$BRAIN_ANIMATION_HTML', $this->escapeForBackticks($this->brainAnimationHtml()), $injectorJs);

        return $scrollJs . "\n\n" . $injectorJs;
    }

    /**
     * Escape string for use inside JavaScript backtick template literals
     */
    protected function escapeForBackticks(string $str): string
    {
        // Escape backticks and template literal sequences
        return str_replace(
            ['\\', '`', '${'],
            ['\\\\', '\\`', '\\${'],
            $str
        );
    }

    /**
     * Generate user bubble placeholder HTML matching the actual PHP component
     * Uses animate-message-user class for entrance animation
     * Includes edit button with js-edit-message class for event delegation
     */
    protected function userBubblePlaceholderHtml(): string
    {
        $editButton = $this->settings()->enableEdit()
            ? '<div class="flex justify-end"><button type="button" class="js-edit-message opacity-0 group-hover:opacity-100 text-xs text-gray-400 hover:text-gray-600 mt-1 transition-opacity flex items-center gap-1"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>' . __('ai.common.edit') . '</button></div>'
            : '';

        return '<div class="group"><div class="flex justify-end items-end animate-message-user"><div class="group px-4 py-3 rounded-2xl rounded-tr-md max-w-xl bg-gradient-to-r ' . $this->theme()->primaryGradient() . ' text-white shadow-md animate-new-message-highlight"><div class="whitespace-pre-wrap">$MESSAGE</div><div class="text-xs opacity-60 mt-2">$TIMESTAMP</div></div>$AVATAR</div>' . $editButton . '</div>';
    }

    /**
     * Generate typing indicator HTML matching the actual AI assistant style
     * Respects typingAnimationStyle setting: dots, wave, pulse, brain
     */
    protected function typingIndicatorHtml(): string
    {
        $avatarHtml = $this->assistantAvatarHtml();
        $style = $this->settings()->typingAnimationStyle();
        $animationHtml = $this->getTypingAnimationHtml($style);

        return '<div class="flex items-start mt-4 animate-message-assistant"><div class="mr-3 flex-shrink-0 self-start">' . $avatarHtml . '</div><div class="typing-indicator-content group px-5 py-4 rounded-2xl rounded-tl-md bg-white border border-gray-100 shadow-sm">' . $animationHtml . '<div class="text-xs text-gray-400 mt-2">' . __('ai.chat.thinking') . '</div></div></div>';
    }

    /**
     * Get the animation HTML for the specified typing style
     */
    protected function getTypingAnimationHtml(string $style): string
    {
        return match($style) {
            'wave' => $this->waveAnimationHtml(),
            'pulse' => $this->pulseAnimationHtml(),
            'brain' => $this->brainAnimationHtml(),
            default => $this->dotsAnimationHtml(),
        };
    }

    /**
     * Dots animation - bouncing dots (default)
     */
    protected function dotsAnimationHtml(): string
    {
        $color = $this->theme()->primarySolid();
        return '<div class="flex items-center gap-1.5 h-6"><span class="inline-block w-2.5 h-2.5 rounded-full ' . $color . ' animate-typing-dot-1"></span><span class="inline-block w-2.5 h-2.5 rounded-full ' . $color . ' animate-typing-dot-2"></span><span class="inline-block w-2.5 h-2.5 rounded-full ' . $color . ' animate-typing-dot-3"></span></div>';
    }

    /**
     * Wave animation - vertical bars
     */
    protected function waveAnimationHtml(): string
    {
        $color = $this->theme()->primarySolid();
        return '<div class="flex items-end gap-1 h-6"><span class="inline-block w-1 rounded-full ' . $color . ' animate-wave-bar-1"></span><span class="inline-block w-1 rounded-full ' . $color . ' animate-wave-bar-2"></span><span class="inline-block w-1 rounded-full ' . $color . ' animate-wave-bar-3"></span><span class="inline-block w-1 rounded-full ' . $color . ' animate-wave-bar-4"></span><span class="inline-block w-1 rounded-full ' . $color . ' animate-wave-bar-5"></span></div>';
    }

    /**
     * Pulse animation - expanding circles
     */
    protected function pulseAnimationHtml(): string
    {
        $color = $this->theme()->primarySolid();
        return '<div class="relative flex items-center justify-center w-10 h-6"><span class="absolute w-4 h-4 rounded-full ' . $color . ' animate-typing-pulse-inner"></span><span class="absolute w-6 h-6 rounded-full ' . $color . ' opacity-30 animate-typing-pulse-outer"></span><span class="absolute w-8 h-8 rounded-full ' . $color . ' opacity-10 animate-typing-pulse-glow"></span></div>';
    }

    /**
     * Brain animation - thinking brain with sparkles
     */
    protected function brainAnimationHtml(): string
    {
        $textColor = $this->theme()->primaryText();
        return '<div class="flex items-center gap-2 h-6"><svg class="w-5 h-5 ' . $textColor . ' animate-thinking-brain" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C8.5 2 5.5 4.5 5 8c-2 .5-3.5 2.3-3.5 4.5 0 2.5 2 4.5 4.5 4.5h12c2.5 0 4.5-2 4.5-4.5 0-2.2-1.5-4-3.5-4.5-.5-3.5-3.5-6-7-6z"/></svg><div class="flex gap-0.5"><span class="w-1 h-1 rounded-full bg-amber-400 animate-sparkle-1"></span><span class="w-1 h-1 rounded-full bg-amber-400 animate-sparkle-2"></span><span class="w-1 h-1 rounded-full bg-amber-400 animate-sparkle-3"></span></div></div>';
    }

    // ========== ACTION METHODS ==========

    // createConversation() provided by HasConversationCreation trait

    public function openSettings()
    {
        return new ChatSettingsModal();
    }

    public function openHelp()
    {
        return new ChatHelpModal();
    }

    public function editMessage($id)
    {
        return new EditMessageModal(null, [
            'message_id' => $id,
            'conversation_id' => $this->conversation?->id,
        ]);
    }

}
