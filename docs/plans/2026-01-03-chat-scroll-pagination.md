# Chat Scroll Pagination Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Implement chat-style scroll pagination that loads newest 30 messages first, scrolls to bottom on load, and loads older messages when scrolling up.

**Architecture:** Leverage Kompo's built-in `Scroll` pagination type with `topPagination=true`. Create a reusable `HasChatScroll` trait that encapsulates the configuration and query pattern. The trait handles: DESC pagination query, ASC display order, scroll-to-bottom on load, and proper scroll position maintenance when loading older items.

**Tech Stack:** PHP 8.1+, Laravel, Kompo Query components, Vue.js (Kompo frontend)

---

## Background Research Summary

Kompo already supports chat-style scrolling via:
- `$paginationType = 'Scroll'` - enables infinite scroll
- `$topPagination = true` - loads older items when scrolling to top
- Vue Query.vue automatically:
  - Uses `flex-col-reverse` class for reversed layout
  - Scrolls to bottom on mount
  - Maintains scroll position via `fixTopPaginationScroll()`

Current MessagesQuery uses `$perPage = 200` with no pagination, loading all messages at once.

---

### Task 1: Create HasChatScroll Trait

**Files:**
- Create: `src/Kompo/Traits/HasChatScroll.php`

**Step 1: Create the trait file**

```php
<?php

namespace Condoedge\Ai\Kompo\Traits;

/**
 * Trait for chat-style scroll pagination in Kompo Query components.
 *
 * Provides:
 * - Newest items loaded first, displayed oldest-first (natural chat order)
 * - Scroll to bottom on initial load
 * - Load older items when scrolling up
 * - Configurable items per page (default 30)
 *
 * Usage:
 *   class MyMessagesQuery extends Query {
 *       use HasChatScroll;
 *
 *       public function chatScrollQuery() {
 *           return $this->conversation->messages();
 *       }
 *   }
 */
trait HasChatScroll
{
    /**
     * Initialize chat scroll configuration.
     * Call this in created() method.
     */
    protected function initChatScroll(int $perPage = 30): void
    {
        $this->paginationType = 'Scroll';
        $this->topPagination = true;
        $this->bottomPagination = false;
        $this->perPage = $perPage;

        // Wrapper classes for proper scrolling
        $this->itemsWrapperClass = ($this->itemsWrapperClass ?? '') . ' overflow-y-auto mini-scroll';
    }

    /**
     * Get the base query for chat messages.
     * Override this in your Query class.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    abstract protected function chatScrollQuery();

    /**
     * Get the ordering column for chat messages.
     * Override to customize (default: 'created_at').
     */
    protected function chatScrollOrderColumn(): string
    {
        return 'created_at';
    }

    /**
     * Build the paginated query for chat scroll.
     * Returns items in ASC order (oldest first) for natural chat display,
     * but pagination loads newest items first.
     */
    protected function buildChatScrollQuery()
    {
        $baseQuery = $this->chatScrollQuery();
        $orderColumn = $this->chatScrollOrderColumn();
        $currentPage = request()->get('page', 1);

        // For chat scroll with topPagination, we need to:
        // 1. Get total count
        // 2. Calculate offset from the END (newest items)
        // 3. Return items in ASC order for display

        $total = $baseQuery->count();
        $perPage = $this->perPage;

        // Calculate how many pages exist
        $lastPage = (int) ceil($total / $perPage);

        // Page 1 = newest items, Page N = oldest items
        // So we need to reverse the offset calculation
        $reversedPage = $lastPage - $currentPage + 1;
        $offset = max(0, ($reversedPage - 1) * $perPage);

        // Get items in ASC order (oldest first for display)
        return $baseQuery
            ->orderBy($orderColumn, 'asc')
            ->skip($offset)
            ->take($perPage);
    }

    /**
     * Generate scroll-to-bottom JavaScript.
     * Use in bottom() method: _Hidden()->onLoad->run($this->chatScrollToBottom())
     */
    protected function chatScrollToBottom(bool $smooth = false): string
    {
        $behavior = $smooth ? 'smooth' : 'auto';
        $id = $this->id ?? 'chat-scroll-query';

        return "() => {
            const wrapper = document.getElementById('{$id}')?.querySelector('.vlQueryWrapper');
            if (wrapper) {
                setTimeout(() => wrapper.scrollTo({ top: wrapper.scrollHeight, behavior: '{$behavior}' }), 100);
            }
        }";
    }
}
```

**Step 2: Verify file created correctly**

Run: `php -l src/Kompo/Traits/HasChatScroll.php`
Expected: No syntax errors

