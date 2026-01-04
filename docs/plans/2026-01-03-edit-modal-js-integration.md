# Edit Modal JS Integration Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Make EditMessageModal use the same JS animation pattern as ChatMessageForm instead of full page refresh.

**Architecture:** Extend ChatMessageInjector with new methods for edit/delete operations. Modal closes immediately, JS updates DOM with animations, server response goes to staging panel, JS processes it when ready.

**Tech Stack:** JavaScript (ChatMessageInjector), PHP (Kompo components), CSS animations

---

## Problem Analysis

**Current flow (broken):**
1. User clicks "Save & Regenerate" in modal
2. `selfPost('updateMessage')` → server processes (slow - AI call)
3. `refresh(MessagesQuery::ID)` → full page refresh
4. No animations, jarring UX, scroll position lost

**Target flow (like ChatMessageForm):**
1. User clicks "Save & Regenerate" in modal
2. Modal closes immediately
3. JS updates user message content in place
4. JS removes subsequent messages with fade-out
5. JS shows typing indicator
6. `selfPost` sends to staging panel
7. JS processes staging response into typing indicator

---

### Task 1: Add edit/delete methods to ChatMessageInjector

**Files:**
- Modify: `resources/js/chat-message-injector.js`

**Step 1: Add `updateMessageContent()` method**

Add after `clearPlaceholders()` method (around line 205):

```javascript
    /**
     * Update an existing user message content in place
     * @param {string|number} messageId - The message ID to update
     * @param {string} newContent - The new message content
     */
    updateMessageContent(messageId, newContent) {
        const messageBubble = document.querySelector(`[data-message-id="${messageId}"]`);
        if (!messageBubble) {
            console.warn('Message not found:', messageId);
            return false;
        }

        // Find the content area inside user bubble (the text content div)
        const contentArea = messageBubble.querySelector('.whitespace-pre-wrap');
        if (contentArea) {
            contentArea.textContent = newContent;
        }

        // Add a subtle flash animation to show the update
        messageBubble.classList.add('animate-pulse');
        setTimeout(() => messageBubble.classList.remove('animate-pulse'), 500);

        return true;
    },
```

**Step 2: Add `removeMessagesAfter()` method**

Add after `updateMessageContent()`:

```javascript
    /**
     * Remove all messages after a given message ID with fade-out animation
     * @param {string|number} messageId - Remove messages after this ID
     * @returns {Promise} Resolves when animations complete
     */
    removeMessagesAfter(messageId) {
        return new Promise((resolve) => {
            const messageBubble = document.querySelector(`[data-message-id="${messageId}"]`);
            if (!messageBubble) {
                resolve();
                return;
            }

            // Get all message bubbles
            const allMessages = Array.from(document.querySelectorAll('[data-message-id]'));
            const messageIndex = allMessages.indexOf(messageBubble);

            // Messages to remove (those BEFORE in DOM due to flex-col-reverse)
            const toRemove = allMessages.slice(0, messageIndex);

            if (toRemove.length === 0) {
                resolve();
                return;
            }

            // Fade out and remove each
            let completed = 0;
            toRemove.forEach((el, i) => {
                el.style.transition = 'opacity 0.3s ease-out, transform 0.3s ease-out';
                el.style.opacity = '0';
                el.style.transform = 'translateY(-10px)';

                setTimeout(() => {
                    el.remove();
                    completed++;
                    if (completed === toRemove.length) {
                        resolve();
                    }
                }, 300 + (i * 50)); // Stagger removal
            });
        });
    },
```

**Step 3: Add `showTypingAfterMessage()` method**

Add after `removeMessagesAfter()`:

```javascript
    /**
     * Show typing indicator after a specific message
     * @param {string|number} messageId - Show typing after this message
     */
    showTypingAfterMessage(messageId) {
        const messageBubble = document.querySelector(`[data-message-id="${messageId}"]`);
        if (!messageBubble) {
            console.warn('Message not found for typing indicator:', messageId);
            return;
        }

        // Create typing indicator from template
        const typingIndicator = document.createElement('div');
        typingIndicator.setAttribute('data-placeholder', 'typing-indicator');
        typingIndicator.setAttribute('data-void', 'true');
        typingIndicator.innerHTML = this.typingIndicatorTemplate;

        // Insert BEFORE the message in DOM (appears AFTER due to flex-col-reverse)
        messageBubble.parentNode.insertBefore(typingIndicator, messageBubble);

        this.scrollToBottom();
    },
```

