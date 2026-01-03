# Module 03: CONTEXT_RETRIEVAL - Findings

> **Status:** COMPLETE
> **Analyzed:** 2026-01-03
> **Files Reviewed:** 8

## Executive Summary

The context retrieval module implements a sophisticated RAG (Retrieval-Augmented Generation) system that combines vector search (Qdrant) and graph database (Neo4j) to provide rich context for LLM query generation. The architecture is well-designed with interface-based dependency injection, graceful degradation, and semantic-first context selection for token efficiency.

---

## Architecture Overview

### Data Flow
```
User Question
     |
     v
+------------------+
| ContextRetriever | (Main orchestrator)
+------------------+
     |
     +---> SemanticContextSelector ---> VectorStore (Qdrant)
     |         |                             |
     |         v                             v
     |    Entity/Scope/Relationship     Semantic search
     |    relevance filtering           on 'context_index'
     |
     +---> ScopeSemanticMatcher ------> VectorStore (Qdrant)
     |         |                             |
     |         v                             v
     |    Scope detection via          Semantic search
     |    examples/concepts            on 'scope_examples'
     |
     +---> GraphStore (Neo4j)
     |         |
     |         v
     |    Schema + Example entities
     |
     +---> VectorStore (Qdrant)
               |
               v
          Similar past queries
          from 'questions' collection
```

### Key Components

| Component | Responsibility | Lines |
|-----------|----------------|-------|
| `ContextRetriever` | Main RAG orchestrator, combines all context sources | 1323 |
| `SemanticContextSelector` | Semantic filtering of entities/relationships/scopes | 509 |
| `ScopeSemanticMatcher` | Scope detection via semantic matching | 383 |
| `SemanticMatcher` | General-purpose semantic similarity matching | 509 |
| `SemanticIndexer` | Builds/maintains vector indexes for semantic search | 450 |

---

## Qdrant (Vector Store) Integration

### Collections Used

| Collection | Purpose | Indexed Content |
|------------|---------|-----------------|
| `questions` | Similar past Q&A pairs | Question text + Cypher query |
| `context_index` | Semantic context selection | Entities, relationships, scopes, properties |
| `scope_examples` | Scope semantic matching | Scope concepts, examples, aliases |
| `semantic_entities` | Entity matching | Entity names, aliases, descriptions |
| `semantic_scopes` | Scope matching | Scope names, descriptions, concepts |
| `semantic_templates` | Template matching | Query template descriptions, examples |

### Search Operations

**Similar Query Search** (`ContextRetriever::searchSimilarQueries`):
```php
$embedding = $this->embeddingProvider->embed($question);
$results = $this->vectorStore->search(
    $collection,      // 'questions'
    $embedding,
    $limit,           // default 5
    [],               // no filter
    $scoreThreshold   // default 0.0
);
```

**Semantic Context Selection** (`SemanticContextSelector::selectRelevantContext`):
```php
$results = $this->vectorStore->search(
    $collectionName,        // 'context_index'
    $questionEmbedding,
    $topK * 2,              // over-fetch for filtering
    []
);
// Filter by threshold (default 0.65)
// Group by type: entity, relationship, scope
```

**Scope Matching** (`ScopeSemanticMatcher::findMatchingScopes`):
```php
$results = $this->vectorStore->search(
    $collectionName,        // 'scope_examples'
    $questionEmbedding,
    $topK * 2,              // over-fetch for filtering
    []
);
// Filter by threshold (default 0.7)
// Deduplicate by scope key
```

---

## Neo4j (Graph Store) Integration

### Schema Retrieval

```php
// ContextRetriever::getGraphSchema()
$schema = $this->graphStore->getSchema();
return [
    'labels' => $schema['labels'],
    'relationships' => $schema['relationshipTypes'],
    'properties' => $propertiesByLabel,  // from entity configs
    'propertyKeys' => $schema['propertyKeys'],
];
```

### Example Entity Retrieval

```php
// ContextRetriever::getExampleEntities($label, $limit)
$cypher = "MATCH (n:`{$label}`) WITH n, size(keys(n)) AS keyCount
           ORDER BY keyCount DESC RETURN n LIMIT \$limit";
$results = $this->graphStore->query($cypher, ['limit' => $limit]);
```

**Note:** Prioritizes nodes with more properties for richer context examples.

---

## Relevance Scoring Algorithm

### 1. Cosine Similarity (SemanticMatcher)
```php
private function cosineSimilarity(array $vector1, array $vector2): float
{
    $dotProduct = 0.0;
    $magnitude1 = 0.0;
    $magnitude2 = 0.0;

    for ($i = 0; $i < count($vector1); $i++) {
        $dotProduct += $vector1[$i] * $vector2[$i];
        $magnitude1 += $vector1[$i] * $vector1[$i];
        $magnitude2 += $vector2[$i] * $vector2[$i];
    }

    return $dotProduct / (sqrt($magnitude1) * sqrt($magnitude2));
}
```

### 2. Threshold Configuration

| Context | Default Threshold | Config Key |
|---------|-------------------|------------|
| Similar queries | 0.0 | N/A (accepts all) |
| Semantic context | 0.65 | `ai.semantic_context.threshold` |
| Scope matching | 0.7 | `ai.semantic_matching.scope_threshold` |
| Minimal context | 0.75 | Hardcoded in `getMinimalContext` |

### 3. Score Propagation

When a relationship is matched semantically:
- Related entities receive score * 0.8 (20% penalty for indirect match)
- Ensures connected entities are included but ranked lower

### 4. Confidence Calculation

```php
// ContextRetriever::getContextConfidence()
$allScores = array_merge($entityScores, $relationshipScores, $scopeScores);
$confidence['overall'] = array_sum($allScores) / count($allScores);
```