**Step 3: Commit**

```bash
git add src/Kompo/Traits/HasChatScroll.php
git commit -m "feat: add HasChatScroll trait for chat-style pagination"
```

---

### Task 2: Test Built-in Kompo Scroll Behavior First

Before implementing the trait, test if Kompo's built-in scroll pagination works correctly.

**Files:**
- Modify: `src/Kompo/MessagesQuery.php`

**Step 1: Add minimal scroll configuration to MessagesQuery**

Modify `src/Kompo/MessagesQuery.php` - in class properties (around line 29):

```php
public $perPage = 30;
public $paginationType = 'Scroll';
public $topPagination = true;
public $bottomPagination = false;
```

**Step 2: Test the behavior manually**

1. Open the chat interface in browser
2. Check if:
   - Only 30 messages load initially
   - Messages appear in correct order (oldest at top, newest at bottom)
   - Scrolling to top triggers loading of older messages
   - Scroll position is maintained when older messages load
   - New messages appear at bottom correctly

**Step 3: Document findings**

Create notes on what works and what doesn't. The built-in behavior may have issues with:
- `flex-col-reverse` breaking the visual layout
- Query ordering conflicts
- Scroll position jumping

**Step 4: Revert if issues found**

If built-in behavior has problems, revert to `$perPage = 200` and proceed to Task 3.

```bash
git stash  # Save changes for reference
```

---

### Task 3: Implement Custom Chat Scroll Query Pattern

If Task 2 reveals issues, implement a custom query pattern that works with Kompo pagination.

**Files:**
- Modify: `src/Kompo/MessagesQuery.php:29-48` (properties and created())
- Modify: `src/Kompo/MessagesQuery.php:144-160` (query() method)

**Step 1: Update MessagesQuery properties**

```php
public $perPage = 30;
public $paginationType = 'Scroll';
public $topPagination = true;
public $bottomPagination = false;
```

**Step 2: Update created() method to set scroll wrapper styling**

Add to `created()` method after existing code:

```php
// Chat scroll styling - ensure proper height and overflow
$this->itemsWrapperClass .= ' flex-1';
$this->itemsWrapperStyle = 'max-height: calc(95vh - 200px);';
```

**Step 3: Update query() method for reversed pagination**

Replace the current `query()` method:

```php
public function query()
{
    if (!$this->conversation) {
        return null;
    }

    // For chat scroll: page 1 = newest, load older on scroll up
    // But display in ASC order (oldest first, natural chat flow)

    $baseQuery = $this->conversation->messages();
    $currentPage = request()->get('page', 1);
    $perPage = $this->perPage;

    // Get total count for reverse pagination calculation
    $total = $baseQuery->count();
    $lastPage = (int) ceil($total / $perPage);

    // Reverse the page: page 1 should get the LAST items (newest)
    // page 2 should get second-to-last items, etc.
    $reversedPage = max(1, $lastPage - $currentPage + 1);
    $offset = ($reversedPage - 1) * $perPage;

    // Return in ASC order for natural chat display
    return $baseQuery
        ->orderBy('created_at', 'asc')
        ->skip($offset)
        ->take($perPage);
}
```

**Step 4: Test the pagination**

1. With a conversation that has 100+ messages:
   - Initial load should show newest 30
   - Scroll up should load older 30
   - Messages should always display oldest-first (natural chat order)

**Step 5: Commit**

```bash
git add src/Kompo/MessagesQuery.php
git commit -m "feat: implement chat scroll pagination with reversed page loading"
```

---

### Task 4: Handle Scroll-to-Bottom on Initial Load

**Files:**
- Modify: `src/Kompo/MessagesQuery.php:127-144` (bottom() method)

**Step 1: Ensure scroll-to-bottom runs after items load**

The current `bottom()` method already has scroll script. Verify it works with pagination:

```php
public function bottom()
{
    if (!$this->conversation) {
        return null;
    }

    return _Rows(
        new ChatMessageForm(null, [
            'conversation_id' => $this->conversation->id,
            'response_style' => $this->settings()->responseStyle(),
        ]),

        // Scroll to bottom on initial load (after items render)
        _Hidden()->onLoad->run($this->scrollScript(false)),
    );
}
```

**Step 2: Test scroll behavior**

1. Load chat with messages
2. Verify it scrolls to bottom (newest messages visible)
3. Scroll up to load older messages
4. Verify scroll position is maintained (not jumping to bottom)

**Step 3: Commit if changes made**

