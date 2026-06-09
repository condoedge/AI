<?php

declare(strict_types=1);

namespace Condoedge\Ai\Kompo\Support;

use Condoedge\Ai\Services\Response\ContentLinkProcessor;
use Condoedge\Ai\Services\Settings\ChatSettingsInterface;
use Condoedge\Ai\Services\UI\ChatThemeInterface;
use Condoedge\Ai\Services\UI\SafeMarkdownRenderer;

/**
 * Builds the user and assistant chat bubbles (and the per-message action bar).
 *
 * This was the bulk of MessagesQuery's rendering surface (userBubble / assistantBubble /
 * messageActionBar). It is a pure view builder over (theme + settings + a message + already
 * resolved per-message context), so it lives here as a plain collaborator while MessagesQuery
 * keeps the list/render orchestration.
 *
 * Kompo round-trip note: the action-bar links call selfPost('feedback'|'regenerate'),
 * copyToClipboard(...) and the edit link calls selfGet('editMessage'). Those method-name
 * strings are resolved by Kompo against the owning MessagesQuery component when the booted
 * element tree is traversed — building the elements here does not change the request target,
 * so the public feedback/regenerate/editMessage methods stay on MessagesQuery.
 *
 * Behavior-preserving: every class string, animation class, slot and markup is copied verbatim
 * from the original MessagesQuery methods.
 */
class MessageBubbleRenderer
{
    public function __construct(
        protected ChatThemeInterface $theme,
        protected ChatSettingsInterface $settings,
        protected RichDataRenderer $richData,
        protected ContentLinkProcessor $contentLinkProcessor,
        protected string $userAvatarHtml,
        protected string $assistantAvatarHtml,
    ) {
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
        $animationClass = $isNew ? ' animate-message-user' : '';
        $highlightClass = $isNew ? ' animate-new-message-highlight' : '';

        return _Rows(
            _FlexEnd(
                _Rows(
                    _Html(e($message->content))->class('whitespace-pre-wrap'),
                    $this->settings->showTimestamps()
                        ? _Html($message->created_at->format('g:i A'))->class('text-xs opacity-60 mt-2')
                        : null,
                )->class('group px-4 py-3 rounded-2xl rounded-tr-md max-w-xl bg-gradient-to-r ' . $this->theme->primaryGradient() . ' text-white shadow-md' . $highlightClass),
                $this->settings->showAvatars()
                    ? _Html($this->userAvatarHtml)->class('ml-3 flex-shrink-0')
                    : null,
            )->class('items-end' . $animationClass),
            $this->settings->enableEdit() ? _FlexEnd(
                _Link(__('ai.common.edit'))->icon('pencil')
                    ->class('opacity-0 group-hover:opacity-100 text-xs text-gray-400 hover:text-gray-600 mt-1 transition-opacity')
                    ->selfGet('editMessage', ['id' => $message->id])->inModal(),
            ) : null,
        )->class('group');
    }

    public function assistantBubble($message, array $resultSet)
    {
        $isNew = $this->isNewMessage($message);
        $animationClass = $isNew ? ' animate-message-assistant' : '';
        $contentRevealClass = $isNew ? ' animate-content-reveal' : '';
        $staggerClass = $isNew ? ' animate-stagger-content' : '';

        $content = [];

        $files = $message->hasFileReferences() ? $message->getReferencedFiles() : [];

        // Process content: strip action links, strip citations, create elements.
        // We pass the result set so render_table/export_* actions can build tables/exports
        // from the data without the LLM shipping it through the link.
        $processed = $this->contentLinkProcessor->processForDirectRendering(
            $message->content,
            [
                'files' => $files,
                'result_set' => $resultSet,
                'user' => auth()->user(),
            ]
        );

        $content[] = _Html($this->renderMarkdown($processed['content']))
            ->class('prose prose-sm max-w-none [&_a]:font-medium [&_a]:underline [&_a]:underline-offset-2' . $contentRevealClass);

        if ($processed['has_links']) {
            // Action links arrive as bare _Link()s from config closures with no styling, so a
            // viewer cannot tell they are clickable. Render each as a clearly-actionable chip:
            // the row carries the theme's accent text colour (anchors inherit it), and every
            // direct child anchor becomes a bordered button with a hover lift and accent border.
            // Scoped to `> a` so non-link renderables in the same row (tables, approval cards)
            // are untouched.
            $content[] = _Flex(...$processed['elements'])
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

        return _Rows(
            _Flex(
                $this->settings->showAvatars()
                    ? _Html($this->assistantAvatarHtml)->class('mr-3 flex-shrink-0 self-start')
                    : null,
                _Rows(...$content)
                    ->class('group px-5 py-4 rounded-2xl rounded-tl-md max-w-2xl bg-white border border-gray-100 shadow-sm hover:shadow-md transition-shadow'),
            )->class('items-start' . $animationClass),
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
