# Conversation Context Refactor Recommendation

**Date:** 2025-12-30
**Based on:** conversation-context-deep-audit.md
**Approach:** Option B (Clean) + Option C (Enhanced)

---

## Recommendation Summary

**Recommended approach: Option B + C**

1. **Option B (Clean Architecture):** Update ChatMessageForm to call `askWithConversation()` directly, delete `askWithHistory()` and all dead code
2. **Option C (Enhanced Context):** Extend context system to store actual entity data (IDs, filters, result samples) not just labels

---

## Phase 1: Clean Up Dead Code

### Files to DELETE entirely

| File | Reason |
|------|--------|
| `src/Services/Chat/AmbiguityDetector.php` | Built but never integrated, not needed |
| `src/Domain/Contracts/Searchable.php` | Never implemented |

### Methods to DELETE

| File | Method | Reason |
|------|--------|--------|
| `AiChatService.php` | `askWithHistory()` | Replaced by `askWithConversation()` |
| `AiChatService.php` | `buildQuestionWithHistory()` | Only used by deleted method |
| `AiChatService.php` | `ask()` | Redundant with `askWithConversation()` |
| `AiChatService.php` | `getSuggestions()` | AI response includes suggestions |
| `AiChatService.php` | `getExampleQuestions()` | Panel uses settings directly |
| `AiChatMessage.php` | `system()` | Never used |
| `AiChatResponseData.php` | `list()` | Never used |
| `AiChatResponseData.php` | `metric()` | Never used |
| `AiChatResponseData.php` | `withActions()` | Never used |

### Traits to DELETE

| File | Reason |
|------|--------|
| `src/Kompo/Traits/HasTypingIndicator.php` | Included but never used |

### Update Interface

**`src/Services/Chat/AiChatServiceInterface.php`:**

```php
<?php

namespace Kompo\Ai\Services\Chat;

use Kompo\Ai\Models\AiConversation;

interface AiChatServiceInterface
{
    /**
     * Ask a question within a conversation context.
     *
     * @param string $question The user's question
     * @param AiConversation $conversation The conversation for context tracking
     * @param array $options Options including 'style'
     * @return array Response with 'answer', 'data', 'suggestions', 'sources', 'cypher_query'
     */
    public function askWithConversation(string $question, AiConversation $conversation, array $options = []): array;

    /**
     * Get the graph schema for context building.
     */
    public function getSchema(): array;
}
```

---

## Phase 2: Wire UI to askWithConversation()

### Update ChatMessageForm

**`src/Kompo/ChatMessageForm.php`:**

```php
// In sendMessage() method, replace:

// OLD:
$history = $this->conversation->getRecentMessages(10);
$response = app(AiChatServiceInterface::class)->askWithHistory(
    $message,
    $history,
    ['style' => $style]
);

// NEW:
$response = app(AiChatServiceInterface::class)->askWithConversation(
    $message,
    $this->conversation,
    ['style' => $style]
);

// Response handling - askWithConversation returns array:
$responseContent = $response['answer'] ?? 'I could not generate a response.';
$responseData = $response['data'] ?? [];
$suggestions = $response['suggestions'] ?? [];
$sources = $response['sources'] ?? [];
$cypherQuery = $response['cypher_query'] ?? null;
```

---

## Phase 3: Enhance Context Storage (Option C)

### Extend AiConversation Model

**Add to `src/Models/AiConversation.php`:**

```php
/**
 * Update context with entity data from query results.
 */
public function updateEntityContext(array $entityData): void
{
    $snapshot = $this->context_snapshot ?? [];

    $snapshot['focused_entity_data'] = $entityData;
    $snapshot['updated_at'] = now()->toIso8601String();

    $this->context_snapshot = $snapshot;
    $this->save();
}

/**
 * Get the focused entity's identifying filter.
 */
public function getFocusedEntityFilter(): ?string
{
    return $this->context_snapshot['focused_entity_filter'] ?? null;
}

/**
 * Get a sample of the last query results.
 */
public function getLastResultSample(): array
{
    return $this->context_snapshot['last_result_sample'] ?? [];
}

/**
 * Get previous query for reference.
 */
public function getPreviousCypherQuery(): ?string
{
    return $this->context_snapshot['last_cypher_query'] ?? null;
}
```

