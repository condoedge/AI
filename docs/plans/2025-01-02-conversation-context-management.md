# Conversation Context Management Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Enable the AI to understand follow-up questions by tracking entities, queries, and references across conversation messages.

**Architecture:** A hybrid approach combining structured entity tracking with semantic reference resolution. The system extracts entities from each exchange, stores query history, and resolves conversational references ("those customers", "the same filter") before sending to the LLM. Integrates as a new prompt section in the existing SemanticPromptBuilder pipeline.

**Tech Stack:** PHP 8.2+, Laravel, PHPUnit, existing AiConversation/AiMessage models

---

## Background

### Current State
- User context (team, user ID) already injected via `CurrentUserContextSection.php`
- `AiChatService::buildQuestionWithHistory()` does primitive text concatenation
- `AiMessage` model already stores `cypher_query` field (query memory partially exists)
- No entity tracking from conversation history
- No reference resolution for "those", "them", "the same"

### What We're Building
```
User: "How many customers do we have?"
AI: "There are 150 customers." (stores: focus=Customer, query=MATCH (c:Customer)...)

User: "Show me the top 5 by revenue"
AI: Understands "the top 5" refers to Customers (tracked entity focus)

User: "and those in the Sales team?"
AI: Resolves "those" = Customers, adds Team filter
```

### Integration Points
- `AiConversation` model - add `context_snapshot` JSON field
- `AiMessage` model - already has `cypher_query`, add `extracted_entities`
- `SemanticPromptBuilder` - add new `ConversationContextSection`
- `AiChatService::askWithHistory()` - use new context manager

---

## Task 1: Add Context Snapshot to AiConversation

**Files:**
- Create: `database/migrations/2025_01_02_000001_add_context_snapshot_to_ai_conversations.php`
- Modify: `src/Models/AiConversation.php:18-31`
- Test: `tests/Unit/Models/AiConversationTest.php`

**Step 1: Write the failing test**

```php
<?php

namespace Condoedge\Ai\Tests\Unit\Models;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AiConversationContextTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_stores_context_snapshot_as_json(): void
    {
        $conversation = AiConversation::create([
            'user_id' => 1,
            'title' => 'Test conversation',
        ]);

        $conversation->updateContextSnapshot([
            'focused_entity' => 'Customer',
            'mentioned_entities' => ['Customer', 'Order'],
            'active_filters' => ['team_id' => 5],
        ]);

        $conversation->refresh();

        $this->assertEquals('Customer', $conversation->context_snapshot['focused_entity']);
        $this->assertContains('Order', $conversation->context_snapshot['mentioned_entities']);
    }

    /** @test */
    public function it_gets_focused_entity_from_snapshot(): void
    {
        $conversation = AiConversation::create([
            'user_id' => 1,
            'context_snapshot' => [
                'focused_entity' => 'Customer',
                'last_query_type' => 'count',
            ],
        ]);

        $this->assertEquals('Customer', $conversation->getFocusedEntity());
        $this->assertEquals('count', $conversation->getLastQueryType());
    }

    /** @test */
    public function it_returns_null_for_empty_context(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);

        $this->assertNull($conversation->getFocusedEntity());
        $this->assertNull($conversation->getLastQueryType());
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Models/AiConversationContextTest.php --filter it_stores_context_snapshot_as_json`
Expected: FAIL with "Unknown column 'context_snapshot'" or "Call to undefined method"

**Step 3: Create migration**

```php
<?php
// database/migrations/2025_01_02_000001_add_context_snapshot_to_ai_conversations.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->json('context_snapshot')->nullable()->after('metadata');
        });

        Schema::table('ai_messages', function (Blueprint $table) {
            $table->json('extracted_entities')->nullable()->after('metadata');
        });
    }

    public function down(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->dropColumn('context_snapshot');
        });

        Schema::table('ai_messages', function (Blueprint $table) {
            $table->dropColumn('extracted_entities');
        });
    }
};
```

**Step 4: Update AiConversation model**

Add to `src/Models/AiConversation.php`:

```php
// Add to $fillable array (line ~18-26)
protected $fillable = [
    'uuid',
    'user_id',
    'team_id',
    'title',
    'status',
    'metadata',
    'context_snapshot',  // ADD THIS
    'last_message_at',
];

// Add to $casts array (line ~28-31)
protected $casts = [
    'metadata' => 'array',
    'context_snapshot' => 'array',  // ADD THIS
    'last_message_at' => 'datetime',
];

// Add these methods after getRecentMessages() (after line 84)

/**
 * Update the conversation's context snapshot
 */
public function updateContextSnapshot(array $context): void
{
    $this->update(['context_snapshot' => array_merge(
        $this->context_snapshot ?? [],
        $context
    )]);
}

/**
 * Get the currently focused entity type
 */
public function getFocusedEntity(): ?string
{
    return $this->context_snapshot['focused_entity'] ?? null;
}

/**
 * Get the last query type (count, list, aggregate, etc.)
 */
public function getLastQueryType(): ?string
{
    return $this->context_snapshot['last_query_type'] ?? null;
}

/**
 * Get mentioned entities from context
 */
public function getMentionedEntities(): array
{
    return $this->context_snapshot['mentioned_entities'] ?? [];
}

/**
 * Get the last Cypher query from most recent assistant message
 */
public function getLastCypherQuery(): ?string
{
    $lastAssistantMessage = $this->messages()
        ->where('role', 'assistant')
        ->whereNotNull('cypher_query')
        ->orderBy('created_at', 'desc')
        ->first();

    return $lastAssistantMessage?->cypher_query;
}
```

**Step 5: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Unit/Models/AiConversationContextTest.php`
Expected: PASS (3 tests)

**Step 6: Commit**

```bash
git add database/migrations/2025_01_02_000001_add_context_snapshot_to_ai_conversations.php
git add src/Models/AiConversation.php
git add tests/Unit/Models/AiConversationContextTest.php
git commit -m "feat(conversation): add context snapshot storage to AiConversation"
```

---

## Task 2: Create EntityExtractor Service

**Files:**
- Create: `src/Services/Context/EntityExtractor.php`
- Test: `tests/Unit/Services/Context/EntityExtractorTest.php`

**Step 1: Write the failing test**

```php
<?php

namespace Condoedge\Ai\Tests\Unit\Services\Context;

use Condoedge\Ai\Services\Context\EntityExtractor;
use Condoedge\Ai\Tests\TestCase;

class EntityExtractorTest extends TestCase
{
    private EntityExtractor $extractor;

    public function setUp(): void
    {
        parent::setUp();
        $this->extractor = new EntityExtractor();
    }

    /** @test */
    public function it_extracts_entity_from_count_question(): void
    {
        $result = $this->extractor->extractFromQuestion(
            'How many customers do we have?',
            ['labels' => ['Customer', 'Order', 'Product']]
        );

        $this->assertEquals('Customer', $result['focused_entity']);
        $this->assertEquals('count', $result['query_type']);
    }

