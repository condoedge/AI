<?php

namespace Condoedge\Ai\Kompo;

use Condoedge\Ai\DTOs\PageContext;
use Condoedge\Ai\Kompo\Modals\EditMessageModal;
use Condoedge\Ai\Kompo\Modals\FeedbackCommentModal;
use Condoedge\Ai\Kompo\Presenters\ChatPresenter;
use Condoedge\Ai\Kompo\Support\ChatLayout;
use Condoedge\Ai\Kompo\Support\ConversationActions;
use Condoedge\Ai\Kompo\Support\ConversationLimitBanner;
use Condoedge\Ai\Kompo\Support\MessageBubbleRenderer;
use Condoedge\Ai\Kompo\Support\RichDataRenderer;
use Condoedge\Ai\Kompo\Support\SuggestionSubmit;
use Condoedge\Ai\Kompo\Support\WelcomeSuggestions;
use Condoedge\Ai\Kompo\Traits\HandlesTasks;
use Condoedge\Ai\Kompo\Traits\HasAvatars;
use Condoedge\Ai\Kompo\Traits\HasChatSettings;
use Condoedge\Ai\Kompo\Traits\HasChatTheme;
use Condoedge\Ai\Kompo\Traits\HasConversationCreation;
use Condoedge\Ai\Kompo\Traits\HasFilePreview;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Models\AiMessage;
use Condoedge\Ai\Services\Actions\ActionInvocation;
use Condoedge\Ai\Services\Actions\ModelMethodMutationAction;
use Condoedge\Ai\Services\Actions\PendingActionToken;
use Condoedge\Ai\Services\Chat\ConversationResumeBuilder;
use Condoedge\Utils\Kompo\Chat\ChatMessagesQuery;

/**
 * The chat messages panel. Owns list/render orchestration and the Kompo round-trip surface
 * (the public selfPost/selfGet action methods Kompo resolves against this component), and
 * delegates the heavy lifting to three plain collaborators:
 *
 *  - MessageBubbleRenderer — user/assistant bubble + action-bar markup (kit-backed).
 *  - RichDataRenderer       — response_data tables/metrics/lists, suggestions, metrics, file cards.
 *  - ConversationActions    — ownership-scoped pin/feedback/archive/delete mutations.
 *
 * The chat list mechanics (Scroll pagination + topPagination, the flex-col-reverse items
 * wrapper recipe and the snap-to-bottom carrier) come from the shared chat kit's
 * ChatMessagesQuery (condoedge/utils).
 *
 * The action methods stay here as thin entry points because Kompo resolves selfPost('feedback')
 * / selfGet('editMessage') / etc. against THIS component; they delegate their authorization +
 * mutation to ConversationActions (one consistent ownership gate) and their domain work to the
 * ChatPresenter use-case seam.
 */
class MessagesQuery extends ChatMessagesQuery
{
    use HasChatTheme, HasAvatars, HasChatSettings, HasConversationCreation, HasFilePreview, HandlesTasks;

    public const ID = 'chat-messages-panel';
    public $class = '';

    // Kit style + the surface's max-height cap
    public $style = 'max-height: 95vh; display: flex; flex-direction: column;';

    protected $conversation;

    /** The page/record the chat was opened from (forwarded array prop -> DTO). */
    protected ?PageContext $pageContext = null;

    protected $latestMessageId;

    /** Memoized messages()->count() — chatHeader, top and the limit banner all need it; one COUNT per request. */
    protected ?int $messageCount = null;

    /** Lazily built per-request collaborators (see accessors below). */
    protected ?MessageBubbleRenderer $bubbleRenderer = null;
    protected ?RichDataRenderer $richDataRenderer = null;
    protected ?ConversationActions $conversationActions = null;

