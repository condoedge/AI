# Phase 3: Query Generation Flow Trace

**Date:** 2025-12-30
**Task:** Task 36 - Trace query generation flow from user question to Cypher query

---

## Executive Summary

This document traces exactly how user questions become Cypher queries, identifying which prompt sections are actually rendered vs designed but never used. The flow goes:

1. **Entry:** `QueryGenerator::generate()` receives question + context
2. **Template Check:** Simple patterns may skip LLM entirely
3. **Prompt Build:** `SemanticPromptBuilder::buildPrompt()` assembles sections
4. **LLM Call:** `LlmProviderInterface::complete()` generates query
5. **Extraction:** Cypher extracted from response (markdown stripped)
6. **Validation:** Safety and syntax checks applied
7. **Retry Loop:** Up to 3 attempts on validation failure

---

## 1. Entry Point: QueryGenerator::generate()

**File:** `src/Services/QueryGenerator.php:120-205`

```php
public function generate(string $question, array $context, array $options = []): array
{
    // Merge options with defaults
    $temperature = $options['temperature'] ?? $this->config['temperature'] ?? 0.1;
    $maxRetries = $options['max_retries'] ?? $this->config['max_retries'] ?? 3;
    $allowWrite = $options['allow_write'] ?? $this->config['allow_write_operations'] ?? false;
    $explain = $options['explain'] ?? false;
```

### Input Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `$question` | string | Natural language user question |
| `$context` | array | RAG context with schema, entities, metadata |
| `$options` | array | Runtime options (temperature, retries, etc.) |

### Context Array Expected Structure

```php
[
    'graph_schema' => [
        'labels' => ['Customer', 'Order', ...],
        'relationships' => ['PURCHASED', 'HAS', ...],
        'properties' => ['Customer' => ['name', 'email'], ...]
    ],
    'similar_queries' => [
        ['question' => '...', 'query' => '...', 'score' => 0.85],
        ...
    ],
    'relevant_entities' => [
        'Customer' => [
            ['id' => 1, 'name' => 'John', 'email' => 'john@example.com'],
            ...
        ]
    ],
    'entity_metadata' => [
        'detected_entities' => ['Customer', 'Order'],
        'entity_metadata' => [...],
        'detected_scopes' => [...]
    ],
    'conversation_context' => [
        'focused_entity' => 'Customer',
        'recent_exchanges' => [...],
        'is_follow_up' => true
    ],
    'file_context' => [
        'relevant_files' => [
            ['filename' => 'auth.md', 'snippet' => '...', 'relevance' => 0.92]
        ]
    ]
]
```

---

## 2. Template Detection (Fast Path)

**File:** `src/Services/QueryGenerator.php:129-134`

```php
// Check if templates are enabled
if ($this->config['enable_templates'] ?? true) {
    $template = $this->detectTemplate($question);
    if ($template) {
        return $this->generateFromTemplate($question, $template, $context);
    }
}
```

### Built-in Templates

| Template | Pattern | Generated Cypher |
|----------|---------|------------------|
| `list_all` | `/^(show\|list\|get\|display\|find)\s+all\s+(\w+)/i` | `MATCH (n:{label}) RETURN n LIMIT 100` |
| `count` | `/^(how many\|count\|number of)\s+(\w+)/i` | `MATCH (n:{label}) RETURN count(n) as count` |
| `find_by_property` | `/^find\s+(\w+)\s+(with\|where\|having)...` | `MATCH (n:{label} {{property: $value}}) RETURN n LIMIT 100` |
| `relationship_query` | `/^(show\|find\|get)\s+(\w+)\s+(connected to\|related to)...` | `MATCH (a:{label1})-[r]-(b:{label2}) RETURN a, r, b LIMIT 100` |
| `aggregation` | `/^(sum\|total\|average\|avg\|max\|min)\s+(.+)/i` | `MATCH (n:{label}) RETURN {aggregation}(n.{property}) as result` |
| `filtering` | `/^(\w+)\s+where\s+(.+)/i` | `MATCH (n:{label}) WHERE {condition} RETURN n LIMIT 100` |

**NOTE:** Template matches **bypass LLM entirely** - they use simple pattern-based generation.

---

## 3. Prompt Assembly: SemanticPromptBuilder