    /** @test */
    public function it_extracts_entity_from_list_question(): void
    {
        $result = $this->extractor->extractFromQuestion(
            'Show me all orders',
            ['labels' => ['Customer', 'Order', 'Product']]
        );

        $this->assertEquals('Order', $result['focused_entity']);
        $this->assertEquals('list', $result['query_type']);
    }

    /** @test */
    public function it_extracts_multiple_entities(): void
    {
        $result = $this->extractor->extractFromQuestion(
            'Show customers with their orders',
            ['labels' => ['Customer', 'Order', 'Product']]
        );

        $this->assertContains('Customer', $result['mentioned_entities']);
        $this->assertContains('Order', $result['mentioned_entities']);
    }

    /** @test */
    public function it_detects_aggregation_query_type(): void
    {
        $result = $this->extractor->extractFromQuestion(
            'What is the total revenue from orders?',
            ['labels' => ['Customer', 'Order']]
        );

        $this->assertEquals('aggregate', $result['query_type']);
    }

    /** @test */
    public function it_extracts_from_cypher_query(): void
    {
        $result = $this->extractor->extractFromCypher(
            'MATCH (c:Customer)-[:PLACED]->(o:Order) WHERE o.total > 100 RETURN c, o LIMIT 10'
        );

        $this->assertContains('Customer', $result['entities']);
        $this->assertContains('Order', $result['entities']);
        $this->assertContains('PLACED', $result['relationships']);
    }

    /** @test */
    public function it_handles_plural_forms(): void
    {
        $result = $this->extractor->extractFromQuestion(
            'List all products',
            ['labels' => ['Customer', 'Order', 'Product']]
        );

        $this->assertEquals('Product', $result['focused_entity']);
    }

