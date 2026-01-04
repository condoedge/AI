# JS Action Button Linking - Definitive Plan

## Executive Summary

JS-injected messages need functional action buttons. The solution: **move proxies from staging INTO each message element** after processing, so they survive staging replacement.

---

## Part 1: Understanding What Works (and Why)

### Current Flow - Sending a Message

```
1. User clicks send
   ↓
2. JS: precreateMessagePlaceholder()
   - Creates user bubble with .js-edit-message button (static HTML, no Kompo binding)
   - Creates typing indicator (NO action buttons)
   - Appends to #temp-message-display
   ↓
3. Server: sendMessageAndGetResponse() → inPanel('temp-message-staging')
   Returns:
   ┌────────────────────────────────────────────────────┐
   │ .chat-assistant-staged-message                     │
   │   └─ prose content [data-user-message-id="123"]    │
   │                                                    │
   │ .js-edit-message-proxy [data-message-id="123"]     │ ← Kompo binding
   └────────────────────────────────────────────────────┘
   ↓
4. JS: processServerResponse()
   - Extracts content from .chat-assistant-staged-message
   - Sets innerHTML of .typing-indicator-content (replaces dots with content)
   - Sets data-message-id="123" on user placeholder
   - Wires .js-edit-message onclick → looks in staging for proxy
```

### Why User Edit Works (Single Message)

```javascript
// Current wiring:
userPlaceholder.querySelectorAll('.js-edit-message').forEach(btn => {
    btn.onclick = () => {
        const editProxy = stagingPanel.querySelector(
            '.js-edit-message-proxy[data-message-id="' + userMessageId + '"]'
        );
        editProxy?.click();  // ← Finds proxy in staging, clicks it
    };
});
```

Works because:
1. User bubble template includes `.js-edit-message` button (line 197 of AiChatPanel.php)
2. Staging has `.js-edit-message-proxy` with Kompo binding
3. onclick finds proxy by data-message-id in staging
4. Proxy still exists in staging when clicked

### Why Multiple Messages Work (Before Editing)

Each `->inPanel('temp-message-staging')` REPLACES staging content. So:
- Message 1 sent → staging has proxy for ID=1
- Message 2 sent → staging REPLACED → only has proxy for ID=2

But message 1's onclick still looks for `[data-message-id="1"]` in staging → **SHOULD fail**.

**Actual observation:** User reports multiple messages work. This means either:
1. User tested with server-rendered messages (buttons directly on them)
2. Or there's caching/timing where proxy isn't accessed
3. Or the specific edit path wasn't tested

Regardless, the architecture is fragile - staging replacement WILL break older proxies.

### Why Editing Breaks Things

```
1. Send message 1, 2, 3 → staging accumulates proxies (maybe)
2. Edit message 2 → updateMessageAndGetResponse() → inPanel('temp-message-staging')
3. Staging REPLACED → only has regenerated response's proxy
4. Message 1's proxy is GONE
5. Message 3 was deleted by removeMessagesAfter()
6. Click edit on message 1 → looks in staging → NOT FOUND → BROKEN
```

### What's Missing: Assistant Action Buttons

Looking at the typing indicator template (line 212 of AiChatPanel.php):
```html
<div class="typing-indicator-content">
    <div class="dots">...</div>
    <div class="text-xs">Thinking...</div>
    <!-- NO ACTION BUTTONS -->
</div>
```

Compare to server-rendered assistant bubble (MessagesQuery::assistantBubble + messageActionBar):
- Copy button (copyToClipboard)
- Feedback positive (selfPost → MessagesQuery::feedback)
- Feedback negative (selfPost → MessagesQuery::feedback)
- Regenerate (selfPost → MessagesQuery::regenerate)

JS-injected assistant messages have **none of these**.

---

## Part 2: The Solution

### Core Principle: Proxy-per-Message

Instead of keeping proxies in staging (where they get replaced), **MOVE proxies INTO the message element** after processing.

```
BEFORE (fragile):
┌──────────────────┐     ┌─────────────────────┐
│ Message element  │     │ Staging (replaced)  │
│  └─ visible btn  │────▶│  └─ proxy           │
└──────────────────┘     └─────────────────────┘
       onclick looks in staging (can be empty)

AFTER (robust):
┌────────────────────────────────────────────┐
│ Message element                            │
│  ├─ visible btn                            │
│  └─ .js-proxy-container (hidden)           │
│      └─ proxy (MOVED from staging)         │
└────────────────────────────────────────────┘
       onclick looks within self (always there)
```

