# Architectural Remediation Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fix security vulnerabilities, activate the dormant conversation context system, remove dead code, and improve AI response quality in the Kompo AI package.

**Architecture:** This remediation follows a phased approach: (1) Security fixes first to eliminate critical vulnerabilities, (2) Context system activation to enable multi-turn conversations, (3) Dead code removal for maintainability, (4) Code consolidation to reduce duplication.

**Tech Stack:** Laravel PHP, Neo4j (Cypher), Qdrant Vector Store, OpenAI/Anthropic APIs, Kompo UI Framework

**Source Documents:**
- `docs/audit/phase6-refactor-plan.md`
- `docs/audit/phase6-removal-plan.md`
- `docs/audit/phase6-ai-improvement-plan.md`
- `docs/audit/phase6-merge-plan.md`
- `docs/audit/conversation-context-refactor-recommendation.md`

---

## Phase 1: Security Sprint (Tasks 1-4)

Critical security vulnerabilities that must be fixed immediately.

---

### Task 1: Fix Cypher Injection in Template Generation

**Priority:** CRITICAL
**Risk:** User input flows directly into Cypher queries via template patterns

**Files:**
- Modify: `src/Services/QueryGenerator.php:129-134`
- Modify: `src/Services/Security/CypherSanitizer.php` (verify/enhance)
- Test: `tests/Unit/Services/QueryGeneratorSecurityTest.php` (create)

**Step 1: Read current QueryGenerator template handling**

```bash
# Examine the template bypass section
```

Read file: `src/Services/QueryGenerator.php` lines 120-150

**Step 2: Write security test for label injection**

Create `tests/Unit/Services/QueryGeneratorSecurityTest.php`:

```php
<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use Condoedge\Ai\Services\QueryGenerator;
use Condoedge\Ai\Services\Security\CypherSanitizer;
use InvalidArgumentException;

class QueryGeneratorSecurityTest extends TestCase
{
    /** @test */
    public function it_sanitizes_labels_extracted_from_user_input(): void
    {
        // Attempt injection via label
        $maliciousInput = "Customer`) MATCH (n) DETACH DELETE n//";

        $sanitized = CypherSanitizer::escapeLabel($maliciousInput);

        // Should only contain alphanumeric and underscore
        $this->assertMatchesRegularExpression('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $sanitized);
    }

    /** @test */
    public function it_validates_labels_against_schema(): void
    {
        $generator = app(QueryGenerator::class);

        // Valid label should pass
        $this->assertTrue($generator->isValidLabel('Customer'));

        // Invalid label should fail
        $this->assertFalse($generator->isValidLabel('NonExistentLabel'));
    }

    /** @test */
    public function it_rejects_unknown_labels_in_template_queries(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown label');

        $generator = app(QueryGenerator::class);
        $generator->generateFromTemplate('Show all MaliciousLabel');
    }
}
```

**Step 3: Run test to verify it fails**

```bash
php vendor/bin/phpunit tests/Unit/Services/QueryGeneratorSecurityTest.php -v
```

Expected: FAIL (methods don't exist yet)

**Step 4: Enhance CypherSanitizer with label escaping**

Verify `src/Services/Security/CypherSanitizer.php` has `escapeLabel()`:

```php
/**
 * Escape a label for safe use in Cypher queries.
 * Labels can only contain alphanumeric characters and underscores.
 */
public static function escapeLabel(string $label): string
{
    // Remove any characters that aren't alphanumeric or underscore
    $sanitized = preg_replace('/[^a-zA-Z0-9_]/', '', $label);

    // Ensure it starts with a letter or underscore
    if (empty($sanitized) || !preg_match('/^[a-zA-Z_]/', $sanitized)) {
        $sanitized = 'Invalid_' . $sanitized;
    }

    return $sanitized;
}
```

**Step 5: Add label validation to QueryGenerator**

In `src/Services/QueryGenerator.php`, add schema validation:

```php
/**
 * Check if a label exists in the graph schema.
 */
public function isValidLabel(string $label): bool
{
    $schema = $this->graphStore->getSchema();
    $validLabels = $schema['labels'] ?? [];

    return in_array($label, $validLabels, true);
}

/**
 * Generate query from template with security validation.
 */
public function generateFromTemplate(string $question): ?array
{
    // Extract label from question
    $label = $this->extractLabelFromQuestion($question);

    if ($label) {
        // SECURITY: Validate label exists in schema
        if (!$this->isValidLabel($label)) {
            throw new \InvalidArgumentException("Unknown label: {$label}");
        }

        // SECURITY: Sanitize label before use
        $safeLabel = CypherSanitizer::escapeLabel($label);

        // Build query with safe label
        $cypher = "MATCH (n:{$safeLabel}) RETURN n LIMIT 100";

        return [
            'cypher' => $cypher,
            'source' => 'template',
            'confidence' => 0.9,
        ];
    }

    return null;
}
```

**Step 6: Run tests to verify they pass**

```bash
php vendor/bin/phpunit tests/Unit/Services/QueryGeneratorSecurityTest.php -v
```

Expected: PASS

**Step 7: Commit**

```bash
git add src/Services/QueryGenerator.php src/Services/Security/CypherSanitizer.php tests/Unit/Services/QueryGeneratorSecurityTest.php
git commit -m "fix(security): add Cypher injection protection to template generation

