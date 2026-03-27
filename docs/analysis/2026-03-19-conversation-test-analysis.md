# Conversation Test Failure Analysis

> **Date:** 2026-03-19
> **Purpose:** Detailed root cause analysis for sharing with another AI to continue improvements
> **Current pass rate:** 12/20 (60%) across 5 conversation scripts
> **Project:** Kompo AI — Laravel RAG package that converts natural language to Neo4j Cypher queries

---

## Architecture Overview

The conversation pipeline flows through these components:

```
User Question
  -> AiChatService.askWithConversation()
    -> ConversationContextManager.processQuestion()     [extracts entities, resolves references]
    -> AI::answerQuestion()
      -> ContextRetriever.retrieveContext()              [semantic selection + QU]
        -> SemanticContextSelector.selectRelevantContext() [embedding search in context_index]
        -> QueryUnderstandingService.analyze()            [LLM pre-processing, conditional]
      -> QueryGenerator.generateQuery()                  [LLM generates Cypher]
      -> QueryExecutor.executeQuery()                    [runs Cypher against Neo4j]
      -> ResponseGenerator.generateResponse()            [LLM generates answer]
    -> ConversationContextManager.recordResponse()       [updates context_snapshot]
```

**Key files:**
- `src/Services/Chat/AiChatService.php` — orchestrates the chat pipeline
- `src/Services/Context/ConversationContextManager.php` — manages context_snapshot across turns
- `src/Services/Context/EntityExtractor.php` — extracts entity types from questions
- `src/Services/Context/ReferenceResolver.php` — resolves "them", "those", "he" etc.
- `src/Services/ContextRetriever.php` — retrieves relevant schema/entities via semantic search
- `src/Services/SemanticContextSelector.php` — embedding-based entity selection
- `src/Services/QueryUnderstandingService.php` — LLM pre-processing for entity/name detection
- `src/Services/QueryGenerator.php` — generates Cypher from question + context
- `src/Services/PromptSections/ConversationContextSection.php` — injects conversation history into LLM prompt
- `src/Services/PromptSections/RelationshipsSection.php` — injects relationship schema
- `src/Services/PromptSections/NameResolutionSection.php` — injects name search patterns

**Key config:**
- `config/ai.php` — semantic_context thresholds, QU settings
- `config/entities.php` — loads from `config/ai-entities/*.php` category files
- Entity configs have: `graph` (label, properties, relationships), `vector`, `security`, `metadata` (aliases, description, examples, scopes, common_properties)

---

## Test Results (20 turns across 5 conversations)

### Test 1: Possessive Queries (user 302 — Herve Pilorge, a resident)

| Turn | Question | Result | Cypher Generated |
|------|----------|--------|-----------------|
| 1 | "Can you show me details about my unit?" | PASS | `MATCH (c:Contact {user_id: 302})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(u:Unit) WHERE cu.sold_at IS NULL...` |
| 2 | "Who are my neighbors?" | PASS | `MATCH (c:Contact)<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(u:Unit) WHERE ... u.name <> "3"...` |
| 3 | "Do I have any unpaid invoices?" | PASS | `MATCH (i:Invoice) WHERE i.status <> '5'...` |
| 4 | "What's my insurance status?" | **FAIL** | No Cypher generated |

**Turn 4 failure analysis:**
- After Turn 3 returned Invoice data, the context_snapshot has `focused_entity: Invoice`
- "My insurance status" requires querying Unit properties (insurance_term, insurance_company)
- The conversation context force-includes Invoice in semantic selection (score 0.55)
- Semantic search for "insurance status" might find Unit (insurance fields) but the LLM receives both Invoice and Unit context
- The word "insurance" is ambiguous — could relate to invoice insurance charges or unit insurance properties
- The LLM decides not to generate a query, likely confused by the dual context
- **NOTE:** This turn passes intermittently (~50% of runs), suggesting LLM non-determinism is the primary factor
- When run standalone (`ai:test-question "What's my insurance status?" --user=302`), it ALWAYS works

### Test 2: Name Lookup & Follow-ups (user 1512 — Tavelli Ezequiel)

| Turn | Question | Result | Cypher Generated |
|------|----------|--------|-----------------|
| 1 | "Is there a contact named Bassem?" | PASS | `MATCH (c:Contact) WHERE toLower(c.first_name) CONTAINS toLower("Bassem")...` |
| 2 | "What unit does he live in?" | PASS | `MATCH (c:Contact {id: 1155})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(u:Unit)...` |
| 3 | "Who else lives there?" | **FAIL** | No Cypher generated |
| 4 | "Are any of them on the board?" | **FAIL** | No Cypher generated |

