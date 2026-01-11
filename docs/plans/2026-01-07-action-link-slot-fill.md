# Action Link Slot-Fill Architecture

## Problem Statement

AI generates markdown like `[profile](entity://Person/152463/profile)` which needs to become a clickable Kompo element that executes an action (redirect, modal, etc.).

**Current symptoms:**
- **JS path (new messages)**: Shows raw markdown text `[profile](entity://...)` instead of button
- **PHP path (page reload)**: Shows styled span but clicking does nothing

---

## Current Architecture Analysis

### PHP Path (Page Reload)

```
MessagesQuery::query()
  → render($message)
    → assistantBubble($message)
      → renderMarkdown($content)
        → SafeMarkdownRenderer::render()  // markdown → HTML
        → processActionLinks($html)       // entity:// → <span class="js-action-...">text</span>
      → extractActionLinkProxies($content)
        → Creates hidden _Link() elements with class "js-action-...-proxy hidden"
      → Returns _Rows(_Html(prose), ...proxies)
→ Page HTML is served
→ Kompo initializes _Link() elements on page load
→ ❌ MISSING: No JS to wire span clicks to proxy clicks
```

**Root Cause #1**: `wireActionButtons()` is never called for PHP-rendered messages. The span has no click handler.

### JS Path (New Messages)

```
ChatMessageForm::sendMessageAndGetResponse()
  → renderStagedAssistantResponse($assistantMessage, $userMessage)
    → SafeMarkdownRenderer::render($content)     // ❌ No processActionLinks()!
    → stagedVisibleActionBar()                   // copy, feedback, regenerate buttons
    → stagedAssistantProxies()                   // ❌ Only copy/feedback/regenerate proxies, no entity proxies!
  → .inPanel('temp-message-staging')             // Kompo initializes elements
  → JS processServerResponse()
    → Moves content from staging to display
    → wireActionButtons()                        // Only wires copy/feedback/regenerate
```

**Root Cause #2**: `HasStagedMessageRendering` doesn't call `processActionLinks()` - entity links remain as raw markdown.

**Root Cause #3**: `stagedAssistantProxies()` doesn't include entity action proxies - only copy/feedback/regenerate.

---

## Proposed Solution: Slot-Fill Pattern

Instead of wiring clicks (span → proxy), we **move** the Kompo element **into** the slot where it becomes visible and functional.

### Concept

1. **Slot**: Empty placeholder in prose marking where element should appear
2. **Filler**: Hidden Kompo element that will be moved into slot
3. **Fill**: JS moves filler into slot, making it visible and functional

```
Before fill:  [prose with <slot>][hidden filler]
After fill:   [prose with filler-now-visible]
```

### Why This Works

- Kompo elements are initialized when rendered (PHP path) or injected via inPanel (JS path)
- Moving a DOM element preserves its event handlers
- After moving into slot, the Kompo element is visible AND functional

---

## Implementation Plan

### Task 1: Update `processActionLinks()` to Create Slots

**File**: `src/Kompo/MessagesQuery.php`

**Current** (line 668):
```php
return "<span class=\"{$actionClass} action-link cursor-pointer...\">{$text}</span>";
```

**New**:
```php
$slotId = "entity-{$entityType}-{$entityId}-{$actionKey}";
return "<span data-action-slot=\"{$slotId}\" class=\"action-slot inline\"></span>";
```

Note: Text is now passed to the filler element, not kept in slot.

### Task 2: Update `extractActionLinkProxies()` to Create Fillers

**File**: `src/Kompo/MessagesQuery.php`

**Current** (line 723-727):
```php
$element = $resolver($entityId);
if ($element) {
    $proxyClass = "js-action-entity-{$entityType}-{$entityId}-{$actionKey}-proxy";
    $proxies[$key] = $element->class($proxyClass . ' hidden');
}
```

**New**:
```php
$text = $match[1]; // Get text from markdown
$element = $resolver($entityId, $text);
if ($element) {
    $slotId = "entity-{$entityType}-{$entityId}-{$actionKey}";
    $proxies[$key] = $element
        ->attr(['data-fills-slot' => $slotId])
        ->class('hidden action-filler inline text-indigo-600 hover:text-indigo-800 underline cursor-pointer');
}
```

### Task 3: Update Config Resolver Signature

**File**: `config/ai.php` (documentation update)

Resolvers should accept optional `$text` parameter:
```php
'entity_actions' => [
    'Person' => [
        'profile' => [
            'action' => fn($id, $text = 'View Profile') => _Link($text)->href(route('people.show', $id)),
        ],
    ],
],
```

### Task 4: Add `fillActionSlots()` JS Function

**File**: `resources/js/chat-message-injector.js`