---

## Token Budget Management

### Budget-Aware Context Retrieval

```php
// ContextRetriever::getContextWithBudget($question, $maxTokens)
// 1. Start with minimal context
$context = $this->getMinimalContext($question, $options);
$stats = $this->getContextStats($context);

// 2. If under 50% budget, enhance with examples
if ($stats['token_estimate'] < $maxTokens * 0.5) {
    $options['includeExamples'] = true;
    $options['examplesPerLabel'] = 1;
    // ... try enhanced context
}
```

### Token Estimation

```php
private function estimateTokens(string $text): int
{
    // Rough approximation: 4 characters per token
    $baseTokens = (int) ceil(strlen($text) / 4);

    // Add 20% overhead for JSON structure
    if (looks_like_json($text)) {
        $baseTokens = (int) ceil($baseTokens * 1.2);
    }

    return $baseTokens;
}
```

### Compression Ratio

Tracks how much context was filtered out:
```php
$stats['compression_ratio'] = (1 - ($selectedEntities / $totalEntities)) * 100;
```

---

## Issues Found

| ID | Severity | Description | Evidence | Recommendation |
|----|----------|-------------|----------|----------------|
| CR-001 | LOW | Duplicate collection name variable | `SemanticContextSelector` line 59: `$collectionName = $this->config['collection'] ?? $this->collectionName;` when `$this->collectionName` already initialized from same source in constructor | Remove redundant assignment |
| CR-002 | MEDIUM | Dual entity detection paths | `ContextRetriever` has both `getEntityMetadata()` (string-based) and `buildMetadataFromSemanticSelection()` (semantic-based) which can produce different results | Consider unifying detection logic or documenting expected differences |
| CR-003 | LOW | Potential N+1 in example retrieval | `retrieveExampleEntities()` makes individual Neo4j queries per label | Consider batch query for multiple labels |
| CR-004 | INFO | Magic number thresholds | Score thresholds like 0.5, 0.8 hardcoded in multiple places | Consider centralizing in config |
| CR-005 | LOW | Embedding cache unbounded | `SemanticMatcher::$embeddingCache` grows without limit | Add cache size limit or TTL |
| CR-006 | INFO | Stopword list incomplete | `ScopeSemanticMatcher::extractSignificantWords()` has hardcoded English stopwords | Consider using configurable or multi-language stopwords |

---

## File Analysis

### ContextRetriever.php (1323 lines)

**Strengths:**
- Excellent documentation with examples
- Interface-based DI for testability
- Graceful degradation on partial failures
- Token budget management
- Confidence scoring

**Key Methods:**
- `retrieveContext()` - Main entry point, aggregates all context sources
- `getMinimalContext()` - Token-efficient minimal context
- `getContextWithBudget()` - Budget-aware context retrieval
- `getContextStats()` - Token usage statistics
- `filterSchemaByRelevance()` - Semantic schema filtering

### SemanticContextSelector.php (509 lines)

**Strengths:**
- Clean separation of indexing and selection
- Keyword fallback when vector index unavailable
- Proper deduplication of results

**Key Methods:**
- `selectRelevantContext()` - Main semantic selection
- `indexContext()` - Build context index
- `keywordBasedSelection()` - Fallback selection

### ScopeSemanticMatcher.php (383 lines)

**Strengths:**
- Semantic understanding of scope intent
- Good fallback to string matching
- Configurable thresholds

**Key Methods:**
- `findMatchingScopes()` - Semantic scope detection
- `indexScopes()` - Build scope index
- `fallbackStringMatch()` - Keyword-based fallback
- `explainMatch()` - Debug/explain matching decisions

### SemanticMatcher.php (509 lines)

**Strengths:**
- Efficient embedding caching
- Batch embedding support
- Fast-path exact matching

**Key Methods:**
- `findBestMatch()` - Core matching with multiple strategies
- `matchEntities()` - Entity detection in questions
- `matchLabel()` - Label matching with context
- `computeSimilarities()` - Rank all candidates

### SemanticIndexer.php (450 lines)

**Strengths:**
- Batch processing for efficiency
- Supports incremental and rebuild modes
- Clear collection organization

**Collections:**
- `semantic_entities` - Entity names, aliases, descriptions
- `semantic_scopes` - Scope information
- `semantic_templates` - Query template patterns

---

## Recommendations

### High Priority

1. **Add Circuit Breaker for Vector Store**
   - If Qdrant is unavailable, fall back to keyword-based matching faster
   - Currently relies on exception catching which can be slow

2. **Bound Embedding Cache**
   - Add max size or LRU eviction to `SemanticMatcher::$embeddingCache`
   - Risk of memory issues in long-running processes

### Medium Priority

3. **Batch Neo4j Queries for Examples**
   - Replace N individual queries with single multi-label query
   - Example: `MATCH (n) WHERE labels(n) IN $labels RETURN labels(n)[0] as label, collect(n)[0..$limit] as examples`

4. **Centralize Threshold Configuration**
   - Move all magic numbers to config
   - Allow per-entity or per-scope threshold overrides

### Low Priority

5. **Add Telemetry/Metrics**
   - Track context selection effectiveness
   - Monitor token usage patterns
   - Log semantic match quality for tuning

6. **Multi-language Stopword Support**
   - Load stopwords from config/language files
   - Support French (detected in lang files)

---

## Test Coverage Gaps

| Area | Current Coverage | Recommendation |
|------|------------------|----------------|
| Token budget edge cases | Unknown | Test when budget exactly equals context size |
| Fallback chains | Partial | Test semantic -> keyword -> no-result paths |
| Concurrent indexing | None | Test concurrent rebuildIndexes calls |
| Empty collections | Unknown | Test behavior when all collections empty |