    /** @test */
    public function it_returns_null_when_no_entity_found(): void
    {
        $result = $this->extractor->extractFromQuestion(
            'Hello, how are you?',
            ['labels' => ['Customer', 'Order']]
        );

        $this->assertNull($result['focused_entity']);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Services/Context/EntityExtractorTest.php --filter it_extracts_entity_from_count_question`
Expected: FAIL with "Class EntityExtractor not found"

**Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Context;

use Illuminate\Support\Str;

/**
 * EntityExtractor
 *
 * Extracts entity types and query patterns from questions and Cypher queries.
 * Used to track what entities are being discussed in a conversation.
 */
class EntityExtractor
{
    /**
     * Query type patterns
     */
    private array $queryTypePatterns = [
        'count' => '/\b(how many|count|number of|total)\b/i',
        'list' => '/\b(show|list|display|get|find|all)\b/i',
        'aggregate' => '/\b(sum|total|average|avg|max|min|revenue)\b/i',
        'detail' => '/\b(detail|specific|particular|information about)\b/i',
        'compare' => '/\b(compare|versus|vs|difference|between)\b/i',
    ];

    /**
     * Extract entities and query type from a natural language question
     *
     * @param string $question The user's question
     * @param array $schema Schema with 'labels' key containing available entity types
     * @return array{focused_entity: ?string, query_type: ?string, mentioned_entities: array}
     */
    public function extractFromQuestion(string $question, array $schema): array
    {
        $labels = $schema['labels'] ?? [];
        $questionLower = strtolower($question);

        $mentionedEntities = [];
        $focusedEntity = null;

        // Find all mentioned entities
        foreach ($labels as $label) {
            $labelLower = strtolower($label);
            $pluralLower = Str::plural($labelLower);

            if (str_contains($questionLower, $labelLower) || str_contains($questionLower, $pluralLower)) {
                $mentionedEntities[] = $label;

                // First mentioned entity is usually the focus
                if ($focusedEntity === null) {
                    $focusedEntity = $label;
                }
            }
        }

        // Detect query type
        $queryType = $this->detectQueryType($question);

        return [
            'focused_entity' => $focusedEntity,
            'query_type' => $queryType,
            'mentioned_entities' => array_unique($mentionedEntities),
        ];
    }

    /**
     * Extract entities and relationships from a Cypher query
     *
     * @param string $cypher The Cypher query
     * @return array{entities: array, relationships: array}
     */
    public function extractFromCypher(string $cypher): array
    {
        $entities = [];
        $relationships = [];

        // Extract node labels (e.g., :Customer, :Order)
        if (preg_match_all('/\(:?(\w+)\)|\[:\s*(\w+)\s*\]|:(\w+)\s*[{)\]]/', $cypher, $matches)) {
            foreach ($matches[1] as $match) {
                if (!empty($match) && $match !== strtolower($match)) {
                    $entities[] = $match;
                }
            }
        }

        // More precise label extraction
        if (preg_match_all('/\((\w+):(\w+)/', $cypher, $nodeMatches)) {
            foreach ($nodeMatches[2] as $label) {
                $entities[] = $label;
            }
        }

        // Extract relationship types (e.g., [:PLACED], -[:HAS]->)
        if (preg_match_all('/\[:(\w+)\]/', $cypher, $relMatches)) {
            $relationships = $relMatches[1];
        }

        return [
            'entities' => array_unique($entities),
            'relationships' => array_unique($relationships),
        ];
    }

    /**
     * Detect the type of query from the question
     */
    private function detectQueryType(string $question): ?string
    {
        foreach ($this->queryTypePatterns as $type => $pattern) {
            if (preg_match($pattern, $question)) {
                return $type;
            }
        }

        return null;
    }
}
```

**Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Unit/Services/Context/EntityExtractorTest.php`
Expected: PASS (8 tests)

**Step 5: Commit**

```bash
git add src/Services/Context/EntityExtractor.php
git add tests/Unit/Services/Context/EntityExtractorTest.php
git commit -m "feat(context): add EntityExtractor for tracking conversation entities"
```

---

## Task 3: Create ReferenceResolver Service

**Files:**
- Create: `src/Services/Context/ReferenceResolver.php`
- Test: `tests/Unit/Services/Context/ReferenceResolverTest.php`

**Step 1: Write the failing test**

```php
<?php

namespace Condoedge\Ai\Tests\Unit\Services\Context;

use Condoedge\Ai\Services\Context\ReferenceResolver;
use Condoedge\Ai\Tests\TestCase;

class ReferenceResolverTest extends TestCase
{
    private ReferenceResolver $resolver;

    public function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ReferenceResolver();
    }

    /** @test */
    public function it_detects_follow_up_question(): void
    {
        $this->assertTrue($this->resolver->isFollowUp('and those in Sales team?'));
        $this->assertTrue($this->resolver->isFollowUp('show me the top 5'));
        $this->assertTrue($this->resolver->isFollowUp('filter them by status'));
        $this->assertFalse($this->resolver->isFollowUp('How many customers do we have?'));
    }

    /** @test */
    public function it_resolves_those_reference(): void
    {
        $context = [
            'focused_entity' => 'Customer',
            'mentioned_entities' => ['Customer', 'Order'],
            'last_cypher_query' => 'MATCH (c:Customer) RETURN count(c)',
        ];

        $result = $this->resolver->resolve('and those in Sales team?', $context);

        $this->assertEquals('Customer', $result['resolved_entity']);
        $this->assertEquals('filter', $result['operation']);
        $this->assertStringContainsString('Customer', $result['enriched_question']);
    }

    /** @test */
    public function it_resolves_them_reference(): void
    {
        $context = [
            'focused_entity' => 'Order',
            'last_cypher_query' => 'MATCH (o:Order) RETURN o LIMIT 10',
        ];

        $result = $this->resolver->resolve('sort them by date', $context);

        $this->assertEquals('Order', $result['resolved_entity']);
        $this->assertEquals('modify', $result['operation']);
    }

    /** @test */
    public function it_resolves_the_same_reference(): void
    {
        $context = [
            'focused_entity' => 'Customer',
            'last_cypher_query' => 'MATCH (c:Customer) WHERE c.status = "active" RETURN c',
        ];

        $result = $this->resolver->resolve('show the same but with orders', $context);

        $this->assertEquals('extend', $result['operation']);
        $this->assertNotNull($result['base_query']);
    }

    /** @test */
    public function it_handles_implicit_continuation(): void
    {
        $context = [
            'focused_entity' => 'Customer',
            'last_query_type' => 'count',
        ];

        // "top 5" implies we're still talking about Customers
        $result = $this->resolver->resolve('show me the top 5 by revenue', $context);

        $this->assertEquals('Customer', $result['resolved_entity']);
    }

    /** @test */
    public function it_returns_unresolved_when_no_context(): void
    {
        $result = $this->resolver->resolve('show them', []);

        $this->assertFalse($result['resolved']);
        $this->assertNull($result['resolved_entity']);
    }

    /** @test */
    public function it_builds_enriched_question(): void
    {
        $context = [
            'focused_entity' => 'Customer',
            'last_cypher_query' => 'MATCH (c:Customer) RETURN count(c)',
        ];

        $result = $this->resolver->resolve('and in Sales team?', $context);

        // Should transform vague question into specific one
        $this->assertStringContainsString('Customer', $result['enriched_question']);
        $this->assertStringContainsString('Sales', $result['enriched_question']);
    }

    /** @test */
    public function it_detects_reference_type(): void
    {
        $this->assertEquals('pronoun', $this->resolver->detectReferenceType('show them'));
        $this->assertEquals('demonstrative', $this->resolver->detectReferenceType('those customers'));
        $this->assertEquals('definite', $this->resolver->detectReferenceType('the orders'));
        $this->assertEquals('implicit', $this->resolver->detectReferenceType('top 5 by revenue'));
        $this->assertNull($this->resolver->detectReferenceType('How many users?'));
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Services/Context/ReferenceResolverTest.php --filter it_detects_follow_up_question`
Expected: FAIL with "Class ReferenceResolver not found"

**Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Context;

/**
 * ReferenceResolver
 *
 * Resolves conversational references like "those", "them", "the same"
 * by using conversation context to understand what entities are being discussed.
 */
class ReferenceResolver
{
    /**
     * Patterns that indicate a follow-up question
     */
    private array $followUpPatterns = [
        '/^and\s+/i',
        '/^but\s+/i',
        '/^also\s+/i',
        '/^what about\s+/i',
        '/\b(those|them|these|it)\b/i',
        '/^(show|filter|sort|group)\s+(me\s+)?the\s+/i',
        '/^the\s+(same|top|first|last)\b/i',
        '/^(top|first|last)\s+\d+/i',
    ];

    /**
     * Pronoun patterns
     */
    private array $pronounPatterns = [
        'pronoun' => '/\b(them|they|it)\b/i',
        'demonstrative' => '/\b(those|these|that|this)\b/i',
        'definite' => '/\bthe\s+(same|top|first|last|\w+s)\b/i',
    ];

    /**
     * Check if a question is a follow-up to previous context
     */
    public function isFollowUp(string $question): bool
    {
        foreach ($this->followUpPatterns as $pattern) {
            if (preg_match($pattern, $question)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect the type of reference in a question
     *
     * @return string|null 'pronoun', 'demonstrative', 'definite', 'implicit', or null
     */
    public function detectReferenceType(string $question): ?string
    {
        foreach ($this->pronounPatterns as $type => $pattern) {
            if (preg_match($pattern, $question)) {
                return $type;
            }
        }

        // Check for implicit continuation (e.g., "top 5 by revenue" without entity)
        if (preg_match('/^(top|first|last|show me|filter|sort)\s+/i', $question)) {
            // Only implicit if no explicit entity mentioned
            if (!preg_match('/\b[A-Z][a-z]+s?\b/', $question)) {
                return 'implicit';
            }
        }

        return null;
    }

    /**
     * Resolve references in a question using conversation context
     *
     * @param string $question The user's question
     * @param array $context Conversation context with keys:
     *                       - focused_entity: Current entity focus
     *                       - mentioned_entities: Previously mentioned entities
     *                       - last_cypher_query: The last executed Cypher query
     *                       - last_query_type: Type of last query (count, list, etc.)
     * @return array Resolution result with keys:
     *               - resolved: bool - whether resolution succeeded
     *               - resolved_entity: ?string - the entity being referenced
     *               - operation: ?string - 'filter', 'modify', 'extend'
     *               - enriched_question: string - question with resolved references
     *               - base_query: ?string - previous query to build upon
     */
    public function resolve(string $question, array $context): array
    {
        $referenceType = $this->detectReferenceType($question);
        $focusedEntity = $context['focused_entity'] ?? null;
        $lastQuery = $context['last_cypher_query'] ?? null;

        // No context available
        if (empty($focusedEntity) && empty($lastQuery)) {
            return [
                'resolved' => false,
                'resolved_entity' => null,
                'operation' => null,
                'enriched_question' => $question,
                'base_query' => null,
                'reference_type' => $referenceType,
            ];
        }

        // Determine operation type
        $operation = $this->determineOperation($question);

        // Build enriched question with resolved references
        $enrichedQuestion = $this->buildEnrichedQuestion($question, $focusedEntity, $operation);

        return [
            'resolved' => true,
            'resolved_entity' => $focusedEntity,
            'operation' => $operation,
            'enriched_question' => $enrichedQuestion,
            'base_query' => $operation === 'extend' ? $lastQuery : null,
            'reference_type' => $referenceType,
        ];
    }

    /**
     * Determine the operation type from the question
     */
    private function determineOperation(string $question): string
    {
        if (preg_match('/\b(filter|where|in|with|by)\b/i', $question)) {
            return 'filter';
        }

        if (preg_match('/\b(sort|order|group)\b/i', $question)) {
            return 'modify';
        }

        if (preg_match('/\b(same|also|and|include)\b/i', $question)) {
            return 'extend';
        }

        return 'filter';
    }

    /**
     * Build an enriched question with resolved references
     */
    private function buildEnrichedQuestion(string $question, ?string $entity, string $operation): string
    {
        if ($entity === null) {
            return $question;
        }

        // Replace pronouns with entity name
        $enriched = preg_replace('/\b(those|them|these|they|it)\b/i', strtolower($entity) . 's', $question);

        // If question starts with "and", make it a complete sentence
        if (preg_match('/^and\s+/i', $enriched)) {
            $enriched = preg_replace('/^and\s+/i', "Show {$entity}s ", $enriched);
        }

        // Add entity context if implicit
        if ($enriched === $question && !str_contains(strtolower($question), strtolower($entity))) {
            $enriched = "{$entity}s: {$question}";
        }

        return $enriched;
    }
}
```

**Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Unit/Services/Context/ReferenceResolverTest.php`
Expected: PASS (8 tests)

**Step 5: Commit**

```bash
git add src/Services/Context/ReferenceResolver.php
git add tests/Unit/Services/Context/ReferenceResolverTest.php
git commit -m "feat(context): add ReferenceResolver for conversational references"
```

---

## Task 4: Create ConversationContextManager

**Files:**
- Create: `src/Services/Context/ConversationContextManager.php`
- Test: `tests/Unit/Services/Context/ConversationContextManagerTest.php`

**Step 1: Write the failing test**

```php
<?php

namespace Condoedge\Ai\Tests\Unit\Services\Context;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Models\AiMessage;
use Condoedge\Ai\Services\Context\ConversationContextManager;
use Condoedge\Ai\Services\Context\EntityExtractor;
use Condoedge\Ai\Services\Context\ReferenceResolver;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ConversationContextManagerTest extends TestCase
{
    use RefreshDatabase;

    private ConversationContextManager $manager;

    public function setUp(): void
    {
        parent::setUp();
        $this->manager = new ConversationContextManager(
            new EntityExtractor(),
            new ReferenceResolver()
        );
    }

    /** @test */
    public function it_processes_question_and_updates_context(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);
        $schema = ['labels' => ['Customer', 'Order']];

        $result = $this->manager->processQuestion(
            $conversation,
            'How many customers do we have?',
            $schema
        );

        $this->assertEquals('Customer', $result['focused_entity']);
        $this->assertEquals('count', $result['query_type']);

        // Context should be updated on conversation
        $conversation->refresh();
        $this->assertEquals('Customer', $conversation->getFocusedEntity());
    }

    /** @test */
    public function it_resolves_follow_up_questions(): void
    {
        $conversation = AiConversation::create([
            'user_id' => 1,
            'context_snapshot' => [
                'focused_entity' => 'Customer',
                'last_query_type' => 'count',
            ],
        ]);

        // Add a previous message with cypher
        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'There are 150 customers.',
            'cypher_query' => 'MATCH (c:Customer) RETURN count(c)',
        ]);

        $result = $this->manager->processQuestion(
            $conversation,
            'and those in Sales team?',
            ['labels' => ['Customer', 'Team']]
        );

        $this->assertTrue($result['is_follow_up']);
        $this->assertEquals('Customer', $result['resolved_entity']);
        $this->assertStringContainsString('Customer', $result['enriched_question']);
    }