```javascript
/**
 * Fill action slots with their corresponding Kompo filler elements.
 * Moves hidden filler elements into slot positions, making them visible.
 */
fillActionSlots(container) {
    if (!container) return;

    const slots = container.querySelectorAll('[data-action-slot]');

    slots.forEach(slot => {
        const slotId = slot.getAttribute('data-action-slot');
        const filler = container.querySelector(`[data-fills-slot="${slotId}"]`);

        if (filler) {
            // Remove hidden class and move into slot position
            filler.classList.remove('hidden');
            slot.replaceWith(filler);
        } else {
            // No filler found - show slot text as fallback or remove slot
            console.warn('[AI Chat] No filler found for slot:', slotId);
            slot.remove();
        }
    });
}
```

### Task 5: Call `fillActionSlots()` for PHP Path

**File**: `resources/js/chat-message-injector.js`

Add initialization on page load:
```javascript
// At end of file, after ChatMessageInjector definition
document.addEventListener('DOMContentLoaded', () => {
    const panel = document.getElementById(ChatMessageInjector.panelId);
    if (panel) {
        // Fill slots for all PHP-rendered messages
        panel.querySelectorAll('[data-message-id]').forEach(message => {
            ChatMessageInjector.fillActionSlots(message);
        });
    }
});
```

### Task 6: Update `HasStagedMessageRendering` for JS Path

**File**: `src/Kompo/Traits/HasStagedMessageRendering.php`

**6a. Add processActionLinks call** (line 38):
```php
// Current:
_Html($renderer->render($assistantMessage->content))

// New:
_Html($this->processActionLinksForStaging($renderer->render($assistantMessage->content)))
```

**6b. Add entity action proxies to stagedAssistantProxies()** (after line 125):
```php
// Add entity action proxies
$entityProxies = $this->extractEntityActionProxies($assistantMessage->content);
foreach ($entityProxies as $proxy) {
    $proxies[] = $proxy;
}
```

**6c. Add helper methods**:
```php
protected function processActionLinksForStaging(string $content): string
{
    // Same logic as MessagesQuery::processActionLinks()
    // Could be extracted to a shared service
}

protected function extractEntityActionProxies(string $content): array
{
    // Same logic as MessagesQuery::extractActionLinkProxies()
    // Could be extracted to a shared service
}
```

### Task 7: Call `fillActionSlots()` in `processServerResponse()`

**File**: `resources/js/chat-message-injector.js`

In `processServerResponse()`, after wiring action buttons (line 169):
```javascript
// Wire visible action buttons to proxies within same element
this.wireActionButtons(typingIndicator);

// Fill action slots with entity/generic action elements
this.fillActionSlots(typingIndicator);
```

---

## Code Deduplication (Optional Task 8)

Extract shared logic to a service to avoid duplication between `MessagesQuery` and `HasStagedMessageRendering`:

**File**: `src/Services/Response/ResponseActionLinkProcessor.php`

```php
class ResponseActionLinkProcessor
{
    public function processActionLinks(string $content): string
    {
        // Shared slot creation logic
    }

    public function extractActionLinkFillers(string $content): array
    {
        // Shared filler creation logic
    }
}
```

---

## Testing Checklist

### PHP Path (Page Reload)
- [ ] Entity action link renders as slot in prose
- [ ] Kompo filler element renders (hidden) in message
- [ ] On page load, JS fills slot with filler
- [ ] Clicking action link triggers expected action (redirect/modal)

### JS Path (New Message)
- [ ] Entity action link in response creates slot
- [ ] Kompo filler element in staging panel
- [ ] After processServerResponse(), slot is filled
- [ ] Clicking action link triggers expected action

### Edge Cases
- [ ] Multiple action links in same message
- [ ] Same entity/action referenced multiple times
- [ ] Missing resolver (graceful fallback)
- [ ] Generic action links (action:// protocol)

---

## Flow Diagrams

### PHP Path After Fix
```
Page Load
  → MessagesQuery renders messages
  → processActionLinks() creates slots in prose
  → extractActionLinkProxies() creates hidden fillers
  → Kompo initializes filler elements
  → DOMContentLoaded: fillActionSlots() moves fillers into slots
  → User clicks action link
  → Kompo handles click (redirect/modal/etc)
```

### JS Path After Fix
```
User sends message
  → Server: renderStagedAssistantResponse()
    → processActionLinksForStaging() creates slots
    → extractEntityActionProxies() creates hidden fillers
  → .inPanel() injects to staging, Kompo initializes
  → JS: processServerResponse()
    → Moves content to display
    → wireActionButtons() for copy/feedback/regenerate
    → fillActionSlots() moves fillers into slots
  → User clicks action link
  → Kompo handles click
```

---

## Questions to Verify

1. When Kompo renders a `_Link()->href(...)` to initial HTML, does the click handler work after DOM move?
2. Does `slot.replaceWith(filler)` preserve Kompo's event bindings?
3. Should we keep wireActionButtons for copy/feedback/regenerate, or switch those to slot-fill too?

---

## Estimated Changes

| File | Lines Changed |
|------|---------------|
| MessagesQuery.php | ~30 |
| HasStagedMessageRendering.php | ~50 |
| chat-message-injector.js | ~40 |
| config/ai.php | ~5 (docs) |
| ResponseActionLinkProcessor.php (new) | ~80 (optional) |

