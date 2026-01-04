# JS Action Button Linking Design

## Problem Statement

JS-injected messages (user bubbles + assistant responses) need functional action buttons (edit, copy, feedback, regenerate) that work like server-rendered messages, but:

1. **Kompo bindings only work on server-rendered elements** - JS-injected HTML is static
2. **Staging panel gets replaced** on each new message - proxies for older messages are lost
3. **Current patch works for user edit only** - assistant messages have no action bar

## Current Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ SENDING A MESSAGE - Current Flow                                            │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  1. User clicks send                                                        │
│     ↓                                                                       │
│  2. JS: precreateMessagePlaceholder()                                       │
│     - Creates user bubble [data-placeholder="user-message"]                 │
│     - Creates typing indicator [data-placeholder="typing-indicator"]        │
│     - Appends to #temp-message-display                                      │
│     ↓                                                                       │
│  3. Server: sendMessageAndGetResponse()                                     │
│     - Saves user + assistant messages to DB                                 │
│     - Returns to #temp-message-staging:                                     │
│       ┌────────────────────────────────────────┐                           │
│       │ .chat-assistant-staged-message         │                           │
│       │   └─ prose content                     │                           │
│       │      └─ [data-user-message-id="123"]   │                           │
│       │                                        │                           │
│       │ .js-edit-message-proxy (hidden)        │ ← Kompo binding           │
│       │   └─ [data-message-id="123"]           │                           │
│       └────────────────────────────────────────┘                           │
│     ↓                                                                       │
│  4. JS: processServerResponse()                                             │
│     - Moves content → .typing-indicator-content                             │
│     - Sets data-message-id on user placeholder                              │
│     - Wires .js-edit-message → .js-edit-message-proxy                       │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

## What Works

| Feature | Status | Why |
|---------|--------|-----|
| User edit (first message) | ✅ | Proxy exists in staging |
| User edit (subsequent) | ❌ | Staging replaced, old proxy gone |
| Assistant copy | ❌ | No action bar in typing indicator |
| Assistant feedback | ❌ | No action bar in typing indicator |
| Assistant regenerate | ❌ | No action bar in typing indicator |

## Root Causes

### 1. Staging Replacement
```javascript
// Each selfPost()->inPanel('temp-message-staging') REPLACES content
// Message 1 sent → staging has proxy for ID=1
// Message 2 sent → staging REPLACED → only proxy for ID=2
// Try to edit message 1 → proxy NOT FOUND
```

### 2. No Action Bar in Typing Indicator
```php
// AiChatPanel::typingIndicatorHtml() returns:
'<div class="typing-indicator-content">
    <div class="dots">...</div>  <!-- Just dots, no buttons -->
</div>'

// But MessagesQuery::assistantBubble() renders:
'<div class="group">
    <div class="prose">...</div>
    <div class="action-bar">
        <button>Copy</button>      <!-- Has Kompo binding -->
        <button>Feedback+</button>  <!-- Has Kompo binding -->
        <button>Feedback-</button>  <!-- Has Kompo binding -->
        <button>Regenerate</button> <!-- Has Kompo binding -->
    </div>
</div>'
```

## Proposed Solution

### Core Concept: Proxy-per-Message

Instead of keeping proxies in staging (where they get replaced), **move proxies INTO the message element** after processing. Each message carries its own hidden proxies.

```
BEFORE (current):                           AFTER (proposed):
┌──────────────────┐   ┌─────────────────┐  ┌────────────────────────────────┐
│ Visible message  │   │ Staging (wiped) │  │ Visible message                │
│  └─ .js-edit     │──▶│  └─ .proxy      │  │  ├─ .js-action-copy (visible)  │
└──────────────────┘   └─────────────────┘  │  ├─ .js-action-regen (visible) │
                                            │  └─ .js-proxy-container (hidden)│
     ↑ onclick looks in staging              │      ├─ .js-action-copy-proxy  │
       (broken if staging replaced)          │      └─ .js-action-regen-proxy │
                                            └────────────────────────────────┘
                                                 ↑ onclick looks within self
                                                   (always works)
```

### Class Naming Convention

| Visible Button (static HTML) | Proxy Button (Kompo binding) |
|------------------------------|------------------------------|
| `.js-action-copy` | `.js-action-copy-proxy` |
| `.js-action-feedback-positive` | `.js-action-feedback-positive-proxy` |
| `.js-action-feedback-negative` | `.js-action-feedback-negative-proxy` |
| `.js-action-regenerate` | `.js-action-regenerate-proxy` |
| `.js-action-edit` | `.js-action-edit-proxy` |

Pattern: `js-action-{name}` → `js-action-{name}-proxy`

## Implementation Plan

### Phase 1: Restructure Staging Response

**ChatMessageForm.php** - Update `sendMessageAndGetResponse()`:

