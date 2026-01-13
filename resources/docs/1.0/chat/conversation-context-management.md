# Conversation Context Management

Track conversation context across multiple messages to enable natural follow-up questions and reference resolution.

---

## Overview

The Conversation Context Management system allows users to have natural multi-turn conversations with the AI. It tracks what entities are being discussed, resolves references like "those" and "them", detects file references, and maintains context across multiple questions.

### Key Benefits

- **Natural Conversations** - Users can ask follow-up questions without repeating context
- **Reference Resolution** - Pronouns like "those", "them", "it" are automatically resolved
- **Entity Tracking** - The system tracks which entities are being discussed
- **File Detection** - Recognizes file references like "report.pdf" or "the file"
- **Context-Aware Prompts** - The LLM receives relevant conversation history and result samples

### Example Conversation

```
User: "Show me all customers in California"
AI: [Returns list of California customers]

User: "How many of those placed orders last month?"
AI: [Understands "those" = California customers, returns count]

User: "Show me the top 5 by revenue"
AI: [Continues with California customers context]

User: "Show me their orders"
AI: [Resolves "their" to the top 5 California customers, returns their orders]
```

---

## Architecture

The system consists of five main components:

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
                       ┌────────┼────────┐
           ┌───────────▼───┐    │    ┌───▼───────────┐
           │EntityExtractor│    │    │ReferenceResolver│
           │ - Questions   │    │    │ - Follow-ups    │
           │ - Cypher      │    │    │ - Pronouns      │
           └───────────────┘    │    └─────────────────┘
                          ┌─────▼─────────┐
                          │FilenameExtract│
                          │ - File refs   │
                          └───────────────┘
                                │
        ┌───────────────────────┴───────────────────────┐
        │                                               │
┌───────▼───────────────────┐       ┌───────────────────▼───────┐
│ ConversationContextSection│       │ResponseConversationContext│
│  (Query prompt context)   │       │  (Response prompt context)│
└───────────────────────────┘       └───────────────────────────┘
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

**How Entity Detection Works:**

1. The question is converted to lowercase for case-insensitive matching
2. Each schema label is checked for presence (singular and plural forms)
3. The first mentioned entity becomes the "focused entity"
4. Query type is detected using regex patterns against the question

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
//     'base_query' => null,
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

**How Reference Resolution Works:**

1. Check if question starts with conjunctions or contains pronouns
2. Detect the reference type (pronoun, demonstrative, definite, implicit)
3. Look up the focused entity from conversation context
4. Determine the operation (filter, modify, extend)
5. Build an enriched question by replacing pronouns with the resolved entity

**Operation Types:**

| Operation | Trigger Words | Description |
|-----------|---------------|-------------|
| `modify` | sort, order, group | Reorders or reorganizes results |
| `extend` | same, also, include | Adds to existing results |
| `filter` | filter, where, in, with, by | Narrows down results |

---

### FilenameExtractor

Extracts filenames from natural language queries to enable filename-based search alongside semantic search.

**Location:** `src/Services/Context/FilenameExtractor.php`

```php
use Condoedge\Ai\Services\Context\FilenameExtractor;

$extractor = new FilenameExtractor();

// Extract filenames from a query
$filenames = $extractor->extract('Show me the report.pdf file');
// Returns: ['report.pdf']

// Works with quoted filenames (can contain spaces)
$filenames = $extractor->extract('Find "my document.pdf"');
// Returns: ['my document.pdf']

// Extracts from paths
$filenames = $extractor->extract('Look at docs/readme.md');
// Returns: ['readme.md']
```

**Supported File Extensions:**

- Documents: txt, pdf, doc, docx, md, rtf, odt
- Spreadsheets: xls, xlsx, csv
- Data: json, xml, html, htm
- Presentations: ppt, pptx
- Images: png, jpg, jpeg, gif, svg

**Detection Patterns:**

1. **Quoted filenames** - Matches `"my document.pdf"` or `'report.xlsx'`
2. **Unquoted filenames** - Matches `report.pdf`, `budget_2024.xlsx`
3. **Path extraction** - Extracts filename from paths like `docs/readme.md`

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

Record an AI response and update context with query information. This method extracts comprehensive data from the response to enable reference resolution in follow-up questions.