    public function created()
    {
        $this->id(self::ID);

        // Page context (array prop -> DTO): forwarded to ChatMessageForm and used to prefer
        // per-entity-type welcome examples. Empty/absent => null (no effect).
        $page = $this->prop('page_context');
        if (is_array($page) && !empty($page)) {
            $this->pageContext = PageContext::fromArray($page);
        }

        // If animations disabled, force speed to 'none' which disables all animations via CSS
        $animationSpeed = $this->settings()->enableAnimations()
            ? $this->settings()->animationSpeed()
            : 'none';
        $speedClass = 'animation-speed-' . $animationSpeed;

        $this->conversation = AiConversation::where('user_id', auth()->id())
            ->find($this->prop('conversation_id') ?? session('selected_conversation_id'));

        $this->class = '!static flex-1 min-h-0 bg-gradient-to-b ' . $this->theme()->heroBackground() . ' ' . $speedClass;

        $this->latestMessageId = $this->conversation?->messages()
            ->orderBy('created_at', 'desc')
            ->first()?->id;
    }

    public function top()
    {
        // Compact has no conversation list to pick from or to pin/archive into.
        if (ChatLayout::isCompact()) {
            return !$this->conversation || $this->messageCount() === 0 ? $this->welcomeState() : null;
        }

        $content = null;

        if (!$this->conversation) {
            $content = $this->emptyState();
        }

        if ($this->conversation && $this->messageCount() === 0) {
            $content = $this->welcomeState();
        }

        return _Rows(
            $this->chatHeader(),
            $content,
        );
    }

    protected function chatHeader()
    {
        if (!$this->conversation) {
            return null;
        }

        $isPinned = $this->conversation->metadata['pinned'] ?? false;
        $messageCount = $this->messageCount();

        return _FlexBetween(
            _Rows(
                _Flex(
                    _Html($this->conversation->title ?? __('ai.chat.new-conversation'))
                        ->class('font-semibold text-gray-800 text-lg'),
                    $isPinned
                        ? _Html('<span class="ml-2 px-2 py-0.5 bg-amber-100 text-amber-700 text-xs rounded-full font-medium flex items-center gap-1"><svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg> Pinned</span>')
                        : null,
                )->class('items-center'),
                _Flex(
                    _Html('<span class="inline-flex items-center px-2 py-0.5 bg-gray-100 text-gray-700 text-xs rounded-full">' . $messageCount . ' messages</span>'),
                    _Html('•')->class('mx-2 text-gray-700'),
                    _Html($this->conversation->last_message_at?->diffForHumans() ?? __('ai.chat.just-now'))
                        ->class('text-xs text-gray-700'),
                )->class('items-center mt-1'),
            ),
            $this->headerActions(),
        )->class('px-6 py-4 border-b border-gray-100/70 bg-white/90 backdrop-blur-md shadow-sm');
    }

    protected function headerActions()
    {
        $isPinned = $this->conversation->metadata['pinned'] ?? false;

        return _Flex(
            _Link()->icon(_Sax('star', 18))
                ->class('p-2.5 rounded-xl transition-all duration-200 ' . ($isPinned
                    ? 'bg-amber-100 text-amber-600 shadow-sm'
                    : 'text-gray-700 hover:text-amber-500 hover:bg-amber-50'))
                ->balloon($isPinned ? __('ai.messages.unpin') : __('ai.messages.pin'), 'down')
                ->selfPost('togglePin', ['id' => $this->conversation->id])
                ->refresh([self::ID, ConversationListQuery::ID]),
            _Link()->icon(_Sax('export-2', 18))
                ->class('p-2.5 rounded-xl text-gray-700 ' . $this->theme()->linkHover() . ' transition-all duration-200')
                ->balloon(__('ai.messages.export'), 'down')
                ->href('ai.export-chat', ['id' => $this->conversation->id])
                ->attr(['download' => 'conversation-' . $this->conversation->id . '.md']),
            _Link()->icon(_Sax('archive', 18))
                ->class('p-2.5 rounded-xl text-gray-700 ' . $this->theme()->linkHover() . ' transition-all duration-200')
                ->balloon(__('ai.messages.archive'), 'down')
                ->selfPost('archiveConversation', ['id' => $this->conversation->id])
                ->refresh([self::ID, ConversationListQuery::ID]),
            _DeleteLink()->icon(_Sax('trash', 18))
                ->class('p-2.5 rounded-xl text-gray-700 hover:text-red-500 hover:bg-red-50 transition-all duration-200')
                ->balloon(__('ai.common.delete'), 'down')
                ->selfPost('deleteConversation', ['id' => $this->conversation->id])
                ->refresh([self::ID, ConversationListQuery::ID]),
        )->class('gap-1 bg-gray-50/50 rounded-2xl p-1');
    }

