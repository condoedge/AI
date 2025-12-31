# Phase 3: Chat Entry Point Flow Trace

## Executive Summary

This document traces the **ACTUAL** execution path when a user sends a message in the AI chat interface. It documents exactly which code runs, what data flows through each step, and critically - which code exists but **NEVER EXECUTES** in the primary chat flow.

## Complete Flow Diagram

```
USER TYPES MESSAGE
        |
        v
+------------------------------------------+
| AiChatPanel.php:522-533                  |
| inputArea() creates ChatMessageForm      |
| Passes: conversation_id, panel_id, style |
+------------------------------------------+
        |
        v
+------------------------------------------+
| ChatMessageForm.php:55-74                |
| render() creates textarea + send button  |
| Button: selfPost('sendMessage')          |
+------------------------------------------+
        |
        v (AJAX POST on button click)
+------------------------------------------+
| ChatMessageForm.php:113-195              |
| sendMessage() - MAIN ENTRY POINT         |
| 1. Gets message from request             |
| 2. Validates conversation exists         |
| 3. Saves user message                    |
| 4. Calls AI service                      |
| 5. Saves assistant message               |
+------------------------------------------+
        |
        v
+------------------------------------------+
| AiConversation.php:128-166               |
| addMessage('user', $message)             |
| Creates AiMessage record                 |
| Updates last_message_at                  |
| Auto-generates title from first message  |
+------------------------------------------+
        |
        v
+------------------------------------------+
| AiChatService.php:124-167                |
| askWithHistory() - CALLED BY FORM        |
| NOT askWithConversation() !              |
| Builds enriched question with history    |
| Calls AI::answerQuestion()               |
+------------------------------------------+
        |
        v
+------------------------------------------+
| AiManager.php:685-757                    |
| answerQuestion() - FULL PIPELINE         |
| Step 1: retrieveContext()                |
| Step 2: generateQuery()                  |
| Step 3: executeQuery()                   |
| Step 4: generateResponse()               |
+------------------------------------------+
        |
        +---------------+---------------+---------------+
        v               v               v               v
+----------------+ +----------------+ +----------------+ +----------------+
| ContextRetriever | QueryGenerator | QueryExecutor  | ResponseGenerator|
| :176-298        | :120-205       | :53-139        | :219-289         |
| retrieveContext | generate()     | execute()      | generate()       |
+----------------+ +----------------+ +----------------+ +----------------+
        |               |               |               |
        v               v               v               v
+----------------+ +----------------+ +----------------+ +----------------+
| VectorStore    | LlmProvider    | GraphStore     | LlmProvider      |
| (Qdrant)       | (OpenAI/etc)   | (Neo4j)        | (OpenAI/etc)     |
| searchSimilar  | complete()     | query()        | complete()       |
+----------------+ +----------------+ +----------------+ +----------------+
        |
        v (back up the chain)
+------------------------------------------+
| ChatMessageForm.php:166-174              |
| Saves assistant message with:            |
| - response_data                          |
| - referenced_files                       |
| - execution_time_ms                      |
| - confidence_score                       |
| - cypher_query                           |
| - metadata (suggestions)                 |
+------------------------------------------+
        |
        v
+------------------------------------------+
| AiChatPanel.php - Panel refresh          |
| renderMessages() re-fetches messages     |
| Displays new user + assistant bubbles    |
| Scrolls to bottom via JS                 |
+------------------------------------------+
```

## Detailed Step-by-Step Trace

### Step 1: UI Entry Point

**File:** `src/Kompo/AiChatPanel.php:522-533`

```php
protected function inputArea()
{
    if (!$this->conversation) {
        return null;
    }

    return new ChatMessageForm(null, [
        'conversation_id' => $this->conversation->id,
        'panel_id' => self::MESSAGES_PANEL_ID,
        'response_style' => $this->settings()->responseStyle(),
    ]);
}
```

**Data Passed:**
- `conversation_id` - integer
- `panel_id` - string constant `'chat-messages-panel'`
- `response_style` - string from settings (e.g., `'friendly'`)

