# Module 03: CONTEXT_RETRIEVAL - Analysis Plan

> **Module Slug:** context-retrieval
> **Priority:** HIGH (RAG context for query generation)
> **Estimated Files:** 5

---

## 1. Responsibility

### Ideal
- Retrieve relevant context from vector store (semantic search)
- Retrieve relevant context from graph store
- Select and merge context intelligently
- Match scopes semantically

### Files to Analyze
| File | Purpose |
|------|---------|
| `src/Services/ContextRetriever.php` | Main RAG retrieval |
| `src/Services/SemanticContextSelector.php` | Intelligent context selection |
| `src/Services/ScopeSemanticMatcher.php` | Scope matching via embeddings |
| `src/Services/SemanticMatcher.php` | General semantic matching |
| `src/Services/SemanticIndexer.php` | Indexing for semantic search |

---

## 2. Key Questions

- How is context retrieved from Qdrant?
- How is context retrieved from Neo4j?
- How are results merged/prioritized?
- What determines context relevance?
- How are scopes matched?

---

## 3. Dependencies to Trace

- VectorStoreInterface (Qdrant)
- GraphStoreInterface (Neo4j)
- EmbeddingProviderInterface
- Config settings for context

---

## 4. Risk Areas

| Risk | Severity | Check |
|------|----------|-------|
| Context too large | Medium | Check token limits |
| Context irrelevant | High | Check relevance scoring |
| Missing context | High | Check retrieval completeness |
| Scope mismatch | Medium | Verify scope resolution |

---

## 5. Agent Instructions

1. Read each file, document purpose
2. Trace context retrieval flow
3. Verify vector/graph integration
4. Check relevance algorithms
5. Document any issues