- Add CypherSanitizer::escapeLabel() for safe label handling
- Add QueryGenerator::isValidLabel() to validate against schema
- Reject unknown labels before query generation
- Add security regression tests

Fixes: S1 from phase6-refactor-plan.md"
```

---

### Task 2: Fix Missing User Authentication in File Context

**Priority:** CRITICAL
**Risk:** File access security bypassed when user not passed

**Files:**
- Modify: `src/Kompo/ChatMessageForm.php`
- Modify: `src/Services/Context/FileContextProvider.php`
- Test: `tests/Unit/Services/Context/FileContextProviderSecurityTest.php` (create)

**Step 1: Read current ChatMessageForm sendMessage method**

Read file: `src/Kompo/ChatMessageForm.php` - find the sendMessage method

**Step 2: Write security test for user requirement**

Create `tests/Unit/Services/Context/FileContextProviderSecurityTest.php`:

```php
<?php

namespace Tests\Unit\Services\Context;

use Tests\TestCase;
use Condoedge\Ai\Services\Context\FileContextProvider;
use RuntimeException;

class FileContextProviderSecurityTest extends TestCase
{
    /** @test */
    public function it_throws_when_security_enabled_and_user_missing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('User required for file context retrieval');

        $provider = app(FileContextProvider::class);

        // Mock shouldEnforceSecurity to return true
        $provider->getFileContext('test question', null);
    }

    /** @test */
    public function it_allows_retrieval_when_user_provided(): void
    {
        $provider = app(FileContextProvider::class);
        $user = $this->createMockUser();

        // Should not throw
        $result = $provider->getFileContext('test question', $user);

        $this->assertIsArray($result);
    }
}
```

**Step 3: Run test to verify it fails**

```bash
php vendor/bin/phpunit tests/Unit/Services/Context/FileContextProviderSecurityTest.php -v
```

Expected: FAIL (security check not implemented)

**Step 4: Add user requirement to FileContextProvider**

In `src/Services/Context/FileContextProvider.php`:

```php
public function getFileContext(string $question, mixed $user): array
{
    // SECURITY: Require user when security is enabled
    if ($this->accessResolver->shouldEnforceSecurity() && !$user) {
        throw new \RuntimeException('User required for file context retrieval');
    }

    // ... existing logic
}
```

**Step 5: Update ChatMessageForm to pass user**

In `src/Kompo/ChatMessageForm.php`, update the sendMessage method:

```php
// When calling AI service, include the authenticated user
$response = app(AiChatServiceInterface::class)->askWithConversation(
    $message,
    $this->conversation,
    [
        'style' => $style,
        'user' => auth()->user(), // SECURITY: Pass authenticated user
    ]
);
```

**Step 6: Run tests to verify they pass**

```bash
php vendor/bin/phpunit tests/Unit/Services/Context/FileContextProviderSecurityTest.php -v
```

Expected: PASS

**Step 7: Commit**

```bash
git add src/Kompo/ChatMessageForm.php src/Services/Context/FileContextProvider.php tests/Unit/Services/Context/FileContextProviderSecurityTest.php
git commit -m "fix(security): require user authentication for file context

- Add user requirement check in FileContextProvider
- Pass auth()->user() from ChatMessageForm to AI service
- Add security regression tests

Fixes: S2 from phase6-refactor-plan.md"
```

---

### Task 3: Fix Neo4j Parameter Binding

**Priority:** HIGH
**Risk:** String interpolation in Neo4j queries allows injection

**Files:**
- Modify: `src/GraphStore/Neo4jStore.php`
- Test: `tests/Unit/GraphStore/Neo4jStoreSecurityTest.php` (create)

**Step 1: Read current Neo4jStore implementation**

Read file: `src/GraphStore/Neo4jStore.php` - find arrayToCypherProps and related methods

**Step 2: Write test for parameter binding**

Create `tests/Unit/GraphStore/Neo4jStoreSecurityTest.php`:

```php
<?php

namespace Tests\Unit\GraphStore;

use Tests\TestCase;
use Condoedge\Ai\GraphStore\Neo4jStore;

class Neo4jStoreSecurityTest extends TestCase
{
    /** @test */
    public function it_uses_parameter_binding_for_node_creation(): void
    {
        $store = $this->getMockBuilder(Neo4jStore::class)
            ->onlyMethods(['query'])
            ->getMock();

        $store->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('$properties'),
                $this->callback(function ($params) {
                    return isset($params['properties']);
                })
            );

        $store->createNode('TestLabel', ['name' => "Test'; DROP DATABASE"]);
    }

    /** @test */
    public function it_uses_parameter_binding_for_relationship_creation(): void
    {
        $store = $this->getMockBuilder(Neo4jStore::class)
            ->onlyMethods(['query'])
            ->getMock();

        $store->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('$properties'),
                $this->callback(function ($params) {
                    return isset($params['properties']) && isset($params['fromId']) && isset($params['toId']);
                })
            );

        $store->createRelationship(1, 2, 'KNOWS', ['since' => '2024']);
    }
}
```

**Step 3: Run test to verify it fails**

```bash
php vendor/bin/phpunit tests/Unit/GraphStore/Neo4jStoreSecurityTest.php -v
```

Expected: FAIL (still using string interpolation)

**Step 4: Refactor Neo4jStore to use parameter binding**

In `src/GraphStore/Neo4jStore.php`:

```php
/**
 * Create a node with proper parameter binding.
 */