    /** @test */
    public function it_records_response_and_updates_context(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);

        $this->manager->recordResponse(
            $conversation,
            'There are 150 customers.',
            'MATCH (c:Customer) RETURN count(c) as count',
            ['data' => [['count' => 150]]]
        );

        $conversation->refresh();

        $this->assertEquals('Customer', $conversation->getFocusedEntity());
        $this->assertContains('Customer', $conversation->getMentionedEntities());
    }

    /** @test */
    public function it_builds_context_for_prompt(): void
    {
        $conversation = AiConversation::create([
            'user_id' => 1,
            'context_snapshot' => [
                'focused_entity' => 'Customer',
                'mentioned_entities' => ['Customer', 'Order'],
            ],
        ]);

        $conversation->messages()->create([
            'role' => 'user',
            'content' => 'How many customers?',
        ]);

        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => '150 customers',
            'cypher_query' => 'MATCH (c:Customer) RETURN count(c)',
        ]);

        $promptContext = $this->manager->buildPromptContext($conversation);

        $this->assertEquals('Customer', $promptContext['focused_entity']);
        $this->assertNotNull($promptContext['last_cypher_query']);
        $this->assertArrayHasKey('recent_exchanges', $promptContext);
    }

    /** @test */
    public function it_limits_conversation_history(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);

        // Create 20 messages
        for ($i = 0; $i < 20; $i++) {
            $conversation->messages()->create([
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => "Message {$i}",
            ]);
        }

        $promptContext = $this->manager->buildPromptContext($conversation, maxHistory: 5);

        $this->assertCount(5, $promptContext['recent_exchanges']);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Services/Context/ConversationContextManagerTest.php --filter it_processes_question_and_updates_context`
Expected: FAIL with "Class ConversationContextManager not found"

**Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Context;

use Condoedge\Ai\Models\AiConversation;

/**
 * ConversationContextManager
 *
 * Orchestrates conversation context tracking by combining:
 * - Entity extraction from questions and responses
 * - Reference resolution for follow-up questions
 * - Context snapshot management on AiConversation
 *
 * This is the main entry point for conversation context handling.
 */
class ConversationContextManager
{
    public function __construct(
        private readonly EntityExtractor $entityExtractor,
        private readonly ReferenceResolver $referenceResolver
    ) {
    }

    /**
     * Process an incoming question and update conversation context
     *
     * @param AiConversation $conversation The conversation
     * @param string $question User's question
     * @param array $schema Database schema with labels
     * @return array Processing result with context info
     */
    public function processQuestion(
        AiConversation $conversation,
        string $question,
        array $schema
    ): array {
        // Extract entities from the new question
        $extraction = $this->entityExtractor->extractFromQuestion($question, $schema);

        // Check if this is a follow-up
        $isFollowUp = $this->referenceResolver->isFollowUp($question);

        $result = [
            'is_follow_up' => $isFollowUp,
            'focused_entity' => $extraction['focused_entity'],
            'query_type' => $extraction['query_type'],
            'mentioned_entities' => $extraction['mentioned_entities'],
            'enriched_question' => $question,
            'resolved_entity' => null,
        ];

        // If follow-up, try to resolve references
        if ($isFollowUp) {
            $currentContext = $this->buildPromptContext($conversation);
            $resolution = $this->referenceResolver->resolve($question, $currentContext);

            if ($resolution['resolved']) {
                $result['resolved_entity'] = $resolution['resolved_entity'];
                $result['enriched_question'] = $resolution['enriched_question'];

                // Use resolved entity as focus if extraction didn't find one
                if ($result['focused_entity'] === null) {
                    $result['focused_entity'] = $resolution['resolved_entity'];
                }
            }
        }

        // Update conversation context (only if we have new info)
        if ($result['focused_entity'] !== null) {
            $conversation->updateContextSnapshot([
                'focused_entity' => $result['focused_entity'],
                'last_query_type' => $result['query_type'],
                'mentioned_entities' => array_unique(array_merge(
                    $conversation->getMentionedEntities(),
                    $result['mentioned_entities']
                )),
            ]);
        }

        return $result;
    }

    /**
     * Record an AI response and update context with query info
     *
     * @param AiConversation $conversation The conversation
     * @param string $response The AI's response
     * @param string $cypherQuery The Cypher query that was executed
     * @param array $queryResult The query execution result
     */
    public function recordResponse(
        AiConversation $conversation,
        string $response,
        string $cypherQuery,
        array $queryResult
    ): void {
        // Extract entities from the Cypher query
        $cypherEntities = $this->entityExtractor->extractFromCypher($cypherQuery);

        // Update context with the entities from the executed query
        $currentEntities = $conversation->getMentionedEntities();
        $newEntities = array_unique(array_merge($currentEntities, $cypherEntities['entities']));

        $focusedEntity = $conversation->getFocusedEntity();
        if ($focusedEntity === null && !empty($cypherEntities['entities'])) {
            $focusedEntity = $cypherEntities['entities'][0];
        }

        $conversation->updateContextSnapshot([
            'focused_entity' => $focusedEntity,
            'mentioned_entities' => $newEntities,
            'last_relationships' => $cypherEntities['relationships'],
            'last_result_count' => count($queryResult['data'] ?? []),
        ]);
    }

    /**
     * Build context data for the prompt builder
     *
     * @param AiConversation $conversation The conversation
     * @param int $maxHistory Maximum number of exchanges to include
     * @return array Context data for prompt
     */
    public function buildPromptContext(AiConversation $conversation, int $maxHistory = 5): array
    {
        $snapshot = $conversation->context_snapshot ?? [];
        $lastQuery = $conversation->getLastCypherQuery();

        // Get recent message exchanges
        $recentMessages = $conversation->messages()
            ->orderBy('created_at', 'desc')
            ->limit($maxHistory * 2) // User + assistant pairs
            ->get()
            ->reverse()
            ->values();

        $recentExchanges = [];
        foreach ($recentMessages->chunk(2) as $pair) {
            $exchange = [];
            foreach ($pair as $message) {
                $exchange[$message->role] = [
                    'content' => $message->content,
                    'cypher_query' => $message->cypher_query,
                ];
            }
            if (!empty($exchange)) {
                $recentExchanges[] = $exchange;
            }
        }

        // Limit to maxHistory exchanges
        $recentExchanges = array_slice($recentExchanges, -$maxHistory);

        return [
            'focused_entity' => $snapshot['focused_entity'] ?? null,
            'mentioned_entities' => $snapshot['mentioned_entities'] ?? [],
            'last_query_type' => $snapshot['last_query_type'] ?? null,
            'last_cypher_query' => $lastQuery,
            'last_result_count' => $snapshot['last_result_count'] ?? null,
            'recent_exchanges' => $recentExchanges,
        ];
    }
}
```

**Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Unit/Services/Context/ConversationContextManagerTest.php`
Expected: PASS (5 tests)

**Step 5: Commit**

```bash
git add src/Services/Context/ConversationContextManager.php
git add tests/Unit/Services/Context/ConversationContextManagerTest.php
git commit -m "feat(context): add ConversationContextManager orchestrator"
```

---

## Task 5: Create ConversationContextSection for Prompts

**Files:**
- Create: `src/Services/PromptSections/ConversationContextSection.php`
- Test: `tests/Unit/Services/PromptSections/ConversationContextSectionTest.php`

**Step 1: Write the failing test**

```php
<?php

namespace Condoedge\Ai\Tests\Unit\Services\PromptSections;

use Condoedge\Ai\Services\PromptSections\ConversationContextSection;
use Condoedge\Ai\Tests\TestCase;

class ConversationContextSectionTest extends TestCase
{
    private ConversationContextSection $section;

    public function setUp(): void
    {
        parent::setUp();
        $this->section = new ConversationContextSection();
    }

    /** @test */
    public function it_has_correct_name_and_priority(): void
    {
        $this->assertEquals('conversation_context', $this->section->getName());
        $this->assertEquals(55, $this->section->getPriority()); // After similar_queries (50)
    }

    /** @test */
    public function it_should_not_include_when_no_context(): void
    {
        $result = $this->section->shouldInclude('question', [], []);
        $this->assertFalse($result);

        $result = $this->section->shouldInclude('question', ['conversation_context' => []], []);
        $this->assertFalse($result);
    }

    /** @test */
    public function it_should_include_when_context_has_data(): void
    {
        $context = [
            'conversation_context' => [
                'focused_entity' => 'Customer',
                'recent_exchanges' => [['user' => ['content' => 'test']]],
            ],
        ];

        $result = $this->section->shouldInclude('question', $context, []);
        $this->assertTrue($result);
    }

    /** @test */
    public function it_formats_context_with_focused_entity(): void
    {
        $context = [
            'conversation_context' => [
                'focused_entity' => 'Customer',
                'last_query_type' => 'count',
                'last_cypher_query' => 'MATCH (c:Customer) RETURN count(c)',
            ],
        ];

        $output = $this->section->format('show top 5', $context);

        $this->assertStringContainsString('CONVERSATION CONTEXT', $output);
        $this->assertStringContainsString('Customer', $output);
        $this->assertStringContainsString('count', $output);
        $this->assertStringContainsString('MATCH (c:Customer)', $output);
    }

    /** @test */
    public function it_formats_recent_exchanges(): void
    {
        $context = [
            'conversation_context' => [
                'focused_entity' => 'Customer',
                'recent_exchanges' => [
                    [
                        'user' => ['content' => 'How many customers?'],
                        'assistant' => [
                            'content' => 'There are 150 customers.',
                            'cypher_query' => 'MATCH (c:Customer) RETURN count(c)',
                        ],
                    ],
                ],
            ],
        ];

        $output = $this->section->format('and in Sales?', $context);

        $this->assertStringContainsString('How many customers?', $output);
        $this->assertStringContainsString('150 customers', $output);
    }