### Step 2: Form Rendering

**File:** `src/Kompo/ChatMessageForm.php:55-74`

```php
public function render()
{
    return _Rows(
        _Flex(
            _Textarea()->name('message')...
            $this->responseStyleSelector(),
            _Button()->icon(_Sax('send-1', 20))...
                ->selfPost('sendMessage')->withAllFormValues()
                ->refresh($this->panelId)
                ->refresh('chat-message-form'),
        )...
    )...
}
```

**Data in Form:**
- `message` - user's text input
- `style` - optional response style selection

### Step 3: Form Submission Handler

**File:** `src/Kompo/ChatMessageForm.php:113-195`

```php
public function sendMessage()
{
    $message = trim(request('message') ?? '');  // Line 115
    $style = request('style') ?? $this->responseStyle;  // Line 116

    if (empty($message) || !$this->conversation) {
        return;  // Line 119 - silent return, no error shown
    }

    // Add user message - Line 123
    $this->conversation->addMessage('user', $message);

    try {
        $aiManager = app(AiChatServiceInterface::class);  // Line 126

        // Get conversation context for history - Lines 129-133
        $recentMessages = $this->conversation->getRecentMessages(10);
        $history = array_map(fn($m) => [
            'role' => $m['role'],
            'content' => $m['content'],
        ], $recentMessages);

        // Call AI service - Lines 136-139
        $response = $aiManager->askWithHistory($message, $history, [
            'style' => $style,
            'conversation_id' => $this->conversation->id,
        ]);

        // ... response processing continues
    }
}
```

**Data Transformation:**
- INPUT: Raw form data `request('message')`, `request('style')`
- OUTPUT to addMessage: `'user'`, trimmed message string
- OUTPUT to AI: message, history array, options array

### Step 4: User Message Storage

**File:** `src/Models/AiConversation.php:128-166`

```php
public function addMessage(string $role, string $content, array $data = []): AiMessage
{
    $metadata = $data['metadata'] ?? [];
    if (isset($data['referenced_files'])) {
        $metadata['referenced_files'] = $data['referenced_files'];
    }

    $message = $this->messages()->create([
        'role' => $role,
        'content' => $content,
        'response_data' => $data['response_data'] ?? null,
        'context_used' => $data['context_used'] ?? null,
        'cypher_query' => $data['cypher_query'] ?? null,
        'execution_time_ms' => $data['execution_time_ms'] ?? null,
        'confidence_score' => $data['confidence_score'] ?? null,
        'metadata' => !empty($metadata) ? $metadata : null,
    ]);

    $this->update(['last_message_at' => now()]);

    // Auto-generate title from first user message
    if ($this->title === null && $role === 'user') {
        $this->update(['title' => Str::limit($content, 50)]);
    }

    // ... context snapshot updates
}
```

**Database Record Created (AiMessage):**
| Column | Value |
|--------|-------|
| conversation_id | From conversation |
| role | 'user' |
| content | User's message |
| response_data | null (for user messages) |
| context_used | null |
| cypher_query | null |
| execution_time_ms | null |
| confidence_score | null |
| metadata | null |

### Step 5: AI Service Call

**File:** `src/Services/Chat/AiChatService.php:124-167`

```php
public function askWithHistory(string $question, array $history, array $options = []): AiChatMessage
{
    $startTime = microtime(true);
    $options = array_merge($this->config, $options);

    try {
        // Build context-enriched question with conversation history
        $enrichedQuestion = $this->buildQuestionWithHistory($question, $history, $options);

        // Use answerQuestion with RAG - pass enriched question
        $aiResponse = AI::answerQuestion($enrichedQuestion, [
            'style' => $options['style'] ?? 'friendly',
        ]);

        // ... response processing
    }
}
```

**CRITICAL: What Gets Called vs What Doesn't**

- **CALLED:** `askWithHistory()` at line 124
- **NOT CALLED:** `askWithConversation()` at line 183 - This method exists but is NEVER invoked from the UI!

