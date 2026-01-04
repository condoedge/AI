# Edit Message & Regenerate Button Refactor Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Refactor EditMessageModal and MessagesQuery.regenerate() to follow the same verified patterns used in ChatMessageForm, ensuring proper message cleanup and AI response regeneration.

**Architecture:** EditMessageModal's updateMessage() and MessagesQuery.regenerate() both need to: (1) delete appropriate messages, (2) trigger AI regeneration using SendMessageService, and (3) refresh the UI with proper placeholder injection pattern.

**Tech Stack:** PHP 8.2, Kompo components, SendMessageService, AiChatServiceInterface

---

## Critical Issues Found

### Issue 1: EditMessageModal.updateMessage() - BROKEN REGENERATION
**Location:** `src/Kompo/Modals/EditMessageModal.php:110-129`

**Problem:** After editing a message, the code:
1. Updates the message content
2. Deletes assistant messages after this one
3. **DOES NOT regenerate an AI response**

The comment says "This is handled by the panel refresh" but:
- The modal uses `->closeModal()` which just closes the modal
- There is no `->refresh()` call to refresh MessagesQuery
- Even if there was a refresh, a refresh only re-renders existing messages - it does NOT trigger AI generation

**Evidence:**
```php
// Line 127-128: Comment claims refresh handles regeneration - FALSE
// Trigger regeneration by calling the chat form's sendMessage
// This is handled by the panel refresh
```

### Issue 2: MessagesQuery.regenerate() - DUPLICATED USER MESSAGE
**Location:** `src/Kompo/Modals/MessagesQuery.php:509-549`

**Problem:** The regenerate method:
1. Finds the user message before the assistant message
2. Deletes the old assistant message
3. Calls `askWithConversation()` which stores a NEW user message
4. Tries to delete the duplicate user message (hacky workaround)

This is wrong because `AiChatService.askWithConversation()` at line 101 does:
```php
$conversation->addMessage('user', $question, [...]);
```

So regenerate creates a duplicate user message, then tries to delete it. This is:
- Wasteful (creates then deletes)
- Fragile (race conditions possible)
- Wrong pattern (verified ChatMessageForm uses SendMessageService properly)

### Issue 3: EditMessageModal - NO UI FEEDBACK
**Location:** `src/Kompo/Modals/EditMessageModal.php:91-94`

**Problem:** No loading state, no typing indicator, no placeholder injection like ChatMessageForm does.

### Issue 4: Both components bypass SendMessageService
**Location:** Both `EditMessageModal` and `MessagesQuery.regenerate()`

**Problem:** They call `AiChatServiceInterface::askWithConversation()` directly instead of using `SendMessageService::sendMessage()`.

The verified `ChatMessageForm` uses `SendMessageService` which:
- Validates messages
- Provides consistent error handling
- Is the documented entry point

---

## Refactoring Plan

### Task 1: Create RegenerateMessageService

**Files:**
- Create: `src/Services/Chat/RegenerateMessageService.php`
- Test: `tests/Unit/Services/Chat/RegenerateMessageServiceTest.php`

**Step 1: Write the failing test**

```php
<?php

namespace Condoedge\Ai\Tests\Unit\Services\Chat;

use Condoedge\Ai\Services\Chat\RegenerateMessageService;
use Condoedge\Ai\Services\Chat\AiChatService;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Models\AiMessage;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

class RegenerateMessageServiceTest extends TestCase
{
    public function test_regenerate_deletes_messages_after_target()
    {
        // Create conversation with messages
        $conversation = AiConversation::factory()->create(['user_id' => $this->user->id]);
        $userMsg = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'What is 2+2?',
            'created_at' => now()->subMinutes(3),
        ]);
        $assistantMsg1 = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'It is 4.',
            'created_at' => now()->subMinutes(2),
        ]);
        $userMsg2 = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Thanks!',
            'created_at' => now()->subMinute(),
        ]);
        $assistantMsg2 = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'You are welcome.',
            'created_at' => now(),
        ]);

        // Mock AiChatService to avoid actual AI calls
        $mockChatService = Mockery::mock(AiChatService::class);
        $mockChatService->shouldReceive('regenerateResponse')
            ->once()
            ->with($conversation, $userMsg, Mockery::any())
            ->andReturn(['answer' => 'Regenerated: 4']);

        $service = new RegenerateMessageService($mockChatService);

        $result = $service->regenerateFromMessage($conversation, $userMsg, $this->user);

        // Verify messages after userMsg are deleted
        $this->assertDatabaseMissing('ai_messages', ['id' => $assistantMsg1->id]);
        $this->assertDatabaseMissing('ai_messages', ['id' => $userMsg2->id]);
        $this->assertDatabaseMissing('ai_messages', ['id' => $assistantMsg2->id]);

        // Original user message still exists
        $this->assertDatabaseHas('ai_messages', ['id' => $userMsg->id]);
    }

    public function test_regenerate_does_not_duplicate_user_message()
    {
        $conversation = AiConversation::factory()->create(['user_id' => $this->user->id]);
        $userMsg = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Hello',
            'created_at' => now()->subMinute(),
        ]);
        $assistantMsg = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Hi there!',
            'created_at' => now(),
        ]);

        $mockChatService = Mockery::mock(AiChatService::class);
        $mockChatService->shouldReceive('regenerateResponse')
            ->once()
            ->andReturn(['answer' => 'Hello again!']);

        $service = new RegenerateMessageService($mockChatService);
        $service->regenerateFromMessage($conversation, $userMsg, $this->user);

        // Should have exactly 2 messages: original user + new assistant
        $this->assertEquals(2, $conversation->messages()->count());
        $this->assertEquals(1, $conversation->messages()->where('role', 'user')->count());
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Services/Chat/RegenerateMessageServiceTest.php -v`
Expected: FAIL with "Class 'RegenerateMessageService' not found"