```php
$manager->recordResponse(
    $conversation,
    'Here are 150 customers in California...',
    'MATCH (c:Customer {state: "CA"}) RETURN c',
    [
        'data' => [...],                    // Query results
        'referenced_files' => [...],        // Files mentioned in response
        'detected_template' => 'list',      // Query template type
        'query_type' => 'list',             // Type of query
        'insights' => [...],                // Analysis insights
        'stats' => [...],                   // Execution statistics
        'available_actions' => [...],       // Actions for entity types
        'visualizations' => [...],          // Visualization metadata
    ]
);
```

**Data Stored by recordResponse():**

| Field | Description |
|-------|-------------|
| `focused_entity` | Primary entity type from the query |
| `mentioned_entities` | All entities mentioned in conversation |
| `last_relationships` | Relationships from Cypher query |
| `last_cypher_query` | The executed Cypher query |
| `last_result_count` | Number of results returned |
| `last_result_sample` | First 10 results for context |
| `focused_entity_filter` | WHERE clause conditions |
| `last_answer_summary` | Truncated response (500 chars) |
| `last_referenced_files` | Files mentioned in response |
| `last_detected_template` | Query template type |
| `last_insights` | Analysis insights |
| `last_execution_stats` | Query execution statistics |
| `last_available_actions` | Available entity actions |
| `last_visualizations` | Visualization metadata |

#### buildPromptContext()

Build comprehensive context data for the prompt builder.

```php
$promptContext = $manager->buildPromptContext($conversation, maxHistory: 5);

// Returns:
// [
//     'focused_entity' => 'Customer',
//     'focused_entity_filter' => 'c.state = "CA"',
//     'last_result_sample' => [...],
//     'last_result_count' => 150,
//     'last_cypher_query' => 'MATCH (c:Customer {state: "CA"}) RETURN c',
//     'last_query_type' => 'list',
//     'last_detected_template' => 'list',
//     'last_relationships' => ['PLACED'],
//     'mentioned_entities' => ['Customer', 'Order'],
//     'last_answer_summary' => 'Here are 150 customers...',
//     'last_referenced_files' => [...],
//     'last_insights' => [...],
//     'last_visualizations' => [...],
//     'last_execution_stats' => [...],
//     'last_available_actions' => [...],
//     'recent_exchanges' => [
//         [
//             'role' => 'user',
//             'question' => 'Show me all customers',
//             'answer_summary' => null,
//         ],
//         [
//             'role' => 'assistant',
//             'question' => null,
//             'answer_summary' => 'Here are all customers...',
//         ],
//     ],
// ]
```

---

### ConversationContextSection

Adds conversation context to LLM prompts for query generation.

**Location:** `src/Services/PromptSections/ConversationContextSection.php`

**Priority:** 55 (after similar_queries at 50, before detected_entities at 60)

**Inclusion Conditions:**

The section is included when any of the following exist in context:
- `focused_entity` - A currently focused entity type
- `recent_exchanges` - Recent conversation history
- `last_cypher_query` - A previous Cypher query
- `last_result_sample` - Sample of previous results
- `last_referenced_files` - Files from previous response

**Formatted Output Example:**

```
## Conversation Context

**Current Focus:** Customer (list query)
**Active Filter:** `c.state = "CA"`

**Previous Query:**
```cypher
MATCH (c:Customer {state: "CA"}) RETURN c
```
Returned 150 results.

**Sample of Previous Results:**
```json
[
  {"name": "Acme Corp", "state": "CA"},
  {"name": "Tech Inc", "state": "CA"}
]
```

**Recent Conversation:**
- User: Show me all customers
  Assistant: Here are all customers...

**Entities discussed:** Customer, Order

**Files Referenced in Previous Response:**
- [report.pdf] (ID: 123): Summary of quarterly sales...

**Previous Insights:**
- Most customers are in California
- Average order value is $500

**Note:** Previous results were truncated (showing 100 of 500 available).

**Available Actions from Previous Response:**
- **Customer:**
  - `view_details`: View customer details
  - `export`: Export to CSV

**Instructions:** Use the above context to understand follow-up questions. If user references 'those', 'them', 'the same', etc., use the previous results/filter. If user asks about 'the file', 'that file', etc., refer to the files referenced above.
```