    public function bottom()
    {
        // Always show the form - even without a conversation
        // ChatMessageForm will auto-create conversation on first message
        return _Rows(
            $this->limitBanner(),

            new ChatMessageForm(null, [
                'conversation_id' => $this->conversation?->id,
                'response_style' => $this->settings()->responseStyle(),
                'page_context' => $this->pageContext?->toArray(),
            ]),

            // Kit snap-to-bottom carrier: remounts (and re-snaps to the newest message)
            // at mount and on every refresh of this Query
            $this->conversation
                ? $this->snapToBottomOnLoad()
                : null,
        )->class('flex-shrink-0 relative z-0');
    }

    /**
     * The soft conversation-length banner, shown right below the thread (above the composer)
     * once the conversation reaches the configured message count. Purely advisory — the
     * composer stays enabled (ConversationLimitBanner::shouldShow owns + unit-tests the gate).
     *
     * The button does BOTH things in ONE click via TWO SIBLING click interactions ($e->run
     * + $e->selfPost as separate statements): the kompo front end runs every click
     * interaction of the element, whereas chaining ->run(js)->selfPost(...) would nest the
     * selfPost under the runJs action's 'success' event — which runJsAction never fires
     * (verified in vue-kompo core/Action.js), so a single chain would silently drop the
     * new-conversation call.
     *
     *  1. run(): copies the server-built mini resume to the clipboard (user-gesture-safe,
     *     JSON_HEX_*-encoded — see ConversationLimitBanner::copyResumeJs).
     *  2. selfPost('createConversation') + refresh(AiChatPanel::ID): the exact chain the
     *     emptyState "start new" button uses (HasConversationCreation).
     *
     * The resume is built server-side at render time — deterministic serialization of the
     * context snapshot + recent questions, NO LLM call (ConversationResumeBuilder).
     */
    protected function limitBanner()
    {
        if (!ConversationLimitBanner::shouldShow($this->conversation, $this->messageCount())) {
            return null;
        }

        $resume = app(ConversationResumeBuilder::class)->build($this->conversation);

        return _Rows(
            _Html(e(__('ai.chat.limit-banner-text')))
                ->class('text-sm text-amber-800'),
            _Link(e(__('ai.chat.limit-banner-button')))->icon(_Sax('copy', 16))
                ->class('mt-2 self-start px-4 py-2 bg-amber-100 hover:bg-amber-200 text-amber-800 text-sm font-medium rounded-xl transition-all')
                ->onClick(function ($e) use ($resume) {
                    $e->run(ConversationLimitBanner::copyResumeJs($resume));
                    $e->selfPost('createConversation')->refresh(AiChatPanel::ID);
                }),
        )->class('flex flex-col px-6 py-3 mx-4 mb-2 bg-amber-50 border border-amber-200 rounded-xl');
    }

    public function query()
    {
        if (!$this->conversation) {
            // Return empty query to ensure vlQueryWrapper structure exists
            // This allows JS message injection to work before first message
            return AiMessage::where('id', 0);
        }

        // With topPagination=true, Kompo:
        // 1. Paginates this query (page 1 = newest due to DESC order)
        // 2. Uses flex-col-reverse to display oldest-first visually
        // 3. Loads older pages when scrolling to top
        return $this->conversation->messages()
            ->orderBy('created_at', 'desc');
    }

    public function render($message)
    {
        $bubble = $this->bubbleRenderer()->render($message, $this->messageResultSet($message));

        $isLatest = $message->id === $this->latestMessageId;

        return _Rows(
            $bubble,
        )->attr([
            'data-message-id' => $message->id,
            'data-latest' => $isLatest ? 'true' : null,
        ]);
    }