**Step 3: Write the service**

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Chat;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Models\AiMessage;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;

/**
 * Service for regenerating AI responses from a specific message.
 *
 * Unlike askWithConversation(), this service:
 * 1. Deletes all messages AFTER the target user message
 * 2. Does NOT create a duplicate user message
 * 3. Only generates a new assistant response
 */
class RegenerateMessageService
{
    public function __construct(
        private readonly AiChatService $chatService
    ) {}

    /**
     * Regenerate response from a user message.
     *
     * @param AiConversation $conversation The conversation
     * @param AiMessage $userMessage The user message to regenerate from
     * @param Authenticatable|null $user The user for file access authorization
     * @param array $options Additional options (style, etc.)
     * @return array The regenerated response
     */
    public function regenerateFromMessage(
        AiConversation $conversation,
        AiMessage $userMessage,
        ?Authenticatable $user = null,
        array $options = []
    ): array {
        if ($userMessage->role !== 'user') {
            throw new \InvalidArgumentException('Can only regenerate from user messages');
        }

        // Delete all messages after this user message
        $conversation->messages()
            ->where('created_at', '>', $userMessage->created_at)
            ->delete();

        // Regenerate without creating duplicate user message
        return $this->chatService->regenerateResponse(
            $conversation,
            $userMessage,
            array_merge($options, ['user' => $user])
        );
    }

    /**
     * Regenerate from an assistant message (finds preceding user message).
     */
    public function regenerateFromAssistantMessage(
        AiConversation $conversation,
        AiMessage $assistantMessage,
        ?Authenticatable $user = null,
        array $options = []
    ): array {
        if ($assistantMessage->role !== 'assistant') {
            throw new \InvalidArgumentException('Expected assistant message');
        }

        // Find the user message before this assistant message
        $userMessage = $conversation->messages()
            ->where('created_at', '<', $assistantMessage->created_at)
            ->where('role', 'user')
            ->orderByDesc('created_at')
            ->first();

        if (!$userMessage) {
            throw new \RuntimeException('No user message found before assistant message');
        }

        return $this->regenerateFromMessage($conversation, $userMessage, $user, $options);
    }
}
```

**Step 4: Add regenerateResponse to AiChatService**

Modify: `src/Services/Chat/AiChatService.php`

Add this method after `askWithConversation()`:

```php
/**
 * Regenerate response for an existing user message.
 *
 * Unlike askWithConversation(), this does NOT store the user message again.
 * It only generates and stores a new assistant response.
 *
 * @param AiConversation $conversation The conversation
 * @param AiMessage $userMessage The existing user message to respond to
 * @param array $options Options including user, style
 * @return array The response
 */