**Data Passed to AI::answerQuestion:**
- `$enrichedQuestion` - string with history context prepended
- `options['style']` - response style

### Step 6: History Enrichment

**File:** `src/Services/Chat/AiChatService.php:334-374`

```php
protected function buildQuestionWithHistory(string $question, array $history, array $options): string
{
    $maxHistory = $options['max_history_messages'] ?? 10;
    $recentHistory = array_slice($history, -$maxHistory);

    if (count($recentHistory) <= 1) {
        return $question;  // No context needed
    }

    $contextMessages = array_slice($recentHistory, 0, -1);

    // Build context string
    $contextParts = [];
    foreach ($contextMessages as $message) {
        if ($role === 'user') {
            $contextParts[] = "User asked: {$content}";
        } else {
            $truncated = strlen($content) > 200 ? substr($content, 0, 200) . '...' : $content;
            $contextParts[] = "Assistant replied: {$truncated}";
        }
    }

    return "[Previous conversation context:]\n{$contextString}\n\n[Current question:]\n{$question}";
}
```

**Data Transformation:**
- INPUT: question, history array
- OUTPUT: Enriched string with format:
  ```
  [Previous conversation context:]
  User asked: ...
  Assistant replied: ...

  [Current question:]
  <actual question>
  ```

### Step 7: Full Pipeline Execution

**File:** `src/Services/AiManager.php:685-757`

```php
public function answerQuestion(string $question, array $options = []): array
{
    try {
        // Step 1: Retrieve context
        $context = $this->retrieveContext($question, $options);  // Line 689

        // Merge conversation context if provided
        if (!empty($options['conversation_context'])) {
            $context['conversation_context'] = $options['conversation_context'];
        }

        // Merge file context if enabled
        if (config('ai.file_context.enabled', true)) {
            $fileContext = $this->retrieveFileContext($question, $options['user'] ?? null);
            if (!empty($fileContext)) {
                $context['file_context'] = $fileContext;
            }
        }

        // Step 2: Generate query
        $queryResult = $this->generateQuery($question, $context, $options);  // Line 705

        // Step 3: Execute query
        $executionResult = $this->executeQuery($queryResult['cypher'], [], $options);  // Line 708

        // Step 4: Generate natural language response
        $responseResult = $this->generateResponse(
            $question,
            $executionResult,
            $queryResult['cypher'],
            $options
        );  // Lines 711-716

        return [
            'question' => $question,
            'answer' => $responseResult['answer'],
            'insights' => $responseResult['insights'],
            'visualizations' => $responseResult['visualizations'],
            'cypher' => $queryResult['cypher'],
            'data' => $executionResult['data'],
            'stats' => $executionResult['stats'],
            'referenced_files' => $responseResult['referenced_files'] ?? [],
            'metadata' => [...],
        ];
    }
}
```

**Full Pipeline Data Flow:**

| Step | Service | Input | Output |
|------|---------|-------|--------|
| 1 | ContextRetriever | question | similar_queries, graph_schema, entity_metadata |
| 2 | QueryGenerator | question, context | cypher, explanation, confidence |
| 3 | QueryExecutor | cypher | data, stats, metadata |
| 4 | ResponseGenerator | question, data, cypher | answer, insights, visualizations |

### Step 8: Context Retrieval

**File:** `src/Services/ContextRetriever.php:176-298`

```php
public function retrieveContext(string $question, array $options = []): array
{
    // 1. Search for similar queries (vector search)
    $context['similar_queries'] = $this->searchSimilarQueries(...);

    // 2. Get graph schema
    $context['graph_schema'] = $this->getGraphSchema();

    // 3. Get example entities
    $context['relevant_entities'] = $this->retrieveExampleEntities(...);

    // 4. Get entity metadata
    $context['entity_metadata'] = $this->getEntityMetadata($question);

    return $context;
}
```