public function createNode(string $label, array $properties = []): int
{
    $safeLabel = CypherSanitizer::escapeLabel($label);

    // Use parameter binding instead of string interpolation
    $cypher = "CREATE (n:{$safeLabel}) SET n = \$properties RETURN id(n) as id";

    $result = $this->query($cypher, ['properties' => $properties]);

    return $result[0]['id'] ?? 0;
}

/**
 * Create a relationship with proper parameter binding.
 */
public function createRelationship(
    int $fromId,
    int $toId,
    string $type,
    array $properties = []
): int {
    $safeType = CypherSanitizer::escapeLabel($type);

    $cypher = "MATCH (a), (b) WHERE id(a) = \$fromId AND id(b) = \$toId
               CREATE (a)-[r:{$safeType}]->(b) SET r = \$properties
               RETURN id(r) as id";

    $result = $this->query($cypher, [
        'fromId' => $fromId,
        'toId' => $toId,
        'properties' => $properties,
    ]);

    return $result[0]['id'] ?? 0;
}
```

**Step 5: Remove or deprecate arrayToCypherProps**

```php
/**
 * @deprecated Use parameter binding instead
 */
protected function arrayToCypherProps(array $properties): string
{
    trigger_error('arrayToCypherProps is deprecated. Use parameter binding.', E_USER_DEPRECATED);
    // ... existing implementation for backward compatibility
}
```

**Step 6: Run tests to verify they pass**

```bash
php vendor/bin/phpunit tests/Unit/GraphStore/Neo4jStoreSecurityTest.php -v
```

Expected: PASS

**Step 7: Commit**

```bash
git add src/GraphStore/Neo4jStore.php tests/Unit/GraphStore/Neo4jStoreSecurityTest.php
git commit -m "fix(security): use parameter binding in Neo4j queries

- Refactor createNode to use parameter binding
- Refactor createRelationship to use parameter binding
- Deprecate arrayToCypherProps helper
- Add security regression tests

Fixes: S3 from phase6-refactor-plan.md"
```

---

### Task 4: Add Rate Limiting to Query Execution

**Priority:** HIGH
**Risk:** DoS via expensive query flooding

**Files:**
- Modify: `src/Services/QueryExecutor.php`
- Use existing: `src/Services/Resilience/RateLimiter.php`
- Test: `tests/Unit/Services/QueryExecutorRateLimitTest.php` (create)

**Step 1: Read current QueryExecutor and RateLimiter**

Read files:
- `src/Services/QueryExecutor.php`
- `src/Services/Resilience/RateLimiter.php`

**Step 2: Write test for rate limiting**

Create `tests/Unit/Services/QueryExecutorRateLimitTest.php`:

```php
<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use Condoedge\Ai\Services\QueryExecutor;
use Condoedge\Ai\Exceptions\QueryExecutionException;

class QueryExecutorRateLimitTest extends TestCase
{
    /** @test */
    public function it_enforces_rate_limit_on_query_execution(): void
    {
        $executor = app(QueryExecutor::class);

        // Execute queries rapidly
        for ($i = 0; $i < 10; $i++) {
            $executor->execute('MATCH (n) RETURN n LIMIT 1');
        }

        // 11th query should be rate limited
        $this->expectException(QueryExecutionException::class);
        $this->expectExceptionMessage('Rate limit exceeded');

        $executor->execute('MATCH (n) RETURN n LIMIT 1');
    }
}
```

**Step 3: Run test to verify it fails**

```bash
php vendor/bin/phpunit tests/Unit/Services/QueryExecutorRateLimitTest.php -v
```

Expected: FAIL (no rate limiting)

**Step 4: Add rate limiter to QueryExecutor**

In `src/Services/QueryExecutor.php`:

```php
use Condoedge\Ai\Services\Resilience\RateLimiter;
use Condoedge\Ai\Exceptions\QueryExecutionException;

class QueryExecutor
{
    protected RateLimiter $rateLimiter;

    public function __construct(
        protected GraphStoreInterface $graphStore,
        ?RateLimiter $rateLimiter = null
    ) {
        $this->rateLimiter = $rateLimiter ?? new RateLimiter(
            key: 'query_executor',
            maxAttempts: config('ai.rate_limits.queries_per_minute', 30),
            decayMinutes: 1
        );
    }

    public function execute(string $cypherQuery, array $parameters = [], array $options = []): array
    {
        // SECURITY: Enforce rate limiting
        if (!$this->rateLimiter->attempt()) {
            throw new QueryExecutionException(
                'Rate limit exceeded for query execution. Please wait before trying again.'
            );
        }

        // ... existing execution logic
    }
}
```

**Step 5: Add config for rate limits**

In `config/ai.php`, add:

```php
'rate_limits' => [
    'queries_per_minute' => env('AI_QUERIES_PER_MINUTE', 30),
    'embeddings_per_minute' => env('AI_EMBEDDINGS_PER_MINUTE', 60),
],
```

**Step 6: Run tests to verify they pass**

```bash
php vendor/bin/phpunit tests/Unit/Services/QueryExecutorRateLimitTest.php -v
```

Expected: PASS

**Step 7: Commit**

```bash
git add src/Services/QueryExecutor.php config/ai.php tests/Unit/Services/QueryExecutorRateLimitTest.php
git commit -m "fix(security): add rate limiting to query execution

