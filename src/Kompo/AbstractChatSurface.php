<?php

namespace Condoedge\Ai\Kompo;

use Condoedge\Ai\DTOs\PageContext;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Services\Chat\AiChatServiceInterface;
use Condoedge\Ai\Kompo\Traits\HasChatSettings;
use Condoedge\Ai\Kompo\Traits\HasChatTheme;
use Condoedge\Ai\Kompo\Traits\HasAvatars;
use Condoedge\Ai\Kompo\Traits\HasConversationCreation;
use Condoedge\Ai\Kompo\Modals\ChatSettingsModal;
use Condoedge\Ai\Kompo\Modals\ChatHelpModal;
use Condoedge\Ai\Kompo\Modals\EditMessageModal;
use Condoedge\Ai\Kompo\Support\ThemedChatBubbleRenderer;
use Condoedge\Ai\Kompo\Traits\HasMethodsAsProperties;
use Condoedge\Utils\Kompo\Chat\ChatScripts;
use Condoedge\Utils\Kompo\Common\Form;

/**
 * AbstractChatSurface - The single shared body for every chat surface.
 *
 * Holds the full-featured chat interface (conversation sidebar + MessagesQuery +
 * the chat JS bundle + every animation/template helper). Concrete surfaces are
 * thin shells that differ ONLY in chrome (the wrapper style/class and how the
 * floating button opens them - modal, drawer or routed screen).
 *
 * Subclasses override surfaceStyle()/surfaceClass() to supply their chrome;
 * the body is defined here exactly once.
 *
 * Features: Conversation list, search, pin/archive, feedback, regenerate, edit,
 * export, file references, rich data display, response style selector.
 */
abstract class AbstractChatSurface extends Form
{
    use HasChatSettings, HasChatTheme, HasAvatars, HasMethodsAsProperties, HasConversationCreation;

    public const ID = 'chat-panel';

    protected ?int $selectedConversationId = null;
    protected ?AiConversation $conversation = null;
    protected bool $isOnline = true;

    /**
     * The page/record the host opened the chat from. A host passes ONE array prop
     * 'page_context' (arrays cross the Kompo prop boundary; the object would not survive
     * serialization). Null when the host is not page-aware.
     */
    protected ?PageContext $pageContext = null;

