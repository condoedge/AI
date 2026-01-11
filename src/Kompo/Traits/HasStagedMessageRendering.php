<?php

declare(strict_types=1);

namespace Condoedge\Ai\Kompo\Traits;

use Condoedge\Ai\Models\AiMessage;
use Condoedge\Ai\Services\Response\ContentLinkProcessor;
use Condoedge\Ai\Services\UI\SafeMarkdownRenderer;

/**
 * Provides staged message rendering for JS-injected chat messages.
 *
 * This trait renders assistant responses in a structure that JS can process:
 * - Content is rendered with link elements directly (actions + file citations)
 * - Visible action buttons are included (static HTML)
 * - Hidden proxy buttons with Kompo bindings are moved into message elements
 *
 * Used by ChatMessageForm and EditMessageModal to avoid code duplication.
 *
 * Requires: HasChatSettings, HasChatTheme traits to be present.
 */
trait HasStagedMessageRendering
{
    /**
     * Render a staged assistant response for JS injection.
     *
     * Returns a structure containing:
     * - Content with link elements rendered directly (actions + citations)
     * - Visible action bar (static HTML buttons)
     * - Hidden proxies (Kompo bindings, moved into message elements by JS)
     */
    protected function renderStagedAssistantResponse(AiMessage $assistantMessage, ?AiMessage $userMessage = null)
    {
        $renderer = new SafeMarkdownRenderer();
        $linkProcessor = app(ContentLinkProcessor::class);

        // Get file references for citation linking
        $files = $assistantMessage->hasFileReferences() ? $assistantMessage->getReferencedFiles() : [];

        // Process content: strip links, create elements (actions + file citations)
        $processed = $linkProcessor->processForDirectRendering(
            $assistantMessage->content,
            ['files' => $files]
        );

        // Render clean markdown (links replaced with plain text)
        $htmlContent = $renderer->render($processed['content']);

        return _Rows(
            // 1. Main content
            _Html($htmlContent)
                ->class('prose prose-sm max-w-none chat-staged-content'),

            // 2. Link elements rendered directly (actions + file citations)
            $this->stagedLinkElements($processed['elements']),

            // 3. Visible action bar - injected alongside content
            $this->stagedVisibleActionBar($assistantMessage),

            // 4. Hidden proxies for assistant actions - moved into assistant message element
            $this->stagedAssistantProxies($assistantMessage),

            // 5. Hidden proxy for user edit - moved into user message element
            $this->stagedUserEditProxy($userMessage),

            // 6. Hidden proxies for file citation actions - moved into assistant message element
            $this->stagedFileCitationProxies($processed['file_citations'] ?? []),

        )->attr([
            'data-user-message-id' => $userMessage?->id,
            'data-assistant-message-id' => $assistantMessage->id,
        ]);
    }

    /**
     * Render link elements directly (actions + file citations from AI response).
     */
    protected function stagedLinkElements(array $elements)
    {
        if (empty($elements)) {
            return null;
        }

        return _Flex(...$elements)
            ->class('chat-staged-actions mt-3 pt-2 border-t border-gray-100 gap-2 flex-wrap');
    }

    /**
     * Visible action buttons (static HTML, no Kompo bindings).
     * Matches the structure of MessagesQuery::messageActionBar() visually.
     */
    protected function stagedVisibleActionBar(AiMessage $message)
    {
        $buttons = [];

        if ($this->settings()->enableCopy()) {
            $buttons[] = _Link()->icon(_Sax('copy', 16))
                ->class('js-action-copy p-1.5 rounded-lg hover:bg-gray-100 text-gray-400 hover:text-gray-600 transition-all')
                ->balloon(__('ai.messages.copy'), 'up');
        }

        if ($this->settings()->enableFeedback()) {
            $buttons[] = _Link()->icon(_Sax('like-1', 16))
                ->class('js-action-feedback-pos p-1.5 rounded-lg hover:bg-emerald-50 text-gray-400 hover:text-emerald-600 transition-all')
                ->balloon(__('ai.messages.helpful'), 'up');

            $buttons[] = _Link()->icon(_Sax('dislike', 16))
                ->class('js-action-feedback-neg p-1.5 rounded-lg hover:bg-red-50 text-gray-400 hover:text-red-600 transition-all')
                ->balloon(__('ai.messages.not-helpful'), 'up');
        }

        if ($this->settings()->enableRegenerate()) {
            $buttons[] = _Link()->icon(_Sax('refresh', 16))
                ->class('js-action-regenerate p-1.5 rounded-lg hover:bg-gray-100 text-gray-400 hover:text-gray-600 transition-all')
                ->balloon(__('ai.messages.regenerate'), 'up');
        }

        if (empty($buttons)) {
            return null;
        }

        return _Flex(...$buttons)
            ->class('chat-staged-action-bar mt-3 pt-2 border-t border-gray-50 gap-1 opacity-0 group-hover:opacity-100 transition-opacity');
    }