### Class Naming Convention

| Visible (static HTML) | Proxy (Kompo binding) |
|-----------------------|-----------------------|
| `.js-action-copy` | `.js-action-copy-proxy` |
| `.js-action-feedback-pos` | `.js-action-feedback-pos-proxy` |
| `.js-action-feedback-neg` | `.js-action-feedback-neg-proxy` |
| `.js-action-regenerate` | `.js-action-regenerate-proxy` |
| `.js-action-edit` | `.js-action-edit-proxy` |

Pattern: `js-action-{name}` → `js-action-{name}-proxy`

---

## Part 3: Implementation

### 3.1 Create Shared Trait for Staged Rendering

**New file: `src/Kompo/Traits/HasStagedMessageRendering.php`**

```php
<?php

namespace Condoedge\Ai\Kompo\Traits;

use Condoedge\Ai\Models\AiMessage;
use Condoedge\Ai\Services\UI\SafeMarkdownRenderer;

/**
 * Shared rendering for staged assistant messages.
 * Used by ChatMessageForm and EditMessageModal to avoid code duplication.
 */
trait HasStagedMessageRendering
{
    /**
     * Render staged assistant response with content, visible action bar, and proxies.
     */
    protected function renderStagedAssistantResponse(AiMessage $assistantMessage, ?AiMessage $userMessage = null)
    {
        $renderer = new SafeMarkdownRenderer();

        return _Rows(
            // 1. Content (injected into typing indicator)
            _Html($renderer->render($assistantMessage->content))
                ->class('prose prose-sm max-w-none')
                ->class('chat-staged-content'),

            // 2. Visible action bar (injected alongside content)
            $this->stagedVisibleActionBar($assistantMessage),

            // 3. Hidden proxies for assistant actions (moved into assistant message)
            $this->stagedAssistantProxies($assistantMessage),

            // 4. Hidden proxy for user edit (moved into user message)
            $this->stagedUserEditProxy($userMessage),

        )->attr([
            'data-user-message-id' => $userMessage?->id,
            'data-assistant-message-id' => $assistantMessage->id,
        ]);
    }

    /**
     * Visible action buttons (static HTML, no Kompo bindings).
     * These get injected into the typing indicator content.
     */
    protected function stagedVisibleActionBar(AiMessage $message)
    {
        $buttons = [];

        if ($this->settings()->enableCopy()) {
            $buttons[] = _Html($this->actionButtonHtml('copy', 'copy', __('ai.messages.copy')))
                ->class('js-action-copy');
        }

        if ($this->settings()->enableFeedback()) {
            $buttons[] = _Html($this->actionButtonHtml('like-1', 'feedback-pos', __('ai.messages.helpful')))
                ->class('js-action-feedback-pos');
            $buttons[] = _Html($this->actionButtonHtml('dislike', 'feedback-neg', __('ai.messages.not-helpful')))
                ->class('js-action-feedback-neg');
        }

        if ($this->settings()->enableRegenerate()) {
            $buttons[] = _Html($this->actionButtonHtml('refresh', 'regenerate', __('ai.messages.regenerate')))
                ->class('js-action-regenerate');
        }

        if (empty($buttons)) {
            return null;
        }

        return _Flex(...$buttons)
            ->class('chat-staged-action-bar mt-3 pt-2 border-t border-gray-50 gap-1 opacity-0 group-hover:opacity-100 transition-opacity');
    }

    /**
     * Generate action button HTML (static, no Kompo binding).
     */
    protected function actionButtonHtml(string $icon, string $action, string $tooltip): string
    {
        $iconSvg = $this->getSaxIconSvg($icon, 16);
        return '<button type="button" class="p-1.5 rounded-lg hover:bg-gray-100 text-gray-400 hover:text-gray-600 transition-all" title="' . e($tooltip) . '">' . $iconSvg . '</button>';
    }

    /**
     * Get SAX icon SVG (simplified - you may need to adjust based on your icon system).
     */
    protected function getSaxIconSvg(string $name, int $size): string
    {
        // Map to actual SVG paths - adjust based on your icon system
        $icons = [
            'copy' => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>',
            'like-1' => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 10h4.764a2 2 0 011.789 2.894l-3.5 7A2 2 0 0115.263 21h-4.017c-.163 0-.326-.02-.485-.06L7 20m7-10V5a2 2 0 00-2-2h-.095c-.5 0-.905.405-.905.905 0 .714-.211 1.412-.608 2.006L7 11v9m7-10h-2M7 20H5a2 2 0 01-2-2v-6a2 2 0 012-2h2.5"></path></svg>',
            'dislike' => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14H5.236a2 2 0 01-1.789-2.894l3.5-7A2 2 0 018.736 3h4.018a2 2 0 01.485.06l3.76.94m-7 10v5a2 2 0 002 2h.096c.5 0 .905-.405.905-.904 0-.715.211-1.413.608-2.008L17 13V4m-7 10h2m5-10h2a2 2 0 012 2v6a2 2 0 01-2 2h-2.5"></path></svg>',
            'refresh' => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>',
        ];

        return $icons[$name] ?? '';
    }

    /**
     * Hidden proxy buttons for assistant actions (Kompo bindings).
     * These get moved into the assistant message element.
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
                ->class('hidden js-action-feedback-pos-proxy');
            $proxies[] = _Link()
                ->selfPost('feedback', ['id' => $assistantMessage->id, 'type' => 'negative'])
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
     * Hidden proxy for user message edit (Kompo binding).
     * Gets moved into the user message element.
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
}
```

