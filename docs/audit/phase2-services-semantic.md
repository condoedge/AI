# Phase 2 Audit: Semantic Services

## Overview

This audit covers the semantic services that power entity/scope detection, context selection, prompt building, and text chunking for the AI system. These services form the "intelligence layer" that enables natural language understanding and vector-based semantic matching.

---

## Service: SemanticChunker

**File:** `src/Services/SemanticChunker.php`

### Purpose
Intelligently splits text content into chunks while preserving semantic coherence (sentence and paragraph boundaries). Used for file processing pipeline to prepare content for embedding.

### Dependencies
- **Implements:** `FileChunkerInterface`
- **No external dependencies** - Pure PHP text processing

### Main Functionality
| Method | Purpose |
|--------|---------|
| `chunk(string $content, array $options)` | Main entry point - chunks text by paragraphs, sentences, or characters |
| `getRecommendedChunkSize(string $fileType)` | Returns optimal chunk size for file type |
| `getRecommendedOverlap(string $fileType)` | Returns optimal overlap for file type |

### Embedding/Vector Operations
- **None directly** - This service prepares text for embedding but doesn't call embedding APIs

### Pipeline Integration
- Registered in `AiServiceProvider` as `FileChunkerInterface::class` binding
- Used by `FileProcessor` to chunk file content before embedding
- Part of the file processing pipeline: `File -> Extract -> Chunk -> Embed -> Store`

### Configuration
```php
const CHUNK_SIZES = [
    'pdf' => 1200, 'txt' => 1000, 'md' => 1500,
    'docx' => 1200, 'html' => 1500, 'default' => 1000
];
const OVERLAP_SIZES = [
    'pdf' => 200, 'txt' => 150, 'md' => 300,
    'docx' => 200, 'html' => 300, 'default' => 200
];
```

### Test Coverage
- **Test file:** `tests/Unit/Services/SemanticChunkerTest.php`
- Well-tested with 15+ test cases

### Notes
- Clean implementation with no unused methods
- All methods are actively used

---

## Service: SemanticContextSelector

**File:** `src/Services/SemanticContextSelector.php`

### Purpose
Intelligently selects which context (entities, relationships, scopes) to include in prompts based on semantic relevance to the user's question. Reduces token consumption by excluding irrelevant context.

### Dependencies
- `VectorStoreInterface` - For vector similarity search
- `EmbeddingProviderInterface` - For generating embeddings

### Main Functionality
| Method | Purpose |
|--------|---------|
| `selectRelevantContext(string $question, array $entityConfigs, array $options)` | Main entry - finds relevant entities/relationships/scopes |
| `indexContext(array $entityConfigs)` | Creates vector index of all context |
| `keywordBasedSelection()` | Fallback when index doesn't exist |

### Private Methods
| Method | Purpose |
|--------|---------|
| `createEntityPoints()` | Prepares entity data for indexing |
| `createRelationshipPoints()` | Prepares relationship data for indexing |
| `createPropertyPoints()` | Prepares property data for indexing |
| `createScopePoints()` | Prepares scope data for indexing |
| `indexExists()` | Checks if vector index is populated |
| `findFullEntityName()` | Resolves short name to FQCN |

### Embedding/Vector Operations
- `embeddingProvider->embed($question)` - Embeds user questions
- `embeddingProvider->embedBatch($texts)` - Batch embeds context during indexing
- `vectorStore->search()` - Finds similar context
- `vectorStore->ensureCollection()` - Creates collection
- `vectorStore->deleteAll()` - Clears collection for rebuild
- `vectorStore->upsertBatch()` - Stores indexed vectors

### Pipeline Integration
- Registered in `AiServiceProvider` as singleton
- Used by `ContextRetriever` to filter context before prompt building
- Used by `IndexContextCommand` for indexing
- **Collection:** `context_index` (configurable)

### Configuration
```php
const DEFAULT_COLLECTION_NAME = 'context_index';
const DEFAULT_THRESHOLD = 0.65;
const DEFAULT_TOP_K = 10;
```

### Unused Methods
- All methods appear to be used

### Notes
- Graceful fallback to keyword matching when index is unavailable
- Adds entities transitively when relationships match

---

## Service: SemanticIndexer

**File:** `src/Services/SemanticIndexer.php`

### Purpose
Builds and maintains vector store indexes for semantic matching. Creates separate collections for entities, scopes, and templates.

