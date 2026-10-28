<?php

namespace Condoedge\Ai\Kompo;

use Condoedge\Ai\DTOs\PageContext;
use Condoedge\Ai\Kompo\Modals\EditMessageModal;
use Condoedge\Ai\Kompo\Presenters\ChatPresenter;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Kompo\Traits\HasAvatars;
use Condoedge\Ai\Kompo\Traits\HasChatSettings;
use Condoedge\Ai\Kompo\Traits\HasChatTheme;
use Condoedge\Ai\Kompo\Traits\HasConversationCreation;
use Condoedge\Ai\Kompo\Support\SuggestionSubmit;
use Condoedge\Ai\Kompo\Traits\HasFilePreview;
use Condoedge\Utils\Kompo\Chat\ChatComposerForm;

/**
 * Chat Message Form - Input form for sending messages in the chat.
 *
 * Built on the shared chat kit's ChatComposerForm (composer layout slots); the SEND FLOW
 * stays the ai-specific run()+selfPost+refresh chain instead of the kit's background send:
 * an ai send runs a whole LLM turn (long), needs a typing indicator with no self-expiry,
 * and must surface the ASSISTANT reply too — so the optimistic user bubble + typing
 * indicator come from the ai injector (precreateMessagePlaceholder) and the panel refresh
 * after the selfPost re-renders both persisted messages from the DB.
 *
 * Orchestration (security gate, context, persistence, RAG, error mapping) lives in
 * ChatPresenter + the Phase 1 use cases; this component only collects request input, shows
 * the optimistic placeholder, calls the presenter, and lets the panel refresh re-render the
 * messages from the DB.
 *
 * Usage:
 *   new ChatMessageForm(null, [
 *       'conversation_id' => $conversationId,
 *       'panel_id' => AiChatPanel::MESSAGES_PANEL_ID,
 *   ])
 */
class ChatMessageForm extends ChatComposerForm
{
    use HasAvatars, HasChatSettings, HasChatTheme, HasConversationCreation, HasFilePreview;

    protected ?int $conversationId = null;
    protected ?AiConversation $conversation = null;
    protected string $responseStyle = 'friendly';

    /**
     * The page/record the chat was opened from, forwarded as an array prop from the surface
     * (like conversation_id). Null when the chat was not opened from a page-aware host.
     */
    protected ?PageContext $pageContext = null;

    public function created()
    {
        $this->conversationId = $this->prop('conversation_id');
        $this->responseStyle = $this->prop('response_style') ?? $this->settings()->responseStyle() ?? 'friendly';

        // Page context crosses the Kompo prop boundary as an array; rebuild the DTO here.
        $page = $this->prop('page_context');
        if (is_array($page) && !empty($page)) {
            $this->pageContext = PageContext::fromArray($page);
        }

        if ($this->conversationId) {
            $this->conversation = AiConversation::where('user_id', auth()->id())
                ->find($this->conversationId);
        }
    }

    /* COMPOSER (chat kit slots) - the layout comes from ChatComposerForm::render();
       only the ai input/button/chrome are supplied here. */

    protected function composerInput()
    {
        return _Textarea()->name('message')
            ->placeholder($this->settings()->inputPlaceholder())
            // Kompo-side Enter handling (kit enterToSend is disabled below so the two
            // never double-fire); Shift+Enter keeps inserting a new line natively
            ->onEnter($this->sendingMessageActions())
            // Id shared with SuggestionSubmit (welcome examples / follow-up chips fill this
            // input and click the send button below to reuse the exact typed-send chain).
            ->id(SuggestionSubmit::INPUT_ID)
            ->class('flex-1 bg-transparent !mb-0 border-0 focus:ring-0 text-gray-800 placeholder-gray-400 resize-none min-h-[44px] max-h-[200px]')
            ->rows(1)
            ->attr(['oninput' => "this.style.height='auto';this.style.height=this.scrollHeight+'px'"]);
    }

    protected function composerInputType(): string
    {
        return static::INPUT_TEXTAREA;
    }