- Inject RateLimiter into QueryExecutor
- Throw QueryExecutionException when limit exceeded
- Add configurable rate limit settings
- Add rate limiting tests

Fixes: S4 from phase6-refactor-plan.md"
```

---

## Phase 2: Context System Activation (Tasks 5-8)

Wire the dormant conversation context system to the UI.

---

### Task 5: Update AiChatServiceInterface

**Priority:** CRITICAL
**Impact:** Enables the entire context system

**Files:**
- Modify: `src/Services/Chat/AiChatServiceInterface.php`
- Reference: `docs/audit/conversation-context-refactor-recommendation.md`

**Step 1: Read current interface**

Read file: `src/Services/Chat/AiChatServiceInterface.php`

**Step 2: Update interface to clean contract**

Replace content of `src/Services/Chat/AiChatServiceInterface.php`:

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Chat;

use Condoedge\Ai\Models\AiConversation;

/**
 * Interface for AI chat service operations.
 *
 * This is the primary interface for chat interactions. All chat requests
 * should go through askWithConversation() to ensure proper context tracking.
 */
interface AiChatServiceInterface
{
    /**
     * Ask a question within a conversation context.
     *
     * This method:
     * - Processes the question through ConversationContextManager
     * - Resolves references ("those", "them") to previous results
     * - Extracts and tracks focused entities
     * - Calls the AI with full conversation context
     * - Records the response for future reference
     *
     * @param string $question The user's question
     * @param AiConversation $conversation The conversation for context tracking
     * @param array $options Options including:
     *   - 'style' => string (friendly|professional|concise)
     *   - 'user' => User model for file access authorization
     * @return array{
     *   answer: string,
     *   data: array,
     *   suggestions: array<string>,
     *   sources: array,
     *   cypher_query: ?string
     * }
     */
    public function askWithConversation(
        string $question,
        AiConversation $conversation,
        array $options = []
    ): array;

    /**
     * Get the graph schema for context building.
     *
     * @return array The schema with 'labels', 'relationships', 'properties'
     */
    public function getSchema(): array;

    /**
     * Check if the chat service is available.
     *
     * @return bool True if LLM and graph store are accessible
     */
    public function isAvailable(): bool;
}
```

**Step 3: Commit**

```bash
git add src/Services/Chat/AiChatServiceInterface.php
git commit -m "refactor(interface): simplify AiChatServiceInterface to clean contract

- Remove deprecated method signatures
- Add comprehensive documentation
- Define exact return type structure
- Prepare for askWithConversation() as primary entry point

Part of: A1 from phase6-refactor-plan.md"
```

---

### Task 6: Refactor AiChatService to Use Context System

**Priority:** CRITICAL
**Impact:** Activates entity tracking, reference resolution, context persistence

**Files:**
- Modify: `src/Services/Chat/AiChatService.php`
- Reference: `src/Services/Context/ConversationContextManager.php`

**Step 1: Read current AiChatService**

Read file: `src/Services/Chat/AiChatService.php`

**Step 2: Read ConversationContextManager**

Read file: `src/Services/Context/ConversationContextManager.php`

**Step 3: Refactor AiChatService**

Keep only essential methods, wire to context system:

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Chat;

