# Phase 2 Audit: File Services

**Task:** 22 of 30
**Date:** 2024-12-30
**Auditor:** AI Architectural Review

## Overview

This document reviews all file-related services in the AI package. The file services provide a complete pipeline for:
1. Discovering files (physical and database-backed)
2. Extracting text content from various formats
3. Chunking text semantically
4. Generating embeddings
5. Storing in vector database (Qdrant)
6. Searching file content
7. Access control and security

---

## 1. FileProcessor

**Location:** `src/Services/FileProcessor.php`
**Interface:** `Condoedge\Ai\Contracts\FileProcessorInterface`
**Lines:** 332

### Purpose
Orchestrates the complete file processing pipeline: extraction -> chunking -> embedding -> storage.

### Dependencies
| Dependency | Type | Purpose |
|------------|------|---------|
| `FileExtractorRegistry` | Service | Routes extraction to appropriate extractor |
| `FileChunkerInterface` | Contract | Text chunking (SemanticChunker) |
| `EmbeddingProviderInterface` | Contract | Generate vector embeddings |
| `ChunkStoreInterface` | Contract | Store chunks in Qdrant |

### File Types Supported
Supports any file type registered in `FileExtractorRegistry`. By default:
- PDF (.pdf)
- DOCX (.docx)
- Markdown (.md, .markdown, .mdown)
- Text (.txt, .text, .log)

### Integration with Stores
- **Vector Store (Qdrant):** Stores file chunks via `ChunkStoreInterface`
- **Graph Store (Neo4j):** Not directly used; graph integration is via `FileProcessingPlugin`

### Public Methods

| Method | Used | Where Used |
|--------|------|------------|
| `processFile(object $file, array $options)` | YES | `FileProcessingPlugin`, `ProcessFilesCommand`, `IngestEntitiesCommand` |
| `reprocessFile(object $file)` | YES | `FileProcessingPlugin`, `ProcessFilesCommand` |
| `removeFile(object $file)` | YES | `FileProcessingPlugin` |
| `isProcessed(object $file)` | YES | `FileProcessingPlugin` |
| `getFileStats(object $file)` | LOW | Could be used for diagnostics |
| `supportsFileType(string $ext)` | YES | `FileProcessingPlugin`, `ProcessFilesCommand` |
| `getSupportedFileTypes()` | YES | `ProcessFilesCommand` |

### Private Methods
- `validateFileObject()` - Validates file has required properties
- `getFilePath()` - Resolves absolute file path
- `isAbsolutePath()` - Cross-platform path detection
- `createFileChunks()` - Creates FileChunk DTOs

### Pipeline Position
**Entry Point** for file content processing.

```
File Model -> FileProcessingPlugin -> FileProcessor -> [Extractor -> Chunker -> Embedder -> ChunkStore]
```

### Notes/Anomalies
1. **File object validation**: Requires `id`, `name`, `path` properties - works with stdClass or Eloquent models
2. **Cross-platform paths**: Handles both Windows (C:\) and Unix (/) paths correctly
3. **No async processing**: `async` option mentioned in interface but not implemented in service

---

## 2. FileSearchService

**Location:** `src/Services/FileSearchService.php`
**Interface:** None (concrete class)
**Lines:** 367

### Purpose
Provides unified search across both vector (Qdrant) and graph (Neo4j) stores. Supports content-based, metadata-based, and hybrid searches.

### Dependencies
| Dependency | Type | Purpose |
|------------|------|---------|
| `ChunkStoreInterface` | Contract | Content/semantic search |
| `GraphStoreInterface` | Contract | Metadata and relationship queries |
| `Condoedge\Utils\Models\Files\File` | Model | File model class (hardcoded) |

### Integration with Stores
- **Vector Store (Qdrant):** Semantic search via `ChunkStoreInterface`
- **Graph Store (Neo4j):** Cypher queries for metadata/relationships

### Public Methods