**Turn 3 failure analysis:**
- After Turn 2, context has `focused_entity: Unit` and previous results show unit 303
- "Who else lives there?" requires resolving "there" to unit 303 and querying Contact via ContactUnit
- `ReferenceResolver.isFollowUp()` detects this as a follow-up (matches `/\b(there)\b/i`)
- `ReferenceResolver.resolve()` returns `resolved_entity: Unit` (the focused entity)
- The enriched question should be something like "Who else lives in Unit 303?"
- However, the semantic selection with Unit as the focused entity may not include Contact schema
- Without Contact properties in the prompt, the LLM can't generate a valid Cypher
- **Root cause:** The semantic context selector doesn't include related entities (Contact) when the focused entity (Unit) has a relationship to them. The prompt has Unit schema but not Contact schema, so the LLM can't compose a traversal query.
- **NOTE:** This turn passed in an earlier run (4/4), suggesting timing/rate-limit sensitivity

**Turn 4 failure analysis:**
- Cascading failure from Turn 3 — no data in context to resolve "them"
- Even if Turn 3 had worked, "them" should resolve to the contacts returned
- The system would need to detect that "them" refers to the result set from Turn 3 (contacts), not the focused entity

### Test 3: Bilingual Conversation (user 13 — Julie Rousseau, admin)

| Turn | Question | Result | Cypher Generated |
|------|----------|--------|-----------------|
| 1 | "Combien d'unites avons-nous?" | **FAIL** | No Cypher generated |
| 2 | "Qui sont les membres du conseil d'administration?" | PASS | `MATCH (bm:BoardMember)-[:BELONGS_TO_CONTACT]->(c:Contact)...` |
| 3 | "Show me the suppliers" | PASS | `MATCH (s:Supplier) RETURN DISTINCT s.id, s.name...` |
| 4 | "Lesquels ont des factures impayees?" | **FAIL** | No Cypher generated |

**Turn 1 failure analysis:**
- This is the SAME question that passes when run via `ai:test-question` standalone
- The chat pipeline adds `ConversationContextManager.processQuestion()` before the RAG pipeline
- `EntityExtractor.extractFromQuestion()` was updated to check aliases for French terms
- However, entity alias matching depends on `config('entities')` being loaded, which returns all 128 entities
- Building the alias map for 128 entities is expensive and might cause issues
- The aliases for Unit include "unité", "unités", "unite", "unites" — these SHOULD match "unités" in the question
- **Possible root cause:** The `str_contains()` matching may fail if the alias has an accent but the question uses a different Unicode representation (e.g., composed vs decomposed: `é` vs `e` + combining accent)
- **More likely root cause:** This is intermittent (passed in earlier run). The LLM non-determinism or API latency is causing the QueryGenerator to fail silently. When the context retrieval takes too long, the query generation LLM call may time out or return an empty result.
- **Evidence:** Turn 1 passed 4/4 in an earlier bilingual test run

**Turn 4 failure analysis:**
- "Lesquels ont des factures impayées?" = "Which of them have unpaid invoices?"
- After Turn 3 (suppliers), `focused_entity` is Supplier
- The French follow-up "lesquels" (which of them) should reference the suppliers
- Needs to traverse Supplier → Bill (supplier invoices) or similar
- The relationship between Supplier and invoices/bills may not be in the semantic context
- **Root cause:** Cross-entity follow-up in French — the system needs Supplier + Bill/Invoice schema but likely only has Supplier from the focus. Additionally, the French "factures" should match Invoice aliases but the semantic selector may not find the connection.

### Test 4: Financial Deep Dive (user 13)

| Turn | Question | Result | Cypher Generated |
|------|----------|--------|-----------------|
| 1 | "How many open invoices do we have?" | PASS | `MATCH (i:Invoice) WHERE i.status <> '5' RETURN count(i)...` |
| 2 | "What's the total amount?" | PASS | `MATCH (i:Invoice) WHERE i.status <> '5' RETURN sum(i.calc_total_amount)...` |
| 3 | "Which units owe the most?" | **FAIL** | No Cypher generated |
| 4 | "Show me the recent payments" | PASS | `MATCH (t:Transaction)-[:UNIT]->(u:Unit) WHERE t.type = '4'...` |

**Turn 3 failure analysis:**
- "Which units owe the most?" requires a cross-entity aggregation: Invoice → Unit, GROUP BY unit, SUM amount, ORDER BY DESC
- After Turn 2, context has `focused_entity: Invoice` and Invoice is force-included
- The question mentions "units" which should trigger Unit entity inclusion
- The required Cypher would be something like:
  ```cypher
  MATCH (i:Invoice)-[:INVOICED_TO_UNIT]->(u:Unit)
  WHERE i.status <> '5'
  RETURN u.name, sum(i.calc_due_amount) AS total_owed
  ORDER BY total_owed DESC
  LIMIT 10
  ```
