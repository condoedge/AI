<?php

declare(strict_types=1);

namespace Condoedge\Ai\Kompo\Support;

use Condoedge\Ai\Contracts\AiActionInterface;
use Condoedge\Ai\Kompo\Actions\ApprovalCard;
use Condoedge\Ai\Services\Actions\ActionDispatcher;
use Condoedge\Ai\Services\Actions\ActionInvocation;
use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Actions\Results\AiActionResult;
use Condoedge\Ai\Services\Response\ActionLinkHandler;
use Condoedge\Ai\Services\Response\ActionResultPresenter;
use Condoedge\Ai\Services\Response\ContentLinkProcessor;
use Condoedge\Ai\Services\Settings\ChatSettingsInterface;
use Condoedge\Ai\Services\UI\ChatThemeInterface;
use Condoedge\Ai\Services\UI\SafeMarkdownRenderer;

/**
 * Builds the user and assistant chat bubbles (and the per-message action bar).
 *
 * The bubble CHROME (own/other row layout, avatar + timestamp slots, radii, shadows,
 * entrance animations) is delegated to the shared chat kit's ChatBubbleRenderer
 * (condoedge/utils) through the ai-themed ThemedChatBubbleRenderer subclass: the user
 * message renders as the kit's ownBubble, the assistant message as otherBubble. What
 * stays here is everything ai-SPECIFIC: the markdown/link-processed assistant content,
 * rich-data renderables, file references, suggestions, metrics, the per-message action
 * bar and the persisted-invocation/approval cards — all passed to the kit as $content.
 *
 * Kompo round-trip note: the action-bar links call selfPost('feedback'|'regenerate'),
 * copyToClipboard(...) and the edit link calls selfGet('editMessage'). Those method-name
 * strings are resolved by Kompo against the owning MessagesQuery component when the booted
 * element tree is traversed — building the elements here does not change the request target,
 * so the public feedback/regenerate/editMessage methods stay on MessagesQuery.
 */
class MessageBubbleRenderer
{
    protected ActionDispatcher $dispatcher;

    protected ActionResultPresenter $presenter;

    /** The shared chat-kit bubble renderer, themed with this chat's runtime theme. */
    protected ThemedChatBubbleRenderer $kit;

    /**
     * Per-request dispatch memo for persisted invocations, keyed by fingerprint. The renderer
     * is built once per request (MessagesQuery::bubbleRenderer), so a thread re-rendering the
     * same invocation in N bubbles pays authorize() once per request, not N times.
     *
     * @var array<string, AiActionResult|null>
     */
    protected array $invocationResults = [];

    public function __construct(
        protected ChatThemeInterface $theme,
        protected ChatSettingsInterface $settings,
        protected RichDataRenderer $richData,
        protected ContentLinkProcessor $contentLinkProcessor,
        protected string $userAvatarHtml,
        protected string $assistantAvatarHtml,
        ?ActionDispatcher $dispatcher = null,
        ?ActionResultPresenter $presenter = null,
    ) {
        // Same container-tolerant fallback as ActionLinkHandler: resolve the shared dispatcher,
        // degrade to a self-built one so the renderer works without DI wiring.
        $this->dispatcher = $dispatcher ?? $this->resolveDispatcher();
        $this->presenter = $presenter ?? new ActionResultPresenter();
        $this->kit = new ThemedChatBubbleRenderer($theme);
    }

    /**
     * Render a single message bubble, dispatching on role.
     *
     * @param mixed $message       The AiMessage being rendered.
     * @param array $resultSet      Tabular render context for render_table/export_* actions,
     *                              resolved by the orchestrator (MessagesQuery::messageResultSet).
     */
    public function render($message, array $resultSet)
    {
        return $message->role === 'user'
            ? $this->userBubble($message)
            : $this->assistantBubble($message, $resultSet);
    }