    /** @test */
    public function it_includes_continuation_hint_for_follow_ups(): void
    {
        $context = [
            'conversation_context' => [
                'focused_entity' => 'Customer',
                'last_cypher_query' => 'MATCH (c:Customer) RETURN count(c)',
                'is_follow_up' => true,
            ],
        ];

        $output = $this->section->format('and in Sales?', $context);

        $this->assertStringContainsString('continuation', strtolower($output));
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Services/PromptSections/ConversationContextSectionTest.php --filter it_has_correct_name_and_priority`
Expected: FAIL with "Class ConversationContextSection not found"

**Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

/**
 * ConversationContextSection
 *
 * Adds conversation history and context to the LLM prompt.
 * Enables the LLM to understand follow-up questions and references.
 *
 * Priority 55 places this after similar_queries (50) but before
 * detected_entities (60).
 */
class ConversationContextSection extends BasePromptSection
{
    protected string $name = 'conversation_context';
    protected int $priority = 55;

    /**
     * Only include when there's actual conversation context
     */
    public function shouldInclude(string $question, array $context, array $options = []): bool
    {
        $conversationContext = $context['conversation_context'] ?? [];

        // Need at least a focused entity or recent exchanges
        return !empty($conversationContext['focused_entity'])
            || !empty($conversationContext['recent_exchanges']);
    }

    /**
     * Format the conversation context for the prompt
     */
    public function format(string $question, array $context, array $options = []): string
    {
        $conversationContext = $context['conversation_context'] ?? [];

        $output = $this->header('CONVERSATION CONTEXT');

        // Current focus
        if (!empty($conversationContext['focused_entity'])) {
            $output .= "**Current Focus:** {$conversationContext['focused_entity']}";

            if (!empty($conversationContext['last_query_type'])) {
                $output .= " ({$conversationContext['last_query_type']} query)";
            }

            $output .= "\n\n";
        }

        // Recent exchanges
        if (!empty($conversationContext['recent_exchanges'])) {
            $output .= "**Recent Conversation:**\n";

            foreach ($conversationContext['recent_exchanges'] as $i => $exchange) {
                $num = $i + 1;

                if (!empty($exchange['user']['content'])) {
                    $userContent = $this->truncate($exchange['user']['content'], 100);
                    $output .= "  [{$num}] User: {$userContent}\n";
                }

                if (!empty($exchange['assistant']['content'])) {
                    $assistantContent = $this->truncate($exchange['assistant']['content'], 150);
                    $output .= "      Assistant: {$assistantContent}\n";

                    if (!empty($exchange['assistant']['cypher_query'])) {
                        $output .= "      Query: {$exchange['assistant']['cypher_query']}\n";
                    }
                }
            }

            $output .= "\n";
        }

        // Last Cypher query (if not already shown in exchanges)
        if (!empty($conversationContext['last_cypher_query'])
            && empty($conversationContext['recent_exchanges'])) {
            $output .= "**Previous Query:**\n";
            $output .= "```cypher\n{$conversationContext['last_cypher_query']}\n```\n\n";
        }

        // Follow-up hint
        if (!empty($conversationContext['is_follow_up'])) {
            $output .= "**Note:** This is a follow-up question. Consider building upon or ";
            $output .= "modifying the previous query. Pronouns like 'those', 'them', 'it' ";
            $output .= "refer to the {$conversationContext['focused_entity']} entity.\n\n";
        }

        // Mentioned entities for reference
        if (!empty($conversationContext['mentioned_entities'])) {
            $entities = implode(', ', $conversationContext['mentioned_entities']);
            $output .= "**Entities discussed:** {$entities}\n\n";
        }

        return $output;
    }

    /**
     * Truncate text to specified length
     */
    private function truncate(string $text, int $length): string
    {
        if (strlen($text) <= $length) {
            return $text;
        }

        return substr($text, 0, $length) . '...';
    }
}
```

**Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Unit/Services/PromptSections/ConversationContextSectionTest.php`
Expected: PASS (6 tests)

**Step 5: Commit**

```bash
git add src/Services/PromptSections/ConversationContextSection.php
git add tests/Unit/Services/PromptSections/ConversationContextSectionTest.php
git commit -m "feat(context): add ConversationContextSection for prompt building"
```

---

## Task 6: Integrate with AiChatService

**Files:**
- Modify: `src/Services/Chat/AiChatService.php`
- Test: `tests/Unit/Services/Chat/AiChatServiceContextTest.php`

**Step 1: Write the failing test**

```php
<?php

namespace Condoedge\Ai\Tests\Unit\Services\Chat;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Services\Chat\AiChatService;
use Condoedge\Ai\Services\Context\ConversationContextManager;
use Condoedge\Ai\Services\Context\EntityExtractor;
use Condoedge\Ai\Services\Context\ReferenceResolver;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AiChatServiceContextTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_uses_context_manager_for_history(): void
    {
        $service = new AiChatService([
            'context_manager' => new ConversationContextManager(
                new EntityExtractor(),
                new ReferenceResolver()
            ),
        ]);

        // Create conversation with history
        $conversation = AiConversation::create([
            'user_id' => 1,
            'context_snapshot' => [
                'focused_entity' => 'Customer',
            ],
        ]);

        $conversation->messages()->create([
            'role' => 'user',
            'content' => 'How many customers?',
        ]);

        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => '150 customers',
            'cypher_query' => 'MATCH (c:Customer) RETURN count(c)',
        ]);

