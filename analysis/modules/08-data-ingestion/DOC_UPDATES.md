# Module 08: DATA_INGESTION - Documentation Updates

> **Status:** COMPLETE

## Required Changes

| Doc Path | Change Type | Description |
|----------|-------------|-------------|
| `docs/architecture/data-flow.md` | ADD | Document compensating transaction pattern for dual-store operations |
| `docs/api/data-ingestion.md` | ADD | Create API documentation for DataIngestionService public methods |
| `docs/guides/entity-ingestion.md` | ADD | Guide for implementing Nodeable entities with GraphConfig and VectorConfig |
| `docs/operations/data-consistency.md` | ADD | Document critical error scenarios and manual intervention procedures |
| `docs/security/embedding-safety.md` | ADD | Document sensibleColumns exclusion pattern for PII protection |

## Detailed Documentation Requirements

### 1. Compensating Transaction Pattern
Document the dual-store consistency mechanism:
- Graph-first, Vector-second operation order
- Rollback behavior on vector store failure
- Restoration behavior on removal operations
- Critical error handling when rollback/restoration fails
- Manual intervention procedures for data inconsistency

### 2. Nodeable Entity Implementation Guide
- Required interface methods: `getId()`, `toArray()`, `getGraphConfig()`, `getVectorConfig()`
- GraphConfig: label, properties, relationships
- VectorConfig: collection, embedFields, metadata
- RelationshipConfig: foreignKey, targetLabel, type, properties
- Optional `securityRelatedTeamIds()` method for multi-team entities
- Optional `$sensibleColumns` property for PII exclusion

### 3. Batch Ingestion Best Practices
- Pre-validation of entities before batch processing
- Understanding partial success scenarios
- Using `syncRelationships()` after bulk ingestion
- Performance considerations for large batches

### 4. Error Handling Reference
| Exception | Meaning | Action |
|-----------|---------|--------|
| `InvalidArgumentException` | Entity does not implement Nodeable | Fix entity implementation |
| `DataConsistencyException` | Operation failed but rollback succeeded | Safe to retry |
| `RuntimeException (CRITICAL)` | Rollback failed, data inconsistent | Manual cleanup required |

### 5. Security Documentation
- Team-based access control via `BELONGS_TO_TEAM` relationships
- Vector metadata includes `_team_ids` for filtering
- Owner tracking via `_owner_id` metadata
- Log sanitization via `SensitiveDataSanitizer`
- Sensitive column exclusion from embeddings

### 6. Operations Runbook Addition
Add section for `ai:sync-relationships` artisan command:
- Purpose: Reconcile relationships after bulk ingestion
- When to use: After initial data migration or batch imports
- Expected output: Summary of created/skipped/failed relationships