- This requires BOTH Invoice and Unit in the schema context, plus the relationship between them
- **Root cause:** The semantic selector may include Unit (it's mentioned in the question), but the relationship `INVOICED_TO_UNIT` (polymorphic via `customer_type/customer_id`) is complex. The Invoice entity config uses `morph` relationships which require special handling:
  ```php
  'type' => 'HAS_INVOICES',
  'target_label' => 'Invoice',
  'foreign_key' => 'customer_id',
  'morph' => 'customer',
  'direction' => 'incoming',
  'inverse_type' => 'INVOICED_TO_UNIT',
  ```
- The LLM may not understand how to traverse polymorphic relationships from the schema alone
- **Consistently fails** across all runs — this is a genuine capability gap, not intermittent

### Test 5: Complex Relationship Traversals (user 13)

| Turn | Question | Result | Cypher Generated |
|------|----------|--------|-----------------|
| 1 | "Which building has the most units?" | **FAIL** | No Cypher generated |
| 2 | "List the owners of units in that building" | **FAIL** | No Cypher generated |
| 3 | "Do any of them have pets?" | PASS | `MATCH (p:Pet)-[:UNIT]->(u:Unit)-[:BUILDING]->(b:Building)...` |
| 4 | "What about vehicles?" | PASS | `MATCH (v:Vehicle)-[:UNIT]->(u:Unit)...` |

**Turn 1 failure analysis:**
- "Which building has the most units?" requires: MATCH (b:Building)<-[:BUILDING]-(u:Unit) WITH b, COUNT(u) AS unitCount RETURN b ORDER BY unitCount DESC LIMIT 1
- This SAME question passed in an earlier run (generated the exact correct Cypher)
- When run standalone via `ai:test-question`, it works consistently
- **Root cause:** Intermittent — likely LLM non-determinism or API rate limiting

**Turn 2 failure analysis:**
- Cascading failure from Turn 1 — without the building result, "that building" can't be resolved
- When Turn 1 passes, Turn 2 also passes (shown in earlier test run)

---

## Failure Classification

### Category A: Intermittent (LLM Non-determinism)
These failures happen sometimes but not always with the same input. The code path is correct.

| Failure | Evidence |
|---------|----------|
| French "Combien d'unites" (bilingual T1) | Passed 4/4 in one run, failed in another |
| "My insurance status" (possessive T4) | Passed in one run, failed in another |
| "Which building has most units" (complex T1) | Passed 4/4 in one run, failed in another |
| Name lookup T3-T4 | Passed 4/4 in one run, failed in another |

**Characteristics:**
- Same question, same user, same pipeline → different results
- Pass when run standalone via `ai:test-question`
- Fail more often when run after multiple API calls (rate limit sensitivity)
- Typically return "no data available" without even generating Cypher

**Possible causes:**
1. **OpenAI rate limiting** — multiple rapid API calls in conversation tests cause 429 responses that are caught silently
2. **LLM temperature** — QueryGenerator uses non-zero temperature, causing variable outputs
3. **Context window pressure** — conversation context adds ~500-1000 tokens to the prompt, which may push the LLM to generate shorter/empty responses
4. **Timeout** — API calls that take too long may return truncated responses

**Recommended investigation:**
1. Check QueryGenerator temperature setting — consider lowering to 0.0 for deterministic results
2. Add retry logic in QueryGenerator for empty Cypher responses
3. Log the full prompt sent to QueryGenerator when it returns "NO QUERY REQUIRED" to verify context completeness
4. Check if OpenAI returns 429 errors and add rate limit handling in the LLM provider

### Category B: Consistent Failures (Missing Capability)
These failures happen every time and represent genuine gaps.

| Failure | Root Cause |
|---------|-----------|
| "Which units owe the most?" (financial T3) | Cross-entity aggregation via polymorphic relationship |
| "Lesquels ont des factures impayées?" (bilingual T4) | Cross-entity follow-up in French (Supplier→Invoice) |

**Characteristics:**
- Fail consistently across all runs
- Require joining 2+ entities with aggregation
- The LLM lacks sufficient schema context to compose the join
- Polymorphic relationships (morph) are harder for the LLM to understand

---

## Context Snapshot State at Each Turn (Example: Financial Test)

```
Turn 1: "How many open invoices?"
  context_snapshot = {
    focused_entity: "Invoice",
    mentioned_entities: ["Invoice"],
    last_cypher_query: "MATCH (i:Invoice) WHERE i.status <> '5' RETURN count(i)...",
    last_result_sample: [{"open_invoice_count": 4547}],
    last_query_type: "count"
  }

Turn 2: "What's the total amount?"
  -> ReferenceResolver detects follow-up
  -> focused_entity stays Invoice (from context)
  -> LLM sees previous Cypher and modifies count→sum
  -> PASS

Turn 3: "Which units owe the most?"
  -> "units" detected by EntityExtractor
  -> But question also implies Invoice aggregation
  -> Semantic selector: Unit found via "units", Invoice force-included via conversation focus
  -> LLM receives both Unit and Invoice schema
  -> MISSING: The relationship between Invoice and Unit (polymorphic INVOICED_TO_UNIT)
  -> LLM can't compose the join → returns NO QUERY REQUIRED
  -> FAIL
```

---

## Semantic Context Selection Details

The `SemanticContextSelector` uses a `context_index` vector collection containing:
- Entity labels, aliases, descriptions, and **entity-level examples**
- Scope concepts and examples
- Relationship types and descriptions
- Property names

When a question is embedded and searched:
1. Top-K results are retrieved (default: 10, threshold: 0.45)
2. Results are grouped by entity → entities with any match above threshold are included
3. Relationships between selected entities are included
4. Matched scopes are included

**The gap:** When the user says "Which units owe the most?", semantic search finds "Unit" (from "units" keyword), and "Invoice" is force-included from conversation context. But the **relationship** between them (`INVOICED_TO_UNIT`) may not be in the semantic results because neither entity's alias/description mentions the other's relationship name.

The `RelationshipsSection` prompt section should include relationships between selected entities, but it only includes relationships where BOTH entities are in the selected set. If the relationship is defined on one entity but the other entity's config defines it differently (or polymorphically), it may be missed.

---

## Files Modified in This Session (for reference)

| File | Change | Purpose |
|------|--------|---------|
| `src/Services/Context/ConversationContextManager.php` | Removed null guard on focused_entity update in `recordResponse()` | Fix: focused_entity now always reflects the latest Cypher query's entity |
| `src/Services/Context/EntityExtractor.php` | Added alias matching via `buildAliasMap()`, French query type patterns | Fix: French terms like "unités" now match Unit entity |
| `src/Services/ContextRetriever.php` | Force-include conversation's focused entity in semantic selection | Fix: follow-up questions maintain entity context |
| `src/Services/PromptSections/ConversationContextSection.php` | Enhanced instructions for topic switching and aggregation follow-ups | Fix: LLM knows when to reuse previous query vs generate fresh |
| `config/ai.php` | Added `conversation_focus_score` and `qu_entity_score` config keys | Config: tunable scores for context injection |
| `src/Services/Discovery/AiConfigEnhancer.php` | Added `examples`, `relationship_descriptions` to LLM output schema and merge logic | Feature: enhancer now generates entity-level examples and relationship descriptions |
| `src/Services/Discovery/LangExplorer.php` | New service | Feature: extracts bilingual translations from lang files for enhancer |
| `src/Console/Commands/TestConversationCommand.php` | New command | Feature: multi-turn conversation testing (REPL + script modes) |
| `src/Console/Commands/EnhanceConfigCommand.php` | New command | Feature: iterative AI config enhancement with feedback loop |

---

## Recommended Next Steps

### High Impact (addresses consistent failures)
1. **Include relationships between selected entities in prompt** — When both Unit and Invoice are in semantic context, the `RelationshipsSection` should include ALL relationships between them, including polymorphic ones. Currently it may miss morph relationships.
2. **Cross-entity aggregation examples** — Add examples to Invoice config like "Which units owe the most?" with a Cypher pattern showing the join. This gives the LLM a template to follow.
3. **Previous Cypher reuse guidance** — When the conversation context includes a previous Cypher query, explicitly tell the LLM it can modify that query (change RETURN clause, add JOINs) rather than starting from scratch.

### Medium Impact (addresses intermittent failures)
4. **QueryGenerator temperature** — Check current temperature setting. If > 0, consider lowering to 0.0 or 0.1 for more deterministic query generation.
5. **Rate limit handling** — Add retry with backoff in the LLM provider for 429 responses. The conversation tests make 8-12 API calls in rapid succession.
6. **Empty Cypher logging** — When QueryGenerator returns "NO QUERY REQUIRED", log the full prompt context so we can diagnose why the LLM decided not to generate a query.

### Lower Impact (polish)
7. **Conversation context window management** — As conversations grow, the context section becomes large. Consider summarizing older turns instead of including full exchange history.
8. **French embedding quality** — Verify that French aliases are properly indexed in `context_index`. Run `ai:index-context` and check if French terms have embeddings.