| Method | Used | Where Used |
|--------|------|------------|
| `searchByContent(string $query, array $options)` | YES | `FileContextProvider` |
| `searchByMetadata(array $criteria, int $limit)` | LOW | No direct callers found |
| `hybridSearch(string $query, array $filters, array $options)` | LOW | No direct callers found |
| `getRelatedFiles(File $file, ?string $type, int $limit)` | LOW | No direct callers found |
| `getFilesByUser(int $userId, int $limit)` | LOW | No direct callers found |
| `getFilesByTeam(int $teamId, int $limit)` | LOW | No direct callers found |

### Protected Methods
- `getFileRelationships(File $file)` - Get Neo4j relationships for a file
- `buildMetadataQuery(array $criteria, int $limit)` - Build Cypher query
- `filterByMetadata(array $results, array $filters)` - Post-filter results

### Pipeline Position
**Consumer** of stored chunks; provides search capabilities.

```
User Query -> FileContextProvider -> FileSearchService -> [ChunkStore (Qdrant), GraphStore (Neo4j)]
```

### Notes/Anomalies
1. **Hardcoded File model**: Uses `Condoedge\Utils\Models\Files\File` directly - should be configurable
2. **Many unused methods**: `searchByMetadata`, `hybridSearch`, `getRelatedFiles`, `getFilesByUser`, `getFilesByTeam` appear unused
3. **No interface**: Unlike other services, this has no contract interface
4. **Facade exists**: `FileSearch` facade available but not widely used

---

## 3. FileExtractorRegistry

**Location:** `src/Services/FileExtractorRegistry.php`
**Interface:** None (concrete class)
**Lines:** 160

### Purpose
Registry pattern for managing file extractors. Routes extraction requests to appropriate extractor based on file extension.

### Dependencies
| Dependency | Type | Purpose |
|------------|------|---------|
| `FileExtractorInterface` | Contract | Individual extractors implement this |

### Registered Extractors (in AiServiceProvider)
1. `TextExtractor` - .txt, .text, .log
2. `MarkdownExtractor` - .md, .markdown, .mdown
3. `PdfExtractor` - .pdf
4. `DocxExtractor` - .docx

### Public Methods

| Method | Used | Where Used |
|--------|------|------------|
| `register(FileExtractorInterface $extractor)` | YES | `AiServiceProvider` |
| `registerMany(array $extractors)` | YES | `AiServiceProvider` |
| `getExtractor(string $extension)` | NO | Internal use only |
| `supports(string $extension)` | YES | `FileProcessor` |
| `getSupportedExtensions()` | YES | `FileProcessor` |
| `extract(string $filePath)` | YES | `FileProcessor` |
| `extractMetadata(string $filePath)` | NO | Not used anywhere |
| `getStats()` | NO | Could be used for diagnostics |

### Pipeline Position
**Step 1** in FileProcessor pipeline - text extraction.

```
FileProcessor -> FileExtractorRegistry -> [TextExtractor|PdfExtractor|DocxExtractor|MarkdownExtractor]
```

### Notes/Anomalies
1. **Case insensitive**: Extensions are normalized to lowercase
2. **Unused methods**: `extractMetadata()` and `getStats()` are not used
3. **No interface**: Concrete class only

---

## 4. DataIngestionService

**Location:** `src/Services/DataIngestionService.php`
**Interface:** `Condoedge\Ai\Contracts\DataIngestionServiceInterface`
**Lines:** 1258

### Purpose
Handles ingestion of Nodeable entities into both Neo4j (graph) and Qdrant (vector) stores with compensating transactions for consistency.

### Dependencies
| Dependency | Type | Purpose |
|------------|------|---------|
| `VectorStoreInterface` | Contract | Qdrant operations |
| `GraphStoreInterface` | Contract | Neo4j operations |
| `EmbeddingProviderInterface` | Contract | Generate embeddings |
| `Nodeable` | Domain Contract | Entity interface |
| `SensitiveDataSanitizer` | Service | Sanitize logs |
| `Cache` | Facade | Collection existence caching |
| `Log` | Facade | Logging |

### Integration with Stores
- **Vector Store (Qdrant):** Entity vectors with metadata
- **Graph Store (Neo4j):** Nodes with relationships