    protected function panelSelector(): string
    {
        return '#' . MessagesQuery::ID;
    }

    protected function composerId(): string
    {
        return 'chat-message-form';
    }

    protected function composerClass(): string
    {
        return 'p-4 !pt-0 border-t border-gray-100/70';
    }

    protected function composerRowClass(): string
    {
        // The ai pill keeps its own Tailwind chrome (not the kit's .chat-composer-row hook)
        return 'flex items-end gap-3 px-4 py-3 bg-white/90 border border-gray-200/70 rounded-2xl shadow-sm focus-within:border-indigo-300 focus-within:ring-2 focus-within:ring-indigo-100 backdrop-blur-sm transition-all';
    }

    /**
     * Style selector + the icon send button. The button keeps the ai onClick chain
     * (optimistic placeholder + typing indicator via the injector, then the selfPost
     * that runs the LLM turn, then the panel refresh).
     */
    protected function sendButton()
    {
        return _Flex(
            $this->responseStyleSelector(),
            _Button()->icon(_Sax('send-1', 20))->id(SuggestionSubmit::SEND_BUTTON_ID)
                ->class('p-3 rounded-xl bg-gradient-to-r ' . $this->theme()->primaryGradient() . ' text-white shadow-lg hover:shadow-xl hover:scale-105 active:scale-95 transition-all flex-shrink-0')
                ->onClick($this->sendingMessageActions()),
        )->class('items-end gap-3 shrink-0');
    }

    protected function bottomSlot()
    {
        return $this->quickActions();
    }

    /**
     * The kit's background send (selfPost + updateInQuery swap of the temp bubble) is OFF
     * on purpose: it only re-renders the USER card, while an ai send must also surface the
     * assistant reply and keep a typing indicator alive for the whole (long) LLM turn.
     * The ai injector owns the optimistic UI, so the kit's JS optimistic/clear/enter
     * behaviors are disabled too — the run() script in sendingMessageActions() does it all.
     */
    protected function backgroundSendEnabled(): bool
    {
        return false;
    }

    protected function optimisticEnabled(): bool
    {
        return false;
    }

    protected function enterToSendEnabled(): bool
    {
        return false;
    }

    protected function clearInputOnSend(): bool
    {
        return false;
    }

    protected function responseStyleSelector()
    {
        if (!$this->cfg('show_style_selector')) {
            return null;
        }

        return _Select()->name('style')
            ->options([
                'friendly' => __('ai.form.style-friendly'),
                'professional' => __('ai.form.style-professional'),
                'concise' => __('ai.form.style-concise'),
                'detailed' => __('ai.form.style-detailed'),
            ])
            ->default($this->responseStyle)
            ->class('w-32 text-sm border-0 bg-gray-100/50 rounded-lg focus:ring-1 focus:ring-indigo-200')
            ->balloon(__('ai.form.response-style-tooltip'), 'up');
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
                ->class('px-3 py-1.5 text-xs font-medium text-gray-700 bg-gray-100/70 rounded-lg hover:bg-indigo-100 hover:text-indigo-700 transition-all')
                ->selfPost('quickAction', ['action' => $action['value']])
                ->refresh(MessagesQuery::ID),
            array_slice($actions, 0, 4)
        );