### 3.2 Add Action Methods to ChatMessageForm

ChatMessageForm needs `feedback()` and `regenerate()` methods since proxies call selfPost on it.

**Update `ChatMessageForm.php`:**

```php
// Add after editMessage() method:

/**
 * Handle feedback for assistant message (called by staged proxy).
 */
public function feedback($id, $type)
{
    $message = $this->conversation?->messages()->find($id);
    if ($message && $message->role === 'assistant') {
        $metadata = $message->metadata ?? [];
        $metadata['feedback'] = $type;
        $message->update(['metadata' => $metadata]);
    }
}

/**
 * Handle regenerate for assistant message (called by staged proxy).
 */
public function regenerate($id)
{
    if (!$this->conversation) {
        return;
    }

    $message = $this->conversation->messages()->find($id);
    if (!$message || $message->role !== 'assistant') {
        return;
    }

    // Get the user message before this assistant message
    $userMessage = $this->conversation->messages()
        ->where('id', '<', $message->id)
        ->where('role', 'user')
        ->orderBy('id', 'desc')
        ->first();

    if (!$userMessage) {
        return;
    }

    // Delete this assistant message
    $message->delete();

    // Regenerate
    $service = app(RegenerateMessageService::class);
    $service->regenerateFromMessage(
        $this->conversation,
        $userMessage,
        auth()->user(),
        ['style' => $this->settings()->responseStyle()]
    );
}
```

### 3.3 Update sendMessageAndGetResponse()

**Update `ChatMessageForm.php`:**

```php
use Condoedge\Ai\Kompo\Traits\HasStagedMessageRendering;

class ChatMessageForm extends Form
{
    use HasAvatars, HasChatSettings, HasChatTheme, HasConversationCreation, HasStagedMessageRendering;

    // ... existing code ...

    public function sendMessageAndGetResponse()
    {
        $message = trim(request('message') ?? '');
        $style = request('style') ?? $this->responseStyle;

        if (empty($message)) {
            return _Html('')->class('hidden');
        }

        $this->ensureConversation();

        $service = app(SendMessageService::class);

        try {
            $service->sendMessage(
                conversation: $this->conversation,
                message: $message,
                options: [
                    'style' => $style,
                    'user' => auth()->user(),
                ]
            );

            $userMessage = $this->conversation->messages()
                ->where('role', 'user')
                ->orderBy('created_at', 'desc')
                ->first();

            $assistantMessage = $this->conversation->messages()
                ->where('role', 'assistant')
                ->orderBy('created_at', 'desc')
                ->first();

            if (!$assistantMessage) {
                return _Html('')->class('hidden');
            }

            // Use shared rendering (includes content, action bar, and proxies)
            return $this->renderStagedAssistantResponse($assistantMessage, $userMessage);

        } catch (\Throwable $e) {
            \Log::error('Chat message failed: ' . $e->getMessage(), [
                'conversation_id' => $this->conversation->id,
                'exception' => $e,
            ]);

            return _Rows(
                _Html(__('ai.chat.error-message'))->class('text-red-500 text-sm p-4 bg-red-50 rounded-lg')
            );
        }
    }
}
```

### 3.4 Update EditMessageModal Similarly

**Update `EditMessageModal.php`:**

