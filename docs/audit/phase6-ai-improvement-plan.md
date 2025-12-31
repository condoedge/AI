# Phase 6: AI Improvement Plan

**Date:** 2025-12-30
**Scope:** Actionable improvements to enhance AI response quality and user experience
**Based on:** Phase 5 AI Audit, Conversation Context Deep Audit, Refactor Recommendations

---

## Overview

This plan addresses five key improvement areas identified in the audit:
1. Context Management Improvements
2. Semantic Filtering Optimization
3. Learning Loop Activation
4. Embedding Improvements
5. Response Quality

Each section includes specific, actionable items with implementation guidance.

---

## 1. Context Management Improvements

### 1.1 Entity Property Persistence

**Problem:** The system only stores entity *labels* (e.g., "Customer") but never stores entity *properties* (IDs, names, filter conditions). This breaks multi-turn conversations.

**Current State:**
```php
// EntityExtractor::extractFromQuestion() only returns:
return [
    'focused_entity' => 'Customer',     // Label only!
    'query_type' => 'detail',
    'mentioned_entities' => ['Customer'],
];
// No actual entity data (ID, properties, filter conditions)
```

**Implementation:**

1. **Extend context snapshot schema** in `AiConversation`:
   ```php
   // Add to context_snapshot structure:
   [
       'focused_entity' => 'Customer',
       'focused_entity_filter' => "c.name = 'John'",  // NEW: WHERE clause
       'focused_entity_data' => [                      // NEW: Entity details
           'id' => 123,
           'name' => 'John',
           'identifier_property' => 'customer_id'
       ],
       'last_result_sample' => [...],  // NEW: First 3-5 results
   ]
   ```

2. **Extract entity filter from Cypher query**:
   ```php
   // In ConversationContextManager::recordResponse()
   protected function extractEntityFilter(?string $cypherQuery): ?string
   {
       if (!$cypherQuery) return null;

       // Extract WHERE clause conditions
       if (preg_match('/WHERE\s+(.+?)(?:RETURN|ORDER|LIMIT|$)/is', $cypherQuery, $matches)) {
           return trim($matches[1]);
       }
       return null;
   }
   ```

3. **Store result samples after query execution**:
   ```php
   $snapshot['last_result_sample'] = array_slice($queryResult['data'] ?? [], 0, 3);
   ```

**Files to Modify:**
- `src/Models/AiConversation.php` - Add entity context methods
- `src/Services/Context/ConversationContextManager.php` - Extend recordResponse()
- `src/Services/Context/EntityExtractor.php` - Extract more entity details

**Priority:** HIGH
**Effort:** Medium (2-3 days)

---

### 1.2 Previous Query Results Availability

**Problem:** `buildQuestionWithHistory()` only includes text content, not actual query results. The AI cannot reference "those 5 customers" from the previous response.

**Current State:**
```php
// In buildQuestionWithHistory() - only text, no data
$contextParts[] = "User asked: {$content}";
$contextParts[] = "Assistant replied: {$truncated}";  // Text only
// Missing: "Query returned: [{id: 1, name: 'John'}, ...]"
```

**Implementation:**

1. **Include previous results in context**:
   ```php
   // In buildPromptContext()
   return [
       // ...existing fields...
       'last_result_sample' => $snapshot['last_result_sample'] ?? [],
       'last_result_count' => $snapshot['last_result_count'] ?? 0,
       'last_cypher_query' => $snapshot['last_cypher_query'] ?? null,
   ];
   ```