        return _Flex(...$buttons)->class('mt-2 gap-2 flex-wrap');
    }

    /**
     * Ensure a conversation exists, creating one if needed.
     *
     * This allows users to start typing immediately without clicking "New Conversation".
     * The conversation is created on-the-fly when the first message is sent.
     *
     * @return AiConversation The existing or newly created conversation
     */
    protected function ensureConversation(): AiConversation
    {
        if (!$this->conversation) {
            $this->conversation = $this->createNewConversation();
            $this->conversationId = $this->conversation->id;
        }

        return $this->conversation;
    }

    /**
     * Server endpoint the live UI posts to (see sendingMessageActions()).
     *
     * The optimistic user bubble is already shown client-side via precreateMessagePlaceholder();
     * this only needs to run the turn so the subsequent ->refresh(MessagesQuery::ID) re-renders
     * the persisted messages from the DB. No HTML is returned — the panel refresh owns rendering.
     */
    public function sendMessageAndGetResponse()
    {
        return $this->sendMessage();
    }

    public function sendMessage(?string $message = null)
    {
        $message = trim($message ?? request('message') ?? '');
        $style = request('style') ?? $this->responseStyle;

        if (empty($message)) {
            return;
        }

        // Auto-create conversation if needed (allows typing without clicking "New").
        $conversation = $this->ensureConversation();

        try {
            // The presenter is the single Kompo<->app seam: it runs AskQuestionUseCase
            // (security gate, context, persistence, RAG, assistant-message storage) and returns
            // a ChatTurnResult. The user/assistant messages are persisted by the use case and
            // re-rendered by the panel refresh that follows this post.
            $result = app(ChatPresenter::class)->handleSend(
                $conversation,
                $message,
                $style,
                auth()->user(),
                $this->pageContext,
            );

            // Soft failure (injection refusal, AI/DB error): the use case already recorded a
            // visible assistant message in the conversation, so the refresh surfaces it. Log the
            // friendly-error key for traceability without changing the visible flow.
            if ($result->failed()) {
                \Log::warning('Chat message failed: ' . $result->error, [
                    'conversation_id' => $conversation->id,
                ]);
            }
        } catch (\InvalidArgumentException $e) {
            // Empty/invalid message - silently ignore as the UI should prevent this.
            \Log::debug('Chat message validation failed: ' . $e->getMessage());
        } catch (\Throwable $e) {
            // Unexpected/programmer error escaped the use case's own handling. Map it to a
            // user-facing key by exception type and log; the panel refresh re-renders whatever
            // is persisted.
            \Log::error('Chat message failed: ' . $e->getMessage(), [
                'conversation_id' => $conversation->id,
                'friendly_error' => app(ChatPresenter::class)->friendlyError($e),
                'exception' => $e,
            ]);
        }
    }

    public function quickAction($action)
    {
        $actions = [
            'summarize' => __('ai.form.quick-summarize'),
            'clarify' => __('ai.form.quick-clarify'),
            'examples' => __('ai.form.quick-examples'),
            'alternatives' => __('ai.form.quick-alternatives'),
        ];

        $message = $actions[$action] ?? $action;

        // Pass message directly to sendMessage instead of using request()->merge()
        return $this->sendMessage($message);
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
            'message.required' => __('ai.form.validation-required'),
            'message.max' => __('ai.form.validation-max'),
        ];
    }

    protected function sendingMessageActions()
    {
        // Compact refreshes the whole surface so its header knows the chat now has messages.
        $refreshActionWrap = $this->conversationId && !\Condoedge\Ai\Kompo\Support\ChatLayout::isCompact()
            ? fn($e) => $e->refresh(MessagesQuery::ID)
            : fn($e) => $e->refresh([AiChatPanel::ID]);

        return fn($e) => $e
            // 1. Show placeholder immediately (client-side)
            // Note: precreateMessagePlaceholder() also hides welcome/empty state;
            // the avatar is baked into the bubble template server-side (AbstractChatSurface)
            ->run('() => {
                const input = document.getElementById("' . SuggestionSubmit::INPUT_ID . '");
                const message = input ? input.value : "";
                if (message.trim()) {
                    precreateMessagePlaceholder(message);
                    input.value = "";
                    input.style.height = "auto";
                    input.dispatchEvent(new Event("input", { bubbles: true }));
                }
            }')
            // 2. Send to server (runs the chat turn via the presenter)
            // 3. Refresh the messages panel to render the persisted user + assistant messages
            && $refreshActionWrap($e->selfPost('sendMessageAndGetResponse')
                ->withAllFormValues());
    }

    public function editMessage($id)
    {
        return new EditMessageModal(null, [
            'message_id' => $id,
            'conversation_id' => $this->conversation?->id,
        ]);
    }
}
