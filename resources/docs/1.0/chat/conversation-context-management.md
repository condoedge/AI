# Conversation Context Management

Track conversation context across multiple messages to enable natural follow-up questions and reference resolution.

---

## Overview

The Conversation Context Management system allows users to have natural multi-turn conversations with the AI. It tracks what entities are being discussed, resolves references like "those" and "them", and maintains context across multiple questions.

### Key Benefits

- **Natural Conversations** - Users can ask follow-up questions without repeating context
- **Reference Resolution** - Pronouns like "those", "them", "it" are automatically resolved
- **Entity Tracking** - The system tracks which entities are being discussed
- **Context-Aware Prompts** - The LLM receives relevant conversation history

### Example Conversation

```
User: "Show me all customers in California"
AI: [Returns list of California customers]

User: "How many of those placed orders last month?"
AI: [Understands "those" = California customers, returns count]

User: "Show me the top 5 by revenue"
AI: [Continues with California customers context]
```

---

## Architecture

The system consists of four main components:

```
                    ┌─────────────────────────┐
                    │     AiChatService       │
                    │  askWithConversation()  │
                    └───────────┬─────────────┘
                                │
                    ┌───────────▼─────────────┐
                    │ ConversationContextMgr  │
                    │  - processQuestion()    │
                    │  - recordResponse()     │
                    │  - buildPromptContext() │
                    └───────────┬─────────────┘
                       ┌────────┴────────┐
           ┌───────────▼───┐     ┌───────▼───────────┐
           │EntityExtractor│     │ReferenceResolver  │
           │ - Questions   │     │ - Follow-ups      │
           │ - Cypher      │     │ - Pronouns        │
           └───────────────┘     └───────────────────┘
                                          │
                    ┌─────────────────────▼───────┐
                    │  ConversationContextSection │
                    │  (Adds context to prompts)  │
                    └─────────────────────────────┘
```

---

## Components

### EntityExtractor

Extracts entity types and query patterns from questions and Cypher queries.

**Location:** `src/Services/Context/EntityExtractor.php`

```php
use Condoedge\Ai\Services\Context\EntityExtractor;

$extractor = new EntityExtractor();

// Extract from a natural language question
$result = $extractor->extractFromQuestion(
    'How many customers placed orders last month?',
    ['labels' => ['Customer', 'Order', 'Product']]
);

// Returns:
// [
//     'focused_entity' => 'Customer',
//     'query_type' => 'count',
//     'mentioned_entities' => ['Customer', 'Order']
// ]

// Extract from a Cypher query
$cypherResult = $extractor->extractFromCypher(
    'MATCH (c:Customer)-[:PLACED]->(o:Order) RETURN c'
);

// Returns:
// [
//     'entities' => ['Customer', 'Order'],
//     'relationships' => ['PLACED']
// ]
```

**Query Types Detected:**

| Type | Trigger Words |
|------|---------------|
| `aggregate` | sum, total, average, avg, max, min, revenue |
| `count` | how many, count, number of |
| `list` | show, list, display, get, find, all |
| `detail` | detail, specific, particular, information about |
| `compare` | compare, versus, vs, difference, between |

---

### ReferenceResolver

Resolves conversational references like "those", "them", "the same" by using conversation context.

**Location:** `src/Services/Context/ReferenceResolver.php`

```php
use Condoedge\Ai\Services\Context\ReferenceResolver;

$resolver = new ReferenceResolver();

// Check if a question is a follow-up
$isFollowUp = $resolver->isFollowUp('and those in California?');
// Returns: true

// Detect reference type
$type = $resolver->detectReferenceType('Show me those');
// Returns: 'demonstrative'

// Resolve references using context
$context = [
    'focused_entity' => 'Customer',
    'last_cypher_query' => 'MATCH (c:Customer) RETURN c',
];

$resolution = $resolver->resolve('filter those by state', $context);

// Returns:
// [
//     'resolved' => true,
//     'resolved_entity' => 'Customer',
//     'operation' => 'filter',
//     'enriched_question' => 'filter customers by state',
//     'reference_type' => 'demonstrative'
// ]
```

**Follow-up Patterns Detected:**

- Conjunctions: "and...", "but...", "also..."
- Questions: "what about..."
- Pronouns: "those", "them", "these", "it"
- Continuations: "show me the...", "the same...", "top 5..."

**Reference Types:**

| Type | Examples |
|------|----------|
| `pronoun` | them, they, it |
| `demonstrative` | those, these, that, this |
| `definite` | the same, the top, the first |
| `implicit` | "top 5 by revenue" (no entity mentioned) |

---

### ConversationContextManager

Orchestrates context tracking by combining entity extraction and reference resolution.