use Condoedge\Ai\Facades\AI;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Services\Context\ConversationContextManager;
use Illuminate\Support\Facades\Log;

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
        // This extracts entities, resolves references, updates context
        $contextResult = $contextManager->processQuestion($conversation, $question, $schema);

        // Build conversation context for the prompt
        $conversationContext = $contextManager->buildPromptContext($conversation);

        // Use enriched question if references were resolved
        $enrichedQuestion = $contextResult['enriched_question'] ?? $question;

        Log::debug('AiChatService: Processing question with context', [
            'original' => $question,
            'enriched' => $enrichedQuestion,
            'focused_entity' => $conversationContext['focused_entity'] ?? null,
        ]);

        // Call AI with full context
        $aiResponse = AI::answerQuestion($enrichedQuestion, [
            'style' => $options['style'] ?? 'friendly',
            'conversation_id' => $conversation->id,
            'conversation_context' => $conversationContext,
            'user' => $options['user'] ?? null,
        ]);

        // Record response with enhanced context tracking
        $contextManager->recordResponse(
            $conversation,
            $aiResponse['answer'] ?? '',
            $aiResponse['cypher_query'] ?? null,
            ['data' => $aiResponse['data'] ?? []]
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
     * Check if service is available.
     */
    public function isAvailable(): bool
    {
        try {
            return !empty($this->getSchema());
        } catch (\Exception $e) {
            Log::warning('AiChatService: Service unavailable', ['error' => $e->getMessage()]);
            return false;
        }
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

**Step 4: Run existing tests**

```bash
php vendor/bin/phpunit tests/Unit/Services/Chat/AiChatServiceTest.php -v
```

Fix any failures.

**Step 5: Commit**

```bash
git add src/Services/Chat/AiChatService.php
git commit -m "refactor(chat): wire AiChatService to ConversationContextManager

- askWithConversation() now uses full context system
- Entity extraction active on each question
- Reference resolution active ('those', 'them', 'the same')
- Context snapshot updated after each response
- Remove dead methods (ask, askWithHistory, etc.)

Activates: Dormant context system
Fixes: A1 from phase6-refactor-plan.md"
```

---

### Task 7: Update ChatMessageForm to Use askWithConversation

**Priority:** CRITICAL
**Impact:** UI now uses full context system

**Files:**
- Modify: `src/Kompo/ChatMessageForm.php`

**Step 1: Read current ChatMessageForm**

Read file: `src/Kompo/ChatMessageForm.php`

**Step 2: Update sendMessage method**

Find the sendMessage method and update:

```php
/**
 * Send a message and get AI response.
 */
public function sendMessage()
{
    $message = trim($this->model->content ?? '');

    if (empty($message)) {
        return;
    }

    // Store user message
    $this->conversation->addMessage('user', $message);

    // Get chat style from settings
    $style = $this->settings()->responseStyle();

    try {
        // Call AI service with full conversation context
        $response = app(AiChatServiceInterface::class)->askWithConversation(
            $message,
            $this->conversation,
            [
                'style' => $style,
                'user' => auth()->user(),
            ]
        );

        // Response is array with documented structure
        $responseContent = $response['answer'] ?? 'I could not generate a response.';
        $responseData = $response['data'] ?? [];
        $suggestions = $response['suggestions'] ?? [];
        $sources = $response['sources'] ?? [];
        $cypherQuery = $response['cypher_query'] ?? null;

        // Note: Message already stored by AiChatService::askWithConversation()
        // Just need to handle UI response

        return $this->buildResponseElements($responseContent, $responseData, $suggestions, $sources);

    } catch (\Exception $e) {
        Log::error('Chat error', ['error' => $e->getMessage()]);

        return $this->buildErrorResponse($e->getMessage());
    }
}
```

**Step 3: Remove dead code references**

Remove any references to:
- `askWithHistory()`
- `getRecentMessages()` for history (no longer needed)
- `buildQuestionWithHistory()`

**Step 4: Test manually**

Open the chat UI and verify:
1. Messages are sent and responses received
2. Follow-up questions work ("What are their orders?" after "Show customers")
3. Reference resolution works ("those", "them")

**Step 5: Commit**

```bash
git add src/Kompo/ChatMessageForm.php
git commit -m "feat(ui): wire ChatMessageForm to askWithConversation()

- Use askWithConversation() instead of askWithHistory()
- Pass authenticated user for file access security
- Handle array response format
- Remove dead code references

MAJOR: Activates entire conversation context system
Fixes: A1 from phase6-refactor-plan.md"
```

---

### Task 8: Extend AiConversation with Enhanced Context Methods

**Priority:** HIGH
**Impact:** Better entity tracking for follow-up queries

**Files:**
- Modify: `src/Models/AiConversation.php`

**Step 1: Read current AiConversation**

Read file: `src/Models/AiConversation.php`

**Step 2: Add enhanced context methods**

Add to `src/Models/AiConversation.php`:

```php
/**
 * Update context with entity data from query results.
 */
public function updateEntityContext(array $entityData): void
{
    $snapshot = $this->context_snapshot ?? [];

    $snapshot['focused_entity_data'] = $entityData;
    $snapshot['updated_at'] = now()->toIso8601String();

    $this->update(['context_snapshot' => $snapshot]);
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

/**
 * Get the count of last query results.
 */
public function getLastResultCount(): int
{
    return $this->context_snapshot['last_result_count'] ?? 0;
}

/**
 * Update the full context snapshot.
 */
public function updateContextSnapshot(array $snapshot): void
{
    $this->update(['context_snapshot' => $snapshot]);
}
```

**Step 3: Commit**

```bash
git add src/Models/AiConversation.php
git commit -m "feat(model): add enhanced context methods to AiConversation

- Add updateEntityContext() for entity tracking
- Add getFocusedEntityFilter() for WHERE clause tracking
- Add getLastResultSample() for reference resolution
- Add getPreviousCypherQuery() for context building

Supports: Enhanced multi-turn conversations"
```

---

## Phase 3: Dead Code Removal (Tasks 9-13)

Remove unused code to reduce maintenance burden.

---

### Task 9: Delete Dead Subsystem Files

**Priority:** HIGH
**Files to DELETE:**

```
src/Services/Cache/QueryResultCache.php
src/Services/Analytics/QueryAnalytics.php
src/Services/Learning/QueryLearner.php
src/Console/Commands/LearnFromLogsCommand.php
src/Services/Chat/AmbiguityDetector.php
```

**Step 1: Verify no callers exist**

```bash
# Search for any usage
grep -r "QueryResultCache" src/ --include="*.php"
grep -r "QueryAnalytics" src/ --include="*.php"
grep -r "QueryLearner" src/ --include="*.php"
grep -r "LearnFromLogsCommand" src/ --include="*.php"
grep -r "AmbiguityDetector" src/ --include="*.php"
```

Expected: Only the file itself and possibly test files

**Step 2: Delete test files first**

```bash
rm tests/Unit/Services/Learning/QueryLearnerTest.php 2>/dev/null || true
rm tests/Unit/Services/Analytics/QueryAnalyticsTest.php 2>/dev/null || true
rm tests/Unit/Services/Cache/QueryResultCacheTest.php 2>/dev/null || true
rm tests/Unit/Services/Chat/AmbiguityDetectorTest.php 2>/dev/null || true
```

**Step 3: Delete source files**

```bash
rm src/Console/Commands/LearnFromLogsCommand.php
rm src/Services/Learning/QueryLearner.php
rm src/Services/Analytics/QueryAnalytics.php
rm src/Services/Cache/QueryResultCache.php
rm src/Services/Chat/AmbiguityDetector.php
```

**Step 4: Remove empty directories**

```bash
rmdir src/Services/Learning 2>/dev/null || true
rmdir src/Services/Analytics 2>/dev/null || true
rmdir src/Services/Cache 2>/dev/null || true
```

**Step 5: Run composer dump-autoload**

```bash
composer dump-autoload
```

**Step 6: Run tests to verify nothing broke**

```bash
php vendor/bin/phpunit --stop-on-failure
```

**Step 7: Commit**

```bash
git add -A
git commit -m "chore(cleanup): remove dead subsystem files

Deleted (never integrated):
- QueryResultCache (not registered in container)
- QueryAnalytics (depends on never-populated AiQueryLog)
- QueryLearner (AiQueryLog::logSuccess never called)
- LearnFromLogsCommand (calls dead QueryLearner)
- AmbiguityDetector (has tests but never called from UI)

Removed associated test files.

Fixes: A2 from phase6-refactor-plan.md"
```

---

### Task 10: Delete Dead Trait and Contract Files

**Priority:** HIGH

**Files to DELETE:**
```
src/Kompo/Traits/HasTypingIndicator.php
src/Domain/Contracts/Searchable.php
```

**Step 1: Update AiChatPanel to remove HasTypingIndicator**

In `src/Kompo/AiChatPanel.php`:

```php
// REMOVE this import:
use Condoedge\Ai\Kompo\Traits\HasTypingIndicator;

// CHANGE the trait usage from:
use HasChatSettings, HasChatTheme, HasAvatars, HasTypingIndicator, HasMethodsAsProperties;

// TO:
use HasChatSettings, HasChatTheme, HasAvatars, HasMethodsAsProperties;
```

**Step 2: Delete trait and contract files**

```bash
rm src/Kompo/Traits/HasTypingIndicator.php
rm src/Domain/Contracts/Searchable.php
```

**Step 3: Commit**

```bash
git add -A
git commit -m "chore(cleanup): remove dead traits and contracts

Deleted:
- HasTypingIndicator (imported but methods never called)
- Searchable interface (never implemented by any class)

Updated AiChatPanel to remove HasTypingIndicator trait usage.

Fixes: A2 from phase6-refactor-plan.md"
```

---

### Task 11: Remove Dead Methods from AiChatService

**Priority:** HIGH

**Methods to REMOVE from AiChatService.php:**
- `ask()` - superseded by askWithConversation
- `askWithHistory()` - replaced by askWithConversation
- `buildQuestionWithHistory()` - only used by deleted askWithHistory
- `getSuggestions()` - AI response includes suggestions
- `getExampleQuestions()` - panel uses settings directly
- `prepareQuestionWithContext()` - only has tests, not used

**Step 1: Verify these methods are no longer called**

```bash
grep -r "askWithHistory" src/ --include="*.php"
grep -r "getSuggestions\(" src/ --include="*.php"
grep -r "getExampleQuestions\(" src/ --include="*.php"
grep -r "prepareQuestionWithContext\(" src/ --include="*.php"
```

**Step 2: Delete the methods**

Edit `src/Services/Chat/AiChatService.php` and remove the methods listed above.

**Step 3: Run tests**

```bash
php vendor/bin/phpunit tests/Unit/Services/Chat/ -v
```

Fix any test failures by removing tests for deleted methods.

**Step 4: Commit**

```bash
git add src/Services/Chat/AiChatService.php tests/
git commit -m "chore(cleanup): remove dead methods from AiChatService

Removed methods:
- ask() - superseded by askWithConversation
- askWithHistory() - replaced by askWithConversation
- buildQuestionWithHistory() - only used by deleted method
- getSuggestions() - AI response includes suggestions
- getExampleQuestions() - panel uses settings directly
- prepareQuestionWithContext() - only had tests, not used in production

Fixes: A2 from phase6-refactor-plan.md"
```

---

### Task 12: Remove Dead Methods from Other Classes

**Priority:** MEDIUM

**Classes and methods to clean up:**

1. **AiChatMessage.php**: Remove `system()` factory method
2. **AiChatResponseData.php**: Remove `list()`, `metric()`, `withActions()`
3. **CircuitBreaker.php**: Remove `syncToCache()`, `getState()`, `getFailureCount()`, `isOpen()`, `reset()`
4. **RateLimiter.php**: Remove `remaining()`

**Step 1: Remove methods from each file**

For each file, verify no callers exist and remove the dead methods.

**Step 2: Run tests after each file**

```bash
php vendor/bin/phpunit --stop-on-failure
```

**Step 3: Commit**

```bash
git add -A
git commit -m "chore(cleanup): remove dead methods from various classes

Removed:
- AiChatMessage::system() - factory never used
- AiChatResponseData::list(), metric(), withActions() - factories never called
- CircuitBreaker diagnostic methods (no admin interface)
- RateLimiter::remaining() - never used

Fixes: A2 from phase6-refactor-plan.md"
```

---

### Task 13: Fix Return Type Mismatches

**Priority:** MEDIUM

**Files:**
- Modify: `src/Contracts/VectorStoreInterface.php`
- Modify: `src/DTOs/ProcessingResult.php`

**Step 1: Fix VectorStoreInterface**

In `src/Contracts/VectorStoreInterface.php`:

```php
/**
 * List all collections in the vector store.
 *
 * @return array<string> Collection names
 */
public function listCollections(): array;
```

**Step 2: Fix ProcessingResult**

In `src/DTOs/ProcessingResult.php`:

```php
// Change from:
public readonly int $fileId,

// To:
public readonly string|int $fileId,
```

**Step 3: Commit**

```bash
git add src/Contracts/VectorStoreInterface.php src/DTOs/ProcessingResult.php
git commit -m "fix(types): correct return type declarations

- Add return type to VectorStoreInterface::listCollections()
- Allow string|int for ProcessingResult::fileId (physical files use strings)

Fixes: A3 from phase6-refactor-plan.md"
```

---

## Phase 4: Enhanced Context (Tasks 14-16)

Improve multi-turn conversation quality.

---

### Task 14: Enhance ConversationContextManager

**Priority:** HIGH
**Impact:** Better entity tracking and reference resolution

**Files:**
- Modify: `src/Services/Context/ConversationContextManager.php`

**Step 1: Read current implementation**

Read file: `src/Services/Context/ConversationContextManager.php`

**Step 2: Add enhanced recordResponse method**

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
    $snapshot['last_answer_summary'] = \Illuminate\Support\Str::limit($answer, 200);
    $snapshot['updated_at'] = now()->toIso8601String();

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
```

**Step 3: Enhance buildPromptContext**

```php
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

**Step 4: Commit**

```bash
git add src/Services/Context/ConversationContextManager.php
git commit -m "feat(context): enhance context manager with entity data storage

- Extract WHERE clause filters from Cypher queries
- Store result samples for reference resolution
- Track result counts for context
- Include entity filter in prompt context

Enables: 'those', 'them', 'the same' reference resolution"
```

---

### Task 15: Update ConversationContextSection

**Priority:** HIGH
**Impact:** AI receives rich context in prompts

**Files:**
- Modify: `src/Services/PromptSections/ConversationContextSection.php`

**Step 1: Read current implementation**

Read file: `src/Services/PromptSections/ConversationContextSection.php`

**Step 2: Update render method**

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

**Step 3: Commit**

```bash
git add src/Services/PromptSections/ConversationContextSection.php
git commit -m "feat(prompt): enhance ConversationContextSection with rich context

- Include focused entity and active filter
- Show previous Cypher query and result count
- Include sample of previous results for reference
- Add explicit instructions for reference resolution

Improves: Multi-turn conversation quality"
```

---

### Task 16: Wire AiQueryLog Population

**Priority:** MEDIUM
**Impact:** Enables future analytics and learning

**Files:**
- Modify: `src/Services/QueryExecutor.php`
- Existing: `src/Models/AiQueryLog.php`

**Step 1: Read AiQueryLog model**

Read file: `src/Models/AiQueryLog.php`

**Step 2: Add logging to QueryExecutor**

In `src/Services/QueryExecutor.php`:

```php
use Condoedge\Ai\Models\AiQueryLog;

public function execute(string $cypherQuery, array $parameters = [], array $options = []): array
{
    $startTime = microtime(true);

    try {
        $result = $this->doExecute($cypherQuery, $parameters);

        // Log successful execution
        $this->logSuccess(
            question: $options['original_question'] ?? 'N/A',
            cypherQuery: $cypherQuery,
            executionTime: (microtime(true) - $startTime) * 1000,
            resultCount: count($result['data'] ?? []),
            templateUsed: $options['template'] ?? null
        );

        return $result;

    } catch (\Exception $e) {
        // Log failure
        $this->logFailure(
            question: $options['original_question'] ?? 'N/A',
            cypherQuery: $cypherQuery,
            error: $e->getMessage()
        );

        throw $e;
    }
}

protected function logSuccess(
    string $question,
    string $cypherQuery,
    float $executionTime,
    int $resultCount,
    ?string $templateUsed
): void {
    try {
        AiQueryLog::create([
            'question' => $question,
            'cypher_query' => $cypherQuery,
            'execution_time_ms' => $executionTime,
            'result_count' => $resultCount,
            'template_used' => $templateUsed,
            'status' => 'success',
            'created_at' => now(),
        ]);
    } catch (\Exception $e) {
        Log::warning('Failed to log query', ['error' => $e->getMessage()]);
    }
}

protected function logFailure(string $question, string $cypherQuery, string $error): void
{
    try {
        AiQueryLog::create([
            'question' => $question,
            'cypher_query' => $cypherQuery,
            'error_message' => $error,
            'status' => 'failure',
            'created_at' => now(),
        ]);
    } catch (\Exception $e) {
        Log::warning('Failed to log query failure', ['error' => $e->getMessage()]);
    }
}
```

**Step 3: Commit**

```bash
git add src/Services/QueryExecutor.php
git commit -m "feat(logging): wire AiQueryLog population in QueryExecutor

- Log successful queries with timing and result counts
- Log failures with error messages
- Non-blocking logging (catches exceptions)
- Enables future analytics and learning

Fixes: A5 from phase6-refactor-plan.md"
```

---

## Phase 5: Code Consolidation (Tasks 17-19)

Reduce duplication and improve maintainability.

---

### Task 17: Consolidate Physical File Handling

**Priority:** LOW
**Impact:** Single source of truth for file ID handling

**Files:**
- Modify: `src/Services/Context/FileContextProvider.php`
- Reference: `src/Services/Context/FileAccessResolver.php`

**Step 1: Remove duplicate constant and method from FileContextProvider**

In `src/Services/Context/FileContextProvider.php`:

```php
// REMOVE these lines:
private const PHYSICAL_PREFIX = 'physical:';

private function isPhysicalFile(int|string $fileId): bool
{
    return is_string($fileId) && str_starts_with($fileId, self::PHYSICAL_PREFIX);
}

// REPLACE usages with:
$this->accessResolver->isPhysicalFile($fileId)
FileAccessResolver::PHYSICAL_PREFIX
```

**Step 2: Commit**

```bash
git add src/Services/Context/FileContextProvider.php
git commit -m "refactor(files): consolidate physical file handling in FileAccessResolver

- Remove duplicate PHYSICAL_PREFIX constant from FileContextProvider
- Remove duplicate isPhysicalFile() method
- Use FileAccessResolver as single source of truth

Fixes: Section 2.3 from phase6-merge-plan.md"
```

---

### Task 18: Delete SemanticMatcher::matchScopes()

**Priority:** LOW
**Impact:** Remove confusion between SemanticMatcher and ScopeSemanticMatcher

**Files:**
- Modify: `src/Services/SemanticMatcher.php`

**Step 1: Verify no callers**

```bash
grep -r "matchScopes" src/ --include="*.php"
```

Should only show the method definition.

**Step 2: Remove the method**

Delete `matchScopes()` from `src/Services/SemanticMatcher.php` (approximately 45 lines).

**Step 3: Commit**

```bash
git add src/Services/SemanticMatcher.php
git commit -m "refactor(semantic): remove dead matchScopes() from SemanticMatcher

- ScopeSemanticMatcher::findMatchingScopes() is the active method
- This method was never called from the pipeline

Fixes: Section 2.2 from phase6-merge-plan.md"
```

---

### Task 19: Remove Unused Trait Methods

**Priority:** LOW
**Impact:** Cleaner trait definitions

**Files:**
- Modify: `src/Kompo/Traits/HasChatSettings.php`
- Modify: `src/Kompo/Traits/HasChatTheme.php`

**Step 1: Identify unused shorthand methods in HasChatSettings**

Methods to remove (all delegate to `$this->settings()->method()`):
- `showAvatars()`
- `showTimestamps()`
- `showTyping()` (all 15 shorthand methods)

**Step 2: Verify no callers**

```bash
grep -r "->showAvatars()" src/ --include="*.php"
```

Components call `$this->settings()->showAvatars()` directly.

**Step 3: Remove unused methods from both traits**

Keep only the core `settings()` and `theme()` methods and any that ARE actually used.

**Step 4: Commit**

```bash
git add src/Kompo/Traits/HasChatSettings.php src/Kompo/Traits/HasChatTheme.php
git commit -m "refactor(traits): remove unused shorthand methods

- Components use settings()->method() directly
- Shorthand methods were never called
- Reduces trait complexity

Fixes: Section 1.8 from phase6-merge-plan.md"
```

---

## Phase 6: Final Verification (Task 20)

---

### Task 20: Full Test Suite and Verification

**Priority:** CRITICAL

**Step 1: Run full test suite**

```bash
php vendor/bin/phpunit --stop-on-failure
```

Fix any failures.

**Step 2: Run static analysis (if available)**

```bash
./vendor/bin/phpstan analyse src/ --level=5
```

Fix any issues.

**Step 3: Clear all caches**

```bash
php artisan cache:clear
php artisan config:clear
php artisan view:clear
php artisan route:clear
composer dump-autoload
```

**Step 4: Manual verification**

Test in the UI:
1. Send a message: "Show me all customers"
2. Follow-up: "What are their orders?"
3. Verify context is maintained
4. Check that "those", "them" references work

**Step 5: Check for broken references**

```bash
grep -r "QueryLearner" src/ --include="*.php"
grep -r "AmbiguityDetector" src/ --include="*.php"
grep -r "askWithHistory" src/ --include="*.php"
```

All should return empty.

**Step 6: Final commit**

```bash
git add -A
git commit -m "chore: final cleanup and verification

- All tests passing
- No broken references
- Context system active
- Security fixes in place"
```

---

## Summary

### Tasks by Phase

| Phase | Tasks | Description |
|-------|-------|-------------|
| 1 | 1-4 | Security Sprint |
| 2 | 5-8 | Context System Activation |
| 3 | 9-13 | Dead Code Removal |
| 4 | 14-16 | Enhanced Context |
| 5 | 17-19 | Code Consolidation |
| 6 | 20 | Final Verification |

### Expected Outcomes

After completing all tasks:

1. **Security:** All 4 critical/high vulnerabilities fixed
2. **Context:** Multi-turn conversations maintain context
3. **References:** "those", "them", "the same" work correctly
4. **Clean:** 12 files deleted, 35+ methods removed
5. **Logging:** All queries logged to AiQueryLog
6. **Types:** Return types consistent across interfaces

### Files Changed Summary

| Action | Count |
|--------|-------|
| Files deleted | 12 |
| Methods removed | 35+ |
| Files modified | ~15 |
| New test files | 4 |

---

*Plan created: 2025-12-30*
*Total tasks: 20*
*Estimated effort: 80-100 hours*
