<?php

declare(strict_types=1);

namespace Condoedge\Ai\Kompo\Traits;

use Condoedge\Ai\Models\AiMessage;
use Condoedge\Ai\Services\UI\SafeMarkdownRenderer;

/**
 * Provides staged message rendering for JS-injected chat messages.
 *
 * This trait renders assistant responses in a structure that JS can process:
 * - Content is injected into the typing indicator
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
     * - Content (to be injected into typing indicator)
     * - Visible action bar (static HTML buttons)
     * - Hidden proxies (Kompo bindings, moved into message elements by JS)
     */
    protected function renderStagedAssistantResponse(AiMessage $assistantMessage, ?AiMessage $userMessage = null)
    {
        $renderer = new SafeMarkdownRenderer();

        return _Rows(
            // 1. Content - injected into typing indicator
            _Html($renderer->render($assistantMessage->content))
                ->class('prose prose-sm max-w-none chat-staged-content'),

            // 2. Visible action bar - injected alongside content
            $this->stagedVisibleActionBar($assistantMessage),

            // 3. Hidden proxies for assistant actions - moved into assistant message element
            $this->stagedAssistantProxies($assistantMessage),

            // 4. Hidden proxy for user edit - moved into user message element
            $this->stagedUserEditProxy($userMessage),

        )->attr([
            'data-user-message-id' => $userMessage?->id,
            'data-assistant-message-id' => $assistantMessage->id,
        ]);
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
     * Handle feedback for assistant message.
     * Called by staged proxy selfPost.
     */
    public function feedback($id, $type)
    {
        $conversation = $this->getConversationForAction();
        if (!$conversation) {
            return;
        }

        $message = $conversation->messages()->find($id);
        if ($message && $message->role === 'assistant') {
            $metadata = $message->metadata ?? [];
            $metadata['feedback'] = $type;
            $message->update(['metadata' => $metadata]);
        }
    }

    /**
     * Handle regenerate for assistant message.
     * Called by staged proxy selfPost.
     */
    public function regenerate($id)
    {
        $conversation = $this->getConversationForAction();
        if (!$conversation) {
            return;
        }

        $message = $conversation->messages()->find($id);
        if (!$message || $message->role !== 'assistant') {
            return;
        }

        // Get the user message before this assistant message
        $userMessage = $conversation->messages()
            ->where('id', '<', $message->id)
            ->where('role', 'user')
            ->orderBy('id', 'desc')
            ->first();

        if (!$userMessage) {
            return;
        }

        // Delete this assistant message
        $message->delete();

        // Regenerate using the service
        $service = app(\Condoedge\Ai\Services\Chat\RegenerateMessageService::class);
        $service->regenerateFromMessage(
            $conversation,
            $userMessage,
            auth()->user(),
            ['style' => $this->settings()->responseStyle()]
        );
    }

    /**
     * Get the conversation for action methods.
     * Must be implemented by using class or override this method.
     */
    protected function getConversationForAction()
    {
        // Try common property names
        return $this->conversation ?? null;
    }
}