### Extend ConversationContextManager

**Update `src/Services/Context/ConversationContextManager.php`:**

```php
/**
 * Record response with enhanced entity data.
 */
public function recordResponse(
    AiConversation $conversation,
    string $answer,
    ?string $cypherQuery,
    array $queryResult
): void {
    $snapshot = $conversation->context_snapshot ?? [];

    // Extract entity filter from Cypher WHERE clause
    $entityFilter = $this->extractEntityFilter($cypherQuery);

    // Store result sample (first 3 results for context)
    $resultSample = array_slice($queryResult['data'] ?? [], 0, 3);

    // Update snapshot with enhanced data
    $snapshot['last_cypher_query'] = $cypherQuery;
    $snapshot['last_result_count'] = count($queryResult['data'] ?? []);
    $snapshot['last_result_sample'] = $resultSample;
    $snapshot['focused_entity_filter'] = $entityFilter;
    $snapshot['last_answer_summary'] = Str::limit($answer, 200);

    $conversation->updateContextSnapshot($snapshot);
}

/**
 * Extract WHERE clause conditions from Cypher query.
 */
protected function extractEntityFilter(?string $cypherQuery): ?string
{
    if (!$cypherQuery) {
        return null;
    }

    // Extract WHERE clause
    if (preg_match('/WHERE\s+(.+?)(?:RETURN|ORDER|LIMIT|$)/is', $cypherQuery, $matches)) {
        return trim($matches[1]);
    }

    return null;
}

/**
 * Build enhanced prompt context with entity data.
 */
public function buildPromptContext(AiConversation $conversation): array
{
    $snapshot = $conversation->context_snapshot ?? [];
    $recentMessages = $conversation->getRecentMessages(5);

    return [
        'focused_entity' => $snapshot['focused_entity'] ?? null,
        'focused_entity_filter' => $snapshot['focused_entity_filter'] ?? null,
        'last_result_sample' => $snapshot['last_result_sample'] ?? [],
        'last_result_count' => $snapshot['last_result_count'] ?? 0,
        'last_cypher_query' => $snapshot['last_cypher_query'] ?? null,
        'mentioned_entities' => $snapshot['mentioned_entities'] ?? [],
        'recent_exchanges' => $this->formatRecentExchanges($recentMessages),
    ];
}
```

### Update ConversationContextSection

**Update `src/Services/PromptSections/ConversationContextSection.php`:**

```php
public function render(string $question, array $context, array $options = []): string
{
    $conversationContext = $context['conversation_context'] ?? [];

    if (empty($conversationContext)) {
        return '';
    }

    $output = "## Conversation Context\n\n";

    // Focused entity with filter
    if (!empty($conversationContext['focused_entity'])) {
        $output .= "**Current Focus:** {$conversationContext['focused_entity']}\n";

        if (!empty($conversationContext['focused_entity_filter'])) {
            $output .= "**Active Filter:** `{$conversationContext['focused_entity_filter']}`\n";
        }
    }

    // Previous query reference
    if (!empty($conversationContext['last_cypher_query'])) {
        $output .= "\n**Previous Query:**\n```cypher\n{$conversationContext['last_cypher_query']}\n```\n";
        $output .= "Returned {$conversationContext['last_result_count']} results.\n";
    }

    // Result sample for reference
    if (!empty($conversationContext['last_result_sample'])) {
        $output .= "\n**Sample of Previous Results:**\n```json\n";
        $output .= json_encode($conversationContext['last_result_sample'], JSON_PRETTY_PRINT);
        $output .= "\n```\n";
    }

    // Recent exchanges
    if (!empty($conversationContext['recent_exchanges'])) {
        $output .= "\n**Recent Conversation:**\n";
        foreach ($conversationContext['recent_exchanges'] as $exchange) {
            $output .= "- User: {$exchange['question']}\n";
            $output .= "  Assistant: {$exchange['answer_summary']}\n";
        }
    }

    $output .= "\n**Instructions:** Use the above context to understand follow-up questions. ";
    $output .= "If user references 'those', 'them', 'the same', etc., use the previous results/filter.\n";

    return $output;
}
```

---

## Phase 4: Update AiChatService

**Refactored `src/Services/Chat/AiChatService.php`:**

```php
<?php