```php
return _Rows(
    // Content for display
    _Rows(
        _Html($renderer->render($assistantMessage->content))
            ->class('prose prose-sm max-w-none'),
    )->class('chat-assistant-staged-content'),

    // Hidden proxy container with Kompo bindings
    _Rows(
        // Copy (uses copyToClipboard - needs special handling)
        _Link()->icon(_Sax('copy', 16))
            ->copyToClipboard($assistantMessage->content, 'translate.copied')
            ->class('hidden js-action-copy-proxy'),

        // Feedback positive
        $this->settings()->enableFeedback() ? _Link()
            ->selfPost('feedback', ['id' => $assistantMessage->id, 'type' => 'positive'])
            ->class('hidden js-action-feedback-positive-proxy') : null,

        // Feedback negative
        $this->settings()->enableFeedback() ? _Link()
            ->selfPost('feedback', ['id' => $assistantMessage->id, 'type' => 'negative'])
            ->class('hidden js-action-feedback-negative-proxy') : null,

        // Regenerate
        $this->settings()->enableRegenerate() ? _Link()
            ->selfPost('regenerate', ['id' => $assistantMessage->id])
            ->class('hidden js-action-regenerate-proxy') : null,

        // Edit (for user message)
        $this->settings()->enableEdit() ? _Link()
            ->selfGet('editMessage', ['id' => $userMessage->id])->inModal()
            ->class('hidden js-action-edit-proxy') : null,
    )->class('chat-staged-proxies hidden')
      ->attr(['data-assistant-message-id' => $assistantMessage->id]),
)->attr(['data-user-message-id' => $userMessage->id]);
```

### Phase 2: Update Typing Indicator Template

**AiChatPanel.php** - Update `typingIndicatorHtml()`:

Add visible action buttons (static HTML, no Kompo bindings):

```php
protected function typingIndicatorHtml(): string
{
    $actionBar = '';
    if ($this->settings()->enableCopy() || $this->settings()->enableFeedback() || $this->settings()->enableRegenerate()) {
        $actionBar = '<div class="action-bar mt-3 pt-2 border-t border-gray-50 flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">'
            . ($this->settings()->enableCopy()
                ? '<button type="button" class="js-action-copy p-1.5 rounded-lg hover:bg-gray-100 text-gray-400 hover:text-gray-600 transition-all"><svg class="w-4 h-4">...</svg></button>'
                : '')
            . ($this->settings()->enableFeedback()
                ? '<button type="button" class="js-action-feedback-positive p-1.5 rounded-lg hover:bg-emerald-50 text-gray-400 hover:text-emerald-600 transition-all"><svg class="w-4 h-4">...</svg></button>'
                  . '<button type="button" class="js-action-feedback-negative p-1.5 rounded-lg hover:bg-red-50 text-gray-400 hover:text-red-600 transition-all"><svg class="w-4 h-4">...</svg></button>'
                : '')
            . ($this->settings()->enableRegenerate()
                ? '<button type="button" class="js-action-regenerate p-1.5 rounded-lg hover:bg-gray-100 text-gray-400 hover:text-gray-600 transition-all"><svg class="w-4 h-4">...</svg></button>'
                : '')
            . '</div>';
    }

    return '<div class="flex items-start mt-4 animate-message-assistant">'
        . '<div class="mr-3 flex-shrink-0 self-start">' . $this->assistantAvatarHtml() . '</div>'
        . '<div class="typing-indicator-content group px-5 py-4 rounded-2xl rounded-tl-md bg-white border border-gray-100 shadow-sm">'
        . '<div class="flex items-center gap-1.5 h-6">..dots..</div>'
        . '<div class="text-xs text-gray-400 mt-2">' . __('ai.chat.thinking') . '</div>'
        . $actionBar
        . '</div></div>';
}
```

### Phase 3: Generic JS Button Linking

**chat-message-injector.js** - Add method:

```javascript
/**
 * Link all action buttons from staging to visible message
 * Uses class naming convention: js-action-X → js-action-X-proxy
 * Moves proxies into message element for persistence
 */
linkStagedActions() {
    const staging = document.getElementById(this.stagingPanelId);
    const proxies = staging?.querySelector('.chat-staged-proxies');
    if (!proxies) return;

    const assistantMsgId = proxies.getAttribute('data-assistant-message-id');

    // Find the typing indicator that was just filled (now has content)
    // It's the one without typing dots, most recently modified
    const typingContents = document.querySelectorAll('.typing-indicator-content');
    const messageEl = typingContents.length > 0
        ? typingContents[typingContents.length - 1].closest('[data-placeholder="typing-indicator"]')
            || typingContents[typingContents.length - 1].parentElement
        : null;

    if (!messageEl) {
        console.warn('Could not find message element for action linking');
        return;
    }

    // Set assistant message ID on the element
    if (assistantMsgId) {
        messageEl.setAttribute('data-assistant-message-id', assistantMsgId);
    }

    // Create hidden proxy container inside the message
    let proxyContainer = messageEl.querySelector('.js-proxy-container');
    if (!proxyContainer) {
        proxyContainer = document.createElement('div');
        proxyContainer.className = 'js-proxy-container hidden';
        messageEl.appendChild(proxyContainer);
    }

    // Find all proxies and link them
    proxies.querySelectorAll('[class*="js-action-"][class*="-proxy"]').forEach(proxy => {
        // Extract action name: js-action-copy-proxy → js-action-copy
        const proxyClass = [...proxy.classList].find(c => /js-action-[\w-]+-proxy/.test(c));
        if (!proxyClass) return;

        const visibleClass = proxyClass.replace('-proxy', '');
        const visibleBtn = messageEl.querySelector(`.${visibleClass}`);

        if (visibleBtn) {
            // Wire visible button to click the proxy
            visibleBtn.onclick = (e) => {
                e.preventDefault();
                e.stopPropagation();
                proxy.click();
            };
        }

        // MOVE (not clone) proxy to message's container
        // This preserves Kompo event bindings
        proxyContainer.appendChild(proxy);
    });

    // Remove empty proxies container from staging
    const emptyProxies = staging.querySelector('.chat-staged-proxies');
    if (emptyProxies && emptyProxies.children.length === 0) {
        emptyProxies.remove();
    }
}
```