---

### ResponseConversationContextSection

Adds conversation context to response generation prompts, enabling the AI to answer follow-up questions about files and previous results.

**Location:** `src/Services/ResponseSections/ResponseConversationContextSection.php`

**Priority:** 45 (after FileContextSection at 40, before ResultsData at 50)

**Inclusion Conditions:**

The section is included when any of the following exist:
- `last_referenced_files` - Files from previous response
- `last_result_sample` - Sample of previous results
- `focused_entity` - A currently focused entity type

**Formatted Output Example:**

```
=== Conversation Context ===

**Files Referenced in Previous Response:**

**[1] report.pdf** (ID: 123)
Download: /api/files/123/download
Content:
```
Quarterly sales summary showing growth of 15%...
```

**Instructions:** If user asks about 'the file', 'that file', 'raw content', or similar references, use the file content above to answer.

**Previous Query Results Sample:**
```json
[
  {"name": "Acme Corp", "revenue": 50000}
]
```

**Current Focus:** Customer
**Active Filter:** `c.state = "CA"`
```

---

## Data Storage

### AiConversation Model

The `AiConversation` model stores conversation state and context in the `context_snapshot` JSON field.

**Location:** `src/Models/AiConversation.php`

**Key Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `uuid` | string | Unique identifier |
| `user_id` | integer | Owner user ID |
| `team_id` | integer | Team ID (optional) |
| `title` | string | Auto-generated from first message |
| `status` | string | active, archived |
| `metadata` | json | Additional metadata |
| `context_snapshot` | json | Conversation context data |
| `last_message_at` | datetime | Timestamp of last message |

**Context Accessor Methods:**

```php
// Get the currently focused entity
$entity = $conversation->getFocusedEntity();
// Returns: 'Customer' or null

// Get all mentioned entities
$entities = $conversation->getMentionedEntities();
// Returns: ['Customer', 'Order']

// Get the last query type
$queryType = $conversation->getLastQueryType();
// Returns: 'list', 'count', 'aggregate', etc.

// Get the last Cypher query (from messages)
$cypher = $conversation->getLastCypherQuery();
// Returns: 'MATCH (c:Customer) RETURN c'

// Get the focused entity's filter condition
$filter = $conversation->getFocusedEntityFilter();
// Returns: 'c.state = "CA"'

// Get a sample of last results
$sample = $conversation->getLastResultSample();
// Returns: [['name' => 'Acme Corp'], ...]

// Get the previous Cypher query (from context)
$previousQuery = $conversation->getPreviousCypherQuery();
// Returns: 'MATCH (c:Customer) RETURN c'

// Get the count of last results
$count = $conversation->getLastResultCount();
// Returns: 150
```

**Context Update Methods:**

```php
// Update context snapshot (merges with existing)
$conversation->updateContextSnapshot([
    'focused_entity' => 'Customer',
    'mentioned_entities' => ['Customer', 'Order'],
    'last_query_type' => 'list',
]);

// Update entity context with query result data
$conversation->updateEntityContext([
    'ids' => [1, 2, 3],
    'names' => ['Acme', 'Tech Inc', 'Global Corp'],
]);
```

### AiMessage Model

The `AiMessage` model stores individual messages within a conversation.

**Location:** `src/Models/AiMessage.php`

**Key Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `conversation_id` | integer | Parent conversation |
| `role` | string | 'user' or 'assistant' |
| `content` | text | Message content |
| `response_data` | json | Structured response data |
| `context_used` | json | Context at time of message |
| `cypher_query` | text | Generated Cypher query |
| `execution_time_ms` | integer | Query execution time |
| `confidence_score` | float | AI confidence score |
| `metadata` | json | Additional metadata |

**File Reference Methods:**

```php
// Get referenced files from metadata
$files = $message->getReferencedFiles();
// Returns: [['id' => 123, 'name' => 'report.pdf', 'snippet' => '...']]

// Check if message has file references
if ($message->hasFileReferences()) {
    // Process file references
}
```

**Metadata Structure:**