**Context Structure Returned:**
```php
[
    'similar_queries' => [
        ['question' => '...', 'query' => 'MATCH...', 'score' => 0.89],
    ],
    'graph_schema' => [
        'labels' => ['Customer', 'Order', ...],
        'relationships' => ['PLACED', 'HAS', ...],
        'properties' => ['id', 'name', ...],
    ],
    'relevant_entities' => [
        'Customer' => [['id' => 1, 'name' => 'John'], ...],
    ],
    'entity_metadata' => [
        'detected_entities' => ['Customer'],
        'detected_scopes' => ['active' => [...]],
    ],
]
```

### Step 9: Query Generation

**File:** `src/Services/QueryGenerator.php:120-205`

```php
public function generate(string $question, array $context, array $options = []): array
{
    // Check templates first
    $template = $this->detectTemplate($question);
    if ($template) {
        return $this->generateFromTemplate($question, $template, $context);
    }

    // LLM generation with retries
    while ($retryCount < $maxRetries) {
        $prompt = $this->buildPrompt($question, $context, $allowWrite, $lastError);

        // Rate limit check
        if (!$this->rateLimiter->waitAndAttempt(10)) {
            throw new QueryGenerationException('Rate limit exceeded');
        }

        $response = $this->llm->complete($prompt, null, [...]);
        $cypher = $this->extractCypher($response);
        $validation = $this->validate($cypher, [...]);

        if ($validation['valid']) {
            return [
                'cypher' => $cypher,
                'explanation' => $explain ? $this->generateExplanation(...) : '',
                'confidence' => $this->calculateConfidence($cypher, $context),
                'warnings' => $validation['warnings'],
                'metadata' => [...],
            ];
        }
    }
}
```

### Step 10: Query Execution

**File:** `src/Services/QueryExecutor.php:53-139`

```php
public function execute(string $cypherQuery, array $parameters = [], array $options = []): array
{
    // Handle empty query case
    if (empty(trim($cypherQuery))) {
        return [
            'success' => true,
            'data' => [],
            'stats' => [],
            'metadata' => [],
            'context' => 'NO QUERY',
        ];
    }

    // Check read-only mode
    if ($readOnly && $this->containsWriteOperations($cypherQuery)) {
        throw new ReadOnlyViolationException(...);
    }

    // Apply limit if not present
    if (!preg_match('/\bLIMIT\b/i', $cypherQuery)) {
        $cypherQuery .= " LIMIT {$limit}";
    }

    // Execute
    $rawResults = $this->graphStore->query($cypherQuery, $parameters);
    $formattedData = $this->formatAsTable($rawResults);

    return [
        'success' => true,
        'data' => $formattedData,
        'stats' => $this->collectStatistics($rawResults, $startTime),
        'metadata' => [...],
    ];
}
```

### Step 11: Response Generation

**File:** `src/Services/ResponseGenerator.php:219-289`

```php
public function generate(
    string $originalQuestion,
    array $queryResult,
    string $cypherQuery,
    array $options = []
): array {
    // Handle empty results
    if (empty($queryResult['data']) && ($queryResult['context'] ?? '') !== 'NO QUERY') {
        return $this->generateEmptyResponse($originalQuestion, $cypherQuery, $options);
    }

    // Build prompt using section pipeline
    $context = [
        'question' => $originalQuestion,
        'cypher' => $cypherQuery,
        'data' => $queryResult['data'],
        'stats' => $queryResult['stats'] ?? [],
    ];
    $prompt = $this->buildPrompt($context, $sectionOptions);

    // Generate response via LLM
    $answer = $this->llm->complete($prompt, null, [...]);

    // Extract insights
    $insights = $this->extractInsights($queryResult['data']);

    // Suggest visualizations
    $visualizations = $this->suggestVisualizations($queryResult['data'], $cypherQuery);

    return [
        'answer' => trim($answer),
        'insights' => $insights,
        'visualizations' => $visualizations,
        'format' => $format,
        'metadata' => [...],
    ];
}
```

### Step 12: Assistant Message Storage

**File:** `src/Kompo/ChatMessageForm.php:166-174`