### Public Methods

| Method | Used | Where Used |
|--------|------|------------|
| `ingest(Nodeable $entity)` | YES | `AiManager`, `FileProcessingPlugin`, `IngestEntitiesCommand` |
| `ingestBatch(array $entities)` | YES | `IngestEntitiesCommand` |
| `remove(Nodeable $entity)` | YES | `FileProcessingPlugin` |
| `sync(Nodeable $entity)` | YES | `FileProcessingPlugin` |
| `syncRelationships(array $entities)` | YES | `SyncRelationshipsCommand` |

### Private Methods (Key ones)
- `validateEntity()` - Ensure entity implements Nodeable
- `ingestToGraph()` - Create Neo4j node
- `updateGraph()` - Update Neo4j node
- `createRelationships()` - Create Neo4j relationships
- `ingestToVector()` - Create Qdrant vector
- `batchIngestToGraph()` - Batch graph operations
- `batchIngestToVector()` - Batch vector operations
- `resolveTeamIds()` - Security: resolve team access
- `getSensibleColumns()` - Filter sensitive data from embeddings
- `ingestTeamRelationships()` - Create BELONGS_TO_TEAM relationships
- `ensureCollectionExists()` - Lazy collection creation with caching

### Pipeline Position
**Entry point** for Nodeable entity ingestion (not file content).

```
Model Event -> FileProcessingPlugin -> AI Facade -> DataIngestionService -> [Neo4j, Qdrant]
```

### Notes/Anomalies
1. **Compensating transactions**: Rollback graph on vector failure, restore graph on failed deletion
2. **Critical error handling**: Logs CRITICAL for rollback failures requiring manual intervention
3. **Team security**: Automatically creates BELONGS_TO_TEAM relationships
4. **Sensitive column filtering**: Excludes `sensibleColumns` from embeddings
5. **Collection caching**: Uses 5-minute cache to avoid repeated existence checks
6. **Not for file chunks**: This is for entities, not file chunks (QdrantChunkStore handles chunks)

---

## 5. PhysicalFileIndexer

**Location:** `src/Services/Files/PhysicalFileIndexer.php`
**Interface:** None (concrete class)
**Lines:** 242

### Purpose
Discovers and indexes physical documentation files from the filesystem using glob patterns.

### Dependencies
| Dependency | Type | Purpose |
|------------|------|---------|
| `FileAccessResolver` | Service | Physical file ID prefix constant |
| `Symfony\Component\Finder\Finder` | Library | Recursive file discovery |

### Configuration
- `ai.file_context.physical_paths` - Glob patterns
- `ai.file_context.base_path` - Root directory
- `ai.file_context.supported_extensions` - Allowed extensions (md, mdx, txt, rst)

### Public Methods

| Method | Used | Where Used |
|--------|------|------------|
| `discoverFiles(?array $patterns)` | YES | `createFileObjects()` |
| `generateFileId(string $path)` | YES | `createFileObject()` |
| `createFileObject(string $path)` | YES | `createFileObjects()` |
| `createFileObjects(?array $patterns)` | YES | `IngestEntitiesCommand` |

### Private Methods
- `getBasePath()` - Get configured base path
- `findFilesForPattern()` - Route to recursive or simple pattern
- `findFilesRecursive()` - Handle `**` patterns with Finder
- `findFilesSimple()` - Handle simple glob patterns

### Pipeline Position
**Discovery** for physical documentation files.

```
IngestEntitiesCommand --docs -> PhysicalFileIndexer -> FileProcessor
```

### Notes/Anomalies
1. **Physical prefix**: Uses `physical:` prefix for file IDs (bypasses security)
2. **Cross-platform**: Normalizes path separators to forward slashes
3. **Recursive patterns**: Supports `**` for recursive directory matching

---

## 6. Supporting Components

### 6.1 QdrantChunkStore

**Location:** `src/Services/QdrantChunkStore.php`
**Interface:** `Condoedge\Ai\Contracts\ChunkStoreInterface`
**Lines:** 296

**Purpose:** Stores file chunks with embeddings in Qdrant.