### Phase 4: Update processServerResponse

```javascript
processServerResponse() {
    const stagingPanel = document.getElementById(this.stagingPanelId);
    if (!stagingPanel || !stagingPanel.innerHTML.trim()) {
        console.warn('Staging panel empty or not found');
        return;
    }

    // Get the response HTML from staging (content only, not proxies)
    const responseHtml = stagingPanel.querySelector('.chat-assistant-staged-content')?.innerHTML || '';

    // ... existing content swap logic ...

    // After content is swapped, link all action buttons
    this.linkStagedActions();

    // Handle user message edit button (existing logic, refactored)
    this.linkUserEditButton();

    // Scroll to show new message
    this.scrollToBottom();
}

/**
 * Link user message edit button (existing logic, extracted)
 */
linkUserEditButton() {
    const stagingPanel = document.getElementById(this.stagingPanelId);
    const userPlaceholders = document.querySelectorAll('[data-placeholder="user-message"]');
    const userPlaceholder = userPlaceholders.length > 0
        ? userPlaceholders[userPlaceholders.length - 1]
        : null;

    if (!userPlaceholder) return;

    const responseEl = stagingPanel.querySelector('[data-user-message-id]');
    const userMessageId = responseEl?.getAttribute('data-user-message-id');

    if (userMessageId) {
        userPlaceholder.setAttribute('data-message-id', userMessageId);

        // Find edit proxy in the proxy container (already moved by linkStagedActions)
        const proxyContainer = userPlaceholder.querySelector('.js-proxy-container');
        const editProxy = proxyContainer?.querySelector('.js-action-edit-proxy')
            || stagingPanel.querySelector('.js-action-edit-proxy');

        userPlaceholder.querySelectorAll('.js-edit-message, .js-action-edit').forEach(btn => {
            btn.onclick = () => editProxy?.click();
        });

        // Move edit proxy to user message if still in staging
        if (editProxy && !proxyContainer?.contains(editProxy)) {
            let container = userPlaceholder.querySelector('.js-proxy-container');
            if (!container) {
                container = document.createElement('div');
                container.className = 'js-proxy-container hidden';
                userPlaceholder.appendChild(container);
            }
            container.appendChild(editProxy);
        }
    }
}
```

## Files to Modify

| File | Changes |
|------|---------|
| `src/Kompo/AiChatPanel.php` | Add action buttons to `typingIndicatorHtml()` |
| `src/Kompo/ChatMessageForm.php` | Restructure `sendMessageAndGetResponse()` to include proxy container |
| `src/Kompo/Modals/EditMessageModal.php` | Same staging structure in `updateMessageAndGetResponse()` |
| `resources/js/chat-message-injector.js` | Add `linkStagedActions()`, update `processServerResponse()` |

## Special Considerations

### Copy Button
`copyToClipboard()` is client-side only. The proxy still works because it's a Kompo method that generates the appropriate JS handler.

### Feedback/Regenerate with ->refresh()
These actions call `->refresh()` which re-renders vlQueryWrapper. This is actually fine because:
1. Messages ARE in the database at this point
2. Refresh replaces JS content with server-rendered content
3. The action transitions from JS-mode to proper server-rendered mode

### Multiple Messages in Session
Each message now carries its own proxy container. Editing message 1 after sending message 2 works because message 1's proxies live inside message 1, not in staging.

## Testing Checklist

- [ ] Send message 1, verify edit button works
- [ ] Send message 2, verify message 1 edit still works
- [ ] Send message 2, verify message 2 edit works
- [ ] Verify assistant message copy button works
- [ ] Verify assistant message feedback buttons work
- [ ] Verify assistant message regenerate works
- [ ] Edit message, verify regenerated response has working buttons
- [ ] Delete message, verify cleanup is correct