**File:** `src/Services/SemanticPromptBuilder.php:272-298`

```php
public function buildPrompt(
    string $question,
    array $context,
    bool $allowWrite = false
): string {
    $options = ['allowWrite' => $allowWrite];

    // Start with system prompt
    $prompt = $this->systemPrompt
        ?? "You are a Neo4j Cypher query expert who generates queries based on semantic business definitions.\n\n";

    $this->processModules(
        beforeCallbackProcess: function($callback) use (&$prompt, $question, $context, $options) {
            $prompt .= $callback($question, $context, $options);
        },
        moduleProcess: function($section) use (&$prompt, $question, $context, $options) {
            if ($section->shouldInclude($question, $context, $options)) {
                $prompt .= $section->format($question, $context, $options);
            }
        },
        afterCallbackProcess: function($callback) use (&$prompt, $question, $context, $options) {
            $prompt .= $callback($question, $context, $options);
        },
    );

    return $prompt;
}
```

### Module Processing Order

**File:** `src/Services/HasInternalModules.php:456-486`

Sections are sorted by priority (ascending) and processed in order. Each section:
1. Runs any registered "before" callbacks
2. Checks `shouldInclude()` - if true, calls `format()`
3. Runs any registered "after" callbacks

---

## 4. Prompt Sections Analysis

### Registered Sections (from config/ai.php:706-722)

| Priority | Section | Always Included? | Inclusion Condition |
|----------|---------|------------------|---------------------|
| 10 | `ProjectContextSection` | **NO** | `!empty(config('ai.project'))` or custom context set |
| 15 | `GenericContextSection` | **YES** | Always (base class default) |
| 17 | `CurrentUserContextSection` | **YES** | Always (no override) |
| 20 | `SchemaSection` | **YES** | Always (no override) |
| 30 | `RelationshipsSection` | **NO** | `!empty(config('entities'))` |
| 40 | `ExampleEntitiesSection` | **NO** | `!empty($context['relevant_entities'])` |
| 45 | `FileContextSection` | **NO** | `!empty($context['file_context']['relevant_files'])` |
| 50 | `SimilarQueriesSection` | **NO** | `!empty($context['similar_queries'])` |
| 55 | `ConversationContextSection` | **NO** | Has focused_entity OR recent_exchanges |
| 60 | `DetectedEntitiesSection` | **NO** | `!empty($context['entity_metadata']['detected_entities'])` |
| 65 | `DetectedScopesSection` | **NO** | `!empty($context['entity_metadata']['detected_scopes'])` |
| 70 | `PatternLibrarySection` | **NO** | `!empty($patternLibrary->getAllPatterns())` |
| 75 | `QueryRulesSection` | **YES** | Always (no override) |
| 80 | `QuestionSection` | **YES** | Always (no override) |
| 90 | `TaskInstructionsSection` | **YES** | Always (no override) |

### Sections That ALWAYS Render

1. **GenericContextSection** (priority 15)
   - Output: Current date/time
   - **File:** `src/Services/PromptSections/GenericContextSection.php:18-24`

2. **CurrentUserContextSection** (priority 17)
   - Output: User name, email, ID, team info
   - **File:** `src/Services/PromptSections/CurrentUserContextSection.php:10-19`

3. **SchemaSection** (priority 20)
   - Output: Graph labels, relationships, properties
   - **File:** `src/Services/PromptSections/SchemaSection.php:18-62`
   - NOTE: Even if schema is empty, outputs "No schema information available"

4. **QueryRulesSection** (priority 75)
   - Output: 7 categories of rules (schema compliance, data types, relationships, business, best practices, output format, read-only)
   - **File:** `src/Services/PromptSections/QueryRulesSection.php:55-77`

5. **QuestionSection** (priority 80)
   - Output: The user's question
   - **File:** `src/Services/PromptSections/QuestionSection.php:18-24`

6. **TaskInstructionsSection** (priority 90)
   - Output: Final instructions telling LLM to generate clean Cypher
   - **File:** `src/Services/PromptSections/TaskInstructionsSection.php:35-59`

### Sections That CONDITIONALLY Render

1. **ProjectContextSection** (priority 10)
   - Condition: `!empty(config('ai.project'))` OR custom context set
   - Output: Project name, description, domain, business rules
   - **File:** `src/Services/PromptSections/ProjectContextSection.php:83-87`