**Key Methods:**
- `storeChunk/storeChunks` - Store chunks
- `searchByContent` - Semantic search
- `getFileChunks` - Get all chunks for a file
- `deleteFileChunks` - Remove file chunks
- `hasFileChunks` - Check if processed

**Notes:**
- Hardcoded 1536 dimension vectors (OpenAI ada-002)
- Auto-creates collection on first use

### 6.2 SemanticChunker

**Location:** `src/Services/SemanticChunker.php`
**Interface:** `Condoedge\Ai\Contracts\FileChunkerInterface`
**Lines:** 258

**Purpose:** Intelligently splits text preserving semantic boundaries.

**Chunking Strategies (in order):**
1. Paragraph-based (double newlines)
2. Sentence-based (. ! ? boundaries)
3. Character-based (fallback)

**File-specific chunk sizes:**
| Type | Chunk Size | Overlap |
|------|------------|---------|
| PDF | 1200 | 200 |
| TXT | 1000 | 150 |
| MD | 1500 | 300 |
| DOCX | 1200 | 200 |
| HTML | 1500 | 300 |

### 6.3 File Extractors

#### TextExtractor
**Location:** `src/Services/Extractors/TextExtractor.php`
**Extensions:** .txt, .text, .log
**Features:** Line ending normalization, control character removal

#### MarkdownExtractor
**Location:** `src/Services/Extractors/MarkdownExtractor.php`
**Extensions:** .md, .markdown, .mdown
**Features:**
- Optional structure preservation
- YAML front matter extraction
- Code block detection
- Link/image detection

#### PdfExtractor
**Location:** `src/Services/Extractors/PdfExtractor.php`
**Extensions:** .pdf
**Library:** `smalot/pdfparser`
**Features:**
- Page count extraction
- Metadata extraction (title, author, etc.)
- Text cleaning (soft hyphens, line breaks)

#### DocxExtractor
**Location:** `src/Services/Extractors/DocxExtractor.php`
**Extensions:** .docx
**Library:** `phpoffice/phpword`
**Features:**
- Section-aware extraction
- TextRun and Text element handling
- Document property extraction

### 6.4 FileAccessResolver

**Location:** `src/Services/Context/FileAccessResolver.php`
**Interface:** `Condoedge\Ai\Contracts\FileAccessResolverInterface`
**Lines:** 201

**Purpose:** Resolves file access permissions for security.

**Key Features:**
- Physical files (prefix `physical:`) always accessible
- Database files subject to configured access rules
- Supports closure-based and scope-based resolvers

### 6.5 FileContextProvider

**Location:** `src/Services/Context/FileContextProvider.php`
**Lines:** 227

**Purpose:** Provides file context for AI prompts with access control.

**Key Methods:**
- `searchRelevantFiles()` - Search with access filtering
- `getFileContext()` - Get context for prompt building

---

## 7. File Processing Pipeline

```
                                    Physical Files Path
                                    ===================
IngestEntitiesCommand --docs -----> PhysicalFileIndexer
                                           |
                                           v
                                    createFileObjects()
                                           |
                                           v
                        +------------------+------------------+
                        |                                     |
                        v                                     v
                  FileProcessor                        FileProcessor
                        |                                     |
                        v                                     v
              FileExtractorRegistry                  FileExtractorRegistry
                        |                                     |
                        v                                     v
              [Extractor by type]                   [Extractor by type]
                        |                                     |
                        v                                     v
                SemanticChunker                       SemanticChunker
                        |                                     |
                        v                                     v
              EmbeddingProvider                      EmbeddingProvider
                        |                                     |
                        v                                     v
              QdrantChunkStore                       QdrantChunkStore
              (file_chunks)                          (documentation_chunks)


                                    Database Files Path
                                    ===================
File Model Created -------> FileProcessingPlugin
                                   |
                     +-------------+-------------+
                     |                           |
                     v                           v
              AI::ingest()                 FileProcessor
              (Neo4j node)                 (Qdrant chunks)
                     |                           |
                     v                           v
           DataIngestionService          QdrantChunkStore
                     |
                     v
              Neo4j + Qdrant
              (entity vector)


                                    Search Path
                                    ===========
User Query --------> FileContextProvider
                            |
                            v
                    FileSearchService
                            |
              +-------------+-------------+
              |                           |
              v                           v
       QdrantChunkStore            Neo4j (optional)
       (semantic search)           (metadata/relationships)
              |                           |
              +-------------+-------------+
                            |
                            v
                    FileAccessResolver
                    (filter by access)
                            |
                            v
                    Filtered Results
```