    public function userBubble($message)
    {
        $isNew = $this->isNewMessage($message);

        // Kit ownBubble: right-aligned themed bubble, avatar right of it, entrance
        // animation + highlight for new messages. The ai content is escaped plain text.
        $bubble = $this->kit->ownBubble(
            _Html(e($message->content))->class('whitespace-pre-wrap'),
            null, // the ai user bubble shows no author name
            $this->settings->showAvatars() ? $this->userAvatarHtml : null,
            $this->settings->showTimestamps() ? $message->created_at->format('g:i A') : null,
            null,
            false,
            false,
            $isNew,
        );

        if (!$this->settings->enableEdit()) {
            return $bubble;
        }

        // The edit link is ai-specific chrome below the bubble; the wrapping 'group'
        // reveals it when hovering anywhere on the message (same recipe as before).
        return _Rows(
            $bubble,
            _FlexEnd(
                _Link(__('ai.common.edit'))->icon('pencil')
                    ->class('opacity-0 group-hover:opacity-100 text-xs text-gray-400 hover:text-gray-600 mt-1 transition-opacity')
                    ->selfGet('editMessage', ['id' => $message->id])->inModal(),
            ),
        )->class('group');
    }

    public function assistantBubble($message, array $resultSet)
    {
        $isNew = $this->isNewMessage($message);
        $contentRevealClass = $isNew ? ' animate-content-reveal' : '';
        $staggerClass = $isNew ? ' animate-stagger-content' : '';

        $content = [];

        $files = $message->hasFileReferences() ? $message->getReferencedFiles() : [];

        // Process content: strip action links, strip citations, create elements.
        // We pass the result set so render_table/export_* actions can build tables/exports
        // from the data without the LLM shipping it through the link.
        $linkContext = [
            'files' => $files,
            'result_set' => $resultSet,
            'user' => auth()->user(),
        ];
        $processed = $this->contentLinkProcessor->processForDirectRendering(
            $message->content,
            $linkContext
        );

        $content[] = _Html($this->renderMarkdown($processed['content']))
            ->class('prose prose-sm max-w-none [&_a]:font-medium [&_a]:underline [&_a]:underline-offset-2' . $contentRevealClass);

        // Persisted native-turn invocations render beside the parsed links through the same
        // presenter, fingerprint-deduped so a link the model copied verbatim renders once.
        $actionElements = array_merge(
            $processed['elements'],
            $this->renderPersistedInvocations($message, $linkContext),
        );

        if (!empty($actionElements)) {
            // Action links arrive as bare _Link()s from config closures with no styling, so a
            // viewer cannot tell they are clickable. Render each as a clearly-actionable chip:
            // the row carries the theme's accent text colour (anchors inherit it), and every
            // direct child anchor becomes a bordered button with a hover lift and accent border.
            // Scoped to `> a` so non-link renderables in the same row (tables, approval cards)
            // are untouched.
            $content[] = _Flex(...$actionElements)
                ->class(
                    $this->theme->primaryText()
                    . ' mt-3 pt-2 border-t border-gray-100 gap-2 flex-wrap'
                    . ' [&>a]:inline-flex [&>a]:items-center [&>a]:gap-1.5 [&>a]:px-3 [&>a]:py-1.5'
                    . ' [&>a]:rounded-lg [&>a]:text-sm [&>a]:font-semibold [&>a]:no-underline'
                    . ' [&>a]:bg-white [&>a]:border [&>a]:border-gray-200 [&>a]:shadow-sm'
                    . ' [&>a]:transition-all [&>a]:cursor-pointer'
                    . ' hover:[&>a]:border-current hover:[&>a]:shadow-md hover:[&>a]:-translate-y-px'
                );
        }

        if ($responseData = $message->response_data) {
            $richData = $this->richData->renderRichData($responseData);
            if ($richData && $isNew) {
                $richData->class($staggerClass);
            }
            $content[] = $richData;
        }

        // File reference cards (separate from citations)
        if (!empty($files)) {
            $content[] = $this->richData->renderFileReferences($files);
        }

        $suggestions = $message->metadata['suggestions'] ?? [];
        if ($this->settings->showSuggestions() && !empty($suggestions)) {
            $content[] = $this->richData->renderSuggestions($suggestions);
        }

        if ($this->settings->showMetrics() && $message->execution_time_ms) {
            $content[] = $this->richData->renderMetrics($message);
        }

        $content[] = $this->messageActionBar($message);

        // Kit otherBubble: left-aligned white card, avatar left of it, entrance animation
        // for new messages. All the ai-specific renderables ride in as the $content array.
        return $this->kit->otherBubble(
            $content,
            null, // the assistant bubble shows no author name
            $this->settings->showAvatars() ? $this->assistantAvatarHtml : null,
            null,
            null,
            false,
            false,
            $isNew,
        );
    }

