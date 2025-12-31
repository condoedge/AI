# Phase 5: AI-System-Specific Audit

**Date:** 2025-12-30
**Scope:** Tasks 43-47 Combined - Evaluating AI/Chat System Optimization

This audit evaluates whether the AI/chat system is operating optimally from an AI-specific perspective, examining conversation history usage, context consistency, embedding alignment, data utilization, and quality signals.

---

## 1. Conversation History Usage

### Current Implementation

**Files Analyzed:**
- `src/Models/AiConversation.php` - Conversation persistence
- `src/Models/AiMessage.php` - Message storage
- `src/Services/Chat/AiChatService.php` - Chat processing
- `src/Services/Context/ConversationContextManager.php` - Context management
- `src/Kompo/ChatMessageForm.php` - UI integration

### Findings

#### History Retrieval Methods

1. **`AiConversation::getRecentMessages(int $limit = 10)`**
   - Returns last N messages in chronological order
   - Used by `ChatMessageForm::sendMessage()` to build history for API calls
   - **Hardcoded limit of 10** - no configuration option exposed

2. **`ConversationContextManager::buildPromptContext($conversation, $maxHistory = 5)`**
   - Retrieves `$maxHistory * 2` messages (user + assistant pairs)
   - Chunks messages into exchange pairs
   - Includes cypher_query for each exchange
   - **Content is NOT truncated in storage, only in prompt formatting**

3. **`AiChatService::buildQuestionWithHistory()`**
   - Truncates assistant responses to 200 characters for context
   - Excludes the current question from history
   - Returns empty string if only 1 or no history messages

#### History Truncation Analysis

| Location | Truncation | Limit | Configurable |
|----------|-----------|-------|--------------|
| `getRecentMessages()` | Count-based | 10 messages | No (hardcoded) |
| `buildPromptContext()` | Count-based | 5 exchanges | Yes (parameter) |
| `ConversationContextSection::format()` | Content-based | 100/150 chars | No (hardcoded) |
| `buildQuestionWithHistory()` | Content-based | 200 chars | No (hardcoded) |

#### Filtering

- **No semantic filtering** - All messages included regardless of relevance
- **No role-based filtering** - System messages not distinguished
- **No topic-based filtering** - Off-topic exchanges included

### Issues Identified

1. **ISSUE: No token-aware history limiting**
   - History size based on message count, not token consumption
   - Risk of exceeding LLM context windows with long messages

2. **ISSUE: Dual history paths (inconsistent)**
   - `ChatMessageForm` uses `getRecentMessages(10)`
   - `AiChatService::askWithConversation()` uses `buildPromptContext(5)`
   - Two different paths with different limits

3. **ISSUE: Previous query result NOT used in next turn**
   - Only the Cypher query text is preserved, not the actual result data
   - `last_result_count` stored but never used for context enrichment
   - User cannot reference specific items from previous results

4. **GAP: No conversation summarization**
   - Long conversations include all raw messages
   - No compression or summarization for older context

---

## 2. Context Consistency

### Current Implementation

**Files Analyzed:**
- `src/Services/AiManager.php` - Central orchestration
- `src/Services/ContextRetriever.php` - RAG context retrieval
- `src/Services/Context/ConversationContextManager.php` - Conversation context
- `src/Services/Chat/AiChatService.php` - Chat layer context

### Context Flow Analysis

```
User Question
     |
     v
ChatMessageForm::sendMessage()
     |
     +-- builds history via getRecentMessages(10)
     |
     v
AiChatService::askWithHistory()
     |
     +-- enriches question via buildQuestionWithHistory()
     |
     v
AI Facade::answerQuestion()
     |
     +-- retrieveContext() --> RAG context
     +-- conversation_context (from options)
     +-- file_context (if enabled)
     |
     v
QueryGenerator::generate()
     |
     +-- receives merged context
     |
     v
Response
```

### Context Passing Inconsistencies

1. **Two entry points, different context handling:**
   - `askWithHistory()` - Flattens history into enriched question string
   - `askWithConversation()` - Uses full ConversationContextManager

2. **Context merging in AiManager::answerQuestion():**
   ```php
   // Conversation context only passed if explicitly provided
   if (!empty($options['conversation_context'])) {
       $context['conversation_context'] = $options['conversation_context'];
   }
   ```
   - BUT `ChatMessageForm` uses `askWithHistory()` which doesn't pass this