---

## 8. Usage Summary

### Entry Points

| Command/Event | Service | Store |
|--------------|---------|-------|
| `php artisan ai:ingest` | DataIngestionService | Neo4j + Qdrant |
| `php artisan ai:ingest --docs` | FileProcessor | Qdrant |
| `php artisan ai:process-files` | FileProcessor | Qdrant |
| File::created event | FileProcessingPlugin | Neo4j + Qdrant |
| File::updated event | FileProcessingPlugin | Neo4j + Qdrant |
| File::deleting event | FileProcessingPlugin | Neo4j + Qdrant |

### Service Provider Registrations

```php
// In AiServiceProvider
FileExtractorRegistry::class     -> Singleton with extractors
FileChunkerInterface::class      -> SemanticChunker
ChunkStoreInterface::class       -> QdrantChunkStore
FileProcessorInterface::class    -> FileProcessor
'file-search'                    -> FileSearchService
FileAccessResolverInterface::class -> FileAccessResolver
PhysicalFileIndexer::class       -> Singleton
FileContextProvider::class       -> Singleton
```

---

## 9. Issues and Recommendations

### 9.1 Unused Code

| Service | Unused Methods |
|---------|----------------|
| FileSearchService | `searchByMetadata()`, `hybridSearch()`, `getRelatedFiles()`, `getFilesByUser()`, `getFilesByTeam()` |
| FileExtractorRegistry | `extractMetadata()`, `getStats()` |
| FileProcessor | `getFileStats()` (low usage) |

**Recommendation:** Consider marking these as `@internal` or documenting planned use cases.

### 9.2 Missing Interfaces

| Class | Recommendation |
|-------|----------------|
| FileSearchService | Create `FileSearchServiceInterface` |
| FileExtractorRegistry | Create `FileExtractorRegistryInterface` |
| PhysicalFileIndexer | Create `PhysicalFileIndexerInterface` |

### 9.3 Hardcoded Dependencies

| Location | Issue | Recommendation |
|----------|-------|----------------|
| FileSearchService | Hardcoded `File` model class | Make configurable via `ai.file_context.file_model` |
| QdrantChunkStore | Hardcoded 1536 dimensions | Use `config('ai.embeddings.dimensions')` |

### 9.4 Missing Features

| Feature | Status |
|---------|--------|
| Async file processing | Option documented but not implemented |
| Queue job for large files | Stub implementation, logs warning |
| XLS/XLSX support | No extractor |
| HTML support | No extractor (chunk sizes defined) |
| CSV support | No extractor |

### 9.5 Architecture Observations

1. **Dual storage pattern**: Files stored in both Neo4j (metadata/relationships) and Qdrant (content chunks)
2. **Physical vs Database files**: Clean separation with prefix-based identification
3. **Security by default**: Access resolver enforces security unless explicitly disabled
4. **Graceful degradation**: Errors logged but don't break pipeline when `fail_silently=true`

---

## 10. File Count Summary

| Category | Count |
|----------|-------|
| Main Services | 5 |
| Extractors | 4 |
| Supporting Services | 3 |
| Contracts | 5 |
| Total Files | 17 |

---

## 11. Test Coverage Recommendation

Priority test cases:
1. FileProcessor pipeline (extraction -> chunking -> embedding -> storage)
2. PhysicalFileIndexer pattern matching
3. FileAccessResolver security enforcement
4. Extractor error handling (corrupt files, large files)
5. SemanticChunker edge cases (empty content, single sentences)
6. DataIngestionService compensating transactions