```php
use Condoedge\Ai\Kompo\Traits\HasStagedMessageRendering;

class EditMessageModal extends Modal
{
    use HasChatTheme, HasChatSettings, HasStagedMessageRendering;

    // ... existing code ...

    public function updateMessageAndGetResponse()
    {
        $content = trim(request('content') ?? '');

        if (empty($content) || !$this->message) {
            return _Html('')->class('hidden');
        }

        $this->message->update(['content' => $content]);

        $conversation = $this->message->conversation;
        $conversation->messages()
            ->where('id', '>', $this->message->id)
            ->delete();

        try {
            $service = app(RegenerateMessageService::class);
            $service->regenerateFromMessage(
                $conversation,
                $this->message->fresh(),
                auth()->user(),
                ['style' => $this->settings()->responseStyle()]
            );

            $assistantMessage = $conversation->messages()
                ->where('role', 'assistant')
                ->orderBy('created_at', 'desc')
                ->first();

            if (!$assistantMessage) {
                return _Html('')->class('hidden');
            }

            // Use shared rendering
            return $this->renderStagedAssistantResponse($assistantMessage, $this->message);

        } catch (\Throwable $e) {
            \Log::error('Edit message regeneration failed: ' . $e->getMessage());
            return _Html(__('ai.chat.error-message'))->class('text-red-500 text-sm');
        }
    }

    // Add feedback/regenerate methods (same as ChatMessageForm)
    public function feedback($id, $type) { /* ... */ }
    public function regenerate($id) { /* ... */ }
}
```

### 3.5 Update JavaScript - processServerResponse()

**Update `chat-message-injector.js`:**

```javascript
/**
 * Process server response from staging panel
 * Moves content + action bar to typing indicator
 * Moves proxies into message elements for persistence
 */
processServerResponse() {
    const stagingPanel = document.getElementById(this.stagingPanelId);
    if (!stagingPanel || !stagingPanel.innerHTML.trim()) {
        console.warn('Staging panel empty or not found');
        return;
    }

    // 1. Get content and action bar from staging
    const content = stagingPanel.querySelector('.chat-staged-content')?.outerHTML || '';
    const actionBar = stagingPanel.querySelector('.chat-staged-action-bar')?.outerHTML || '';
    const combinedHtml = content + actionBar;

    // 2. Find the LATEST typing indicator and inject content
    const contentAreas = document.querySelectorAll('.typing-indicator-content');
    const contentArea = contentAreas.length > 0 ? contentAreas[contentAreas.length - 1] : null;

    if (contentArea) {
        contentArea.innerHTML = combinedHtml;
        contentArea.classList.remove('typing-indicator-content');
    }

    // 3. Get message IDs from staging
    const userMessageId = stagingPanel.querySelector('[data-user-message-id]')?.getAttribute('data-user-message-id');
    const assistantMessageId = stagingPanel.querySelector('[data-assistant-message-id]')?.getAttribute('data-assistant-message-id');

    // 4. Handle typing indicator element (becomes assistant message)
    const typingIndicators = document.querySelectorAll('[data-placeholder="typing-indicator"]');
    const typingIndicator = typingIndicators.length > 0 ? typingIndicators[typingIndicators.length - 1] : null;

    if (typingIndicator) {
        typingIndicator.removeAttribute('data-placeholder');
        typingIndicator.removeAttribute('data-void');

        if (assistantMessageId) {
            typingIndicator.setAttribute('data-message-id', assistantMessageId);
        }

        // Move assistant proxies into this element
        const assistantProxies = stagingPanel.querySelector('.chat-staged-assistant-proxies');
        if (assistantProxies) {
            typingIndicator.appendChild(assistantProxies);
        }

        // Wire visible action buttons to proxies (within same element)
        this.wireActionButtons(typingIndicator);
    }

    // 5. Handle user message placeholder
    const userPlaceholders = document.querySelectorAll('[data-placeholder="user-message"]');
    const userPlaceholder = userPlaceholders.length > 0 ? userPlaceholders[userPlaceholders.length - 1] : null;

    if (userPlaceholder) {
        userPlaceholder.removeAttribute('data-void');
        userPlaceholder.removeAttribute('data-placeholder');

        if (userMessageId) {
            userPlaceholder.setAttribute('data-message-id', userMessageId);
        }

        // Move user edit proxy into user message element
        const editProxy = stagingPanel.querySelector('.js-action-edit-proxy');
        if (editProxy) {
            // Create hidden container for proxy
            let proxyContainer = userPlaceholder.querySelector('.js-proxy-container');
            if (!proxyContainer) {
                proxyContainer = document.createElement('div');
                proxyContainer.className = 'js-proxy-container hidden';
                userPlaceholder.appendChild(proxyContainer);
            }
            proxyContainer.appendChild(editProxy);
        }

        // Wire edit button to proxy (within same element)
        this.wireActionButtons(userPlaceholder);
    }

    // 6. Clear staging (proxies have been moved)
    stagingPanel.innerHTML = '';

    // 7. Scroll to show new message
    this.scrollToBottom();
},

/**
 * Wire visible action buttons to their proxy counterparts within the same element.
 * Uses class naming convention: js-action-X → js-action-X-proxy
 */
wireActionButtons(messageElement) {
    if (!messageElement) return;

    // Find all visible action buttons (not proxies)
    const visibleButtons = messageElement.querySelectorAll('[class*="js-action-"]:not([class*="-proxy"])');

    visibleButtons.forEach(btn => {
        // Extract action name from class (e.g., "js-action-copy" → "copy")
        const actionClass = [...btn.classList].find(c => /^js-action-[\w-]+$/.test(c) && !c.includes('-proxy'));
        if (!actionClass) return;

        const proxyClass = actionClass + '-proxy';

        // Find proxy within the same message element
        const proxy = messageElement.querySelector('.' + proxyClass);

        if (proxy) {
            btn.onclick = (e) => {
                e.preventDefault();
                e.stopPropagation();
                proxy.click();
            };
        }
    });

    // Also handle legacy .js-edit-message class (user bubble template)
    messageElement.querySelectorAll('.js-edit-message').forEach(btn => {
        const proxy = messageElement.querySelector('.js-action-edit-proxy');
        if (proxy) {
            btn.onclick = (e) => {
                e.preventDefault();
                e.stopPropagation();
                proxy.click();
            };
        }
    });
}
```