public function regenerateResponse(
    AiConversation $conversation,
    AiMessage $userMessage,
    array $options = []
): array {
    $options = array_merge($this->config, $options);
    $question = $userMessage->content;

    try {
        $schema = $this->getSchema();
        $contextManager = $this->getContextManager();

        // Rebuild context from conversation state
        $conversationContext = $contextManager->buildPromptContext($conversation);

        // Call AI (user message already exists, don't add it again)
        $aiResponse = AI::answerQuestion($question, [
            'style' => $options['style'] ?? 'friendly',
            'conversation_id' => $conversation->id,
            'conversation_context' => $conversationContext,
            'user' => $options['user'] ?? null,
        ]);

        $answerText = $aiResponse['answer'] ?? '';
        $cypherQuery = $aiResponse['cypher'] ?? null;
        $queryData = $aiResponse['data'] ?? [];

        // Record response for context tracking
        $contextManager->recordResponse(
            $conversation,
            $answerText,
            $cypherQuery ?? '',
            ['data' => $queryData]
        );

        // Store ONLY the assistant message (user message already exists)
        $conversation->addMessage('assistant', $answerText, [
            'response_data' => $queryData,
            'cypher_query' => $cypherQuery,
            'suggestions' => $aiResponse['suggestions'] ?? [],
            'sources' => $aiResponse['referenced_files'] ?? [],
            'regenerated' => true,
        ]);

        return [
            'answer' => $answerText,
            'data' => $queryData,
            'suggestions' => $aiResponse['suggestions'] ?? [],
            'sources' => $aiResponse['referenced_files'] ?? [],
            'cypher_query' => $cypherQuery,
        ];

    } catch (\Exception $e) {
        Log::error('AI regenerate response error', [
            'user_message_id' => $userMessage->id,
            'conversation_id' => $conversation->id,
            'error' => $e->getMessage(),
        ]);

        $errorMessage = $this->getUserFriendlyError($e);

        $conversation->addMessage('assistant', $errorMessage, [
            'error' => true,
            'regenerated' => true,
        ]);

        return [
            'answer' => $errorMessage,
            'data' => [],
            'suggestions' => [],
            'sources' => [],
            'cypher_query' => null,
        ];
    }
}
```

**Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Services/Chat/RegenerateMessageServiceTest.php -v`
Expected: PASS

---

### Task 2: Refactor MessagesQuery.regenerate()

**Files:**
- Modify: `src/Kompo/MessagesQuery.php:509-549`
- Test: `tests/Unit/Kompo/MessagesQueryTest.php` (add test)

**Step 1: Write the failing test**