2. **Update ConversationContextSection to render results**:
   ```php
   if (!empty($context['last_result_sample'])) {
       $output .= "\n**Previous Results Sample:**\n```json\n";
       $output .= json_encode($context['last_result_sample'], JSON_PRETTY_PRINT);
       $output .= "\n```\n";
       $output .= "Total results: {$context['last_result_count']}\n";
   }
   ```

3. **Add result summary to prompt instructions**:
   ```php
   $output .= "**Instructions:** When user says 'those', 'them', or 'the same', ";
   $output .= "reference the previous results shown above.\n";
   ```

**Files to Modify:**
- `src/Services/Context/ConversationContextManager.php`
- `src/Services/PromptSections/ConversationContextSection.php`

**Priority:** HIGH
**Effort:** Low (1 day)

---

### 1.3 Reference Resolution ("those", "them", "the same")

**Problem:** `ReferenceResolver` exists but is never called from the UI path (`askWithHistory`).

**Current State:**
- `ChatMessageForm` calls `askWithHistory()`
- `askWithHistory()` bypasses `ConversationContextManager`
- `ReferenceResolver::resolve()` is dead code

**Implementation:**

**Option A: Quick Fix** - Add reference resolution to `askWithHistory()`:
```php
public function askWithHistory(string $question, array $history, array $options = []): AiChatMessage
{
    // NEW: Resolve references if conversation provided
    if ($conversation = $options['conversation'] ?? null) {
        $question = $this->getContextManager()
            ->processQuestion($conversation, $question, $this->getSchema())['enriched_question'] ?? $question;
    }

    // ...existing code...
}
```

**Option B: Clean Architecture** - Switch UI to `askWithConversation()`:
1. Update `ChatMessageForm::sendMessage()` to call `askWithConversation()`
2. Pass `$this->conversation` to the method
3. Remove or deprecate `askWithHistory()`

**Recommendation:** Option B (Clean Architecture) is preferred for long-term maintainability.

**Files to Modify:**
- `src/Kompo/ChatMessageForm.php` - Switch to askWithConversation()
- `src/Services/Chat/AiChatServiceInterface.php` - Add askWithConversation() to interface
- `src/Services/Chat/AiChatService.php` - Deprecate askWithHistory()

**Priority:** HIGH
**Effort:** Medium (2 days)

---

## 2. Semantic Filtering Optimization

### 2.1 Token Budget vs Completeness Tradeoff

**Problem:** History size is based on message count, not token consumption. Risk of exceeding LLM context windows with long messages.

**Current Limits:**
| Location | Truncation | Limit | Configurable |
|----------|-----------|-------|--------------|
| `getRecentMessages()` | Count-based | 10 messages | No |
| `buildPromptContext()` | Count-based | 5 exchanges | Yes |
| `ConversationContextSection::format()` | Content-based | 100/150 chars | No |

**Implementation:**

1. **Add token estimation utility**:
   ```php
   class TokenEstimator
   {
       public function estimate(string $text): int
       {
           // Rough estimation: ~4 chars per token for English
           return (int) ceil(strlen($text) / 4);
       }

       public function estimateArray(array $items): int
       {
           return array_sum(array_map(
               fn($item) => $this->estimate(json_encode($item)),
               $items
           ));
       }
   }
   ```

2. **Token-aware context building**:
   ```php
   public function buildPromptContext(AiConversation $conversation, int $maxTokens = 2000): array
   {
       $context = [];
       $usedTokens = 0;

       // Priority 1: Focused entity (always include)
       $context['focused_entity'] = $snapshot['focused_entity'];
       $usedTokens += $this->tokenEstimator->estimate($context['focused_entity'] ?? '');

       // Priority 2: Last result sample (if space allows)
       $resultSample = $snapshot['last_result_sample'] ?? [];
       $resultTokens = $this->tokenEstimator->estimateArray($resultSample);

       if ($usedTokens + $resultTokens < $maxTokens * 0.4) {
           $context['last_result_sample'] = $resultSample;
           $usedTokens += $resultTokens;
       }

       // Priority 3: Recent exchanges (fill remaining budget)
       // ...progressive loading until budget exhausted
   }
   ```

**Priority:** MEDIUM
**Effort:** Medium (2 days)

---

### 2.2 Priority-Based Entity Inclusion

**Problem:** All entities included regardless of relevance to current question.

**Implementation:**

1. **Score entities by relevance**:
   ```php
   public function scoreEntityRelevance(string $question, array $entities): array
   {
       $scores = [];
       foreach ($entities as $entity) {
           $scores[$entity['label']] = $this->calculateScore($question, $entity);
       }
       arsort($scores);
       return $scores;
   }

   protected function calculateScore(string $question, array $entity): float
   {
       $score = 0.0;

       // Direct mention in question
       if (stripos($question, $entity['label']) !== false) {
           $score += 1.0;
       }

       // Mentioned in recent context
       if (in_array($entity['label'], $this->recentMentions)) {
           $score += 0.5;
       }

       // Semantic similarity (if embeddings available)
       $score += $this->semanticMatcher->similarity($question, $entity['description']);

       return $score;
   }
   ```

2. **Apply priority filtering in SemanticContextSelector**:
   ```php
   public function selectRelevantContext(string $question, int $maxEntities = 10): array
   {
       $allEntities = $this->getAllEntities();
       $scored = $this->scoreEntityRelevance($question, $allEntities);

       return array_slice(array_keys($scored), 0, $maxEntities);
   }
   ```

**Priority:** MEDIUM
**Effort:** Medium (2 days)

---

### 2.3 User-Configurable Filtering Levels

**Problem:** No user control over context verbosity.

**Implementation:**

1. **Add setting to AiUserSetting**:
   ```php
   // In AiUserSetting or ChatSettings
   'context_verbosity' => 'balanced', // Options: minimal, balanced, verbose
   ```

2. **Apply setting in context building**:
   ```php
   public function getContextLimits(string $verbosity): array
   {
       return match($verbosity) {
           'minimal' => ['history' => 3, 'entities' => 5, 'results' => 1],
           'balanced' => ['history' => 5, 'entities' => 10, 'results' => 3],
           'verbose' => ['history' => 10, 'entities' => 20, 'results' => 5],
           default => ['history' => 5, 'entities' => 10, 'results' => 3],
       };
   }
   ```

3. **Add UI control** in ChatSettingsModal.

**Priority:** LOW
**Effort:** Low (1 day)

---

## 3. Learning Loop Activation

### 3.1 Wire Up AiQueryLog Population

**Problem:** `AiQueryLog` exists but may not be consistently populated across all query paths.

**Current State:**
- `AiManager::generateQuery()` calls `storeQuery()` on success
- `storeQuery()` creates `AiQueryLog` records
- BUT: No deduplication before storage

**Implementation:**

1. **Verify all query paths log to AiQueryLog**:
   ```php
   // Ensure these paths all call storeQuery():
   // - AiManager::generateQuery() ✓
   // - AiManager::executeQuery() - may bypass
   // - AiChatService::askWithConversation() - ensure it calls AI facade
   ```

2. **Add deduplication before storage**:
   ```php
   public function storeQuery(string $question, ?string $cypher, array $metadata = []): void
   {
       // Check for near-duplicate in last 24 hours
       if ($this->queryLearner->isAlreadyLearned($question)) {
           Log::debug('Skipping duplicate query storage', ['question' => $question]);
           return;
       }

       AiQueryLog::create([
           'question' => $question,
           'cypher_query' => $cypher,
           // ...
       ]);
   }
   ```

**Priority:** HIGH
**Effort:** Low (0.5 days)

---

### 3.2 Connect QueryLearner/QueryAnalytics

**Problem:** `QueryLearner` and `QueryAnalytics` exist but are not integrated into the main query flow.

**Current State:**
- `QueryLearner::findSimilarLearnedQuery()` not called during query generation
- `learned_queries` collection underutilized
- Infrastructure exists, just not wired

**Implementation:**

1. **Integrate QueryLearner into query generation**:
   ```php
   // In QueryGenerator::generate() or AiManager::generateQuery()
   public function generateQuery(string $question, array $context): array
   {
       // Check learned queries first
       $learned = $this->queryLearner->findSimilarLearnedQuery($question);

       if ($learned && $learned['confidence'] > 0.9) {
           return [
               'cypher' => $learned['cypher'],
               'source' => 'learned',
               'confidence' => $learned['confidence'],
           ];
       }

       // Fall back to LLM generation
       return $this->generateFromLLM($question, $context);
   }
   ```

2. **Add learning from successful queries**:
   ```php
   // After successful query execution with user feedback
   public function learnFromSuccess(string $question, string $cypher, float $confidence): void
   {
       if ($confidence >= 0.8) {
           $this->queryLearner->storeLearnedQuery($question, $cypher, [
               'confidence' => $confidence,
               'learned_at' => now(),
           ]);
       }
   }
   ```

**Decision Required:** If QueryLearner is not providing value, consider removing it to reduce complexity.

**Priority:** MEDIUM
**Effort:** Medium (1-2 days)

---

### 3.3 User Feedback Mechanism (Thumbs Up/Down)

**Problem:** System has no way to know if responses are helpful. Learning is based on execution success, not user satisfaction.

**Implementation:**

1. **Add feedback UI in message display**:
   ```php
   // In AiChatPanel or message template
   // After each assistant message:
   <div class="feedback-buttons">
       <button onclick="submitFeedback(messageId, 'positive')">👍</button>
       <button onclick="submitFeedback(messageId, 'negative')">👎</button>
   </div>
   ```

2. **Add feedback endpoint**:
   ```php
   // New API endpoint or Kompo action
   public function submitFeedback($messageId, $feedback)
   {
       $message = AiMessage::findOrFail($messageId);
       $metadata = $message->metadata ?? [];
       $metadata['feedback'] = $feedback;
       $metadata['feedback_at'] = now()->toIso8601String();
       $message->update(['metadata' => $metadata]);

       // Trigger learning if negative
       if ($feedback === 'negative') {
           event(new NegativeFeedbackReceived($message));
       }
   }
   ```

3. **Factor feedback into learning**:
   ```php
   // In QueryLearner
   public function processUserFeedback(AiMessage $message, string $feedback): void
   {
       if ($feedback === 'positive') {
           // Boost confidence of this pattern
           $this->boostQueryPattern($message->cypher_query);
       } else {
           // Mark as negative example
           $this->recordNegativeExample($message->content, $message->cypher_query);
       }
   }
   ```

**Priority:** HIGH
**Effort:** Medium (2-3 days)

---

## 4. Embedding Improvements

### 4.1 AnthropicEmbeddingProvider Fix or Removal

**Problem:** Need to verify if `AnthropicEmbeddingProvider` is functional.

**Investigation Required:**
1. Check if Anthropic's API supports embeddings
2. Verify implementation in `AnthropicEmbeddingProvider`
3. Test embedding generation

**Implementation (if not working):**
```php
// If Anthropic doesn't support embeddings natively:
// Option A: Remove the provider
// Option B: Use alternative (OpenAI embeddings with Anthropic chat)