---

## Part 4: Why This Works

### Trace: Send Message 1, Then Message 2

```
1. Send message 1
   - JS creates placeholders
   - Server returns staging with proxies
   - JS moves content to typing indicator
   - JS MOVES proxies into message elements
   - Staging is now EMPTY
   - Message 1 has its own proxies inside it

2. Send message 2
   - JS creates new placeholders
   - Server returns staging with message 2's proxies
   - JS moves content to typing indicator
   - JS MOVES proxies into message 2's elements
   - Staging is now EMPTY
   - Message 2 has its own proxies inside it
   - Message 1's proxies are UNTOUCHED (they're inside message 1, not staging)

3. Click edit on message 1
   - onclick looks for .js-action-edit-proxy WITHIN message 1 element
   - Finds it (it was moved there in step 1)
   - Clicks it → modal opens
   - SUCCESS
```

### Trace: Edit Message After Sending Multiple

```
1. Send messages 1, 2, 3
   - Each message has its own proxies inside it
   - Staging is empty after each

2. Edit message 2
   - Modal opens (proxy found inside message 2)
   - User saves
   - removeMessagesAfter(2) removes message 3
   - showTypingAfterMessage(2) creates typing indicator
   - Server returns regenerated response to staging
   - JS moves new proxies into typing indicator (becomes message 2's assistant response)
   - Message 1's proxies still inside message 1
   - SUCCESS

3. Click edit on message 1
   - Looks inside message 1 for proxy
   - Finds it → works
   - SUCCESS
```

### Key Change: Lookup Location

```javascript
// OLD (fragile - staging gets replaced):
const proxy = stagingPanel.querySelector('.js-action-edit-proxy[data-message-id="X"]');

// NEW (robust - proxies live inside each message):
const proxy = messageElement.querySelector('.js-action-edit-proxy');
```

---

## Part 5: Files to Modify

| File | Changes |
|------|---------|
| `src/Kompo/Traits/HasStagedMessageRendering.php` | **NEW** - shared rendering for staged responses |
| `src/Kompo/ChatMessageForm.php` | Use trait, add feedback/regenerate methods |
| `src/Kompo/Modals/EditMessageModal.php` | Use trait, add feedback/regenerate methods |
| `resources/js/chat-message-injector.js` | Update processServerResponse(), add wireActionButtons() |

---

## Part 6: Testing Checklist

- [ ] Send message 1 → edit button works
- [ ] Send message 2 → message 1 edit still works
- [ ] Send message 2 → message 2 edit works
- [ ] Assistant message copy button works (JS-injected)
- [ ] Assistant message feedback buttons work (JS-injected)
- [ ] Assistant message regenerate works (JS-injected)
- [ ] Edit message 1 after sending 1,2,3 → all buttons still work
- [ ] Regenerate preserves action buttons on new response
- [ ] Delete message removes element cleanly
- [ ] Server-rendered messages (page refresh) still work normally