```bash
git add src/Kompo/MessagesQuery.php
git commit -m "fix: ensure scroll-to-bottom works with chat pagination"
```

---

### Task 5: Handle New Message Injection with Pagination

**Files:**
- Modify: `resources/js/chat-message-injector.js`

**Step 1: Verify new message injection works with pagination**

The current `processServerResponse()` should work, but verify:
1. Send a new message
2. User bubble placeholder appears at bottom
3. Assistant response replaces typing indicator
4. No issues with paginated item list

**Step 2: Add scroll-to-bottom after new message**

Ensure `scrollToBottom()` is called after processing response (already in place):

```javascript
processServerResponse() {
    // ... existing code ...

    // Scroll to show new message
    this.scrollToBottom();
},
```

**Step 3: Test full flow**

1. Load chat (scrolls to bottom)
2. Scroll up to load older messages
3. Send new message
4. Verify: placeholder appears, response loads, scrolls to bottom

**Step 4: Commit if changes made**

```bash
git add resources/js/chat-message-injector.js
git commit -m "fix: ensure new messages work with chat scroll pagination"
```

---

### Task 6: Create Reusable Trait (If Needed)

If the implementation works well, extract it into a reusable trait.

**Files:**
- Create: `src/Kompo/Traits/HasChatScroll.php` (from Task 1)
- Modify: `src/Kompo/MessagesQuery.php` to use trait

**Step 1: Finalize trait based on working implementation**

Update `HasChatScroll.php` with any adjustments discovered during testing.

**Step 2: Update MessagesQuery to use trait**

```php
<?php

namespace Condoedge\Ai\Kompo;

use Condoedge\Ai\Kompo\Traits\HasChatScroll;
// ... other imports

class MessagesQuery extends Query
{
    use HasChatTheme, HasAvatars, HasChatSettings, HasChatScroll;

    // ... existing code ...

    public function created()
    {
        $this->id(self::ID);

        // Initialize chat scroll with 30 items per page
        $this->initChatScroll(30);

        // ... rest of existing created() code ...
    }

    protected function chatScrollQuery()
    {
        return $this->conversation->messages();
    }

    public function query()
    {
        if (!$this->conversation) {
            return null;
        }

        return $this->buildChatScrollQuery();
    }
}
```

**Step 3: Test with trait**

Verify all functionality still works after refactoring to trait.

**Step 4: Commit**

```bash
git add src/Kompo/Traits/HasChatScroll.php src/Kompo/MessagesQuery.php
git commit -m "refactor: extract chat scroll pagination to reusable trait"
```

---

### Task 7: Handle Edge Cases

**Files:**
- Modify: `src/Kompo/MessagesQuery.php`
- Modify: `resources/js/chat-message-injector.js`

**Step 1: Handle empty conversation**

Verify welcome state still shows when no messages.

**Step 2: Handle conversation with fewer than perPage messages**

Test with conversation that has only 5 messages - should load all without pagination controls.

**Step 3: Handle rapid scrolling**

Test scrolling quickly up - should debounce/throttle loading requests.

**Step 4: Handle network errors during pagination**

Add error handling for failed pagination requests.

**Step 5: Commit**

```bash
git add src/Kompo/MessagesQuery.php resources/js/chat-message-injector.js
git commit -m "fix: handle edge cases in chat scroll pagination"
```

---

## Testing Checklist

- [ ] Initial load shows newest 30 messages
- [ ] Page scrolls to bottom on load
- [ ] Scrolling up triggers loading of older messages
- [ ] Scroll position maintained when older messages load
- [ ] Messages always display in chronological order (oldest top, newest bottom)
- [ ] New message placeholder appears correctly
- [ ] New message injection works
- [ ] Empty conversation shows welcome state
- [ ] Small conversations (< 30 messages) work correctly
- [ ] Rapid scrolling doesn't cause issues
- [ ] Network errors handled gracefully

---

## Alternative Approach: Patch Kompo Core (Not Recommended)

If the above approach doesn't work, could patch `vue-kompo` directly:
- Modify `Query.vue` to handle chat-specific behavior
- Add `chatMode` property that changes pagination logic

**Reasons NOT to patch:**
- Maintenance burden on Kompo updates
- Affects all projects using Kompo
- Trait approach is cleaner and self-contained

---

## Summary

The implementation leverages Kompo's built-in `Scroll` pagination with `topPagination=true`, combined with a reversed pagination query that:
1. Loads newest items on page 1
2. Loads older items on subsequent pages (scroll up)
3. Displays all items in ASC order (oldest first)

This provides native chat-style UX without modifying Kompo core.