2. **RelationshipsSection** (priority 30)
   - Condition: `!empty(config('entities'))`
   - Output: Entity relationships with EXACT directions
   - **File:** `src/Services/PromptSections/RelationshipsSection.php:82-86`

3. **ExampleEntitiesSection** (priority 40)
   - Condition: `!empty($context['relevant_entities'])`
   - Output: Sample entities with type hints (integer, string, date format)
   - **File:** `src/Services/PromptSections/ExampleEntitiesSection.php:91-94`

4. **FileContextSection** (priority 45)
   - Condition: `!empty($context['file_context']['relevant_files'])`
   - Output: Relevant files with citation instructions
   - **File:** `src/Services/PromptSections/FileContextSection.php:19-22`

5. **SimilarQueriesSection** (priority 50)
   - Condition: `!empty($context['similar_queries'])`
   - Output: Up to 3 similar past queries (configurable)
   - **File:** `src/Services/PromptSections/SimilarQueriesSection.php:58-61`

6. **ConversationContextSection** (priority 55)
   - Condition: Has `focused_entity` OR `recent_exchanges`
   - Output: Current focus, recent conversation, follow-up hints
   - **File:** `src/Services/PromptSections/ConversationContextSection.php:24-31`

7. **DetectedEntitiesSection** (priority 60)
   - Condition: `!empty($context['entity_metadata']['detected_entities'])`
   - Output: Detected entities with descriptions, aliases, properties
   - **File:** `src/Services/PromptSections/DetectedEntitiesSection.php:59-63`

8. **DetectedScopesSection** (priority 65)
   - Condition: `!empty($context['entity_metadata']['detected_scopes'])`
   - Output: Business concepts with relationship specs, filters, patterns
   - **File:** `src/Services/PromptSections/DetectedScopesSection.php:263-267`

9. **PatternLibrarySection** (priority 70)
   - Condition: `!empty($patternLibrary->getAllPatterns())`
   - Output: Available query patterns from library
   - **File:** `src/Services/PromptSections/PatternLibrarySection.php:53-56`

---

## 5. Typical Prompt Structure (Minimal Case)

When minimal context is provided, the prompt looks like:

```
You are a Neo4j Cypher query expert who generates queries based on semantic business definitions.

=== CONTEXT INFORMATION ===

Current date: 2025-12-30 15:30:45

=== CURRENT USER CONTEXT. USE IT TO HAVE CURRENT TEAM AND CURRENT USER CONTEXT ===

Current user name: John Doe
Current user email: john@example.com
Current user ID: 123
Current team ID: 456
Current team name: Acme Corp

=== GRAPH SCHEMA ===

No schema information available.

=== QUERY GENERATION RULES ===

When generating Cypher queries, you MUST follow these rules:

1. SCHEMA COMPLIANCE:
   - Use only labels and relationships from the schema above
   ...

6. OUTPUT FORMAT:
   - Return ONLY the Cypher query
   - NO markdown code blocks
   - NO explanations or comments
   ...

7. READ-ONLY CONSTRAINT:
   - NO write operations (CREATE, MERGE, SET, DELETE, REMOVE, etc.)
   ...

=== USER QUESTION ===

What customers do we have?

=== YOUR TASK ===

Generate a Cypher query that:
1. Accurately answers the user's question
2. Uses the EXACT relationship directions shown in the schema
...

If the question cannot be answered with a Cypher query based on the provided context or a query it's not required to answer the question, return an strict text: 'NO QUERY REQUIRED'.

CYPHER QUERY:
```

---

## 6. LLM Interaction

**File:** `src/Services/QueryGenerator.php:151-154`

```php
// Call LLM
$response = $this->llm->complete($prompt, null, [
    'temperature' => $temperature,
    'max_tokens' => 500,
]);
```

### Rate Limiting

**File:** `src/Services/QueryGenerator.php:146-148`

```php
// Check rate limit before calling LLM
if (!$this->rateLimiter->waitAndAttempt(10)) {
    throw new QueryGenerationException('Rate limit exceeded for LLM API');
}
```

Uses `RateLimiter::forLlm()` - waits up to 10 seconds for rate limit slot.