### Dependencies
- `EmbeddingProviderInterface` - For generating embeddings
- `VectorStoreInterface` - For storing vectors
- `entity configs` - From config/entities.php

### Main Functionality
| Method | Purpose |
|--------|---------|
| `rebuildIndexes(?array $templates)` | Rebuilds all indexes |
| `indexEntities(bool $rebuild)` | Indexes entity names/aliases/descriptions |
| `indexScopes(bool $rebuild)` | Indexes scope names/descriptions/concepts |
| `indexTemplates(array $templates, bool $rebuild)` | Indexes query templates |
| `checkCollections()` | Returns status of all collections |
| `setEntityConfigs(array $configs)` | Updates entity configurations |
| `getCollectionNames()` | Static - returns collection name constants |

### Private Methods
| Method | Purpose |
|--------|---------|
| `indexPoints()` | Batch embeds and upserts points |
| `createCollection()` | Creates new vector collection |
| `recreateCollection()` | Deletes and recreates collection |

### Embedding/Vector Operations
- `embedding->embedBatch($texts)` - Batch embeds texts
- `embedding->getDimensions()` - Gets vector size for collection
- `vectorStore->createCollection()` - Creates collections
- `vectorStore->deleteCollection()` - Removes collections
- `vectorStore->collectionExists()` - Checks collection status
- `vectorStore->upsert()` - Stores vectors

### Pipeline Integration
- Registered in `AiServiceProvider` as singleton
- Used by `IndexSemanticCommand` for CLI indexing
- **Collections:**
  - `semantic_entities` - Entity names/aliases
  - `semantic_scopes` - Scope names/concepts
  - `semantic_templates` - Query templates

### Notes
- Uses batched operations (100 points per batch) for efficiency
- Overlaps functionality with `ScopeSemanticMatcher.indexScopes()`
- Both this and `SemanticContextSelector` create scope indexes (potential duplication)

---

## Service: SemanticMatcher

**File:** `src/Services/SemanticMatcher.php`

### Purpose
Provides semantic similarity matching using vector embeddings. Core utility for fuzzy matching of entities, scopes, labels, and templates.

### Dependencies
- `EmbeddingProviderInterface` - For generating embeddings
- `VectorStoreInterface` - For similarity search

### Main Functionality
| Method | Purpose |
|--------|---------|
| `findBestMatch(string $query, array $candidates, float $threshold, ?string $collection)` | Finds best semantic match |
| `matchEntities(string $question, array $entityConfigs, float $threshold)` | Matches entities in question |
| `matchScopes(string $question, array $scopes, float $threshold)` | Matches scopes in question |
| `matchLabel(string $question, array $labels, array $entityMetadata, float $threshold)` | Matches graph labels |
| `computeSimilarities(string $query, array $candidates)` | Computes all similarity scores |
| `clearCache()` | Clears embedding cache |

### Private Methods
| Method | Purpose |
|--------|---------|
| `searchInCollection()` | Vector store search |
| `computeBestMatch()` | In-memory similarity calculation |
| `getEmbedding()` | Single embedding with caching |
| `getEmbeddings()` | Batch embeddings with caching |
| `cosineSimilarity()` | Cosine similarity calculation |
| `normalizeText()` | Text normalization |

### Embedding/Vector Operations
- `embedding->embed($text)` - Single text embedding
- `embedding->embedBatch($texts)` - Batch embedding
- `vectorStore->search()` - Collection search
- `vectorStore->collectionExists()` - Checks collection availability
- **In-memory cosine similarity calculation**

### Pipeline Integration
- Registered in `AiServiceProvider` as singleton
- Used primarily for semantic matching operations
- Has internal embedding cache (MD5 keyed)

### Potentially Unused Methods
- `matchEntities()` - No direct usages found outside tests/docs
- `matchScopes()` - No direct usages found; `ScopeSemanticMatcher` is used instead
- `matchLabel()` - No direct usages found

### Notes
- Fast path for exact matches before computing embeddings
- Internal embedding cache reduces API calls
- Implements cosine similarity manually
- Has overlap with `ScopeSemanticMatcher` functionality

---

## Service: SemanticPromptBuilder

**File:** `src/Services/SemanticPromptBuilder.php`

### Purpose
Builds comprehensive LLM prompts for Cypher query generation using a modular section pipeline. Composes multiple configurable sections in priority order.