    protected function emptyState()
    {
        // The empty state (no conversation selected yet — first-ever open, or right after an
        // archive/delete cleared the session selection) also surfaces the example questions:
        // this is the state a user actually lands in when opening the chat from a record page,
        // so the per-page page_examples must show HERE, not only on a freshly created empty
        // conversation. Clicking a suggestion is safe without a conversation: it fills the
        // composer and clicks send (SuggestionSubmit), and ChatMessageForm auto-creates the
        // conversation on first send — the injector already hides #chat-empty-state on the
        // optimistic echo (chat-message-injector.js precreateMessagePlaceholder).
        return _Rows(array_filter([
            _Html($this->welcomeAvatarHtml())->class('mb-6'),
            _Html(__('ai.chat.select-conversation'))->class('text-2xl font-bold text-gray-800 mb-3'),
            _Html(__('ai.chat.select-conversation-desc'))
                ->class('text-gray-700 text-center max-w-md mb-8'),
            _Button(__('ai.chat.start-new'))->icon('plus')
                ->class('px-6 py-3 bg-gradient-to-r ' . $this->theme()->primaryGradient() . ' text-white rounded-xl shadow-lg hover:shadow-xl transition-all')
                ->selfPost('createConversation')
                ->refresh(AiChatPanel::ID),
            $this->exampleQuestionsBlock('mt-8'),
        ]))->id('chat-empty-state')->class('flex flex-col items-center justify-center flex-1 py-8');
    }

    protected function welcomeState()
    {
        $elements = [
            _Html($this->welcomeAvatarHtml())->class('mb-4'),
            _Html('<span>' . e($this->settings()->welcomeTitle()) . '</span>')->class('text-2xl font-bold text-gray-800 mb-2'),
            _Html($this->settings()->welcomeMessage())->class('text-gray-700 text-center max-w-md mb-6'),
        ];

        $elements[] = $this->exampleQuestionsBlock();

        return _Rows(array_filter($elements))->id('chat-welcome-state')->class('flex flex-col items-center justify-center flex-1 py-8 px-4');
    }

    /**
     * The example-questions suggestion block, shared by welcomeState() and emptyState().
     *
     * Prefers a per-entity-type override for the page the chat was opened from (config
     * 'ai.chat.page_examples.{EntityType}'), falling back to the global example_questions —
     * honoring show_suggestions + max_suggestions (WelcomeSuggestions). Null when nothing
     * resolves, so callers can array_filter it away.
     *
     * CLICK = TYPED-SEND PARITY: each button runs client-side JS that fills the composer
     * input and clicks the real send button (SuggestionSubmit::fillAndSendJs), so the click
     * goes through ChatMessageForm's own sendingMessageActions() chain — optimistic user
     * bubble + typing indicator appear immediately, THEN the answer arrives via the panel
     * refresh. No separate server endpoint, no parallel send implementation.
     *
     * Labels are e()-escaped because Kompo Link labels render through v-html client-side;
     * the raw question still goes to fillAndSendJs, which JSON_HEX_*-encodes it for JS.
     */
    protected function exampleQuestionsBlock(string $extraClass = '')
    {
        $examples = $this->resolveExampleQuestions();
        $tasks = WelcomeSuggestions::welcomeTasks(auth()->user());
        if (empty($examples) && $tasks === []) {
            return null;
        }

        $chipClass = 'w-full p-3 text-left rounded-xl border border-gray-200 bg-white ' . $this->theme()->primaryLightBgHover() . ' transition-all flex items-center gap-3 group text-sm';
        // Without a conversation, startTask creates one: the whole surface then reloads on it.
        $taskButtons = array_map(fn ($key, $label) =>
            _Link(e($label))->icon('plus')->class($chipClass . ' font-medium')
                ->selfPost('startTask', ['key' => $key])
                ->refresh($this->conversation ? self::ID : AiChatPanel::ID),
            array_keys($tasks),
            $tasks
        );
        $questionButtons = array_map(fn($q) =>
            _Link(e($q))->icon('chat-bubble-left-ellipsis')
                ->class($chipClass)
                ->onClick(fn($e) => $e->run(SuggestionSubmit::fillAndSendJs($q))),
            $examples
        );

        return _Rows(
            _Html(__('ai.chat.try-asking'))->class('text-sm font-medium text-gray-700 mb-3'),
            _Rows(...$taskButtons, ...$questionButtons)->class('space-y-2 w-full max-w-md'),
        )->class(trim('w-full flex flex-col items-center ' . $extraClass));
    }

