<?php

declare(strict_types=1);

namespace Condoedge\Ai\Kompo\Support;

use Condoedge\Ai\Contracts\AiActionInterface;
use Condoedge\Ai\DTOs\Tasks\TaskOutcome;
use Condoedge\Ai\Kompo\Actions\ApprovalCard;
use Condoedge\Ai\Kompo\Actions\TaskSummaryCard;
use Condoedge\Ai\Kompo\MessagesQuery;
use Condoedge\Ai\Models\AiTask;
use Condoedge\Ai\Services\Actions\ActionDispatcher;
use Condoedge\Ai\Services\Actions\ActionInvocation;
use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Actions\BuiltIn\AskFollowUpAction;
use Condoedge\Ai\Services\Actions\BuiltIn\ExportExcelAction;
use Condoedge\Ai\Services\Actions\BuiltIn\ExportPdfAction;
use Condoedge\Ai\Services\Actions\BuiltIn\RecordedResultExport;
use Condoedge\Ai\Services\Actions\BuiltIn\StartTaskAction;
use Condoedge\Ai\Services\Actions\Results\AiActionResult;
use Condoedge\Ai\Services\Response\ActionLinkHandler;
use Condoedge\Ai\Services\Response\ActionResultPresenter;
use Condoedge\Ai\Services\Response\ContentLinkProcessor;
use Condoedge\Ai\Services\Settings\ChatSettingsInterface;
use Condoedge\Ai\Services\Tasks\TaskOrchestrator;
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
 * rich-data renderables, file references, metrics, the per-message action
 * bar and the persisted-invocation/approval cards — all passed to the kit as $content.
 *
 * Kompo round-trip note: the action-bar links call selfPost('feedback'|'regenerate'),
 * selfGet('feedbackComment') and the edit link calls selfGet('editMessage'). Those method-name
 * strings are resolved by Kompo against the owning MessagesQuery component when the booted
 * element tree is traversed — building the elements here does not change the request target,
 * so the public feedback/feedbackComment/regenerate/editMessage methods stay on MessagesQuery.
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

    /** @var array<string, AiTask|null> task rows by uuid, read once per request */
    protected array $tasks = [];

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

        if (!$this->settings->enableEdit() || $this->isTaskMessage($message)) {
            return $bubble;
        }

        // The edit link is ai-specific chrome below the bubble; the wrapping 'group'
        // reveals it when hovering anywhere on the message (same recipe as before).
        return _Rows(
            $bubble,
            _FlexEnd(
                _Link(__('ai.common.edit'))->icon('pencil')
                    ->class('opacity-0 group-hover:opacity-100 text-xs text-gray-700 hover:text-gray-700 mt-1 transition-opacity')
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
            // The text of the query those rows came from — display shaping only (a split
            // name in one column, as the answer's table shows it); never executed here.
            'result_cypher' => is_string($message->cypher_query ?? null) ? $message->cypher_query : null,
            'answer' => $message->content,
            'document_markdown' => $message->metadata['export_document_markdown'] ?? null,
            'document_rows' => $message->metadata['export_document_rows'] ?? [],
            'document_headers' => $message->metadata['export_document_headers'] ?? [],
            'user' => auth()->user(),
        ];
        $processed = $this->contentLinkProcessor->processForDirectRendering(
            $message->content,
            $linkContext
        );

        [$progress, $summaryCard, $choices, $outcomeLinks] = $this->taskElements($message);
        if ($progress !== null) {
            $content[] = $progress;
        }

        $content[] = _Html($this->renderMarkdown($processed['content']))
            ->class('prose prose-sm max-w-none [&_a]:font-medium [&_a]:underline [&_a]:underline-offset-2' . $contentRevealClass);

        if ($summaryCard !== null) {
            $content[] = $summaryCard;
        }

        // Persisted native-turn invocations render beside the parsed links through the same
        // presenter, fingerprint-deduped so a link the model copied verbatim renders once.
        // Follow-up question chips are not page links: they get a block of their own below.
        [$invocationElements, $followUps] = $this->persistedInvocationElements($message, $linkContext);
        $followUps = [...$followUps, ...$choices];
        $actionElements = array_merge($processed['elements'], $invocationElements, $outcomeLinks);

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

        if ($this->settings->showMetrics() && $message->execution_time_ms) {
            $content[] = $this->richData->renderMetrics($message);
        }

        // Suggested next questions come last: after everything the answer itself shows.
        if (($followUpBlock = $this->followUpBlock($followUps)) !== null) {
            $content[] = $followUpBlock;
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
                ->class('p-1.5 rounded-lg hover:bg-gray-100 text-gray-700 hover:text-gray-700 transition-all')
                ->balloon(__('ai.messages.copy'), 'up')
                // The text reaches the JS as ONE JSON string literal (quotes, line breaks, tags
                // and ampersands escaped): the utils copyToClipboard() macro pasted it raw into a
                // "…" literal — an answer with a quote or a line break broke the button, and
                // answer text could close the string and run as script (2026-10-21).
                ->onClick(fn ($e) => $e->run('() => {navigator.clipboard?.writeText('
                        . json_encode($this->contentLinkProcessor->stripLinks((string) $message->content), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE)
                        . ')}')
                    && $e->alert('ai.messages.copied'));
        }

        if ($this->settings->enableFeedback()) {
            $feedback = $message->metadata['feedback'] ?? null;
            $actions[] = _Link()->icon(_Sax('like-1', 16))
                ->class('p-1.5 rounded-lg transition-all ' . ($feedback === 'positive'
                    ? 'bg-emerald-100 text-emerald-600'
                    : 'hover:bg-emerald-50 text-gray-700 hover:text-emerald-600'))
                ->balloon(__('ai.messages.helpful'), 'up')
                ->selfPost('feedback', ['id' => $message->id, 'type' => 'positive'])
                ->alert('ai.messages.feedback-received')
                ->refresh();

            $actions[] = _Link()->icon(_Sax('dislike', 16))
                ->class('p-1.5 rounded-lg transition-all ' . ($feedback === 'negative'
                    ? 'bg-red-100 text-red-600'
                    : 'hover:bg-red-50 text-gray-700 hover:text-red-600'))
                ->balloon(__('ai.messages.not-helpful'), 'up')
                // Sibling interactions: the 👎 is saved even when the optional comment modal is dismissed.
                ->onClick(function ($e) use ($message) {
                    $e->selfPost('feedback', ['id' => $message->id, 'type' => 'negative'])->refresh();
                    $e->selfGet('feedbackComment', ['id' => $message->id])->inModal();
                });
        }

        if ($this->settings->enableRegenerate() && !$this->isTaskMessage($message)) {
            $dotColor = $this->theme->primarySolid();
            $regeneratingText = __('ai.messages.regenerating');
            $messageId = $message->id;
            $actions[] = _Link()->icon(_Sax('refresh', 16))
                ->class('p-1.5 rounded-lg text-gray-700 ' . $this->theme->linkHover() . ' transition-all')
                ->balloon(__('ai.messages.regenerate'), 'up')
                ->onClick(fn($e) => $e
                    ->run('() => {
                        // Show typing dots indicator on this message (find by ID injected from PHP)
                        const bubble = document.querySelector("[data-message-id=\"' . $messageId . '\"]");
                        if (bubble) {
                            const content = bubble.querySelector(".prose");
                            if (content) {
                                content.innerHTML = "<div class=\"flex items-center gap-1.5 h-6\"><span class=\"inline-block w-2.5 h-2.5 rounded-full ' . $dotColor . ' animate-typing-dot-1\"></span><span class=\"inline-block w-2.5 h-2.5 rounded-full ' . $dotColor . ' animate-typing-dot-2\"></span><span class=\"inline-block w-2.5 h-2.5 rounded-full ' . $dotColor . ' animate-typing-dot-3\"></span></div><div class=\"text-xs text-gray-700 mt-2\">' . $regeneratingText . '</div>";
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
     * Render the action invocations persisted on the assistant message metadata (the seam
     * for code-attached record chips; the native tool loop that first produced them was
     * removed 2026-10-01).
     *
     * Mirrors the markdown link path: each invocation re-dispatches through the SAME
     * ActionDispatcher at every render (fresh authorize(), fresh token, fresh TTL) and
     * renders through the shared ActionResultPresenter. Fail-closed throughout: malformed
     * records, unknown keys and read-only failures render nothing. Parsed action:// /
     * entity:// links render first and win by fingerprint, so the same action offered
     * both as a link and as a persisted invocation yields one element. An invocation marked
     * approved in resolved_invocations skips dispatch and shows a static done-note.
     *
     * Follow-up question chips ({@see AskFollowUpAction}) go through the very same path —
     * their dispatch only builds the suggestion element; nothing runs until the click
     * sends the message — and are returned after the other elements.
     *
     * @param mixed $message            The assistant AiMessage (metadata, content, id).
     * @param array<string, mixed> $context  The same context array the link path uses
     *                                       (files, result_set, user).
     * @return array<int, mixed>
     */
    public function renderPersistedInvocations($message, array $context): array
    {
        [$elements, $followUps] = $this->persistedInvocationElements($message, $context);

        return [...$elements, ...$followUps];
    }

    /**
     * The persisted invocations' elements in two groups: the links / actions / cards of the
     * answer, and its follow-up question chips — the bubble shows the latter in a block of
     * their own ({@see followUpBlock()}), whatever order the message stored them in.
     *
     * @param mixed $message
     * @param array<string, mixed> $context
     * @return array{0: array<int, mixed>, 1: array<int, mixed>} [action elements, follow-up chips]
     */
    public function persistedInvocationElements($message, array $context): array
    {
        $records = $message->metadata['invocations'] ?? null;
        if (!is_array($records) || $records === []) {
            return [[], []];
        }

        $linkFingerprints = $this->parsedLinkFingerprints((string) $message->content);
        $resolved = $message->metadata['resolved_invocations'] ?? null;
        $resolved = is_array($resolved) ? $resolved : [];

        $elements = [];
        $followUps = [];
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

            // The export chip of a CAPPED list re-runs a query: never at render. It renders
            // as a link that posts back; the click executes (see exportRecordedResult()).
            if ($this->isRecordedResultExport($action, $invocation)) {
                array_push($elements, ...$this->recordedResultExportChip($message, $invocation, $action, $context, $fingerprint));
                continue;
            }

            $element = $this->presenter->present(
                $this->memoizedDispatch($fingerprint, $action, $invocation, $context),
                $invocation->key,
                $this->approvalConfirmWiring($message, $fingerprint),
                $action->mutates(),
            );

            if ($element === null) {
                continue;
            }
            if ($action instanceof AskFollowUpAction) {
                $followUps[] = $element;
            } else {
                $elements[] = $element;
            }
        }

        return [$elements, $followUps];
    }

    /**
     * The follow-up question chips of an answer as their own block: one suggestion per
     * line, styled exactly as the welcome-screen example questions (bordered white row,
     * chat icon, the theme's light hover) — the same thing, a question one click sends —
     * and so visibly apart from the page-link chips above (compact accent-coloured
     * buttons). Null when the answer has none.
     *
     * @param array<int, mixed> $followUps the chips ({@see persistedInvocationElements()})
     */
    public function followUpBlock(array $followUps): mixed
    {
        if ($followUps === [] || !function_exists('_Rows')) {
            return null;
        }

        $style = 'w-full p-3 text-left rounded-xl border border-gray-200 bg-white '
            . $this->theme->primaryLightBgHover() . ' transition-all flex items-center gap-3 group text-sm';
        foreach ($followUps as $chip) {
            if (is_object($chip) && method_exists($chip, 'class')) {
                $chip->class($style);
            }
        }

        return _Rows(...$followUps)->class('cdge-follow-ups mt-3 pt-2 border-t border-gray-100 space-y-2');
    }

    // =========================================================================
    // Task messages (metadata.task, see TaskOrchestrator)
    // =========================================================================

    /** A message of a task (its question, summary or outcome, or the user's answer): no regenerate, no edit. */
    protected function isTaskMessage($message): bool
    {
        return is_array($message->metadata['task'] ?? null);
    }

    /**
     * The task part of an assistant message: the progress line and choice chips of the current
     * question, the summary card, the outcome's link and follow-up chips, or, when another task's start
     * met this one, its Continue / stop-and-start chips. Controls show only while
     * the message still is the task's current state, so an older question or summary stays inert.
     *
     * @return array{0: mixed, 1: mixed, 2: array<int, mixed>, 3: array<int, mixed>} [progress line, summary card, choice chips, outcome links]
     */
    protected function taskElements($message): array
    {
        $task = $message->metadata['task'] ?? null;
        $links = is_array($task) ? [...$this->outcomeLinks($task), ...$this->switchChips($message, $task)] : [];
        // A summary shown again after a refused write carries the manual page's link as well as its card.
        $card = is_array($task) && is_string($task['uuid'] ?? null) && ($task['status'] ?? null) === AiTask::STATUS_REVIEWING
            && ($links === [] || is_array($task['summary'] ?? null))
            ? new TaskSummaryCard(null, ['conversation_id' => $message->conversation_id ?? null, 'message_id' => $message->id]) : null;
        if ($card !== null || $links !== []) {
            return [null, $card, [], $links];
        }
        if (!is_array($task) || !is_string($task['uuid'] ?? null)) {
            return [null, null, [], []];
        }
        $live = $this->liveTask($message, $task);
        if ($live === null || $live->status !== AiTask::STATUS_COLLECTING) {
            return [null, null, [], []];
        }

        $progress = _Html(e(__('ai.tasks.progress', app(TaskOrchestrator::class)->progress($live, auth()->user()))))
            ->class('cdge-task-progress mb-1 text-xs font-medium text-gray-700');
        $choices = [];
        foreach ((array) ($task['choices'] ?? []) as $choice) {
            if (is_array($choice)) {
                $choices[] = $this->taskChoiceChip($task, $choice);
                continue;
            }
            $choice = is_string($choice) ? AskFollowUpAction::sanitize($choice) : '';
            if ($choice !== '') {
                $choices[] = $this->presenter->present(AiActionResult::followUp($choice, $choice), AskFollowUpAction::KEY);
            }
        }

        return [$progress, null, array_values(array_filter($choices)), []];
    }

    /**
     * A limit's link to the manual page (with or without a task) or a done task's outcome link, then
     * the outcome's follow-ups: a task one renders only while this user may start it.
     *
     * @return array<int, mixed>
     */
    protected function outcomeLinks(array $task): array
    {
        $links = [];
        $link = $task['link'] ?? $task['outcome']['link'] ?? null;
        if (is_string($link['url'] ?? null) && is_string($link['label'] ?? null)) {
            $links[] = _Link(e(__($link['label'])))->href($link['url']);
        }
        $user = auth()->user();
        foreach (TaskOutcome::fromArray((array) ($task['outcome'] ?? []))->followUps as $item) {
            if (isset($item['url'])) {
                $links[] = _Link(e(__($item['label'])))->href($item['url']);
            } elseif (StartTaskAction::invocation($item['task'], $user) !== null) {
                $links[] = StartTaskAction::element($item['task'], (string) __($item['label']), (array) ($item['groups'] ?? []));
            }
        }

        return $links;
    }

    /**
     * Another task's start while this one runs (metadata.task.switch): "Continue" this task, or stop it and start
     * the other one; only while the task is still where the message shows it, and each only while this user may start it.
     *
     * @return array<int, mixed>
     */
    protected function switchChips($message, array $task): array
    {
        $switch = $task['switch'] ?? null;
        if (!is_array($switch) || !is_string($switch['task'] ?? null) || !is_string($task['definition'] ?? null) || !is_string($task['uuid'] ?? null)
            || $this->liveTask($message, $task) === null) {
            return [];
        }
        $user = auth()->user();
        $definitions = TaskOrchestrator::registered();
        $chips = [];
        if (isset($definitions[$task['definition']]) && StartTaskAction::invocation($task['definition'], $user) !== null) {
            $chips[] = StartTaskAction::element($task['definition'], (string) __('ai.tasks.continue', ['task' => __($definitions[$task['definition']]->label())]));
        }
        if (isset($definitions[$switch['task']]) && StartTaskAction::invocation($switch['task'], $user) !== null) {
            $chips[] = StartTaskAction::element($switch['task'], (string) __('ai.tasks.replace', ['task' => __($definitions[$switch['task']]->label())]),
                (array) ($switch['groups'] ?? []), $task['uuid']);
        }

        return $chips;
    }

    /** A code-built choice ({label, field, value}): it posts the value, the orchestrator checks it is still asked. */
    protected function taskChoiceChip(array $task, array $choice): mixed
    {
        if (!is_string($choice['label'] ?? null) || !is_string($choice['field'] ?? null) || !array_key_exists('value', $choice)) {
            return null;
        }

        return _Link(e($choice['label']))->icon('chat-bubble-left-ellipsis')->class(ActionResultPresenter::FOLLOW_UP_CLASS)
            ->selfPost('chooseTask', ['uuid' => $task['uuid'], 'version' => (int) ($task['version'] ?? 0),
                'field' => $choice['field'], 'value' => json_encode($choice['value'])])
            ->refresh(MessagesQuery::ID);
    }

    /** The message's task while it is still at the status and version the message shows, else null. */
    protected function liveTask($message, array $task): ?AiTask
    {
        if (!TaskOrchestrator::configured()) {
            return null;
        }
        $uuid = $task['uuid'];
        if (!array_key_exists($uuid, $this->tasks)) {
            $this->tasks[$uuid] = AiTask::where('uuid', $uuid)->where('conversation_id', $message->conversation_id ?? null)->first();
        }
        $live = $this->tasks[$uuid];

        return $live !== null && $live->status === ($task['status'] ?? null) && $live->version === (int) ($task['version'] ?? 0) ? $live : null;
    }

    // =========================================================================
    // The export chip of a capped list (export_* mode=rerun) — rendered deferred,
    // executed on click
    // =========================================================================

    /**
     * Panel the click's result (the download, or the failure notice) lands in: one per
     * message + invocation, so several chips in a thread never collide.
     */
    public static function exportPanelId(int|string|null $messageId, string $fingerprint): string
    {
        return 'ai-export-result-' . substr(hash('crc32b', $messageId . '|' . $fingerprint), 0, 8);
    }

    /**
     * A built-in export invocation in RE-RUN mode ({@see RecordedResultExport}): the chip
     * ActionAttacher puts on a list whose retrieval was capped. A host action registered
     * under the same key is not one — it is dispatched like any other.
     */
    protected function isRecordedResultExport(AiActionInterface $action, ActionInvocation $invocation): bool
    {
        return ($action instanceof ExportExcelAction || $action instanceof ExportPdfAction)
            && $invocation->entityType === null
            && RecordedResultExport::requested($invocation->params);
    }

    /**
     * The query RECORDED with a message — its text (`cypher_query`) and the `$pii_…`
     * parameters it bound (`metadata.cypher_parameters`) — or null when the message
     * recorded none. Server-stored data of the message itself, never client input.
     *
     * @return array{cypher: string, parameters: array<string, mixed>, conversation_id: mixed}|null
     */
    protected function recordedQuery($message): ?array
    {
        $cypher = $message->cypher_query ?? null;
        if (!is_string($cypher) || trim($cypher) === '') {
            return null;
        }
        $parameters = $message->metadata['cypher_parameters'] ?? null;

        return [
            'cypher' => $cypher,
            'parameters' => is_array($parameters) ? $parameters : [],
            'conversation_id' => $message->conversation_id ?? null,
        ];
    }

    /**
     * RENDER of the re-run export chip: nothing is executed and no file is built. The chip
     * is a link that posts the message id + the invocation's fingerprint back to the owning
     * Query (MessagesQuery::exportRecordedResult), with a panel for the answer.
     *
     * Shown only when the click can work: the user passes the action's authorize() now (the
     * same render-time gate every chip has) and the message recorded its query. Otherwise
     * NOTHING — the message's stored rows are a capped sample, never offered as the list.
     *
     * @param array<string, mixed> $context the bubble's render context (user)
     * @return array<int, mixed>
     */
    protected function recordedResultExportChip(
        $message,
        ActionInvocation $invocation,
        AiActionInterface $action,
        array $context,
        string $fingerprint
    ): array {
        $input = $this->dispatcher->registry()->filterParams($action, $invocation->params);
        try {
            $allowed = $action->authorize($context['user'] ?? null, $input);
        } catch (\Throwable $e) {
            $allowed = false;
        }
        if (!$allowed || $this->recordedQuery($message) === null) {
            return [];
        }

        $label = $invocation->text !== '' ? $invocation->text : $action->label();
        $messageId = $message->id ?? null;
        $panelId = self::exportPanelId($messageId, $fingerprint);

        if (!function_exists('_Link') || !function_exists('_Panel')) {
            // No Kompo runtime (unit context): the structured intent.
            return [['type' => 'recorded_result_export', 'message_id' => $messageId, 'fingerprint' => $fingerprint, 'label' => $label]];
        }

        return [
            // Kompo Link labels render through v-html client-side: escaped.
            _Link(e($label))->icon('export-2')->class('cdge-export-rerun')
                ->selfPost('exportRecordedResult', ['messageId' => $messageId, 'fingerprint' => $fingerprint])
                ->inPanel($panelId),
            _Panel()->id($panelId)->class('cdge-export-rerun-result'),
        ];
    }

    /**
     * CLICK of the re-run export chip: re-run the query recorded with $message as $user —
     * the person clicking NOW — and answer with the download, or a visible friendly failure.
     *
     * - $fingerprint is client input: it only SELECTS one of the invocations stored on the
     *   message, and only a built-in export in re-run mode. Anything else runs nothing.
     * - The action is dispatched through the shared ActionDispatcher: params re-filtered,
     *   authorize() re-run for $user (permissions may have changed since the answer).
     * - The dispatch context carries the acting user and the message's recorded query —
     *   and NO stored rows: this path can only export what the re-run returns. The re-run
     *   itself goes through the scoped executor ({@see RecordedResultExport}).
     *
     * @param mixed $message the assistant AiMessage, already resolved inside the user's own conversation
     */
    public function exportRecordedResult($message, mixed $fingerprint, mixed $user): mixed
    {
        $invocation = is_string($fingerprint) && $fingerprint !== ''
            ? $this->persistedInvocation($message, $fingerprint)
            : null;
        $action = $invocation !== null ? $this->dispatcher->registry()->getGeneric($invocation->key) : null;
        $recorded = $this->recordedQuery($message);

        if ($invocation === null || $action === null || $recorded === null || !$this->isRecordedResultExport($action, $invocation)) {
            return $this->exportFailure();
        }

        $result = $this->dispatcher->dispatch($action, $invocation->params, [
            'user' => $user,
            'text' => $invocation->text,
            'recorded_query' => $recorded,
        ]);
        if ($result === null) {
            return $this->exportFailure();
        }

        $download = $this->presenter->present($result, $invocation->key);

        // The file link lands in the chip's panel, outside the chip row's `> a` styling:
        // give it the same bordered-button look so it reads as the thing to click.
        if (!$result->isError && is_object($download) && method_exists($download, 'class')) {
            $download->class('inline-flex items-center gap-1.5 mt-2 px-3 py-1.5 rounded-lg text-sm font-semibold bg-white border border-gray-200 shadow-sm');
        }

        // The document limit cut the list: say so beside the file (it is never silent).
        if (!$result->isError && is_array($result->payload) && ($result->payload['complete'] ?? true) === false && function_exists('_Rows')) {
            $note = $this->translated(
                'ai.export.answer_showing_first',
                ['shown' => (int) ($result->payload['rows'] ?? 0)],
                'The document shows the first :shown rows — more may exist.'
            );

            return _Rows($download, _Html(e($note))->class('cdge-export-partial text-xs text-gray-700 mt-1'));
        }

        return $download;
    }

    /** The invocation stored on the message whose fingerprint is $fingerprint, if any. */
    protected function persistedInvocation($message, string $fingerprint): ?ActionInvocation
    {
        $records = $message->metadata['invocations'] ?? null;
        foreach (is_array($records) ? $records : [] as $record) {
            $invocation = ActionInvocation::fromArray($record);
            if ($invocation !== null && hash_equals($invocation->fingerprint(), $fingerprint)) {
                return $invocation;
            }
        }

        return null;
    }

    /** The visible notice of an export that cannot be prepared — never an internal reason. */
    public function exportFailure(): mixed
    {
        return $this->presenter->present(
            AiActionResult::error($this->translated('ai.actions.failed', [], 'The action could not be completed.')),
            'export_excel'
        );
    }

    /**
     * A package line with an explicit fallback: a translator that does not know the key
     * returns the key itself, which must never be shown to a user.
     *
     * @param array<string, scalar> $replace
     */
    protected function translated(string $key, array $replace, string $fallback): string
    {
        try {
            $line = __($key, $replace);
        } catch (\Throwable $e) {
            $line = $key;
        }
        if (is_string($line) && $line !== '' && $line !== $key) {
            return $line;
        }

        return strtr($fallback, array_combine(
            array_map(static fn ($name): string => ':' . $name, array_keys($replace)),
            array_map('strval', array_values($replace))
        ));
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