### Special Response Handling

**File:** `src/Services/QueryGenerator.php:156-167`

```php
if ($response == 'NO QUERY REQUIRED') {
    return [
        'cypher' => '',
        'explanation' => 'No query required to answer the question.',
        'confidence' => 1.0,
        'warnings' => [],
        'metadata' => [
            'template_used' => null,
            'retry_count' => $retryCount,
            'complexity' => 0,
        ],
    ];
}
```

---

## 7. Cypher Extraction

**File:** `src/Services/QueryGenerator.php:397-406`

```php
private function extractCypher(string $response): string
{
    // Remove markdown code blocks
    $cypher = preg_replace('/```(?:cypher)?\s*(.*?)\s*```/s', '$1', $response);

    // Remove extra whitespace
    $cypher = trim($cypher);

    return $cypher;
}
```

Handles both:
- Raw Cypher: `MATCH (n) RETURN n`
- Markdown wrapped: ` ```cypher\nMATCH (n) RETURN n\n``` `

---

## 8. Cypher Validation

**File:** `src/Services/QueryGenerator.php:215-266`

### Validation Checks

```php
public function validate(string $cypherQuery, array $options = []): array
{
    // 1. Empty check
    if (empty(trim($cypherQuery))) {
        throw new \InvalidArgumentException('Query cannot be empty');
    }

    // 2. Dangerous keyword check (DELETE, REMOVE, DROP, CREATE, MERGE, SET, DETACH)
    foreach ($this->dangerousKeywords as $keyword) {
        if (stripos($cypherQuery, $keyword) !== false && !$allowWrite) {
            $errors[] = "Query contains forbidden keyword: {$keyword}";
        }
    }

    // 3. LIMIT clause warning
    if (!preg_match('/\bLIMIT\b/i', $cypherQuery)) {
        $warnings[] = "Query missing LIMIT clause - may return large result set";
    }

    // 4. Basic syntax validation (must have MATCH or RETURN)
    if (!preg_match('/\bMATCH\b/i', $cypherQuery) && !preg_match('/\bRETURN\b/i', $cypherQuery)) {
        $errors[] = "Query must contain MATCH or RETURN clause";
    }

    // 5. Complexity check
    $complexity = $this->calculateComplexityScore($cypherQuery);
    if ($complexity > $maxComplexity) {
        $warnings[] = "Query complexity ({$complexity}) exceeds threshold ({$maxComplexity})";
    }
}
```

### Complexity Calculation

**File:** `src/Services/QueryGenerator.php:460-477`

```php
private function calculateComplexityScore(string $cypher): int
{
    $complexity = 0;

    // Count MATCH clauses (10 points each)
    $complexity += substr_count(strtoupper($cypher), 'MATCH') * 10;

    // Count WHERE clauses (5 points each)
    $complexity += substr_count(strtoupper($cypher), 'WHERE') * 5;

    // Count joins/relationships (8 points each)
    $complexity += substr_count($cypher, ']-') * 8;

    // Count aggregations (3 points each)
    $complexity += preg_match_all('/\b(count|sum|avg|max|min)\b/i', $cypher) * 3;

    return $complexity;
}
```

### Dangerous Keywords

**File:** `src/Services/QueryGenerator.php:26-28`

```php
private array $dangerousKeywords = [
    'DELETE', 'REMOVE', 'DROP', 'CREATE', 'MERGE', 'SET', 'DETACH'
];
```

---

## 9. Retry Logic

**File:** `src/Services/QueryGenerator.php:137-205`

```php
$retryCount = 0;
$lastError = null;

while ($retryCount < $maxRetries) {
    try {
        // Build prompt (includes previous error if retrying)
        $prompt = $this->buildPrompt($question, $context, $allowWrite, $lastError);

        // ... LLM call, extraction, validation ...

        if ($validation['valid']) {
            // Success!
            return [...];
        }

        // Validation failed, prepare for retry
        $lastError = implode(', ', $validation['errors']);
        $retryCount++;

    } catch (\Exception $e) {
        $lastError = $e->getMessage();
        $retryCount++;
    }
}

// All retries exhausted
throw new QueryGenerationException(
    "Failed to generate valid query after {$maxRetries} attempts. Last error: {$lastError}"
);
```