    /**
     * Resolve the welcome-screen example questions.
     *
     * When the chat was opened from a page with a known entity type, a host can tailor the
     * suggestions via config('ai.chat.page_examples.{EntityType}') (config-only, no LLM call) so
     * an Order page shows "Show this order's payment history." Falls back to the global example list.
     * Empty when show_suggestions is off; always capped at max_suggestions (see the plain,
     * kit-free WelcomeSuggestions collaborator, which owns + unit-tests this resolution).
     *
     * @return array<int,string>
     */
    protected function resolveExampleQuestions(): array
    {
        return WelcomeSuggestions::resolve($this->pageContext, $this->settings());
    }

    public function togglePin($id)
    {
        $this->conversationActions()->togglePin((int) $id, auth()->user());
    }

    public function feedback($id, $type)
    {
        if (!$this->conversation) {
            return;
        }

        $this->conversationActions()->recordFeedback(
            (int) $this->conversation->id,
            (int) $id,
            $type,
            auth()->user(),
        );
    }

    /** The optional comment after a 👎 on answer $id of this conversation. */
    public function feedbackComment($id)
    {
        return new FeedbackCommentModal(null, [
            'conversation_id' => $this->conversation?->id,
            'message_id' => (int) $id,
        ]);
    }

    /**
     * Regenerate the assistant answer attached to $id.
     *
     * The refresh button lives on the ASSISTANT bubble, so $id is an assistant message; we
     * resolve the user message it answered (the most recent user message before it) and route
     * the turn through ChatPresenter::handleRegenerate (which owns truncation + the security
     * gate via RegenerateAnswerUseCase). The panel ->refresh() then re-renders from the DB.
     */
    public function regenerate($id)
    {
        if (!$this->conversation) {
            return;
        }

        $message = $this->conversation->messages()->find($id);
        if (!$message || $message->role !== 'assistant' || is_array($message->metadata['task'] ?? null)) {
            return;
        }

        // The refresh button is on the assistant bubble; resolve the user message it answered.
        $userMessage = $this->conversation->messages()
            ->where('id', '<', $message->id)
            ->where('role', 'user')
            ->orderByDesc('id')
            ->first();

        if (!$userMessage) {
            return;
        }

        try {
            app(ChatPresenter::class)->handleRegenerate(
                $this->conversation,
                $userMessage,
                $this->settings()->responseStyle(),
                auth()->user(),
            );
        } catch (\Throwable $e) {
            \Log::error('Regenerate failed: ' . $e->getMessage());
        }
    }

    /**
     * Server-side "ask this suggestion" entry point. KEPT for backward compatibility (hosts /
     * older surfaces may still selfPost here), but the package UI no longer targets it: welcome
     * examples and follow-up chips now fill the composer and click the real send button
     * (SuggestionSubmit::fillAndSendJs) so a click gets the same optimistic user bubble +
     * typing indicator as a typed send instead of silently waiting for the whole LLM turn.
     */
    public function askSuggestion($question)
    {
        if (empty(trim($question))) {
            return;
        }

        if (!$this->conversation) {
            \Log::warning('askSuggestion called without conversation context');
            return;
        }

        try {
            // Thread the page context the surface was mounted with, exactly like the
            // typed-question path (ChatMessageForm::sendMessage) — a suggestion clicked
            // from a record page must keep its "this order/invoice" binding.
            app(ChatPresenter::class)->handleSend(
                $this->conversation,
                $question,
                $this->settings()->responseStyle(),
                auth()->user(),
                $this->pageContext,
            );
        } catch (\Throwable $e) {
            \Log::error('Suggestion question failed: ' . $e->getMessage());
            // The Kompo ->refresh() will still happen on the frontend,
            // so the user will see the current state even if sending failed
        }
    }

    public function editMessage($id)
    {
        return new EditMessageModal(null, [
            'message_id' => $id,
            'conversation_id' => $this->conversation?->id,
        ]);
    }