```php
// Add to existing test file or create new one
public function test_regenerate_uses_regenerate_service()
{
    $this->actingAs($this->user);

    $conversation = AiConversation::factory()->create(['user_id' => $this->user->id]);
    $userMsg = $conversation->messages()->create([
        'role' => 'user',
        'content' => 'Test question',
        'created_at' => now()->subMinute(),
    ]);
    $assistantMsg = $conversation->messages()->create([
        'role' => 'assistant',
        'content' => 'Test answer',
        'created_at' => now(),
    ]);

    // Mock the service
    $mockService = Mockery::mock(RegenerateMessageService::class);
    $mockService->shouldReceive('regenerateFromAssistantMessage')
        ->once()
        ->with(
            Mockery::on(fn($c) => $c->id === $conversation->id),
            Mockery::on(fn($m) => $m->id === $assistantMsg->id),
            Mockery::on(fn($u) => $u->id === $this->user->id),
            Mockery::any()
        )
        ->andReturn(['answer' => 'New answer']);

    $this->app->instance(RegenerateMessageService::class, $mockService);

    $query = new MessagesQuery(null, ['conversation_id' => $conversation->id]);
    $query->regenerate($assistantMsg->id);

    // The service was called (verified by Mockery shouldReceive)
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Kompo/MessagesQueryTest.php::test_regenerate_uses_regenerate_service -v`
Expected: FAIL (current code doesn't use the service)

**Step 3: Refactor regenerate() method**

Replace `src/Kompo/MessagesQuery.php` lines 509-549 with:

```php
public function regenerate($id)
{
    if (!$this->conversation) {
        return;
    }

    $message = $this->conversation->messages()->find($id);
    if (!$message || $message->role !== 'assistant') {
        return;
    }

    try {
        $service = app(RegenerateMessageService::class);
        $service->regenerateFromAssistantMessage(
            $this->conversation,
            $message,
            auth()->user(),
            ['style' => $this->settings()->responseStyle()]
        );
    } catch (\Throwable $e) {
        \Log::error('Regenerate failed: ' . $e->getMessage());
    }
}
```

**Step 4: Add import at top of file**

Add to imports:
```php
use Condoedge\Ai\Services\Chat\RegenerateMessageService;
```

**Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Kompo/MessagesQueryTest.php -v`
Expected: PASS

---

### Task 3: Fix EditMessageModal.updateMessage()

**Files:**
- Modify: `src/Kompo/Modals/EditMessageModal.php`
- Test: `tests/Unit/Kompo/Modals/EditMessageModalTest.php`

**Step 1: Write the failing test**

```php
<?php

namespace Condoedge\Ai\Tests\Unit\Kompo\Modals;

use Condoedge\Ai\Kompo\Modals\EditMessageModal;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Services\Chat\RegenerateMessageService;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

class EditMessageModalTest extends TestCase
{
    public function test_update_message_triggers_regeneration()
    {
        $this->actingAs($this->user);

        $conversation = AiConversation::factory()->create(['user_id' => $this->user->id]);
        $userMsg = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Original question',
            'created_at' => now()->subMinute(),
        ]);
        $assistantMsg = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Original answer',
            'created_at' => now(),
        ]);

        // Mock the regenerate service
        $mockService = Mockery::mock(RegenerateMessageService::class);
        $mockService->shouldReceive('regenerateFromMessage')
            ->once()
            ->with(
                Mockery::on(fn($c) => $c->id === $conversation->id),
                Mockery::on(fn($m) => $m->id === $userMsg->id && $m->content === 'Edited question'),
                Mockery::any(),
                Mockery::any()
            )
            ->andReturn(['answer' => 'New answer']);

        $this->app->instance(RegenerateMessageService::class, $mockService);

        // Simulate request
        request()->merge(['content' => 'Edited question']);

        $modal = new EditMessageModal(null, [
            'message_id' => $userMsg->id,
            'conversation_id' => $conversation->id,
        ]);

        $modal->updateMessage();

        // Verify the message was updated
        $this->assertEquals('Edited question', $userMsg->fresh()->content);

        // Service was called (verified by Mockery)
    }

    public function test_update_message_deletes_subsequent_messages()
    {
        $this->actingAs($this->user);

        $conversation = AiConversation::factory()->create(['user_id' => $this->user->id]);
        $userMsg = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Q1',
            'created_at' => now()->subMinutes(3),
        ]);
        $assistantMsg1 = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'A1',
            'created_at' => now()->subMinutes(2),
        ]);
        $userMsg2 = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Q2',
            'created_at' => now()->subMinute(),
        ]);

        $mockService = Mockery::mock(RegenerateMessageService::class);
        $mockService->shouldReceive('regenerateFromMessage')->andReturn(['answer' => 'New']);
        $this->app->instance(RegenerateMessageService::class, $mockService);

        request()->merge(['content' => 'Edited Q1']);

        $modal = new EditMessageModal(null, [
            'message_id' => $userMsg->id,
            'conversation_id' => $conversation->id,
        ]);

        $modal->updateMessage();

        // Messages after edited one should be deleted
        $this->assertDatabaseMissing('ai_messages', ['id' => $assistantMsg1->id]);
        $this->assertDatabaseMissing('ai_messages', ['id' => $userMsg2->id]);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Kompo/Modals/EditMessageModalTest.php -v`
Expected: FAIL (current code doesn't call regenerate service)

**Step 3: Refactor updateMessage() method**

Replace the `updateMessage()` method in `EditMessageModal.php`:

```php
public function updateMessage()
{
    $content = trim(request('content') ?? '');

    if (empty($content) || !$this->message) {
        return;
    }

    // Update the message content
    $this->message->update(['content' => $content]);

    // Delete all messages after this one (will be regenerated)
    $conversation = $this->message->conversation;
    $conversation->messages()
        ->where('created_at', '>', $this->message->created_at)
        ->delete();

    // Regenerate AI response using the service
    try {
        $service = app(RegenerateMessageService::class);
        $service->regenerateFromMessage(
            $conversation,
            $this->message->fresh(), // Fresh to get updated content
            auth()->user(),
            ['style' => $this->settings()->responseStyle()]
        );
    } catch (\Throwable $e) {
        \Log::error('Edit message regeneration failed: ' . $e->getMessage());
    }
}
```

**Step 4: Add imports and trait**

Add at top of file:
```php
use Condoedge\Ai\Services\Chat\RegenerateMessageService;
use Condoedge\Ai\Kompo\Traits\HasChatSettings;
```

Add trait to class if not present:
```php
class EditMessageModal extends Modal
{
    use HasChatTheme, HasChatSettings;
```

**Step 5: Add refresh to modal actions**

Modify `modalActions()` method - the Save button should refresh the messages:

```php
_Button(__('ai.edit.save-regenerate'))->icon('arrow-path')
    ->class('px-4 py-2 text-white rounded-xl shadow-lg transition-all ' . $this->theme()->primaryGradient())
    ->selfPost('updateMessage')
    ->closeModal()
    ->refresh(MessagesQuery::ID),
```

Also add the import:
```php
use Condoedge\Ai\Kompo\MessagesQuery;
```

**Step 6: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Kompo/Modals/EditMessageModalTest.php -v`
Expected: PASS

---

### Task 4: Add typing indicator to regenerate actions

**Files:**
- Modify: `src/Kompo/MessagesQuery.php` (messageActionBar method)
- Modify: `src/Kompo/Modals/EditMessageModal.php` (modalActions method)

**Step 1: Update regenerate button in MessagesQuery**

In `messageActionBar()` method, update the regenerate button to show loading state:

```php
// Regenerate button
if ($this->settings()->enableRegenerate()) {
    $actions[] = _Link()->icon(_Sax('refresh', 16))
        ->class('p-1.5 rounded-lg text-gray-400 ' . $this->theme()->linkHover() . ' transition-all')
        ->balloon(__('ai.messages.regenerate'), 'up')
        ->selfPost('regenerate', ['id' => $message->id])
        ->run('() => {
            // Show regenerating indicator on this message
            const bubble = event.target.closest("[data-message-id]");
            if (bubble) {
                const content = bubble.querySelector(".prose");
                if (content) {
                    content.innerHTML = "<div class=\"flex items-center gap-2 text-gray-400\"><svg class=\"animate-spin h-4 w-4\" xmlns=\"http://www.w3.org/2000/svg\" fill=\"none\" viewBox=\"0 0 24 24\"><circle class=\"opacity-25\" cx=\"12\" cy=\"12\" r=\"10\" stroke=\"currentColor\" stroke-width=\"4\"></circle><path class=\"opacity-75\" fill=\"currentColor\" d=\"M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z\"></path></svg><span>' . __('ai.messages.regenerating') . '</span></div>";
                }
            }
        }')
        ->refresh();
}
```

**Step 2: Update save button in EditMessageModal**

In `modalActions()`, add loading state to save button:

```php
_Button(__('ai.edit.save-regenerate'))->icon('arrow-path')
    ->class('px-4 py-2 text-white rounded-xl shadow-lg transition-all ' . $this->theme()->primaryGradient())
    ->selfPost('updateMessage')
    ->run('() => {
        // Disable button and show loading
        event.target.disabled = true;
        event.target.innerHTML = "<svg class=\"animate-spin h-4 w-4 mr-2\" xmlns=\"http://www.w3.org/2000/svg\" fill=\"none\" viewBox=\"0 0 24 24\"><circle class=\"opacity-25\" cx=\"12\" cy=\"12\" r=\"10\" stroke=\"currentColor\" stroke-width=\"4\"></circle><path class=\"opacity-75\" fill=\"currentColor\" d=\"M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z\"></path></svg>' . __('ai.edit.regenerating') . '";
    }')
    ->closeModal()
    ->refresh(MessagesQuery::ID),
```

**Step 3: Add translation keys**

Check if these keys exist in `resources/lang/en.json`, add if missing:
```json
{
    "ai.messages.regenerating": "Regenerating...",
    "ai.edit.regenerating": "Regenerating..."
}
```

---

### Task 5: Register RegenerateMessageService in ServiceProvider

**Files:**
- Modify: `src/AiServiceProvider.php`

**Step 1: Add binding**

In the `registerServices()` method, add:

```php
$this->app->singleton(RegenerateMessageService::class, function ($app) {
    return new RegenerateMessageService(
        $app->make(AiChatService::class)
    );
});
```

**Step 2: Add import**

```php
use Condoedge\Ai\Services\Chat\RegenerateMessageService;
```

---

### Task 6: Run full test suite and verify

**Step 1: Run all tests**

```bash
vendor/bin/phpunit --filter "Regenerate|EditMessage" -v
```

Expected: All tests pass

**Step 2: Run full suite**

```bash
vendor/bin/phpunit
```

Expected: No regressions

**Step 3: Manual verification checklist**

- [ ] Click regenerate button on an assistant message -> new response appears
- [ ] Edit a user message -> subsequent messages deleted + new response generated
- [ ] Delete a message -> all subsequent messages deleted
- [ ] Loading indicators appear during regeneration
- [ ] No duplicate user messages created

---

## Summary of Changes

| Component | Before | After |
|-----------|--------|-------|
| `EditMessageModal.updateMessage()` | Updates message, deletes subsequent, does NOT regenerate | Updates, deletes subsequent, regenerates via service |
| `MessagesQuery.regenerate()` | Creates duplicate user message then deletes it | Uses service, no duplicates |
| New `RegenerateMessageService` | N/A | Proper regeneration logic |
| New `AiChatService.regenerateResponse()` | N/A | Stores only assistant message |
| UI feedback | None | Loading spinners on regenerate |

**Key Architectural Fix:** Both edit and regenerate now use `RegenerateMessageService` which internally uses `AiChatService.regenerateResponse()` - a method that does NOT create a duplicate user message, only generates and stores the assistant response.