**Location:** `src/Services/Context/ConversationContextManager.php`

```php
use Condoedge\Ai\Services\Context\ConversationContextManager;
use Condoedge\Ai\Services\Context\EntityExtractor;
use Condoedge\Ai\Services\Context\ReferenceResolver;

$manager = new ConversationContextManager(
    new EntityExtractor(),
    new ReferenceResolver()
);
```

**Key Methods:**

#### processQuestion()

Process an incoming question and update conversation context.

```php
$result = $manager->processQuestion(
    $conversation,
    'Show me customers in California',
    ['labels' => ['Customer', 'Order', 'Product']]
);

// Returns:
// [
//     'is_follow_up' => false,
//     'focused_entity' => 'Customer',
//     'query_type' => 'list',
//     'mentioned_entities' => ['Customer'],
//     'enriched_question' => 'Show me customers in California',
//     'resolved_entity' => null
// ]
```

#### recordResponse()

Record an AI response and update context with query information.

```php
$manager->recordResponse(
    $conversation,
    'Here are 150 customers in California...',
    'MATCH (c:Customer {state: "CA"}) RETURN c',
    ['data' => [...]] // Query results
);
```

#### buildPromptContext()

Build context data for the prompt builder.

```php
$promptContext = $manager->buildPromptContext($conversation, maxHistory: 5);

// Returns:
// [
//     'focused_entity' => 'Customer',
//     'mentioned_entities' => ['Customer', 'Order'],
//     'last_query_type' => 'list',
//     'last_cypher_query' => 'MATCH (c:Customer) RETURN c',
//     'last_result_count' => 150,
//     'recent_exchanges' => [...]
// ]
```

---

### ConversationContextSection

Adds conversation context to LLM prompts as a prompt section.

**Location:** `src/Services/PromptSections/ConversationContextSection.php`

**Priority:** 55 (after similar_queries at 50, before detected_entities at 60)

The section is automatically included when conversation context is available. It formats the context for the LLM to understand:

```
=== CONVERSATION CONTEXT ===

**Current Focus:** Customer (list query)

**Recent Conversation:**
  [1] User: Show me all customers
      Assistant: Here are all customers...
      Query: MATCH (c:Customer) RETURN c

**Note:** This is a continuation of the previous conversation.
Consider building upon or modifying the previous query.
Pronouns like 'those', 'them', 'it' refer to the Customer entity.

**Entities discussed:** Customer, Order
```

---

## Usage

### Basic Usage with askWithConversation()

The simplest way to use conversation context is through `AiChatService::askWithConversation()`:

```php
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Services\Chat\AiChatService;

// Create or retrieve a conversation
$conversation = AiConversation::create([
    'user_id' => auth()->id(),
]);

$chatService = app(AiChatService::class);

// First question
$response1 = $chatService->askWithConversation(
    'Show me all customers in California',
    $conversation
);

// Follow-up question - "those" is automatically resolved
$response2 = $chatService->askWithConversation(
    'How many of those placed orders last month?',
    $conversation
);

// Another follow-up
$response3 = $chatService->askWithConversation(
    'Show me the top 5 by revenue',
    $conversation
);
```

### Preview Question Processing

Use `prepareQuestionWithContext()` to preview how a question will be enriched:

```php
$schema = ['labels' => ['Customer', 'Order', 'Product']];

$preview = $chatService->prepareQuestionWithContext(
    'and those in the sales team?',
    $conversation,
    $schema
);

// Returns:
// [
//     'is_follow_up' => true,
//     'enriched_question' => 'Show Customers and customers in the sales team?',
//     'focused_entity' => 'Customer',
//     'query_type' => null,
//     'mentioned_entities' => [],
//     'resolved_entity' => 'Customer',
//     'context' => [...]
// ]
```

### Direct Context Manager Usage

For advanced use cases, use the ConversationContextManager directly:

```php
use Condoedge\Ai\Services\Context\ConversationContextManager;
use Condoedge\Ai\Services\Context\EntityExtractor;
use Condoedge\Ai\Services\Context\ReferenceResolver;

$manager = new ConversationContextManager(
    new EntityExtractor(),
    new ReferenceResolver()
);

// Process a question
$result = $manager->processQuestion($conversation, $question, $schema);

if ($result['is_follow_up']) {
    // Use enriched question for AI
    $questionToAsk = $result['enriched_question'];
}

// After getting AI response
$manager->recordResponse(
    $conversation,
    $response,
    $cypherQuery,
    $queryResults
);

// Build context for custom prompt
$promptContext = $manager->buildPromptContext($conversation);
```

---

## How It Works

### Question Processing Flow