    /**
     * Server-side approval round-trip for an APPROVAL-REQUIRED (mutating) action.
     *
     * The ApprovalCard's "Confirm" button posts here with the signed pending token. We hand
     * it to ActionDispatcher::approve(), which RE-DECODES + RE-VERIFIES the token, confirms it
     * belongs to the current user, RE-RUNS the action's authorize() server-side, and only THEN
     * executes handle(). A mutating action is NEVER executed during render — only here, after
     * this explicit confirmation. Forged/stale/unauthorized tokens are rejected with an alert
     * and nothing is mutated.
     *
     * Cards built from a persisted native invocation also post the owning message id + the
     * invocation fingerprint, so a confirmed approval stamps a resolution marker on the
     * message and later renders show a done-note instead of re-offering the card. The
     * markdown-path cards post only the token; both extras are optional.
     */
    public function approveAiAction(string $token, $messageId = null, $fingerprint = null)
    {
        $result = app(\Condoedge\Ai\Services\Actions\ActionDispatcher::class)->approve($token, [
            'user' => auth()->user(),
            'result_set' => [],
        ]);

        if ($result->isError) {
            return _Html(e($result->summaryForLlm ?: __('ai.actions.failed')))
                ->class('mt-2 px-3 py-2 text-sm text-red-600 bg-red-50 rounded-lg');
        }

        $this->markInvocationApproved($token, $messageId, $fingerprint);

        // Successful mutation: surface the action's renderable (often a link/confirmation),
        // falling back to a brief acknowledgement when the action returns nothing visual.
        return $result->renderable ?? _Html(e($result->summaryForLlm))
            ->class('mt-2 px-3 py-2 text-sm text-emerald-700 bg-emerald-50 rounded-lg');
    }

    /**
     * Server-side round-trip of the export chip of a CAPPED list (export_* `mode=rerun`,
     * eval p18-A roster "25 of 523").
     *
     * Every other chip's action runs when the bubble renders; this one re-executes a query,
     * so it runs HERE, on the click: the chip posts the message id + the invocation's
     * fingerprint, and the answer (the download link, or a visible failure) lands in the
     * chip's own panel.
     *
     * Who and what, at click time:
     *  - the message is looked up inside $this->conversation — the CLICKING user's own
     *    conversation (created() scopes it to auth()->id()); a foreign or unknown message
     *    id resolves to nothing;
     *  - the client-supplied fingerprint only selects an invocation stored on that message;
     *  - the query re-run is the one recorded with that message, executed as auth()->user()
     *    through the scoped executor (fresh tenant scope, read-only, one rate-limit charge),
     *    after the action's authorize() is re-run — MessageBubbleRenderer::exportRecordedResult.
     */
    public function exportRecordedResult($messageId = null, $fingerprint = null)
    {
        $message = $this->conversation && $messageId !== null && (is_int($messageId) || is_string($messageId))
            ? $this->conversation->messages()->find($messageId)
            : null;

        if (!$message || $message->role !== 'assistant') {
            return $this->bubbleRenderer()->exportFailure();
        }

        return $this->bubbleRenderer()->exportRecordedResult($message, $fingerprint, auth()->user());
    }

    /**
     * Stamp resolved_invocations[fingerprint] = 'approved' on the (ownership-scoped) message
     * after a confirmed approval. Best-effort: absent ids and unknown messages are ignored —
     * the approval itself already succeeded and must not be reported as failed. The fingerprint
     * is client-supplied, so it is only stamped once it matches an invocation actually
     * persisted on the message that proposes the very action the token carried; a mismatch
     * skips the stamp (logged), so a tampered fingerprint cannot mark an unrelated proposal
     * as done.
     */
    protected function markInvocationApproved(string $token, $messageId, $fingerprint): void
    {
        if (!$this->conversation || !$messageId || !is_string($fingerprint) || $fingerprint === '') {
            return;
        }

        $message = $this->conversation->messages()->find($messageId);
        if (!$message) {
            return;
        }

        $metadata = $message->metadata ?? [];

        if (!$this->fingerprintMatchesApprovedAction($token, $fingerprint, $metadata['invocations'] ?? null)) {
            \Log::warning('AI action approval: supplied fingerprint matches no persisted invocation for the approved action — resolution stamp skipped.', [
                'message_id' => $message->id,
                'fingerprint' => $fingerprint,
            ]);
            return;
        }

        $resolved = is_array($metadata['resolved_invocations'] ?? null) ? $metadata['resolved_invocations'] : [];
        $resolved[$fingerprint] = 'approved';
        $metadata['resolved_invocations'] = $resolved;
        $message->metadata = $metadata;
        $message->save();
    }