The message metadata can contain:
- `sources` or `referenced_files` - File references
- `suggestions` - Follow-up question suggestions
- `is_follow_up` - Whether this was a follow-up question
- `resolved_entity` - Entity resolved from reference
- `error` - Error information if query failed
- `regenerated` - Whether response was regenerated

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
       - last_result_sample: [first 10 results]
       - focused_entity_filter: c.state = "CA"
       - last_answer_summary: "Here are 150 customers..."

3. User asks: "How many of those placed orders?"
   │
   ├─► EntityExtractor identifies:
   │   - focused_entity: Order (from "orders")
   │   - query_type: count
   │
   ├─► ReferenceResolver detects:
   │   - isFollowUp: true ("those" detected)
   │   - Resolves "those" → Customer (from context)
   │   - operation: filter
   │
   └─► Returns enriched question:
       "How many of customers placed orders?"
```

### Multi-Turn Example: Resolving "their orders"

This example shows how the system resolves complex references across multiple turns:

```
Turn 1: User: "Show me all customers in California"

        Processing:
        - EntityExtractor: focused_entity = Customer, query_type = list
        - ReferenceResolver: is_follow_up = false

        Context after response:
        - focused_entity: Customer
        - focused_entity_filter: c.state = "CA"
        - last_result_count: 150
        - last_result_sample: [{name: "Acme Corp", id: 1}, ...]

Turn 2: User: "Show me the top 5 by revenue"

        Processing:
        - EntityExtractor: focused_entity = null, query_type = aggregate
        - ReferenceResolver: is_follow_up = true (implicit reference)
        - Resolved entity: Customer (from context)

        Context after response:
        - focused_entity: Customer
        - last_result_sample: [{name: "Acme Corp", revenue: 500000}, ...]

Turn 3: User: "Show me their orders"

        Processing:
        - EntityExtractor: focused_entity = Order, query_type = list
        - ReferenceResolver:
          - is_follow_up = true
          - reference_type = pronoun ("their")
          - Resolved: "their" → top 5 California customers
        - enriched_question: "Show me orders for customers"

        The AI generates a query that:
        1. Uses the previous result sample to identify the top 5 customers
        2. Queries orders for those specific customers
```

### Context Snapshot Structure

The `AiConversation` model stores context in the `context_snapshot` JSON field:

```php
[
    // Entity tracking
    'focused_entity' => 'Customer',           // Currently discussed entity
    'mentioned_entities' => ['Customer', 'Order'],  // All entities mentioned
    'focused_entity_filter' => 'c.state = "CA"',    // WHERE clause filter

    // Query information
    'last_query_type' => 'list',              // Type of last query
    'last_detected_template' => 'list',       // Template used
    'last_relationships' => ['PLACED'],       // Relationships from Cypher
    'last_cypher_query' => 'MATCH...',        // Full Cypher query

    // Results
    'last_result_count' => 150,               // Number of results
    'last_result_sample' => [...],            // First 10 results
    'last_answer_summary' => 'Here are...',   // Truncated response

    // Files
    'last_referenced_files' => [              // Files from response
        ['id' => 123, 'name' => 'report.pdf', 'snippet' => '...']
    ],
    'referenced_files' => [123, 456],         // All file IDs in conversation

    // Metadata
    'last_insights' => ['Most customers...'], // Analysis insights
    'last_execution_stats' => [...],          // Query stats
    'last_available_actions' => [...],        // Entity actions
    'last_visualizations' => [...],           // Chart metadata
    'updated_at' => '2024-01-15T10:30:00Z',   // Last update time
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
    ?string $cypherQuery,
    array $queryResult
): void
```

Record an AI response and update context with query information.

**Query Result Keys:**

| Key | Type | Description |
|-----|------|-------------|
| `data` | array | Query result rows |
| `referenced_files` | array | Files mentioned in response |
| `detected_template` | string | Query template type |
| `query_type` | string | Type of query |
| `insights` | array | Analysis insights |
| `stats` | array | Execution statistics |
| `available_actions` | array | Actions for entity types |
| `visualizations` | array | Visualization metadata |

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
// Get focused entity
$entity = $conversation->getFocusedEntity();

// Get mentioned entities
$entities = $conversation->getMentionedEntities();

// Get last query type
$queryType = $conversation->getLastQueryType();

// Get last Cypher query (from messages)
$cypher = $conversation->getLastCypherQuery();

// Get focused entity filter
$filter = $conversation->getFocusedEntityFilter();

// Get last result sample
$sample = $conversation->getLastResultSample();

// Get previous Cypher query (from context)
$previousQuery = $conversation->getPreviousCypherQuery();

// Get last result count
$count = $conversation->getLastResultCount();

// Update context snapshot (merges with existing)
$conversation->updateContextSnapshot([
    'focused_entity' => 'Customer',
    'mentioned_entities' => ['Customer', 'Order'],
]);

// Update entity context
$conversation->updateEntityContext([
    'ids' => [1, 2, 3],
    'names' => ['Acme', 'Tech', 'Global'],
]);
```