    public function created()
    {
        $this->id(static::ID);
        $this->selectedConversationId = session('selected_conversation_id');

        // Read the host's page_context prop and rebuild the DTO, forwarded to MessagesQuery /
        // ChatMessageForm like conversation_id. Empty/absent => null (zero downstream effect).
        $page = $this->prop('page_context');
        if (is_array($page) && !empty($page)) {
            $this->pageContext = PageContext::fromArray($page);
        }

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
        )->class('h-full')->id(static::ID . '-wrapper');
    }

    // ========== CHROME (overridable per surface) ==========

    /**
     * Inline style for the surface wrapper. Surfaces override to supply their chrome
     * (modal sizing vs. drawer width vs. full-page height) without touching the body.
     */
    protected function surfaceStyle(): string
    {
        return 'height: 95vh;';
    }

    /**
     * CSS classes for the surface wrapper. Surfaces override for their chrome.
     */
    protected function surfaceClass(): string
    {
        return '!items-start h-full min-h-[700px] bg-gradient-to-br from-white via-gray-50/50 to-gray-50/30 rounded-2xl shadow-2xl overflow-hidden ring-1 ring-gray-200/50';
    }

    // ========== SHARED BODY ==========

    protected function mainLayout()
    {
        return _Flex(
            _Rows(
                _Hidden()->onLoad->run('() => {' . $this->js() . '}'),
            ),

            $this->sidebar(),
            $this->chatArea(),
        )->class($this->surfaceClass())
            ->style($this->surfaceStyle());
    }

    protected function sidebar()
    {
        return _Rows(
            $this->sidebarHeader(),
            _Panel(
                new ConversationListQuery([
                    'selected_id' => $this->selectedConversationId,
                ]),
            )->id('conversation-list-panel'),
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
                    // The assistant's own configured name — the one it introduces itself with.
                    _Html(e(\Condoedge\Ai\Services\AssistantIdentity::name()))->class('font-semibold text-gray-800'),
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
            // MessagesQuery extends Kompo Query, whose constructor is __construct(?array $store) -
            // it takes the store as its FIRST (only) argument. Passing (null, [...]) the Form way
            // would drop the store entirely, so page_context must be the single store argument.
            new MessagesQuery([
                'page_context' => $this->pageContext?->toArray(),
            ]),
        )->class('flex-1 flex flex-col bg-gradient-to-br from-white to-gray-50/50 h-full');
    }


    // ========== JAVASCRIPT ==========

    /**
     * Include JavaScript for the chat: the shared chat-kit library (scrolling) and the
     * ai message injector (optimistic placeholder + typing indicator + edit helpers).
     * Must be on parent component so JS is available when child components need it.
     *
     * The kit library (window.KompoChatKit, self-guarded) loads FIRST so the injector can
     * delegate its scroll-to-bottom to KompoChatKit.snapToBottom; the snap after each panel
     * refresh comes from the kit carrier in MessagesQuery::bottom() (snapToBottomOnLoad).
     */
    public function js()
    {
        $injectorJs = file_get_contents(__DIR__ . '/../../resources/js/chat-message-injector.js');

        $userBubbleTemplate = $this->userBubblePlaceholderHtml();
        $typingIndicatorTemplate = $this->typingIndicatorHtml();

        // JS uses backtick template literals, so only backticks and ${ need escaping
        $injectorJs = str_replace('$USER_BUBBLE_TEMPLATE', $this->escapeForBackticks($userBubbleTemplate), $injectorJs);
        $injectorJs = str_replace('$TYPING_INDICATOR_TEMPLATE', $this->escapeForBackticks($typingIndicatorTemplate), $injectorJs);

        $injectorJs = str_replace('$TYPING_STYLE', $this->escapeForBackticks($this->settings()->typingAnimationStyle()), $injectorJs);
        $injectorJs = str_replace('$DOTS_ANIMATION_HTML', $this->escapeForBackticks($this->dotsAnimationHtml()), $injectorJs);
        $injectorJs = str_replace('$WAVE_ANIMATION_HTML', $this->escapeForBackticks($this->waveAnimationHtml()), $injectorJs);
        $injectorJs = str_replace('$PULSE_ANIMATION_HTML', $this->escapeForBackticks($this->pulseAnimationHtml()), $injectorJs);
        $injectorJs = str_replace('$BRAIN_ANIMATION_HTML', $this->escapeForBackticks($this->brainAnimationHtml()), $injectorJs);

        return ChatScripts::libJs() . "\n\n" . $injectorJs;
    }

    /**
     * Escape string for use inside JavaScript backtick template literals
     */
    protected function escapeForBackticks(string $str): string
    {
        return str_replace(
            ['\\', '`', '${'],
            ['\\\\', '\\`', '\\${'],
            $str
        );
    }

    /**
     * The optimistic user-bubble template: the shared chat kit's own-bubble markup
     * (ThemedChatBubbleRenderer carries the ai theme gradient and drops the delivery
     * ticks), with $HTML / $TIME placeholders substituted by the injector at send time.
     * The avatar is baked in server-side, so it exactly mirrors the persisted bubble
     * MessageBubbleRenderer renders after the panel refresh.
     */
    protected function userBubblePlaceholderHtml(): string
    {
        $avatarHtml = $this->settings()->showAvatars() ? $this->userAvatarHtml() : null;

        return (new ThemedChatBubbleRenderer($this->theme()))
            ->ownBubbleTemplate(null, $avatarHtml, 'whitespace-pre-wrap');
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