    /**
     * Whether the client-supplied fingerprint identifies an invocation persisted in the
     * message's metadata AND that invocation proposes the same action the just-approved token
     * carried — key, entity type, and entity id (the token stores it in its input). The stamp
     * must never outrun what was actually approved.
     */
    protected function fingerprintMatchesApprovedAction(string $token, string $fingerprint, $records): bool
    {
        if (!is_array($records)) {
            return false;
        }

        $approved = PendingActionToken::decode($token);
        if ($approved === null) {
            return false;
        }

        $approvedEntityId = $approved->input[ModelMethodMutationAction::ID_PARAM] ?? null;

        foreach ($records as $record) {
            $invocation = ActionInvocation::fromArray($record);
            if ($invocation === null || $invocation->fingerprint() !== $fingerprint) {
                continue;
            }

            if ($invocation->key === $approved->actionKey
                && $invocation->entityType === $approved->entityType
                && (string) ($invocation->entityId ?? '') === (string) ($approvedEntityId ?? '')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract a tabular result set for the message, used as render context for
     * render_table/export_* actions. Prefers the structured response_data table rows, falling
     * back to the conversation's last_result_sample.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function messageResultSet($message): array
    {
        $responseData = $message->response_data ?? null;

        if (is_array($responseData)) {
            // Structured 'table' shape → list of associative rows keyed by header.
            if (($responseData['type'] ?? null) === 'table'
                && !empty($responseData['headers'])
                && !empty($responseData['rows'])
            ) {
                $headers = $responseData['headers'];
                $rows = [];
                foreach ($responseData['rows'] as $row) {
                    $rows[] = array_combine(
                        $headers,
                        array_pad(array_slice((array) $row, 0, count($headers)), count($headers), null)
                    );
                }
                return $rows;
            }

            // Already a list of associative rows.
            if (isset($responseData[0]) && is_array($responseData[0])) {
                return $responseData;
            }
        }

        // The sample lives in context_snapshot (written by ConversationContextManager::recordResponse).
        $sample = $this->conversation?->getLastResultSample() ?? [];
        return is_array($sample) ? $sample : [];
    }

    // ========== COLLABORATORS ==========

    /**
     * The bubble renderer, built once per request from the component's resolved theme/settings
     * and avatar HTML. Defaults to an internally-constructed instance so the existing
     * service-provider binding (which constructs MessagesQuery with no args) keeps working.
     */
    protected function bubbleRenderer(): MessageBubbleRenderer
    {
        if ($this->bubbleRenderer === null) {
            $this->bubbleRenderer = new MessageBubbleRenderer(
                $this->theme(),
                $this->settings(),
                $this->richDataRenderer(),
                app(\Condoedge\Ai\Services\Response\ContentLinkProcessor::class),
                $this->userAvatarHtml(),
                $this->assistantAvatarHtml(),
            );
        }
        return $this->bubbleRenderer;
    }

    /**
     * The rich-data renderer (tables/metrics/lists, suggestions, metrics, file cards).
     */
    protected function richDataRenderer(): RichDataRenderer
    {
        if ($this->richDataRenderer === null) {
            $this->richDataRenderer = new RichDataRenderer($this->theme(), $this->settings());
        }
        return $this->richDataRenderer;
    }

    /**
     * The ownership-scoped conversation/message mutation service.
     */
    protected function conversationActions(): ConversationActions
    {
        if ($this->conversationActions === null) {
            $this->conversationActions = app(ConversationActions::class);
        }
        return $this->conversationActions;
    }

    // ========== HELPERS ==========

    /**
     * The conversation's message count, computed at most once per request (chatHeader,
     * top()'s welcome gate and the limit banner all consume it).
     */
    protected function messageCount(): int
    {
        if ($this->messageCount === null) {
            $this->messageCount = $this->conversation
                ? $this->conversation->messages()->count()
                : 0;
        }
        return $this->messageCount;
    }

    public function deleteConversation($id)
    {
        $this->conversationActions()->delete((int) $id, auth()->user());

        session(['selected_conversation_id' => null]);
    }

    public function archiveConversation($id)
    {
        $this->conversationActions()->archive((int) $id, auth()->user());

        session(['selected_conversation_id' => null]);
    }
}