3. **Entity properties NOT persisted across turns:**
   - `context_snapshot` stores:
     - `focused_entity` (label name only)
     - `mentioned_entities` (label names only)
     - `last_query_type`
     - `last_result_count`
   - **Missing: actual entity IDs, property values, result samples**

### Issues Identified

1. **ISSUE: Context snapshot lacks entity details**
   - Only stores entity type names, not specific records
   - Cannot reference "that customer" or "those 5 orders"

2. **ISSUE: ChatMessageForm bypasses ConversationContextManager**
   - Direct call to `askWithHistory()` instead of `askWithConversation()`
   - Loses reference resolution, entity tracking benefits

3. **ISSUE: RAG context not cached per conversation**
   - Each turn re-retrieves similar queries from vector store
   - Could reuse highly relevant context within same conversation

4. **GAP: No context validation**
   - No check that context entities match conversation focus
   - Semantic context may drift from conversation topic

---

## 3. Embedding/Retriever Alignment

### Current Implementation

**Files Analyzed:**
- `src/Contracts/EmbeddingProviderInterface.php`
- `src/Services/SemanticMatcher.php`
- `src/Services/SemanticContextSelector.php`
- `src/Services/ContextRetriever.php`
- `src/VectorStore/QdrantStore.php`
- `src/Services/AiManager.php`

### Embedding Generation Flow

```
Question/Text
     |
     v
EmbeddingProvider::embed()
     |
     v
Vector (array of floats)
     |
     v
VectorStore::upsert() or ::search()
```

### Vector Collections Used

| Collection | Purpose | Indexed By |
|------------|---------|-----------|
| `questions` | Q&A pairs for few-shot learning | Question text |
| `context_index` | Entity/scope semantic matching | Entity descriptions, aliases |
| `learned_queries` | Successful query patterns | Question text |
| `chunks` (via QdrantChunkStore) | File content chunks | Chunk text |

### Alignment Analysis

1. **Generation (IndexSemanticCommand, SemanticContextSelector::indexContext)**
   - Indexes: entity labels + descriptions
   - Indexes: aliases
   - Indexes: relationship types
   - Indexes: scope names + examples + concepts
   - Indexes: property names + descriptions
   - **Well-structured for diverse matching**

2. **Storage**
   - `QdrantStore::upsert()` handles format conversion
   - Payload includes metadata for filtering
   - Auto-creates collection if missing
   - **Robust storage implementation**

3. **Retrieval**
   - `ContextRetriever::searchSimilarQueries()` - Searches questions collection
   - `SemanticContextSelector::selectRelevantContext()` - Searches context_index
   - `SemanticMatcher::findBestMatch()` - Ad-hoc similarity for candidates
   - **Multiple retrieval paths, generally aligned**

4. **Usage**
   - Similar queries → Few-shot examples in prompt
   - Semantic context → Filtered schema/metadata
   - Semantic matcher → Scope/entity detection
   - **Retrieval results actively used**

### Issues Identified

1. **ISSUE: Embedding cache is in-memory only**
   - `SemanticMatcher::$embeddingCache` resets each request
   - Same text re-embedded across requests
   - No persistent caching layer

2. **ISSUE: storeQuery() called on EVERY successful generation**
   ```php
   // AiManager::generateQuery()
   if (isset($generation['cypher']) && $generation['cypher']) {
       $this->storeQuery(...);
   }
   ```
   - Stores duplicate/similar questions repeatedly
   - No deduplication before storage
   - `QueryLearner::isAlreadyLearned()` exists but not used here

3. **GAP: No embedding versioning**
   - Model changes would invalidate all stored vectors
   - No tracking of which model generated each embedding

4. **GAP: learned_queries collection underutilized**
   - `QueryLearner` exists but not integrated into main flow
   - `findSimilarLearnedQuery()` not called during query generation

---

## 4. Data Collection vs Consumption

### Data Collected

**AiMessage (per message):**
| Field | Collected | Consumed | Notes |
|-------|-----------|----------|-------|
| `role` | Yes | Yes | Used for display, history |
| `content` | Yes | Yes | Core message text |
| `response_data` | Yes | Partial | Stored but rarely re-read |
| `context_used` | Yes | **No** | Debug data, never queried |
| `cypher_query` | Yes | Yes | Used for history context |
| `execution_time_ms` | Yes | Partial | Logged, not analyzed |
| `confidence_score` | Yes | **No** | Stored but never used |
| `metadata.referenced_files` | Yes | Yes | Tracked across conversation |
| `metadata.suggestions` | Yes | Partial | UI display only |