**Step 4: Add `removeMessageAndAfter()` method for delete**

Add after `showTypingAfterMessage()`:

```javascript
    /**
     * Remove a message and all messages after it (for delete operation)
     * @param {string|number} messageId - Remove this message and all after it
     * @returns {Promise} Resolves when animations complete
     */
    removeMessageAndAfter(messageId) {
        return new Promise((resolve) => {
            const messageBubble = document.querySelector(`[data-message-id="${messageId}"]`);
            if (!messageBubble) {
                resolve();
                return;
            }

            // Get all message bubbles
            const allMessages = Array.from(document.querySelectorAll('[data-message-id]'));
            const messageIndex = allMessages.indexOf(messageBubble);

            // Messages to remove (this one and those BEFORE in DOM due to flex-col-reverse)
            const toRemove = allMessages.slice(0, messageIndex + 1);

            if (toRemove.length === 0) {
                resolve();
                return;
            }

            // Fade out and remove each
            let completed = 0;
            toRemove.forEach((el, i) => {
                el.style.transition = 'opacity 0.3s ease-out, transform 0.3s ease-out';
                el.style.opacity = '0';
                el.style.transform = 'translateY(-10px)';

                setTimeout(() => {
                    el.remove();
                    completed++;
                    if (completed === toRemove.length) {
                        resolve();
                    }
                }, 300 + (i * 50));
            });
        });
    },
```

**Step 5: Add global helper functions**

Add at the end of the file, before the closing:

```javascript
function updateMessageContent(messageId, newContent) {
    return ChatMessageInjector.updateMessageContent(messageId, newContent);
}

function removeMessagesAfter(messageId) {
    return ChatMessageInjector.removeMessagesAfter(messageId);
}

function showTypingAfterMessage(messageId) {
    ChatMessageInjector.showTypingAfterMessage(messageId);
}

function removeMessageAndAfter(messageId) {
    return ChatMessageInjector.removeMessageAndAfter(messageId);
}

window.updateMessageContent = updateMessageContent;
window.removeMessagesAfter = removeMessagesAfter;
window.showTypingAfterMessage = showTypingAfterMessage;
window.removeMessageAndAfter = removeMessageAndAfter;
```

**Step 6: Verify syntax**

Run: Open browser console and check for JS errors on chat page.

---

### Task 2: Add server endpoint for edit with staging response

**Files:**
- Modify: `src/Kompo/Modals/EditMessageModal.php`

**Step 1: Add `updateMessageAndGetResponse()` method**

This replaces `updateMessage()` to return the assistant response HTML (like `sendMessageAndGetResponse()`).

Add after line 149 (after `updateMessage()` method):

```php
/**
 * Update message and return rendered assistant response for JS injection
 * Follows the ChatMessageForm pattern with staging panel
 */
public function updateMessageAndGetResponse()
{
    $content = trim(request('content') ?? '');

    if (empty($content) || !$this->message) {
        return _Html('')->class('hidden');
    }

    // Update the message content
    $this->message->update(['content' => $content]);

    // Delete all messages after this one
    $conversation = $this->message->conversation;
    $conversation->messages()
        ->where('id', '>', $this->message->id)
        ->delete();

    // Regenerate AI response using the service
    try {
        $service = app(RegenerateMessageService::class);
        $service->regenerateFromMessage(
            $conversation,
            $this->message->fresh(),
            auth()->user(),
            ['style' => $this->settings()->responseStyle()]
        );

        // Get the new assistant response
        $assistantMessage = $conversation->messages()
            ->where('role', 'assistant')
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$assistantMessage) {
            return _Html('')->class('hidden');
        }

        // Return rendered content for JS to inject
        $renderer = new \Condoedge\Ai\Services\UI\SafeMarkdownRenderer();
        return _Html($renderer->render($assistantMessage->content))->class('prose prose-sm max-w-none');

    } catch (\Throwable $e) {
        \Log::error('Edit message regeneration failed: ' . $e->getMessage());
        return _Html(__('ai.chat.error-message'))->class('text-red-500 text-sm');
    }
}
```