    public function messageActionBar($message)
    {
        $actions = [];

        if ($this->settings->enableCopy()) {
            $actions[] = _Link()->icon(_Sax('copy', 16))
                ->class('p-1.5 rounded-lg hover:bg-gray-100 text-gray-400 hover:text-gray-600 transition-all')
                ->balloon(__('ai.messages.copy'), 'up')
                ->copyToClipboard($message->content, 'translate.copied-to-clipboard');
        }

        if ($this->settings->enableFeedback()) {
            $feedback = $message->metadata['feedback'] ?? null;
            $actions[] = _Link()->icon(_Sax('like-1', 16))
                ->class('p-1.5 rounded-lg transition-all ' . ($feedback === 'positive'
                    ? 'bg-emerald-100 text-emerald-600'
                    : 'hover:bg-emerald-50 text-gray-400 hover:text-emerald-600'))
                ->balloon(__('ai.messages.helpful'), 'up')
                ->selfPost('feedback', ['id' => $message->id, 'type' => 'positive'])
                ->alert('translate.feedback-received-successfully')
                ->refresh();

            $actions[] = _Link()->icon(_Sax('dislike', 16))
                ->class('p-1.5 rounded-lg transition-all ' . ($feedback === 'negative'
                    ? 'bg-red-100 text-red-600'
                    : 'hover:bg-red-50 text-gray-400 hover:text-red-600'))
                ->balloon(__('ai.messages.not-helpful'), 'up')
                ->selfPost('feedback', ['id' => $message->id, 'type' => 'negative'])
                ->alert('translate.feedback-received-successfully')
                ->refresh();
        }

        if ($this->settings->enableRegenerate()) {
            $dotColor = $this->theme->primarySolid();
            $regeneratingText = __('ai.messages.regenerating');
            $messageId = $message->id;
            $actions[] = _Link()->icon(_Sax('refresh', 16))
                ->class('p-1.5 rounded-lg text-gray-400 ' . $this->theme->linkHover() . ' transition-all')
                ->balloon(__('ai.messages.regenerate'), 'up')
                ->onClick(fn($e) => $e
                    ->run('() => {
                        // Show typing dots indicator on this message (find by ID injected from PHP)
                        const bubble = document.querySelector("[data-message-id=\"' . $messageId . '\"]");
                        if (bubble) {
                            const content = bubble.querySelector(".prose");
                            if (content) {
                                content.innerHTML = "<div class=\"flex items-center gap-1.5 h-6\"><span class=\"inline-block w-2.5 h-2.5 rounded-full ' . $dotColor . ' animate-typing-dot-1\"></span><span class=\"inline-block w-2.5 h-2.5 rounded-full ' . $dotColor . ' animate-typing-dot-2\"></span><span class=\"inline-block w-2.5 h-2.5 rounded-full ' . $dotColor . ' animate-typing-dot-3\"></span></div><div class=\"text-xs text-gray-400 mt-2\">' . $regeneratingText . '</div>";
                            }
                        }
                    }')
                    && $e->selfPost('regenerate', ['id' => $message->id])
                        ->refresh()
                );
        }

        return _Flex(...$actions)
            ->class('mt-3 pt-2 border-t border-gray-50 gap-1 opacity-0 group-hover:opacity-100 transition-opacity');
    }

    /**
     * Render the native-turn invocations persisted on the assistant message metadata.
     *
     * Mirrors the markdown link path: each invocation re-dispatches through the SAME
     * ActionDispatcher at every render (fresh authorize(), fresh token, fresh TTL) and
     * renders through the shared ActionResultPresenter. Fail-closed throughout: malformed
     * records, unknown keys and read-only failures render nothing. Parsed action:// /
     * entity:// links render first and win by fingerprint, so a history-imitating model
     * emitting both transports for the same action yields one element. An invocation marked
     * approved in resolved_invocations skips dispatch and shows a static done-note.
     *
     * @param mixed $message            The assistant AiMessage (metadata, content, id).
     * @param array<string, mixed> $context  The same context array the link path uses
     *                                       (files, result_set, user).
     * @return array<int, mixed>
     */
    public function renderPersistedInvocations($message, array $context): array
    {
        $records = $message->metadata['invocations'] ?? null;
        if (!is_array($records) || $records === []) {
            return [];
        }

        $linkFingerprints = $this->parsedLinkFingerprints((string) $message->content);
        $resolved = $message->metadata['resolved_invocations'] ?? null;
        $resolved = is_array($resolved) ? $resolved : [];

        $elements = [];
        $seen = [];

        foreach ($records as $record) {
            $invocation = ActionInvocation::fromArray($record);
            if ($invocation === null) {
                continue;
            }

            $fingerprint = $invocation->fingerprint();
            if (isset($linkFingerprints[$fingerprint]) || isset($seen[$fingerprint])) {
                continue;
            }
            $seen[$fingerprint] = true;

            if (($resolved[$fingerprint] ?? null) === 'approved') {
                $elements[] = $this->presenter->approvedNote();
                continue;
            }

            $action = $invocation->entityType !== null
                ? $this->dispatcher->registry()->getEntity($invocation->entityType, $invocation->key)
                : $this->dispatcher->registry()->getGeneric($invocation->key);
            if ($action === null) {
                continue;
            }

            $element = $this->presenter->present(
                $this->memoizedDispatch($fingerprint, $action, $invocation, $context),
                $invocation->key,
                $this->approvalConfirmWiring($message, $fingerprint),
                $action->mutates(),
            );

            if ($element !== null) {
                $elements[] = $element;
            }
        }

        return $elements;
    }