### Dependencies
- `HasInternalModules` trait - For module pipeline
- `PatternLibrary` - For query patterns
- Various `PromptSectionInterface` implementations

### Main Functionality
| Method | Purpose |
|--------|---------|
| `buildPrompt(string $question, array $context, bool $allowWrite)` | Main entry - builds complete prompt |
| `setSystemPrompt(string $prompt)` | Sets custom intro text |
| `setProjectContext(array $context)` | Configures project section |
| `addBusinessRule(string $rule)` | Adds business rule |
| `addQueryRule(string $category, string $rule)` | Adds query generation rule |
| `setMaxSimilarQueries(int $max)` | Configures similar queries limit |
| `setTaskInstructions(string $instructions)` | Sets custom final instructions |
| `getPatternLibrary()` | Returns pattern library |

### Static Factory Methods
| Method | Purpose |
|--------|---------|
| `minimal(PatternLibrary)` | Creates minimal prompt builder |
| `simple(PatternLibrary)` | Creates simple prompt builder |

### Embedding/Vector Operations
- **None** - Prompt building only, no vector operations

### Pipeline Integration
- Registered in `AiServiceProvider` as singleton
- Injected into `QueryGenerator` for prompt building
- Uses sections configured in `config('ai.query_generator_sections')`

### Default Sections (Priority Order)
1. `project_context` (10)
2. `generic_context` (15)
3. `schema` (20)
4. `relationships` (30)
5. `example_entities` (40)
6. `similar_queries` (50)
7. `detected_entities` (60)
8. `detected_scopes` (70)
9. `pattern_library` (80)
10. `query_rules` (90)
11. `current_user` (95)
12. `question` (100)
13. `task_instructions` (110)

### Notes
- Highly extensible via `HasInternalModules` trait
- Supports global extensions and per-instance customization
- All methods appear to be actively used

---

## Service: ScopeSemanticMatcher

**File:** `src/Services/ScopeSemanticMatcher.php`

### Purpose
Specialized semantic matcher for scope detection. Uses vector similarity to match user questions against scope examples and concepts.

### Dependencies
- `VectorStoreInterface` - For vector search
- `EmbeddingProviderInterface` - For embeddings

### Main Functionality
| Method | Purpose |
|--------|---------|
| `findMatchingScopes(string $question, array $entityConfigs, float $threshold, int $topK)` | Finds matching scopes |
| `indexScopes(array $entityConfigs)` | Indexes all scope examples/concepts |
| `explainMatch(string $question, array $entityConfigs)` | Debug/explain match results |

### Private Methods
| Method | Purpose |
|--------|---------|
| `createPoint()` | Creates indexing point |
| `collectionExists()` | Checks collection status |
| `getScopeConfig()` | Resolves scope configuration |
| `fallbackStringMatch()` | String-based fallback |
| `extractSignificantWords()` | Stopword filtering |

### Embedding/Vector Operations
- `embeddingProvider->embed($question)` - Embeds user questions
- `embeddingProvider->embedBatch($texts)` - Batch embeds during indexing
- `vectorStore->search()` - Finds similar scope examples
- `vectorStore->ensureCollection()` - Creates collection
- `vectorStore->deleteAll()` - Clears for rebuild
- `vectorStore->upsertBatch()` - Stores indexed vectors
- `vectorStore->getCollectionInfo()` - Checks collection status

### Pipeline Integration
- Registered in `AiServiceProvider` as singleton
- Injected into `ContextRetriever` for scope detection
- Used by `IndexScopesCommand` and `IndexContextCommand`
- **Collection:** `scope_examples` (configurable)

### Configuration
```php
const DEFAULT_COLLECTION_NAME = 'scope_examples';
const DEFAULT_THRESHOLD = 0.7;
const DEFAULT_TOP_K = 5;
```

### Notes
- Has graceful fallback to string matching
- `explainMatch()` method useful for debugging
- Overlaps with `SemanticIndexer.indexScopes()` functionality

---

## Integration Map