### Retry Context Addition

**File:** `src/Services/QueryGenerator.php:382-388`

```php
// Add retry context if needed
if ($previousError) {
    $prompt .= "\n\nPrevious attempt failed with error: {$previousError}\n";
    $prompt .= "Please fix the error and regenerate the query.\n\n";
    $prompt .= "CYPHER QUERY:";
}
```

---

## 10. Confidence Calculation

**File:** `src/Services/QueryGenerator.php:432-452`

```php
private function calculateConfidence(string $cypher, array $context): float
{
    $confidence = 0.5; // Base confidence

    // Boost if schema labels are referenced
    if (!empty($context['graph_schema']['labels'])) {
        foreach ($context['graph_schema']['labels'] as $label) {
            if (stripos($cypher, $label) !== false) {
                $confidence += 0.1;
            }
        }
    }

    // Boost if has LIMIT
    if (preg_match('/\bLIMIT\b/i', $cypher)) {
        $confidence += 0.1;
    }

    // Cap at 1.0
    return min($confidence, 1.0);
}
```

---

## 11. Final Return Structure

**File:** `src/Services/QueryGenerator.php:177-189`

```php
return [
    'cypher' => $cypher,                     // Generated Cypher query
    'explanation' => $explain ? ... : '',     // Human-readable explanation (optional)
    'confidence' => $this->calculateConfidence($cypher, $context),  // 0.0-1.0
    'warnings' => $validation['warnings'],   // Performance/safety warnings
    'metadata' => [
        'template_used' => null,             // Template name if used
        'retry_count' => $retryCount,        // Number of retry attempts
        'complexity' => $validation['complexity'],  // Query complexity score
    ],
];
```

---

## 12. Sanitization (Post-Processing)

**File:** `src/Services/QueryGenerator.php:274-290`

```php
public function sanitize(string $cypherQuery): string
{
    // Remove dangerous keywords
    foreach ($this->dangerousKeywords as $keyword) {
        $cypherQuery = preg_replace('/\b' . $keyword . '\b[^;]*(;|$)/i', '', $cypherQuery);
    }

    // Add LIMIT if missing
    if (!preg_match('/\bLIMIT\b/i', $cypherQuery)) {
        $defaultLimit = $this->config['default_limit'] ?? 100;
        if (preg_match('/\bRETURN\b/i', $cypherQuery)) {
            $cypherQuery = preg_replace('/(\bRETURN\b.*)$/i', "$1 LIMIT {$defaultLimit}", $cypherQuery);
        }
    }

    return trim($cypherQuery);
}
```

---

## 13. Flow Diagram

```
User Question
     |
     v
+------------------+
| QueryGenerator   |
| ::generate()     |
+------------------+
     |
     v
+------------------+
| Template Match?  |---> YES ---> generateFromTemplate() ---> Return
+------------------+
     |
     NO
     v
+------------------+
| Build Prompt     |
| (SemanticPrompt  |
|  Builder)        |
+------------------+
     |
     v (sorted by priority)
+----------------------------------+
| Section Pipeline:                |
| 1. ProjectContext     (cond)     |
| 2. GenericContext     (always)   |
| 3. CurrentUserContext (always)   |
| 4. Schema             (always)   |
| 5. Relationships      (cond)     |
| 6. ExampleEntities    (cond)     |
| 7. FileContext        (cond)     |
| 8. SimilarQueries     (cond)     |
| 9. ConversationContext(cond)     |
| 10. DetectedEntities  (cond)     |
| 11. DetectedScopes    (cond)     |
| 12. PatternLibrary    (cond)     |
| 13. QueryRules        (always)   |
| 14. Question          (always)   |
| 15. TaskInstructions  (always)   |
+----------------------------------+
     |
     v
+------------------+
| Rate Limiter     |
| Check            |
+------------------+
     |
     v
+------------------+
| LLM Complete()   |
+------------------+
     |
     v
+------------------+
| "NO QUERY        |
| REQUIRED"?       |---> YES ---> Return empty cypher
+------------------+
     |
     NO
     v
+------------------+
| Extract Cypher   |
| (strip markdown) |
+------------------+
     |
     v
+------------------+
| Validate         |
| - Dangerous keywords
| - MATCH/RETURN
| - LIMIT warning
| - Complexity check
+------------------+
     |
     v
+------------------+
| Valid?           |
+------------------+
     |      |
    YES     NO
     |      |
     v      v
Return   Retry < 3?
  |         |
  |        YES ---> Add error to prompt ---> Loop back to LLM
  |         |
  |        NO ---> Throw QueryGenerationException
  v
Success Result
```