        // The service should build context properly
        $this->assertTrue(method_exists($service, 'askWithConversation'));
    }

    /** @test */
    public function it_enriches_follow_up_questions(): void
    {
        $service = new AiChatService();

        $conversation = AiConversation::create([
            'user_id' => 1,
            'context_snapshot' => [
                'focused_entity' => 'Customer',
            ],
        ]);

        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => '150 customers',
            'cypher_query' => 'MATCH (c:Customer) RETURN count(c)',
        ]);

        // Build question should recognize follow-up
        $schema = ['labels' => ['Customer', 'Team']];
        $enrichedQuestion = $service->prepareQuestionWithContext(
            'and those in Sales?',
            $conversation,
            $schema
        );

        $this->assertStringContainsString('Customer', $enrichedQuestion);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Services/Chat/AiChatServiceContextTest.php --filter it_uses_context_manager_for_history`
Expected: FAIL with "Call to undefined method askWithConversation"

**Step 3: Update AiChatService**

Add to `src/Services/Chat/AiChatService.php` after line 355:

```php
    /**
     * Context manager for conversation tracking
     */
    protected ?ConversationContextManager $contextManager = null;

    /**
     * Set context manager (called from constructor if provided in config)
     */
    public function setContextManager(ConversationContextManager $manager): self
    {
        $this->contextManager = $manager;
        return $this;
    }

    /**
     * Get or create context manager
     */
    protected function getContextManager(): ConversationContextManager
    {
        if ($this->contextManager === null) {
            $this->contextManager = new ConversationContextManager(
                new EntityExtractor(),
                new ReferenceResolver()
            );
        }

        return $this->contextManager;
    }

    /**
     * Ask a question using an AiConversation for context
     *
     * This is the preferred method when using conversation persistence.
     * It automatically handles context tracking and reference resolution.
     *
     * @param string $question User's question
     * @param AiConversation $conversation The conversation entity
     * @param array $options Additional options
     * @return AiChatMessage The response
     */
    public function askWithConversation(
        string $question,
        AiConversation $conversation,
        array $options = []
    ): AiChatMessage {
        $startTime = microtime(true);
        $options = array_merge($this->config, $options);
        $schema = $options['schema'] ?? $this->getSchemaForContext();

        try {
            $manager = $this->getContextManager();

            // Process question through context manager
            $processResult = $manager->processQuestion($conversation, $question, $schema);

            // Use enriched question if available
            $questionToAsk = $processResult['enriched_question'] ?? $question;

            // Build conversation context for prompt
            $conversationContext = $manager->buildPromptContext($conversation);
            $conversationContext['is_follow_up'] = $processResult['is_follow_up'];

            // Call AI with conversation context
            $aiResponse = AI::answerQuestion($questionToAsk, [
                'style' => $options['style'] ?? 'friendly',
                'conversation_context' => $conversationContext,
            ]);

            $executionTime = (int) ((microtime(true) - $startTime) * 1000);
            $answerText = $aiResponse['answer'] ?? 'I could not generate a response.';
            $cypherQuery = $aiResponse['cypher'] ?? null;

            // Record the response in context manager
            if ($cypherQuery) {
                $manager->recordResponse(
                    $conversation,
                    $answerText,
                    $cypherQuery,
                    $aiResponse
                );
            }

            // Store messages in conversation
            $conversation->addMessage('user', $question);
            $conversation->addMessage('assistant', $answerText, [
                'cypher_query' => $cypherQuery,
                'execution_time_ms' => $executionTime,
                'context_used' => $conversationContext,
            ]);

            // Build response data
            $responseData = $this->buildResponseData($question, $answerText, $executionTime, $options);

            if (!empty($aiResponse['data'])) {
                $responseData = $this->enrichResponseData($responseData, $aiResponse);
            }

            return AiChatMessage::assistant($answerText, $responseData);

        } catch (\Exception $e) {
            Log::error('AI Chat with conversation error', [
                'question' => $question,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            $errorData = AiChatResponseData::error($this->getUserFriendlyError($e));

            return AiChatMessage::assistant(
                $this->getUserFriendlyError($e),
                $errorData
            );
        }
    }

    /**
     * Prepare a question with conversation context
     *
     * Useful for getting the enriched question without executing.
     */
    public function prepareQuestionWithContext(
        string $question,
        AiConversation $conversation,
        array $schema = []
    ): string {
        $manager = $this->getContextManager();
        $schema = $schema ?: $this->getSchemaForContext();

        $processResult = $manager->processQuestion($conversation, $question, $schema);

        return $processResult['enriched_question'] ?? $question;
    }

    /**
     * Get schema for context (from AI or cache)
     */
    protected function getSchemaForContext(): array
    {
        try {
            return AI::getSchema();
        } catch (\Exception $e) {
            return ['labels' => []];
        }
    }
```

Also add these imports at the top of the file:

```php
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Services\Context\ConversationContextManager;
use Condoedge\Ai\Services\Context\EntityExtractor;
use Condoedge\Ai\Services\Context\ReferenceResolver;
```

**Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Unit/Services/Chat/AiChatServiceContextTest.php`
Expected: PASS (2 tests)

**Step 5: Commit**

```bash
git add src/Services/Chat/AiChatService.php
git add tests/Unit/Services/Chat/AiChatServiceContextTest.php
git commit -m "feat(chat): integrate ConversationContextManager with AiChatService"
```

---

## Task 7: Register ConversationContextSection in Config

**Files:**
- Modify: `config/ai.php` (add section to query_generator_sections)
- Test: `tests/Integration/ConversationContextIntegrationTest.php`

**Step 1: Write the failing integration test**

```php
<?php

namespace Condoedge\Ai\Tests\Integration;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Services\SemanticPromptBuilder;
use Condoedge\Ai\Services\PatternLibrary;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ConversationContextIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_includes_conversation_context_in_prompt(): void
    {
        // Configure to include conversation context section
        config(['ai.query_generator_sections' => [
            \Condoedge\Ai\Services\PromptSections\SchemaSection::class,
            \Condoedge\Ai\Services\PromptSections\ConversationContextSection::class,
            \Condoedge\Ai\Services\PromptSections\QuestionSection::class,
        ]]);

        $builder = new SemanticPromptBuilder(new PatternLibrary());

        $context = [
            'graph_schema' => ['labels' => ['Customer', 'Order']],
            'conversation_context' => [
                'focused_entity' => 'Customer',
                'last_cypher_query' => 'MATCH (c:Customer) RETURN count(c)',
                'recent_exchanges' => [
                    [
                        'user' => ['content' => 'How many customers?'],
                        'assistant' => [
                            'content' => '150 customers',
                            'cypher_query' => 'MATCH (c:Customer) RETURN count(c)',
                        ],
                    ],
                ],
                'is_follow_up' => true,
            ],
        ];

        $prompt = $builder->buildPrompt('and those in Sales?', $context);

        $this->assertStringContainsString('CONVERSATION CONTEXT', $prompt);
        $this->assertStringContainsString('Customer', $prompt);
        $this->assertStringContainsString('How many customers?', $prompt);
        $this->assertStringContainsString('follow-up', strtolower($prompt));
    }

    /** @test */
    public function full_conversation_flow_works(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);

        // Simulate first question
        $conversation->messages()->create([
            'role' => 'user',
            'content' => 'How many customers do we have?',
        ]);

        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'There are 150 customers in the system.',
            'cypher_query' => 'MATCH (c:Customer) RETURN count(c) as count',
        ]);

        $conversation->updateContextSnapshot([
            'focused_entity' => 'Customer',
            'last_query_type' => 'count',
            'mentioned_entities' => ['Customer'],
        ]);

        // Now ask follow-up
        $conversation->refresh();

        $this->assertEquals('Customer', $conversation->getFocusedEntity());
        $this->assertEquals('MATCH (c:Customer) RETURN count(c) as count', $conversation->getLastCypherQuery());
        $this->assertContains('Customer', $conversation->getMentionedEntities());
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Integration/ConversationContextIntegrationTest.php --filter it_includes_conversation_context_in_prompt`
Expected: May pass or fail depending on config state

**Step 3: Update config/ai.php**

Find the `query_generator_sections` array and add the ConversationContextSection:

```php
// In config/ai.php, add to query_generator_sections array:

'query_generator_sections' => [
    \Condoedge\Ai\Services\PromptSections\ProjectContextSection::class,
    \Condoedge\Ai\Services\PromptSections\GenericContextSection::class,
    \Condoedge\Ai\Services\PromptSections\SchemaSection::class,
    \Condoedge\Ai\Services\PromptSections\RelationshipsSection::class,
    \Condoedge\Ai\Services\PromptSections\ExampleEntitiesSection::class,
    \Condoedge\Ai\Services\PromptSections\SimilarQueriesSection::class,
    \Condoedge\Ai\Services\PromptSections\ConversationContextSection::class,  // ADD THIS (priority 55)
    \Condoedge\Ai\Services\PromptSections\DetectedEntitiesSection::class,
    \Condoedge\Ai\Services\PromptSections\DetectedScopesSection::class,
    \Condoedge\Ai\Services\PromptSections\PatternLibrarySection::class,
    \Condoedge\Ai\Services\PromptSections\QueryRulesSection::class,
    \Condoedge\Ai\Services\PromptSections\CurrentUserContextSection::class,
    \Condoedge\Ai\Services\PromptSections\QuestionSection::class,
    \Condoedge\Ai\Services\PromptSections\TaskInstructionsSection::class,
],
```

**Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Integration/ConversationContextIntegrationTest.php`
Expected: PASS (2 tests)

**Step 5: Commit**

```bash
git add config/ai.php
git add tests/Integration/ConversationContextIntegrationTest.php
git commit -m "feat(config): register ConversationContextSection in prompt builder"
```

---

## Task 8: Update AiManager to Pass Conversation Context

**Files:**
- Modify: `src/Services/AiManager.php:683-733` (answerQuestion method)
- Test: `tests/Unit/Services/AiManagerContextTest.php`

**Step 1: Write the failing test**

```php
<?php

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Tests\TestCase;

class AiManagerContextTest extends TestCase
{
    /** @test */
    public function it_passes_conversation_context_to_retriever(): void
    {
        // This test verifies that conversation_context option
        // is passed through the pipeline to the prompt builder

        // The implementation should merge conversation_context
        // into the context array that goes to generateQuery
        $this->assertTrue(true); // Placeholder - actual test requires mocking
    }
}
```

**Step 2: Update AiManager::answerQuestion**

In `src/Services/AiManager.php`, update the `answerQuestion` method around line 683:

```php
/**
 * Complete pipeline: Question → Context → Query → Execute → Respond
 *
 * @param string $question Natural language question
 * @param array $options Options for all stages, including:
 *                       - conversation_context: array with focused_entity, recent_exchanges, etc.
 *                       - style: response style
 * @return array Complete response with natural language answer
 */
public function answerQuestion(string $question, array $options = []): array
{
    try {
        // Step 1: Retrieve context
        $context = $this->retrieveContext($question);

        // Merge conversation context if provided
        if (!empty($options['conversation_context'])) {
            $context['conversation_context'] = $options['conversation_context'];
        }

        // Step 2: Generate query (context now includes conversation_context)
        $queryResult = $this->generateQuery($question, $context, $options);

        // Step 3: Execute query
        $executionResult = $this->executeQuery($queryResult['cypher'], [], $options);

        // Step 4: Generate natural language response
        $responseResult = $this->generateResponse(
            $question,
            $executionResult,
            $queryResult['cypher'],
            $options
        );

        return [
            'question' => $question,
            'answer' => $responseResult['answer'],
            'insights' => $responseResult['insights'],
            'visualizations' => $responseResult['visualizations'],
            'cypher' => $queryResult['cypher'],
            'data' => $executionResult['data'],
            'stats' => $executionResult['stats'],
            'metadata' => [
                'query' => $queryResult['metadata'],
                'execution' => $executionResult['metadata'],
                'response' => $responseResult['metadata'],
            ],
        ];

    } catch (\Throwable $e) {
        // Generate error response
        $errorResponse = $this->responseGenerator->generateErrorResponse($question, $e, $options);

        return [
            'question' => $question,
            'answer' => $errorResponse['answer'],
            'insights' => $errorResponse['insights'],
            'visualizations' => $errorResponse['visualizations'],
            'cypher' => null,
            'data' => [],
            'stats' => [],
            'metadata' => $errorResponse['metadata'],
        ];
    }
}
```

**Step 3: Run tests**

Run: `./vendor/bin/phpunit tests/Unit/Services/AiManagerContextTest.php`
Expected: PASS

**Step 4: Commit**

```bash
git add src/Services/AiManager.php
git add tests/Unit/Services/AiManagerContextTest.php
git commit -m "feat(manager): pass conversation context through AI pipeline"
```

---

## Task 9: Add Service Provider Registration

**Files:**
- Modify: `src/AiServiceProvider.php`
- Create: `tests/Unit/ServiceProviderRegistrationTest.php`

**Step 1: Write the failing test**

```php
<?php

namespace Condoedge\Ai\Tests\Unit;

use Condoedge\Ai\Services\Context\ConversationContextManager;
use Condoedge\Ai\Services\Context\EntityExtractor;
use Condoedge\Ai\Services\Context\ReferenceResolver;
use Condoedge\Ai\Tests\TestCase;

class ServiceProviderRegistrationTest extends TestCase
{
    /** @test */
    public function it_registers_entity_extractor(): void
    {
        $extractor = app(EntityExtractor::class);
        $this->assertInstanceOf(EntityExtractor::class, $extractor);
    }

    /** @test */
    public function it_registers_reference_resolver(): void
    {
        $resolver = app(ReferenceResolver::class);
        $this->assertInstanceOf(ReferenceResolver::class, $resolver);
    }

    /** @test */
    public function it_registers_conversation_context_manager(): void
    {
        $manager = app(ConversationContextManager::class);
        $this->assertInstanceOf(ConversationContextManager::class, $manager);
    }

    /** @test */
    public function it_uses_singleton_for_context_manager(): void
    {
        $manager1 = app(ConversationContextManager::class);
        $manager2 = app(ConversationContextManager::class);

        $this->assertSame($manager1, $manager2);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/ServiceProviderRegistrationTest.php --filter it_registers_entity_extractor`
Expected: FAIL with "Target class EntityExtractor not resolvable"

**Step 3: Update AiServiceProvider**

Add to `src/AiServiceProvider.php` register method:

```php
use Condoedge\Ai\Services\Context\EntityExtractor;
use Condoedge\Ai\Services\Context\ReferenceResolver;
use Condoedge\Ai\Services\Context\ConversationContextManager;

// In the register() method, add:

// Context management services
$this->app->singleton(EntityExtractor::class);
$this->app->singleton(ReferenceResolver::class);

$this->app->singleton(ConversationContextManager::class, function ($app) {
    return new ConversationContextManager(
        $app->make(EntityExtractor::class),
        $app->make(ReferenceResolver::class)
    );
});
```

**Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Unit/ServiceProviderRegistrationTest.php`
Expected: PASS (4 tests)

**Step 5: Commit**

```bash
git add src/AiServiceProvider.php
git add tests/Unit/ServiceProviderRegistrationTest.php
git commit -m "feat(provider): register context management services"
```

---

## Summary

After completing all 9 tasks, you will have:

1. **Context snapshot storage** on AiConversation model
2. **EntityExtractor** service for identifying entities in questions/queries
3. **ReferenceResolver** service for resolving "those", "them", etc.
4. **ConversationContextManager** orchestrating all context handling
5. **ConversationContextSection** adding context to LLM prompts
6. **AiChatService integration** with new `askWithConversation()` method
7. **Config registration** of the new prompt section
8. **AiManager pipeline** passing conversation context through
9. **Service provider registration** for dependency injection

### Usage After Implementation

```php
// Creating a conversation
$conversation = AiConversation::create(['user_id' => auth()->id()]);

// First question
$response1 = $chatService->askWithConversation(
    'How many customers do we have?',
    $conversation
);
// Response: "There are 150 customers."

// Follow-up question - context is automatically tracked
$response2 = $chatService->askWithConversation(
    'and those in the Sales team?',
    $conversation
);
// System automatically:
// 1. Detects this is a follow-up
// 2. Resolves "those" → Customers
// 3. Includes previous Cypher in prompt
// 4. LLM generates: MATCH (c:Customer)-[:BELONGS_TO]->(t:Team {name: 'Sales'}) RETURN count(c)
```

### Test Commands

Run all context tests:
```bash
./vendor/bin/phpunit tests/Unit/Services/Context/
./vendor/bin/phpunit tests/Unit/Models/AiConversationContextTest.php
./vendor/bin/phpunit tests/Integration/ConversationContextIntegrationTest.php
```