// Recommended: Use OpenAI for embeddings regardless of chat provider
'embedding_provider' => 'openai',  // In config
'chat_provider' => 'anthropic',    // Different provider for chat
```

**Priority:** MEDIUM
**Effort:** Low (0.5 days to verify, 1 day to fix/remove)

---

### 4.2 Embedding Cache Strategy

**Problem:** `SemanticMatcher::$embeddingCache` is in-memory only, resets each request.

**Current State:**
```php
// In SemanticMatcher
protected array $embeddingCache = [];  // Lost between requests
```

**Implementation:**

1. **Add persistent cache layer**:
   ```php
   class EmbeddingCache
   {
       protected string $cachePrefix = 'embedding:';
       protected int $ttl = 86400; // 24 hours

       public function get(string $text): ?array
       {
           $key = $this->cachePrefix . md5($text);
           return Cache::get($key);
       }

       public function set(string $text, array $embedding): void
       {
           $key = $this->cachePrefix . md5($text);
           Cache::put($key, $embedding, $this->ttl);
       }

       public function invalidateAll(): void
       {
           // Call when embedding model changes
           Cache::tags(['embeddings'])->flush();
       }
   }
   ```

2. **Use cache in embedding generation**:
   ```php
   public function embed(string $text): array
   {
       // Check cache first
       if ($cached = $this->embeddingCache->get($text)) {
           return $cached;
       }

       // Generate new embedding
       $embedding = $this->provider->embed($text);

       // Cache it
       $this->embeddingCache->set($text, $embedding);

       return $embedding;
   }
   ```

3. **Add model version tracking**:
   ```php
   // Store model version with cache key
   $key = "embedding:{$modelVersion}:" . md5($text);
   ```

**Priority:** MEDIUM
**Effort:** Low (1 day)

---

### 4.3 Multi-Model Embedding Support

**Problem:** No tracking of which model generated each embedding.

**Implementation:**

1. **Add model metadata to vector store**:
   ```php
   public function upsert(string $collection, array $points): void
   {
       foreach ($points as &$point) {
           $point['payload']['embedding_model'] = config('ai.embedding_model');
           $point['payload']['embedded_at'] = now()->toIso8601String();
       }

       $this->store->upsert($collection, $points);
   }
   ```

2. **Filter by model version in search**:
   ```php
   public function search(string $collection, array $vector, int $limit = 10): array
   {
       $currentModel = config('ai.embedding_model');

       return $this->store->search($collection, $vector, $limit, [
           'must' => [
               ['key' => 'embedding_model', 'match' => ['value' => $currentModel]]
           ]
       ]);
   }
   ```

3. **Add re-indexing command**:
   ```bash
   php artisan ai:reindex-embeddings --model=text-embedding-3-small
   ```

**Priority:** LOW
**Effort:** Medium (2 days)

---

## 5. Response Quality

### 5.1 Cypher Query Validation Before Execution

**Problem:** Invalid Cypher queries may be executed, causing errors.

**Implementation:**

1. **Add syntax validation**:
   ```php
   class CypherValidator
   {
       public function validate(string $cypher): ValidationResult
       {
           $errors = [];

           // Check for required clauses
           if (!preg_match('/RETURN/i', $cypher)) {
               $errors[] = 'Missing RETURN clause';
           }

           // Check for dangerous operations
           if (preg_match('/DELETE|REMOVE|DROP|CREATE|MERGE/i', $cypher)) {
               $errors[] = 'Write operations not allowed';
           }

           // Check for proper variable binding
           // ...additional checks...

           return new ValidationResult(empty($errors), $errors);
       }
   }
   ```

2. **Validate before execution**:
   ```php
   public function executeQuery(string $cypher): array
   {
       $validation = $this->validator->validate($cypher);

       if (!$validation->isValid()) {
           return [
               'error' => 'Invalid query',
               'details' => $validation->getErrors(),
           ];
       }

       return $this->executor->run($cypher);
   }
   ```

3. **Add EXPLAIN check for complex queries**:
   ```php
   // Run EXPLAIN first to check query plan
   $explain = $this->executor->run("EXPLAIN " . $cypher);
   if ($this->isExpensiveQuery($explain)) {
       Log::warning('Expensive query detected', ['cypher' => $cypher]);
   }
   ```

**Priority:** HIGH
**Effort:** Medium (2 days)

---

### 5.2 Result Caching Strategy

**Problem:** Each turn re-executes queries even for identical questions.

**Implementation:**

1. **Add query result cache**:
   ```php
   class QueryResultCache
   {
       protected int $ttl = 300; // 5 minutes

       public function getCacheKey(string $cypher): string
       {
           return 'query_result:' . md5($cypher);
       }

       public function get(string $cypher): ?array
       {
           return Cache::get($this->getCacheKey($cypher));
       }

       public function set(string $cypher, array $results): void
       {
           Cache::put($this->getCacheKey($cypher), $results, $this->ttl);
       }
   }
   ```

2. **Use cache in executor**:
   ```php
   public function execute(string $cypher): array
   {
       // Check cache first
       if ($cached = $this->resultCache->get($cypher)) {
           return array_merge($cached, ['cached' => true]);
       }

       $results = $this->runQuery($cypher);

       // Cache results
       $this->resultCache->set($cypher, $results);

       return $results;
   }
   ```

3. **Add cache invalidation on schema changes**.

**Priority:** MEDIUM
**Effort:** Low (1 day)

---

### 5.3 Error Recovery Patterns

**Problem:** Query failures not gracefully handled, no retry logic.

**Implementation:**

1. **Add retry with exponential backoff**:
   ```php
   public function executeWithRetry(string $cypher, int $maxAttempts = 3): array
   {
       $attempt = 0;
       $lastError = null;

       while ($attempt < $maxAttempts) {
           try {
               return $this->execute($cypher);
           } catch (ConnectionException $e) {
               $lastError = $e;
               $attempt++;
               sleep(pow(2, $attempt)); // Exponential backoff
           } catch (QueryException $e) {
               // Don't retry syntax errors
               throw $e;
           }
       }

       throw $lastError;
   }
   ```

2. **Add fallback query simplification**:
   ```php
   public function executeWithFallback(string $cypher): array
   {
       try {
           return $this->execute($cypher);
       } catch (TimeoutException $e) {
           // Try simplified query
           $simplified = $this->simplifyQuery($cypher);
           return $this->execute($simplified);
       }
   }

   protected function simplifyQuery(string $cypher): string
   {
       // Remove ORDER BY
       $cypher = preg_replace('/ORDER BY.+?(?=LIMIT|$)/i', '', $cypher);

       // Add LIMIT if missing
       if (!preg_match('/LIMIT/i', $cypher)) {
           $cypher .= ' LIMIT 100';
       }

       return $cypher;
   }
   ```

3. **Add graceful error messages**:
   ```php
   protected function formatErrorForUser(Exception $e): string
   {
       return match(get_class($e)) {
           TimeoutException::class => "The query took too long. Try asking for fewer results.",
           ConnectionException::class => "Unable to connect to the database. Please try again.",
           QueryException::class => "I had trouble understanding that query. Could you rephrase?",
           default => "Something went wrong. Please try again.",
       };
   }
   ```

**Priority:** HIGH
**Effort:** Medium (2 days)

---

## Implementation Roadmap

### Phase 1: Critical Fixes (Week 1)
| Item | Priority | Effort |
|------|----------|--------|
| 1.3 Reference Resolution (wire UI to askWithConversation) | HIGH | 2 days |
| 1.1 Entity Property Persistence | HIGH | 2-3 days |
| 5.1 Cypher Query Validation | HIGH | 2 days |

### Phase 2: Core Improvements (Week 2)
| Item | Priority | Effort |
|------|----------|--------|
| 1.2 Previous Query Results Availability | HIGH | 1 day |
| 3.1 AiQueryLog Population | HIGH | 0.5 days |
| 3.3 User Feedback Mechanism | HIGH | 2-3 days |
| 5.3 Error Recovery Patterns | HIGH | 2 days |

### Phase 3: Optimization (Week 3)
| Item | Priority | Effort |
|------|----------|--------|
| 2.1 Token Budget Management | MEDIUM | 2 days |
| 2.2 Priority-Based Entity Inclusion | MEDIUM | 2 days |
| 3.2 QueryLearner Integration | MEDIUM | 1-2 days |
| 4.2 Embedding Cache Strategy | MEDIUM | 1 day |

### Phase 4: Polish (Week 4+)
| Item | Priority | Effort |
|------|----------|--------|
| 4.1 AnthropicEmbeddingProvider Fix | MEDIUM | 0.5-1 day |
| 5.2 Result Caching Strategy | MEDIUM | 1 day |
| 2.3 User-Configurable Filtering | LOW | 1 day |
| 4.3 Multi-Model Embedding Support | LOW | 2 days |

---

## Success Metrics

### Conversation Quality
- Multi-turn conversations maintain context (test: "Show X" -> "What about Y?")
- Reference resolution works ("those", "them", "the same")
- Previous results accessible in follow-up questions

### Response Quality
- Query validation catches 95%+ of invalid queries before execution
- Error recovery provides graceful fallbacks
- User feedback rate > 5% of messages

### Performance
- Embedding cache hit rate > 70%
- Query result cache reduces DB calls by 30%
- Token budget prevents context overflow

### Learning
- QueryLearner provides high-confidence matches for 10%+ of queries
- Negative feedback triggers pattern adjustment
- AiQueryLog population is consistent across all paths

---

## Appendix: Files to Modify

| File | Changes |
|------|---------|
| `src/Kompo/ChatMessageForm.php` | Switch to askWithConversation(), add feedback UI |
| `src/Services/Chat/AiChatService.php` | Integrate context manager, deprecate askWithHistory |
| `src/Services/Chat/AiChatServiceInterface.php` | Add askWithConversation to interface |
| `src/Services/Context/ConversationContextManager.php` | Enhanced entity storage, result samples |
| `src/Services/Context/EntityExtractor.php` | Extract entity filter conditions |
| `src/Services/PromptSections/ConversationContextSection.php` | Render entity data, results |
| `src/Models/AiConversation.php` | Entity context methods |
| `src/Models/AiMessage.php` | Feedback metadata |
| `src/Services/QueryGenerator.php` | QueryLearner integration |
| `src/Services/QueryExecutor.php` | Validation, caching, retry logic |
| `src/Services/SemanticMatcher.php` | Persistent embedding cache |
| `src/Services/Learning/QueryLearner.php` | Activate and integrate |

---

## Conclusion

This improvement plan addresses the critical gaps identified in the audit:

1. **Context Management** - The most impactful improvements, enabling true multi-turn conversations
2. **Learning Loop** - Transforms system from "executing queries" to "learning to answer questions"
3. **Response Quality** - Prevents errors and improves reliability

Priority should be given to Phase 1 items, particularly wiring the UI to use `askWithConversation()` with full context management. This single change activates the existing but dormant context system.