---

## 14. Key Findings

### Sections Likely NEVER Rendered in Minimal Setups

1. **ProjectContextSection** - Requires `config('ai.project')` or explicit `setProjectContext()`
2. **RelationshipsSection** - Requires `config('entities')` with relationship definitions
3. **ExampleEntitiesSection** - Requires RAG to provide relevant entities
4. **FileContextSection** - Requires file vector search to find relevant files
5. **SimilarQueriesSection** - Requires vector search to find similar past queries
6. **ConversationContextSection** - Requires conversation state tracking
7. **DetectedEntitiesSection** - Requires entity detection in question
8. **DetectedScopesSection** - Requires scope detection from entity configs
9. **PatternLibrarySection** - Requires patterns in `config('ai.query_patterns')`

### Sections ALWAYS Rendered

1. **GenericContextSection** - Just current date
2. **CurrentUserContextSection** - Auth user info
3. **SchemaSection** - Even if empty, shows "No schema information available"
4. **QueryRulesSection** - Always includes all 7 rule categories
5. **QuestionSection** - Always includes user question
6. **TaskInstructionsSection** - Always includes final instructions

### Template Bypass Risk

Templates match simple patterns and **completely bypass LLM and RAG context**:
- "Show all customers" -> Template match, no LLM call
- "How many orders" -> Template match, no LLM call

This means:
- No semantic scopes applied
- No entity metadata used
- No relationship direction verification
- Just simple regex-based label extraction

### Validation Gaps

1. **No Cypher parsing** - Only regex-based keyword detection
2. **No schema validation** - Doesn't verify labels/relationships exist
3. **No injection protection** - Relies on LLM not generating malicious queries
4. **Complexity is estimation** - Counts keywords, not actual query cost

---

## 15. Cross-References

- **Chat Flow:** `docs/audit/phase3-flow-chat.md` - How questions reach QueryGenerator
- **Prompt Sections:** `docs/audit/phase2-prompt-sections.md` - Section details
- **Semantic Services:** `docs/audit/phase2-services-semantic.md` - SemanticPromptBuilder
- **Core Services:** `docs/audit/phase2-services-core.md` - QueryGenerator details

---

## 16. Files Referenced

| File | Lines Referenced |
|------|------------------|
| `src/Services/QueryGenerator.php` | 1-528 |
| `src/Services/SemanticPromptBuilder.php` | 1-348 |
| `src/Services/HasInternalModules.php` | 1-487 |
| `src/Contracts/PromptSectionInterface.php` | 1-82 |
| `src/Services/PromptSections/BasePromptSection.php` | 1-64 |
| `src/Services/PromptSections/ProjectContextSection.php` | 1-88 |
| `src/Services/PromptSections/GenericContextSection.php` | 1-25 |
| `src/Services/PromptSections/CurrentUserContextSection.php` | 1-21 |
| `src/Services/PromptSections/SchemaSection.php` | 1-63 |
| `src/Services/PromptSections/RelationshipsSection.php` | 1-87 |
| `src/Services/PromptSections/ExampleEntitiesSection.php` | 1-95 |
| `src/Services/PromptSections/FileContextSection.php` | 1-71 |
| `src/Services/PromptSections/SimilarQueriesSection.php` | 1-62 |
| `src/Services/PromptSections/ConversationContextSection.php` | 1-113 |
| `src/Services/PromptSections/DetectedEntitiesSection.php` | 1-64 |
| `src/Services/PromptSections/DetectedScopesSection.php` | 1-268 |
| `src/Services/PromptSections/PatternLibrarySection.php` | 1-57 |
| `src/Services/PromptSections/QueryRulesSection.php` | 1-160 |
| `src/Services/PromptSections/QuestionSection.php` | 1-25 |
| `src/Services/PromptSections/TaskInstructionsSection.php` | 1-60 |
| `config/ai.php` | 706-722 |