**AiConversation (per conversation):**
| Field | Collected | Consumed | Notes |
|-------|-----------|----------|-------|
| `metadata` | Yes | Partial | Only `pinned` checked |
| `context_snapshot.focused_entity` | Yes | Yes | Reference resolution |
| `context_snapshot.mentioned_entities` | Yes | Yes | Context building |
| `context_snapshot.last_query_type` | Yes | Yes | Prompt formatting |
| `context_snapshot.last_result_count` | Yes | **No** | Stored but unused |
| `context_snapshot.last_relationships` | Yes | **No** | Stored but unused |
| `context_snapshot.referenced_files` | Yes | Partial | Tracked but not queried |

**AiQueryLog (per query):**
| Field | Collected | Consumed | Notes |
|-------|-----------|----------|-------|
| `question` | Yes | Yes | Analytics, learning |
| `cypher_query` | Yes | Yes | Learning loop |
| `template_used` | Yes | Yes | Analytics |
| `confidence_score` | Yes | Yes | Learning threshold |
| `execution_time_ms` | Yes | Yes | Analytics |
| `result_count` | Yes | Partial | Logged only |
| `status` | Yes | Yes | Success rate calc |
| `error_message` | Yes | Yes | Debugging |
| `context_stats` | Yes | **No** | Never queried |
| `metadata` | Yes | **No** | Never queried |

### Consumed Data Summary

**Actively Used:**
- Message content and role
- Cypher queries (for history, learning)
- Query status (for analytics)
- Focused entity and mentioned entities
- Template usage patterns

**Partially Used:**
- Execution time (logged, basic analytics)
- Response data (UI display only)
- Metadata (selective fields only)

**Never Consumed (Dead Data):**
- `context_used` on messages
- `confidence_score` on messages
- `last_result_count` in context snapshot
- `last_relationships` in context snapshot
- `context_stats` in query logs
- `metadata` in query logs

### Issues Identified

1. **ISSUE: 6 database fields never consumed**
   - Storage cost with no analytical value
   - Either integrate or stop collecting

2. **ISSUE: context_used stored but never analyzed**
   - Could be valuable for debugging, prompt optimization
   - Currently write-only

3. **ISSUE: confidence_score never influences behavior**
   - Stored per message but not used
   - Could gate response quality, trigger clarification

4. **GAP: No data lifecycle management**
   - No cleanup of old query logs
   - No archival of old conversations
   - Data accumulates indefinitely

---

## 5. Quality Signals

### Feedback Currently Captured

| Signal | Captured | Location | Usage |
|--------|----------|----------|-------|
| Query success/failure | Yes | AiQueryLog.status | Analytics |
| Execution time | Yes | AiQueryLog.execution_time_ms | Slow query logging |
| Result count | Yes | AiQueryLog.result_count | Not analyzed |
| Confidence score | Yes | AiQueryLog.confidence_score | Learning threshold |
| Template match | Yes | AiQueryLog.template_used | Template analytics |
| Error messages | Yes | AiQueryLog.error_message | Debugging |

### Feedback NOT Captured

| Signal | Status | Impact |
|--------|--------|--------|
| **User thumbs up/down** | Missing | Cannot learn user preference |
| **Answer usefulness rating** | Missing | Cannot measure quality |
| **Query correction by user** | Missing | Cannot learn from mistakes |
| **Response regeneration** | Missing | Cannot detect dissatisfaction |
| **Conversation abandonment** | Missing | Cannot detect frustration |
| **Follow-up clarification rate** | Missing | Cannot measure understanding |
| **Result click-through** | Missing | Cannot measure relevance |
| **Copy/share actions** | Missing | Cannot measure value |

### Learning Loop Analysis

**QueryLearner Implementation:**
```php
// Only learns from high-confidence successful queries
$successfulQueries = AiQueryLog::where('status', 'success')
    ->where('confidence_score', '>=', $minConfidence / 100)
    ->whereNotNull('cypher_query')
    ...
```

**Issues:**
1. No user validation of "success"
2. Confidence is model self-assessment, not ground truth
3. No negative learning (what NOT to do)
4. No incremental learning - batch only