```php
$this->conversation->addMessage('assistant', $responseContent, [
    'response_data' => !empty($responseData) ? $responseData : null,
    'referenced_files' => $referencedFiles,
    'execution_time_ms' => $response['execution_time_ms'] ?? null,
    'confidence_score' => $response['confidence'] ?? null,
    'cypher_query' => $response['cypher_query'] ?? null,
    'metadata' => !empty($metadata) ? $metadata : null,
]);
```

**Database Record Created (AiMessage):**
| Column | Value |
|--------|-------|
| conversation_id | From conversation |
| role | 'assistant' |
| content | LLM-generated answer |
| response_data | Structured data (tables, etc.) |
| context_used | null (NOT PASSED!) |
| cypher_query | Generated Cypher query |
| execution_time_ms | Query execution time |
| confidence_score | Generation confidence |
| metadata | {suggestions: [...]} |

### Step 13: UI Refresh

**File:** `src/Kompo/AiChatPanel.php:212-232`

The form's `->refresh($this->panelId)` triggers `renderMessages()`:

```php
public function renderMessages()
{
    if (!$this->conversation) {
        return $this->emptyState();
    }

    $messages = $this->conversation->messages()->orderBy('created_at')->get();

    if ($messages->isEmpty()) {
        return $this->welcomeState();
    }

    $bubbles = $messages->map(fn($msg) => $this->renderMessageBubble($msg))->all();

    return _Rows(
        _Hidden()->onLoad->run($this->scrollScript()),  // Auto-scroll
        ...$bubbles,
    )->class('space-y-6');
}
```

---

## CODE THAT IS SKIPPED (Never Executes in This Flow)

### 1. `AiChatService::askWithConversation()` - NEVER CALLED

**File:** `src/Services/Chat/AiChatService.php:183-272`

This sophisticated method exists but is **never invoked** from the UI:

```php
public function askWithConversation(
    string $question,
    AiConversation $conversation,
    array $options = []
): AiChatMessage {
    // This method:
    // - Uses ConversationContextManager for entity tracking
    // - Resolves references ("those", "them")
    // - Builds enriched prompts with conversation context
    // - Stores messages in the conversation automatically
    // - Records response in context manager

    // BUT: ChatMessageForm calls askWithHistory() instead!
}
```

**What's Lost:**
- Entity focus tracking across conversation
- Reference resolution for follow-up questions
- Automatic conversation context building
- Context manager integration

### 2. `AiChatService::prepareQuestionWithContext()` - NEVER CALLED

**File:** `src/Services/Chat/AiChatService.php:285-309`

```php
public function prepareQuestionWithContext(
    string $question,
    AiConversation $conversation,
    array $schema
): array {
    // Returns enriched_question, is_follow_up, focused_entity, etc.
    // NEVER USED because askWithConversation() isn't called
}
```

### 3. `ConversationContextManager` - NEVER INSTANTIATED

**File:** `src/Services/Context/ConversationContextManager.php`

The entire context management system is bypassed because `askWithConversation()` isn't called. This includes:
- `EntityExtractor` - extracting entities from questions
- `ReferenceResolver` - resolving "those", "them", etc.
- Conversation context building
- Response recording

### 4. `AiConversation::updateContextSnapshot()` - PARTIALLY USED

**File:** `src/Models/AiConversation.php:171-177`

Only called when `response['entities']` is present (which rarely happens via the current flow):

```php
// In ChatMessageForm.php:177-182
if (isset($response['entities'])) {
    $this->conversation->updateContextSnapshot([...]);
}
```

### 5. ConversationController - Only Export Used

**File:** `src/Http/Controllers/ConversationController.php`

The controller only has an `export()` method. All chat functionality uses Kompo's self-posting, not REST endpoints.

---

## Data Lost or Not Passed

### 1. `context_used` Field Never Populated

In `ChatMessageForm.php:166-174`, `context_used` is not passed to `addMessage()`:

```php
$this->conversation->addMessage('assistant', $responseContent, [
    'response_data' => ...,
    'referenced_files' => ...,
    // context_used is NOT included!
]);
```