```
User Question
     |
     v
+------------------+     +------------------------+
| ContextRetriever | --> | SemanticContextSelector|
+------------------+     +------------------------+
     |                            |
     |                   +--------v---------+
     |                   | ScopeSemanticMatcher |
     |                   +--------------------+
     v
+----------------+
| QueryGenerator |
+----------------+
     |
     v
+----------------------+
| SemanticPromptBuilder|
+----------------------+
     |
     v
LLM API Call

File Processing Pipeline:
+------+   +---------------+   +------------------+   +-------------+
| File | > | TextExtractor | > | SemanticChunker | > | ChunkStore  |
+------+   +---------------+   +------------------+   +-------------+
```

---

## Indexing Commands Summary

| Command | Service | Collection |
|---------|---------|------------|
| `ai:index-semantic` | `SemanticIndexer` | `semantic_entities`, `semantic_scopes`, `semantic_templates` |
| `ai:index-scopes` | `ScopeSemanticMatcher` | `scope_examples` |
| `ai:index-context` | `SemanticContextSelector` + `ScopeSemanticMatcher` | `context_index`, `scope_examples` |

---

## Anomalies and Concerns

### 1. Duplicate Scope Indexing
**Issue:** Both `SemanticIndexer.indexScopes()` and `ScopeSemanticMatcher.indexScopes()` create scope indexes with different collection names.
- `SemanticIndexer` -> `semantic_scopes`
- `ScopeSemanticMatcher` -> `scope_examples`

**Impact:** Confusion about which index to use, wasted embedding API calls if both are populated.

**Recommendation:** Consolidate into single scope indexing mechanism or clearly document when to use each.

### 2. SemanticMatcher vs ScopeSemanticMatcher
**Issue:** `SemanticMatcher.matchScopes()` exists but `ScopeSemanticMatcher` is the one actually used in the pipeline.

**Impact:** `SemanticMatcher.matchScopes()` may be dead code.

**Recommendation:** Remove `matchScopes()` from `SemanticMatcher` or consolidate services.

### 3. Potentially Unused Methods in SemanticMatcher
**Methods with no pipeline usage found:**
- `matchEntities()` - Could be useful but not wired in
- `matchScopes()` - Superseded by `ScopeSemanticMatcher`
- `matchLabel()` - Not used in current pipeline

**Recommendation:** Either integrate these methods or remove them.

### 4. Multiple Context Collections
The system creates multiple overlapping collections:
- `context_index` (SemanticContextSelector)
- `scope_examples` (ScopeSemanticMatcher)
- `semantic_entities` (SemanticIndexer)
- `semantic_scopes` (SemanticIndexer)
- `semantic_templates` (SemanticIndexer)

**Impact:** User confusion about which indexing command to run.

**Recommendation:** Document recommended indexing workflow or consolidate.

---

## Services Not Integrated into Main Pipeline

### SemanticMatcher Methods
The following methods in `SemanticMatcher` have no usage in the main pipeline:
- `matchEntities()` - Appears designed for entity detection but not used
- `matchScopes()` - Superseded by `ScopeSemanticMatcher.findMatchingScopes()`
- `matchLabel()` - Appears designed for label inference but not used

These appear to be utility methods that were either:
1. Prototyped but replaced by more specialized services
2. Designed for future use that hasn't materialized
3. Used in external applications that consume this package

### SemanticIndexer Templates
The `indexTemplates()` method is available but templates are only indexed when provided via the `--templates` flag or via `rebuildIndexes()`. The actual query generation in `QueryGenerator` doesn't appear to use the semantic template matching - it uses pattern regex matching instead.

---

## Summary Statistics

| Metric | Value |
|--------|-------|
| Total Services Reviewed | 6 |
| Services Actively Used | 6 |
| Potentially Unused Methods | 3-4 |
| Vector Collections Created | 5 |
| CLI Commands | 3 |
| Test Coverage | SemanticChunker only |

---

## Recommendations

1. **Consolidate Scope Indexing:** Merge `SemanticIndexer.indexScopes()` and `ScopeSemanticMatcher.indexScopes()` or clearly document distinction.

2. **Clean Up SemanticMatcher:** Remove or integrate unused methods (`matchEntities`, `matchScopes`, `matchLabel`).

3. **Document Indexing Strategy:** Create clear documentation on which indexing commands to run and when.

4. **Add Test Coverage:** Create tests for `SemanticContextSelector`, `SemanticIndexer`, `SemanticMatcher`, and `ScopeSemanticMatcher`.

5. **Consider Service Consolidation:** The semantic services have overlapping responsibilities. Consider consolidating into fewer, more focused services.