### Query Success/Failure Tracking

**What's tracked:**
- Status: success, failed, timeout, rejected
- Error messages for failed queries

**What's NOT tracked:**
- Semantic correctness (did answer match intent?)
- Partial success (correct query, wrong interpretation)
- Near-misses (almost correct)
- Query refinement chains

### Issues Identified

1. **CRITICAL: No user feedback mechanism**
   - System has no way to know if responses are helpful
   - Learning is based on execution success, not user satisfaction
   - Cannot improve without feedback loop

2. **CRITICAL: No explicit learning from failures**
   - Failed queries logged but not learned from
   - Same mistakes can repeat indefinitely
   - No "negative examples" in training

3. **ISSUE: Success rate metric misleading**
   - Based on technical execution, not semantic correctness
   - A wrong answer that executes is counted as "success"

4. **ISSUE: No A/B testing infrastructure**
   - Cannot compare prompt variations
   - Cannot measure improvement from changes

5. **GAP: No session-level analytics**
   - Success rate per-query only
   - Cannot track user journey effectiveness

---

## Summary of Data Flow Gaps

### Critical Gaps (AI Quality Impact)

1. **Previous query RESULTS not available for next turn**
   - Only query text preserved, not data
   - Cannot reference "those 5 customers" from last response

2. **No user feedback collection**
   - System blind to response quality
   - Learning based on execution, not satisfaction

3. **Entity properties not persisted**
   - Only entity type names tracked
   - Cannot maintain context about specific records

4. **Dual history paths with inconsistent limits**
   - `ChatMessageForm` vs `AiChatService::askWithConversation`
   - Different context richness depending on entry point

### Moderate Gaps

5. **Embedding cache not persistent**
   - Same text re-embedded across requests
   - Performance overhead

6. **learned_queries underutilized**
   - Infrastructure exists, not integrated

7. **6+ database fields never consumed**
   - Storage waste, potential signal loss

8. **No token-aware context limiting**
   - Risk of context overflow

---

## Missing Signals for AI Quality

### Essential (Should Add)

1. **User feedback on responses**
   - Thumbs up/down per message
   - Optional text feedback
   - Correction submission

2. **Response regeneration tracking**
   - Did user ask to "try again"?
   - How many attempts per question?

3. **Result utilization**
   - Did user act on the data?
   - Copy, export, click-through metrics

### Valuable (Consider Adding)

4. **Clarification request rate**
   - How often does user need to rephrase?

5. **Conversation completion rate**
   - Did user get what they needed?

6. **Time-to-answer correlation**
   - Does faster = more satisfying?

7. **Query complexity vs success rate**
   - Which question types fail most?

---

## Recommendations

### High Priority

1. **Implement user feedback capture**
   - Add thumbs up/down to message UI
   - Store in `ai_messages.metadata.feedback`
   - Factor into QueryLearner

2. **Store result samples in context_snapshot**
   - Keep top 3-5 result records for reference
   - Enable "those customers" type references

3. **Unify history handling**
   - Make `ChatMessageForm` use `askWithConversation()`
   - Single source of truth for context building

4. **Integrate QueryLearner into main flow**
   - Check learned_queries before generating
   - Use as high-confidence few-shot examples

### Medium Priority

5. **Add token-aware context limiting**
   - Estimate token count per context section
   - Trim oldest/least relevant when exceeding budget

6. **Implement persistent embedding cache**
   - Redis or database cache for embeddings
   - Invalidation on model change

7. **Create cleanup jobs for old data**
   - Archive old conversations
   - Prune query logs beyond retention period

8. **Remove or utilize dead data fields**
   - Either build analytics for unused fields
   - Or stop collecting them

### Future Consideration

9. **Conversation summarization for long contexts**
10. **A/B testing framework for prompt experiments**
11. **Session-level success metrics**
12. **Semantic correctness validation**

---

## Conclusion

The AI system has solid foundations for context management and learning, but critical gaps in user feedback prevent true optimization. The system can track execution success but cannot distinguish between technically correct and semantically useful responses.

Priority should be given to:
1. User feedback collection
2. Result data persistence for cross-turn reference
3. Unifying the dual context paths
4. Activating the dormant QueryLearner

These changes would transform the system from "executing queries" to "learning to answer questions."