namespace Kompo\Ai\Services\Chat;

use Kompo\Ai\Facades\AI;
use Kompo\Ai\Models\AiConversation;
use Kompo\Ai\Services\Context\ConversationContextManager;

class AiChatService implements AiChatServiceInterface
{
    protected ?ConversationContextManager $contextManager = null;

    /**
     * Ask a question within a conversation context.
     */
    public function askWithConversation(
        string $question,
        AiConversation $conversation,
        array $options = []
    ): array {
        $schema = $this->getSchema();
        $contextManager = $this->getContextManager();

        // Process question through context system
        $contextResult = $contextManager->processQuestion($conversation, $question, $schema);

        // Build conversation context for prompt
        $conversationContext = $contextManager->buildPromptContext($conversation);

        // Enrich question with resolved references
        $enrichedQuestion = $contextResult['enriched_question'] ?? $question;

        // Call AI with full context
        $aiResponse = AI::answerQuestion($enrichedQuestion, [
            'style' => $options['style'] ?? 'friendly',
            'conversation_id' => $conversation->id,
            'conversation_context' => $conversationContext,
        ]);

        // Record response with enhanced context
        $contextManager->recordResponse(
            $conversation,
            $aiResponse['answer'] ?? '',
            $aiResponse['cypher_query'] ?? null,
            $aiResponse['data'] ?? []
        );

        // Store message in conversation
        $conversation->addMessage('assistant', $aiResponse['answer'] ?? '', [
            'response_data' => $aiResponse['data'] ?? null,
            'cypher_query' => $aiResponse['cypher_query'] ?? null,
            'suggestions' => $aiResponse['suggestions'] ?? [],
            'sources' => $aiResponse['sources'] ?? [],
        ]);

        return [
            'answer' => $aiResponse['answer'] ?? '',
            'data' => $aiResponse['data'] ?? [],
            'suggestions' => $aiResponse['suggestions'] ?? [],
            'sources' => $aiResponse['sources'] ?? [],
            'cypher_query' => $aiResponse['cypher_query'] ?? null,
        ];
    }

    /**
     * Get graph schema.
     */
    public function getSchema(): array
    {
        return AI::getSchema();
    }

    /**
     * Get context manager instance.
     */
    protected function getContextManager(): ConversationContextManager
    {
        if (!$this->contextManager) {
            $this->contextManager = app(ConversationContextManager::class);
        }
        return $this->contextManager;
    }
}
```

---

## Implementation Order

1. **Delete dead code** (files, methods, traits listed above)
2. **Update AiChatServiceInterface** with new contract
3. **Refactor AiChatService** to only have `askWithConversation()`
4. **Update ChatMessageForm** to call new method
5. **Extend AiConversation** with entity data methods
6. **Extend ConversationContextManager** with enhanced recording
7. **Update ConversationContextSection** with new format
8. **Run tests** and fix any breakage
9. **Delete unused test files** for removed code

---

## Expected Outcomes

After implementation:

1. **Multi-turn context works:** "Show customer John" → "What are his orders?" will correctly reference John's ID
2. **Previous results available:** The AI knows what the last query returned
3. **References resolved:** "those", "them", "the same" resolved to previous results
4. **Clean codebase:** No dead code, single clear path for chat flow
5. **Consistent return types:** Array response with documented structure

---

## Files Changed Summary

| Action | Files |
|--------|-------|
| DELETE | AmbiguityDetector.php, Searchable.php, HasTypingIndicator.php |
| MODIFY | AiChatService.php, AiChatServiceInterface.php, ChatMessageForm.php |
| MODIFY | ConversationContextManager.php, ConversationContextSection.php |
| MODIFY | AiConversation.php |
| DELETE METHODS | ~15 methods across various files |