The `AiMessage` model has this field, but it's always null for messages created via the UI.

### 2. Conversation Context Not Passed to answerQuestion

In `ChatMessageForm.php:136-139`:

```php
$response = $aiManager->askWithHistory($message, $history, [
    'style' => $style,
    'conversation_id' => $this->conversation->id,
    // 'conversation_context' is NOT passed
]);
```

The `conversation_context` option that `AiManager::answerQuestion()` checks for (line 692-694) is never provided.

### 3. User Not Passed to File Context

In `AiManager::answerQuestion()` line 698:

```php
$fileContext = $this->retrieveFileContext($question, $options['user'] ?? null);
```

But `options['user']` is never set in the chat flow.

---

## Flow Variations

### Regenerate Message

**File:** `src/Kompo/AiChatPanel.php:606-635`

```php
public function regenerate($id)
{
    // Finds user message before the assistant message
    // Deletes the old assistant message
    // Creates new ChatMessageForm
    // Merges request with old user message content
    // Calls form->sendMessage()
}
```

Uses same flow as normal send, just with deleted message.

### Ask Suggestion

**File:** `src/Kompo/AiChatPanel.php:637-644`

```php
public function askSuggestion($question)
{
    request()->merge(['message' => $question]);
    $form = new ChatMessageForm(null, ['conversation_id' => $this->conversation->id]);
    $form->sendMessage();
    return $this->renderMessages();
}
```

Same flow, just with programmatically set message.

### Quick Action

**File:** `src/Kompo/ChatMessageForm.php:197-210`

```php
public function quickAction($action)
{
    $actions = [
        'summarize' => 'Summarize our conversation so far',
        // ...
    ];
    $message = $actions[$action] ?? $action;
    request()->merge(['message' => $message]);
    return $this->sendMessage();
}
```

Same flow with predefined message.

---

## Architecture Issues Identified

### 1. Bypassed Context Management

The sophisticated `ConversationContextManager` with `EntityExtractor` and `ReferenceResolver` is completely bypassed. Users cannot ask follow-up questions like "show me more details about those" because reference resolution isn't happening.

### 2. Duplicate Message Storage Logic

Messages are stored manually in `ChatMessageForm::sendMessage()` instead of using `AiChatService::askWithConversation()` which would handle storage automatically.

### 3. History vs Context Confusion

Two approaches exist:
1. `askWithHistory()` - Simple string concatenation of history
2. `askWithConversation()` - Rich context management

Only #1 is used, losing all benefits of #2.

### 4. Missing Error Display

Line 118-119 in `ChatMessageForm.php`:
```php
if (empty($message) || !$this->conversation) {
    return;  // Silent return - user sees nothing!
}
```

User gets no feedback if something is wrong.

---

## Recommendations

1. **Use `askWithConversation()` instead of `askWithHistory()`** - This would enable entity tracking, reference resolution, and automatic message storage.

2. **Pass context_used to message storage** - Preserve what context was used for debugging and auditing.

3. **Pass user to file context retrieval** - Enable user-specific file access controls.

4. **Add error feedback** - Show users when their message couldn't be sent.

5. **Consolidate message storage** - Let the service handle storage instead of the form.

---

## Summary Table

| Component | Status | Lines |
|-----------|--------|-------|
| AiChatPanel.inputArea() | USED | 522-533 |
| ChatMessageForm.sendMessage() | USED | 113-195 |
| AiChatService.askWithHistory() | USED | 124-167 |
| AiChatService.askWithConversation() | NEVER USED | 183-272 |
| AiManager.answerQuestion() | USED | 685-757 |
| ContextRetriever.retrieveContext() | USED | 176-298 |
| QueryGenerator.generate() | USED | 120-205 |
| QueryExecutor.execute() | USED | 53-139 |
| ResponseGenerator.generate() | USED | 219-289 |
| ConversationContextManager | NEVER USED | entire file |
| EntityExtractor | NEVER USED | entire file |
| ReferenceResolver | NEVER USED | entire file |