**Step 2: Add `deleteMessageWithAnimation()` method**

Add after `updateMessageAndGetResponse()`:

```php
/**
 * Delete message - just performs the delete, JS handles animation
 */
public function deleteMessageWithAnimation()
{
    if (!$this->message) {
        return _Html('')->class('hidden');
    }

    // Delete this message and all subsequent messages
    $conversation = $this->message->conversation;
    $conversation->messages()
        ->where('id', '>=', $this->message->id)
        ->delete();

    return _Html('deleted')->class('hidden');
}
```

---

### Task 3: Update modal buttons to use JS integration pattern

**Files:**
- Modify: `src/Kompo/Modals/EditMessageModal.php`

**Step 1: Replace the Save & Regenerate button**

Find lines 94-103 and replace with:

```php
_Button(__('ai.edit.save-regenerate'))->icon('arrow-path')
    ->class('px-4 py-2 text-white rounded-xl shadow-lg transition-all ' . $this->theme()->primaryGradient())
    // 1. Close modal and start JS animations immediately
    ->run('() => {
        const messageId = ' . $this->messageId . ';
        const newContent = document.querySelector("[name=content]").value.trim();
        if (!newContent) return;

        // Close the modal
        window.closeModal && window.closeModal();

        // Update the user message content in place
        updateMessageContent(messageId, newContent);

        // Remove all messages after this one, then show typing
        removeMessagesAfter(messageId).then(() => {
            showTypingAfterMessage(messageId);
        });
    }')
    // 2. Send to server, response goes to staging panel
    ->selfPost('updateMessageAndGetResponse')->withAllFormValues()
        ->inPanel('temp-message-staging')
        ->run('() => {
            setTimeout(() => {
                processServerResponse();
            }, 100);
        }')
        ->onError->run('clearMessagePlaceholders'),
```

**Step 2: Replace the Delete button**

Find lines 90-93 and replace with:

```php
_Link(__('ai.edit.delete-message'))->icon('trash')
    ->class('px-4 py-2 text-red-500 hover:text-red-700 hover:bg-red-50 rounded-xl transition-all')
    // 1. Close modal and animate removal
    ->run('() => {
        const messageId = ' . $this->messageId . ';

        // Close the modal
        window.closeModal && window.closeModal();

        // Remove the message and all after it with animation
        removeMessageAndAfter(messageId);
    }')
    // 2. Tell server to delete (no response needed)
    ->selfPost('deleteMessageWithAnimation')
        ->inPanel('temp-message-staging'),
```

**Step 3: Verify PHP syntax**

Run: `php -l src/Kompo/Modals/EditMessageModal.php`
Expected: `No syntax errors detected`

---

### Task 4: Test the complete flow

**Manual testing steps:**

1. **Test Edit + Regenerate:**
   - Open chat with some messages
   - Click edit on a user message
   - Change the text
   - Click "Save & Regenerate"
   - Verify: Modal closes immediately
   - Verify: User message content updates in place (with pulse)
   - Verify: Messages after it fade out
   - Verify: Typing indicator appears
   - Verify: AI response replaces typing indicator

2. **Test Delete:**
   - Open chat with some messages
   - Click edit on a user message
   - Click "Delete"
   - Verify: Modal closes immediately
   - Verify: Message and all after it fade out

3. **Test Error handling:**
   - Disconnect network
   - Try edit - should show error in typing indicator area

---

## Summary

The key changes:
1. **JS methods** for DOM manipulation with animations
2. **New server endpoints** that return HTML to staging panel
3. **Modal buttons** use `run()` for immediate JS + `selfPost().inPanel()` for server response
4. **Same pattern as ChatMessageForm** - staging panel receives response, `processServerResponse()` injects it