    /**
     * Hidden proxy buttons for assistant actions (with Kompo bindings).
     * JS moves these into the assistant message element for persistence.
     */
    protected function stagedAssistantProxies(AiMessage $assistantMessage)
    {
        $proxies = [];

        if ($this->settings()->enableCopy()) {
            $proxies[] = _Link()
                ->copyToClipboard($assistantMessage->content, 'translate.copied-to-clipboard')
                ->class('hidden js-action-copy-proxy');
        }

        if ($this->settings()->enableFeedback()) {
            $proxies[] = _Link()
                ->selfPost('feedback', ['id' => $assistantMessage->id, 'type' => 'positive'])
                ->alert('translate.feedback-received-successfully')
                ->class('hidden js-action-feedback-pos-proxy');

            $proxies[] = _Link()
                ->selfPost('feedback', ['id' => $assistantMessage->id, 'type' => 'negative'])
                ->alert('translate.feedback-received-successfully')
                ->class('hidden js-action-feedback-neg-proxy');
        }

        if ($this->settings()->enableRegenerate()) {
            $proxies[] = _Link()
                ->selfPost('regenerate', ['id' => $assistantMessage->id])
                ->refresh(\Condoedge\Ai\Kompo\MessagesQuery::ID)
                ->class('hidden js-action-regenerate-proxy');
        }

        if (empty($proxies)) {
            return null;
        }

        return _Rows(...$proxies)
            ->class('chat-staged-assistant-proxies hidden')
            ->attr(['data-for-assistant-message-id' => $assistantMessage->id]);
    }

    /**
     * Hidden proxy for user message edit (with Kompo binding).
     * JS moves this into the user message element for persistence.
     */
    protected function stagedUserEditProxy(?AiMessage $userMessage)
    {
        if (!$userMessage || !$this->settings()->enableEdit()) {
            return null;
        }

        return _Link()
            ->selfGet('editMessage', ['id' => $userMessage->id])->inModal()
            ->class('hidden js-action-edit-proxy')
            ->attr(['data-for-user-message-id' => $userMessage->id]);
    }

    /**
     * Hidden proxy buttons for file citation actions (with Kompo bindings).
     * JS moves these into the assistant message element for persistence.
     *
     * @param array $fileCitations Citation metadata from ContentLinkProcessor
     */
    protected function stagedFileCitationProxies(array $fileCitations)
    {
        if (empty($fileCitations)) {
            return null;
        }

        $proxies = [];

        foreach ($fileCitations as $citation) {
            $proxies[] = _Link()
                ->selfGet('viewFile', [
                    'id' => $citation['id'],
                    'type' => $citation['type'],
                    'mime' => $citation['mime'],
                ])
                ->inModal()
                ->class('hidden js-file-citation-proxy')
                ->attr(['data-action-proxy' => $citation['slot']]);
        }

        return _Rows(...$proxies)
            ->class('chat-staged-file-citation-proxies hidden');
    }

    // Note: Action methods (feedback, regenerate) are provided by HasMessageActions trait
    // Make sure to also use HasMessageActions in your component
    // Note: viewFile() method is provided by HasFilePreview trait
}