#### Message Methods

```php
// Get recent messages
$messages = $conversation->getRecentMessages(limit: 10);

// Add a new message
$message = $conversation->addMessage('user', 'Show me customers', [
    'metadata' => ['source' => 'web'],
    'referenced_files' => [...],
    'suggestions' => [...],
    'is_follow_up' => true,
    'resolved_entity' => 'Customer',
]);
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

### Enabling the ResponseConversationContextSection

```php
'response_generator_sections' => [
    // ... other sections
    \Condoedge\Ai\Services\ResponseSections\ResponseConversationContextSection::class,
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
./vendor/bin/phpunit tests/Unit/Services/Context/FilenameExtractorTest.php
./vendor/bin/phpunit tests/Unit/Services/PromptSections/ConversationContextSectionTest.php
./vendor/bin/phpunit tests/Unit/Services/ResponseSections/ResponseConversationContextSectionTest.php

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
| FilenameExtractor | `tests/Unit/Services/Context/FilenameExtractorTest.php` |
| ConversationContextManager | `tests/Unit/Services/Context/ConversationContextManagerTest.php` |
| ConversationContextSection | `tests/Unit/Services/PromptSections/ConversationContextSectionTest.php` |
| ResponseConversationContextSection | `tests/Unit/Services/ResponseSections/ResponseConversationContextSectionTest.php` |
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

### 5. Use Context Preview for Debugging

```php
// Debug how a question will be processed
$preview = $chatService->prepareQuestionWithContext(
    $question,
    $conversation,
    $schema
);

Log::debug('Question processing', [
    'original' => $question,
    'enriched' => $preview['enriched_question'],
    'is_follow_up' => $preview['is_follow_up'],
    'resolved_entity' => $preview['resolved_entity'],
]);
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

4. Verify the question matches follow-up patterns:
   ```php
   $resolver = new ReferenceResolver();
   $isFollowUp = $resolver->isFollowUp($question);
   // Should return true for questions with pronouns
   ```

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

3. Ensure `updateContextSnapshot()` merges rather than overwrites:
   ```php
   // The method merges by default
   $conversation->updateContextSnapshot(['new_key' => 'value']);
   // Existing keys are preserved
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

3. Verify the section's `shouldInclude()` condition is met:
   ```php
   // Needs at least one of: focused_entity, recent_exchanges,
   // last_cypher_query, last_result_sample, last_referenced_files
   ```

### File References Not Working

**Symptom:** Follow-up questions about "the file" are not resolved.

**Solutions:**

1. Verify files are stored in context:
   ```php
   $files = $conversation->context_snapshot['last_referenced_files'] ?? [];
   // Should contain file data from previous response
   ```

2. Check ResponseConversationContextSection is registered for response generation

3. Ensure `recordResponse()` receives `referenced_files` in query result:
   ```php
   $manager->recordResponse($conversation, $response, $cypher, [
       'data' => [...],
       'referenced_files' => [
           ['id' => 123, 'name' => 'report.pdf', 'snippet' => '...']
       ],
   ]);
   ```

---

## Related Documentation

- [Chat Components](/docs/{{version}}/chat/chat-ui) - Chat UI components
- [Chat Pipeline](/docs/{{version}}/chat/module-pipeline) - Chat processing pipeline
- [Custom Prompt Sections](/docs/{{version}}/extending/prompt-sections) - Creating custom sections
- [Entity Configuration](/docs/{{version}}/configuration/entities) - Configuring entities