    /**
     * Fingerprints of the action/entity links parsed out of the message content — the
     * dedupe set persisted invocations are checked against (links render first and win).
     *
     * @return array<string, true>
     */
    protected function parsedLinkFingerprints(string $content): array
    {
        $handler = $this->contentLinkProcessor->getHandler('actions');
        if (!$handler instanceof ActionLinkHandler) {
            return [];
        }

        $fingerprints = [];
        foreach ($handler->parseInvocations($content) as $invocation) {
            $fingerprints[$invocation->fingerprint()] = true;
        }

        return $fingerprints;
    }

    /**
     * Dispatch an invocation through the shared pipeline, memoized per fingerprint for the
     * life of this (once-per-request) renderer. The context mirrors the link path exactly:
     * text rides for the card label, entity_type/entity_id ride for seedEntityId.
     *
     * @param array<string, mixed> $context
     */
    protected function memoizedDispatch(
        string $fingerprint,
        AiActionInterface $action,
        ActionInvocation $invocation,
        array $context
    ): ?AiActionResult {
        if (!array_key_exists($fingerprint, $this->invocationResults)) {
            if ($invocation->text !== '') {
                $context['text'] = $invocation->text;
            }
            if ($invocation->entityType !== null) {
                $context['entity_type'] = $invocation->entityType;
                $context['entity_id'] = $invocation->entityId;
            }

            $this->invocationResults[$fingerprint] = $this->dispatcher->dispatch(
                $action,
                $invocation->params,
                $context
            );
        }

        return $this->invocationResults[$fingerprint];
    }

    /**
     * Confirm wiring for invocation-backed ApprovalCards: posts the message id + fingerprint
     * beside the signed token so approveAiAction can stamp resolved_invocations[fingerprint]
     * on success — later renders show the done-note instead of re-offering the card.
     */
    protected function approvalConfirmWiring($message, string $fingerprint): callable
    {
        return fn ($link, string $token) => $link
            ->selfPost('approveAiAction', [
                'token' => $token,
                'messageId' => $message->id,
                'fingerprint' => $fingerprint,
            ])
            ->inPanel(ApprovalCard::panelIdFor($token));
    }

    /**
     * Resolve the shared dispatcher, tolerating a missing container (pure unit context) —
     * the same fallback ActionLinkHandler uses, so both render paths share one registry.
     */
    protected function resolveDispatcher(): ActionDispatcher
    {
        if (function_exists('app')) {
            try {
                return app(ActionDispatcher::class);
            } catch (\Throwable $e) {
                // Container not bound yet — build a standalone instance below.
            }
        }

        return new ActionDispatcher(new AiActionRegistry());
    }

    protected function renderMarkdown(string $text): string
    {
        $renderer = new SafeMarkdownRenderer([
            'activeBadge' => $this->theme->activeBadge(),
            'primaryText' => $this->theme->primaryText(),
        ]);

        return $renderer->render($text);
    }

    /**
     * Check if message is newly added (for animation purposes).
     * Messages created within the last 5 seconds are considered "new".
     */
    protected function isNewMessage($message): bool
    {
        if (!$message->created_at) {
            return true; // Temp messages are always "new"
        }

        return $message->created_at->diffInSeconds(now()) < 5;
    }
}