```
1. User asks: "Show me customers in California"
   │
   ├─► EntityExtractor identifies:
   │   - focused_entity: Customer
   │   - query_type: list
   │
   ├─► ReferenceResolver checks:
   │   - isFollowUp: false (no pronouns/conjunctions)
   │
   └─► Context updated on AiConversation:
       - focused_entity: Customer
       - mentioned_entities: [Customer]

2. AI responds with Cypher query
   │
   └─► recordResponse() updates:
       - last_cypher_query: MATCH (c:Customer)...
       - last_result_count: 150

3. User asks: "How many of those placed orders?"
   │
   ├─► EntityExtractor identifies:
   │   - focused_entity: Order (from "orders")
   │   - query_type: count
   │
   ├─► ReferenceResolver detects:
   │   - isFollowUp: true ("those" detected)
   │   - Resolves "those" → Customer (from context)
   │
   └─► Returns enriched question:
       "How many of customers placed orders?"
```

### Context Snapshot Structure

The `AiConversation` model stores context in the `context_snapshot` JSON field:

```php
[
    'focused_entity' => 'Customer',        // Currently discussed entity
    'mentioned_entities' => [              // All entities mentioned
        'Customer',
        'Order'
    ],
    'last_query_type' => 'list',          // Type of last query
    'last_relationships' => ['PLACED'],    // Relationships from last Cypher
    'last_result_count' => 150,           // Number of results returned
]
```

### Prompt Context Structure

The `buildPromptContext()` method returns:

```php
[
    'focused_entity' => 'Customer',
    'mentioned_entities' => ['Customer', 'Order'],
    'last_query_type' => 'list',
    'last_cypher_query' => 'MATCH (c:Customer) RETURN c',
    'last_result_count' => 150,
    'recent_exchanges' => [
        [
            'user' => [
                'content' => 'Show me all customers',
                'cypher_query' => null,
            ],
            'assistant' => [
                'content' => 'Here are all customers...',
                'cypher_query' => 'MATCH (c:Customer) RETURN c',
            ],
        ],
        // ... more exchanges
    ],
]
```

---

## API Reference

### AiChatService

#### askWithConversation()

```php
public function askWithConversation(
    string $question,
    AiConversation $conversation,
    array $options = []
): AiChatMessage
```

Process a question within a persistent conversation context.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$question` | string | The user's question |
| `$conversation` | AiConversation | The conversation model |
| `$options` | array | Optional settings (schema, style, etc.) |

**Options:**

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `schema` | array | Auto-fetched | Entity schema for extraction |
| `style` | string | 'friendly' | Response style |

#### prepareQuestionWithContext()

```php
public function prepareQuestionWithContext(
    string $question,
    AiConversation $conversation,
    array $schema
): array
```

Prepare a question with context without executing.

**Returns:**

```php
[
    'is_follow_up' => bool,
    'enriched_question' => string,
    'focused_entity' => ?string,
    'query_type' => ?string,
    'mentioned_entities' => array,
    'resolved_entity' => ?string,
    'context' => array,
]
```

---

### ConversationContextManager

#### processQuestion()

```php
public function processQuestion(
    AiConversation $conversation,
    string $question,
    array $schema
): array
```

Process an incoming question and update conversation context.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$conversation` | AiConversation | The conversation model |
| `$question` | string | The user's question |
| `$schema` | array | Schema with 'labels' key |

**Returns:**

```php
[
    'is_follow_up' => bool,
    'focused_entity' => ?string,
    'query_type' => ?string,
    'mentioned_entities' => array,
    'enriched_question' => string,
    'resolved_entity' => ?string,
]
```

#### recordResponse()

```php
public function recordResponse(
    AiConversation $conversation,
    string $response,
    string $cypherQuery,
    array $queryResult
): void
```

Record an AI response and update context with query information.

#### buildPromptContext()

```php
public function buildPromptContext(
    AiConversation $conversation,
    int $maxHistory = 5
): array
```

Build context data for the prompt builder.

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `$conversation` | AiConversation | - | The conversation model |
| `$maxHistory` | int | 5 | Maximum exchanges to include |

---

### AiConversation Model

#### Context Methods

```php
// Update the context snapshot
$conversation->updateContextSnapshot([
    'focused_entity' => 'Customer',
    'mentioned_entities' => ['Customer', 'Order'],
]);

// Get focused entity
$entity = $conversation->getFocusedEntity();

// Get mentioned entities
$entities = $conversation->getMentionedEntities();

// Get last query type
$queryType = $conversation->getLastQueryType();

// Get last Cypher query
$cypher = $conversation->getLastCypherQuery();
```

---

## Configuration

### Enabling the ConversationContextSection

The section is registered in `config/ai.php`:

```php
'query_generator_sections' => [
    // ... other sections
    \Condoedge\Ai\Services\PromptSections\ConversationContextSection::class,
],
```

### History Limits

Configure maximum history messages:

```php
// config/ai.php
'chat' => [
    'max_history_messages' => 10,
],
```

### Section Priority

The ConversationContextSection has priority 55. To change it, extend the class:

```php
class CustomContextSection extends ConversationContextSection
{
    protected int $priority = 45; // Custom priority
}
```

---

## Testing

### Running Tests

```bash
# Run all conversation context tests
./vendor/bin/phpunit --filter ConversationContext

# Run specific test files
./vendor/bin/phpunit tests/Unit/Services/Context/ConversationContextManagerTest.php
./vendor/bin/phpunit tests/Unit/Services/Context/EntityExtractorTest.php
./vendor/bin/phpunit tests/Unit/Services/Context/ReferenceResolverTest.php
./vendor/bin/phpunit tests/Unit/Services/PromptSections/ConversationContextSectionTest.php

# Run integration tests
./vendor/bin/phpunit tests/Integration/ConversationContextIntegrationTest.php

# Run chat service context tests
./vendor/bin/phpunit tests/Unit/Services/Chat/AiChatServiceContextTest.php
```

### Test Coverage

| Component | Test File |
|-----------|-----------|
| EntityExtractor | `tests/Unit/Services/Context/EntityExtractorTest.php` |
| ReferenceResolver | `tests/Unit/Services/Context/ReferenceResolverTest.php` |
| ConversationContextManager | `tests/Unit/Services/Context/ConversationContextManagerTest.php` |
| ConversationContextSection | `tests/Unit/Services/PromptSections/ConversationContextSectionTest.php` |
| AiChatService (context) | `tests/Unit/Services/Chat/AiChatServiceContextTest.php` |
| Integration | `tests/Integration/ConversationContextIntegrationTest.php` |

---

## Best Practices

### 1. Always Use Conversations for Multi-Turn Chats

```php
// Good - context is preserved
$conversation = AiConversation::firstOrCreate([
    'user_id' => auth()->id(),
    'status' => 'active',
]);
$response = $chatService->askWithConversation($question, $conversation);

// Avoid - no context tracking
$response = $chatService->ask($question);
```

### 2. Provide Complete Schema

```php
// Good - full schema for accurate entity extraction
$schema = [
    'labels' => ['Customer', 'Order', 'Product', 'Category'],
    'relationships' => ['PLACED', 'CONTAINS', 'BELONGS_TO'],
];

// Avoid - missing entities may not be tracked
$schema = ['labels' => ['Customer']];
```

### 3. Let the System Handle References

Don't try to manually resolve references before sending to the AI:

```php
// Good - let the system resolve "those"
$response = $chatService->askWithConversation(
    'Show me those who ordered last month',
    $conversation
);

// Avoid - manual resolution may miss context
$response = $chatService->askWithConversation(
    'Show me customers who ordered last month', // "those" already replaced
    $conversation
);
```

### 4. Clear Context When Starting New Topics

```php
// Start a new conversation for unrelated topics
if ($isNewTopic) {
    $conversation = AiConversation::create([
        'user_id' => auth()->id(),
    ]);
}
```

---

## Troubleshooting

### References Not Being Resolved

**Symptom:** Follow-up questions with "those" or "them" are not being resolved.

**Solutions:**

1. Verify the conversation has context:
   ```php
   $context = $conversation->context_snapshot;
   // Should have 'focused_entity' set
   ```

2. Check that the schema includes the relevant entities

3. Ensure `recordResponse()` was called after the previous response

### Context Not Persisting

**Symptom:** Context is lost between requests.

**Solutions:**

1. Verify the conversation model is being saved:
   ```php
   $conversation->refresh();
   dd($conversation->context_snapshot);
   ```

2. Check database migrations have run:
   ```bash
   php artisan migrate
   ```

### Prompt Not Including Context

**Symptom:** The LLM prompt does not contain conversation context.

**Solutions:**

1. Verify ConversationContextSection is registered:
   ```php
   $sections = config('ai.query_generator_sections');
   // Should include ConversationContextSection::class
   ```

2. Check that `conversation_context` is being passed to the prompt builder

---

## Related Documentation

- [Chat Components](/docs/{{version}}/chat/chat-ui) - Chat UI components
- [Chat Pipeline](/docs/{{version}}/chat/module-pipeline) - Chat processing pipeline
- [Custom Prompt Sections](/docs/{{version}}/extending/prompt-sections) - Creating custom sections
- [Entity Configuration](/docs/{{version}}/configuration/entities) - Configuring entities
