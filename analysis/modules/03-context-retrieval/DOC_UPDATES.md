# Module 03: CONTEXT_RETRIEVAL - Documentation Updates

> **Status:** COMPLETE
> **Analyzed:** 2026-01-03

## Required Changes

| Doc Path | Change Type | Description |
|----------|-------------|-------------|
| `docs/architecture/rag-system.md` | CREATE | Document the RAG architecture, data flow, and component interactions |
| `docs/configuration/thresholds.md` | CREATE | Document all configurable thresholds and their purposes |
| `docs/api/context-retriever.md` | CREATE | API documentation for ContextRetriever public methods |
| `config/ai.php` | UPDATE | Add missing threshold configs with documentation comments |

---

## Detailed Change Descriptions

### 1. RAG System Architecture Documentation

**File:** `docs/architecture/rag-system.md`

**Content to add:**
- Data flow diagram (as shown in FINDINGS.md)
- Component responsibility matrix
- Vector collection descriptions and purposes
- Fallback chain explanation (semantic -> keyword -> no-result)
- Token budget management strategy

### 2. Threshold Configuration Documentation

**File:** `docs/configuration/thresholds.md`

**Content to add:**

```markdown
# Semantic Matching Thresholds

## Overview
The context retrieval system uses multiple similarity thresholds to filter results.
Higher thresholds mean stricter matching (fewer but more relevant results).

## Configuration

| Config Key | Default | Range | Purpose |
|------------|---------|-------|---------|
| `ai.semantic_context.threshold` | 0.65 | 0.0-1.0 | Minimum score for entity/relationship selection |
| `ai.semantic_context.top_k` | 10 | 1-50 | Maximum items to consider before threshold filtering |
| `ai.semantic_matching.scope_threshold` | 0.7 | 0.0-1.0 | Minimum score for scope detection |
| `ai.semantic_matching.max_scopes` | 5 | 1-20 | Maximum scopes to return |

## Tuning Guidelines

- **Lower thresholds** (0.5-0.65): More inclusive, may include tangentially related context
- **Standard thresholds** (0.65-0.75): Balanced precision/recall
- **Higher thresholds** (0.75-0.9): Strict matching, may miss relevant context

## Hardcoded Values to Consider Externalizing

| Location | Value | Purpose |
|----------|-------|---------|
| `SemanticContextSelector` | 0.8 multiplier | Indirect match penalty |
| `ScopeSemanticMatcher::fallbackStringMatch` | 0.5 | String match default score |
| `ContextRetriever::getMinimalContext` | 0.75 | Minimal context threshold |
```

### 3. API Documentation

**File:** `docs/api/context-retriever.md`

**Content to add:**

```markdown
# ContextRetriever API

## Overview
Main orchestrator for RAG context retrieval.

## Public Methods

### retrieveContext(string $question, array $options = []): array

Retrieves comprehensive context for LLM query generation.

**Parameters:**
- `question`: Natural language user question
- `options`: Configuration array
  - `collection` (string): Vector collection name, default 'questions'
  - `limit` (int): Max similar queries, default 5
  - `includeSchema` (bool): Include graph schema, default true
  - `includeExamples` (bool): Include sample entities, default true
  - `examplesPerLabel` (int): Examples per entity type, default 2
  - `scoreThreshold` (float): Minimum similarity, default 0.0
  - `useSemanticSelection` (bool): Use semantic filtering, default true

**Returns:**
```php
[
    'similar_queries' => [...],
    'graph_schema' => [...],
    'relevant_entities' => [...],
    'entity_metadata' => [...],
    'errors' => [...],
    'selection_info' => [...],
]
```

### getMinimalContext(string $question, array $options = []): array

Token-efficient minimal context (requires SemanticContextSelector).

### getContextWithBudget(string $question, int $maxTokens, array $options = []): array

Budget-aware context retrieval with automatic adjustment.

### getContextStats(array $context): array

Returns token estimates and breakdown for a context array.

### getContextConfidence(string $question, array $options = []): array

Returns confidence scores for context selection quality.
```

### 4. Config File Updates

**File:** `config/ai.php`

**Changes needed:**

```php
return [
    // ... existing config ...

    /*
    |--------------------------------------------------------------------------
    | Semantic Context Selection
    |--------------------------------------------------------------------------
    |
    | Controls how context is selected for LLM prompts using vector similarity.
    |
    */
    'semantic_context' => [
        // Minimum similarity score for entities/relationships (0.0-1.0)
        // Lower = more inclusive, Higher = stricter matching
        'threshold' => env('AI_SEMANTIC_CONTEXT_THRESHOLD', 0.65),

        // Maximum items to consider before threshold filtering
        'top_k' => env('AI_SEMANTIC_CONTEXT_TOP_K', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Semantic Matching
    |--------------------------------------------------------------------------
    |
    | Controls scope detection and entity matching thresholds.
    |
    */
    'semantic_matching' => [
        // Minimum score for scope detection (0.0-1.0)
        'scope_threshold' => env('AI_SCOPE_THRESHOLD', 0.7),

        // Maximum scopes to return per query
        'max_scopes' => env('AI_MAX_SCOPES', 5),

        // Score multiplier for indirect matches (via relationships)
        'indirect_match_penalty' => env('AI_INDIRECT_MATCH_PENALTY', 0.8),
    ],

    /*
    |--------------------------------------------------------------------------
    | Relationship Weights
    |--------------------------------------------------------------------------
    |
    | Importance weights for different relationship types.
    | Used to prioritize which relationships to include in context.
    |
    */
    'relationship_weights' => [
        'default' => 0.5,
        // Add specific relationship weights as needed:
        // 'MEMBER_OF' => 0.8,
        // 'PURCHASED' => 0.7,
    ],
];
```

---

## Implementation Notes

### Priority Order

1. **HIGH:** Update `config/ai.php` with documented threshold configs
2. **MEDIUM:** Create `docs/architecture/rag-system.md`
3. **MEDIUM:** Create `docs/configuration/thresholds.md`
4. **LOW:** Create `docs/api/context-retriever.md`

### Dependencies

- No code changes required for documentation updates
- Config changes are backward-compatible (use existing defaults)

### Testing After Changes

- Verify config values are loaded correctly
- Test threshold changes affect behavior as documented
- Validate documentation accuracy against current code
