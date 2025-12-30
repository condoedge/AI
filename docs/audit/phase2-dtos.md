# Phase 2: File-by-File Review - DTOs

**Audit Date:** 2025-12-30
**Directory:** `src/DTOs/`
**Total Files:** 2 DTOs

## Review Checklist

| File | Reviewed | Usage Count | Status |
|------|----------|-------------|--------|
| FileChunk.php | Yes | 40+ | Referenced |
| ProcessingResult.php | Yes | 20+ | Referenced |

---

## Detailed Reviews

### FileChunk.php
**Path:** `src/DTOs/FileChunk.php`

**1. What it does:**
Data Transfer Object representing a chunk of a processed file. Encapsulates all information about a single chunk of file content, including its position, content, embedding vector, and metadata. Used in the file processing and vector search pipeline.

**2. Inputs (properties):**
```php
public readonly int $fileId           // The ID of the source file
public readonly string $fileName      // The name of the source file
public readonly string $content       // The text content of this chunk
public readonly array $embedding      // The vector embedding of this chunk
public readonly int $chunkIndex       // The index of this chunk (0-based)
public readonly int $totalChunks      // The total number of chunks for this file
public readonly int $startPosition    // Character position where chunk starts in original file
public readonly int $endPosition      // Character position where chunk ends in original file
public readonly array $metadata = []  // Additional metadata (page numbers, section headers, etc.)
```

**3. Outputs (methods):**
| Method | Return Type | Description | Used Externally |
|--------|-------------|-------------|-----------------|
| `fromArray(array $data)` | `self` | Static factory to create from array | NO |
| `toArray()` | `array` | Convert to array representation | NO (internal use) |
| `getVectorId()` | `string` | Unique identifier for vector store | YES (QdrantChunkStore) |
| `getContentLength()` | `int` | Get content string length | YES (FileProcessor) |
| `isFirstChunk()` | `bool` | Check if this is the first chunk | NO |
| `isLastChunk()` | `bool` | Check if this is the last chunk | NO |

**4. Dependencies:**
```php
namespace Condoedge\Ai\DTOs;
// No external dependencies - pure DTO
```

**5. Reference status:**
- **Used by:**
  - `ChunkStoreInterface` - Type hints for store/retrieve operations
  - `QdrantChunkStore` - Creates and consumes FileChunk objects
  - `FileProcessor` - Creates FileChunk objects during file processing
  - `FileSearchService` - Consumes FileChunk objects from search results
  - `FileContextProvider` - Reads chunk properties for context building
- **Status:** Referenced

**6. Notes/Anomalies:**

**Properties Usage Analysis:**
| Property | Set | Read Externally | Read via toArray() | Status |
|----------|-----|-----------------|-------------------|--------|
| `fileId` | Yes | Yes (QdrantChunkStore, FileSearchService) | Yes | USED |
| `fileName` | Yes | Yes (QdrantChunkStore, FileContextProvider) | Yes | USED |
| `content` | Yes | Yes (QdrantChunkStore, FileContextProvider, FileProcessor) | Yes | USED |
| `embedding` | Yes | Yes (QdrantChunkStore) | Yes | USED |
| `chunkIndex` | Yes | Yes (QdrantChunkStore, FileContextProvider) | Yes | USED |
| `totalChunks` | Yes | Yes (QdrantChunkStore) | Yes | USED |
| `startPosition` | Yes | Yes (QdrantChunkStore) | Yes | USED |
| `endPosition` | Yes | Yes (QdrantChunkStore) | Yes | USED |
| `metadata` | Yes | Yes (QdrantChunkStore) | Yes | USED |

**Unused Methods:**
- `fromArray()` - Defined but never called in codebase (DEAD CODE)
- `isFirstChunk()` - Defined but never called externally (DEAD CODE)
- `isLastChunk()` - Defined but never called externally (DEAD CODE)

**Design Notes:**
- Uses PHP 8.1+ constructor property promotion with `readonly` modifier
- Clean immutable design prevents accidental modification
- All properties are used via direct access and stored in Qdrant vector DB

---

### ProcessingResult.php
**Path:** `src/DTOs/ProcessingResult.php`

**1. What it does:**
Data Transfer Object representing the result of file processing. Encapsulates the outcome of processing a file, including success status, metrics (chunks created, embeddings generated), timing information, and any error information.

**2. Inputs (properties):**
```php
public readonly bool $success                   // Whether the processing was successful
public readonly int $fileId                     // The ID of the processed file
public readonly int $chunksCreated              // Number of chunks created
public readonly int $embeddingsGenerated        // Number of embeddings generated
public readonly float $processingTimeSeconds    // Time taken to process (in seconds)
public readonly ?string $error = null           // Error message if processing failed
public readonly array $metadata = []            // Additional metadata about the processing
```

**3. Outputs (methods):**
| Method | Return Type | Description | Used Externally |
|--------|-------------|-------------|-----------------|
| `success(...)` | `self` | Static factory for successful result | YES (FileProcessor) |
| `failure(...)` | `self` | Static factory for failed result | YES (FileProcessor) |
| `toArray()` | `array` | Convert to array representation | NO (internal use) |
| `failed()` | `bool` | Check if processing failed | YES (IngestEntitiesCommand, FileProcessingPlugin) |
| `getProcessingRate()` | `float` | Calculate chunks per second | NO (only via getSummary) |
| `getSummary()` | `string` | Get human-readable summary | NO |

**4. Dependencies:**
```php
namespace Condoedge\Ai\DTOs;
// No external dependencies - pure DTO
```

**5. Reference status:**
- **Used by:**
  - `FileProcessorInterface` - Defines return type for processFile/reprocessFile
  - `FileProcessor` - Creates ProcessingResult via success/failure factories
  - `IngestEntitiesCommand` - Consumes result to check failed() status
  - `FileProcessingPlugin` - Consumes result to check failed() and error property
- **Status:** Referenced

**6. Notes/Anomalies:**

**Properties Usage Analysis:**
| Property | Set | Read Externally | Read via toArray() | Status |
|----------|-----|-----------------|-------------------|--------|
| `success` | Yes | Via `failed()` method | Yes | USED |
| `fileId` | Yes | Via `getSummary()` internally | Yes | USED |
| `chunksCreated` | Yes | Yes (IngestEntitiesCommand) | Yes | USED |
| `embeddingsGenerated` | Yes | Via `getSummary()` internally | Yes | USED |
| `processingTimeSeconds` | Yes | Via `getSummary()` internally | Yes | USED |
| `error` | Yes | Yes (FileProcessingPlugin) | Yes | USED |
| `metadata` | Yes | NO | Yes | PARTIALLY USED |

**Property Concern:**
- `metadata` - Set by factory methods but **never read externally**. Only accessible via `toArray()` which is also never called externally. This property may be intended for future use or logging purposes.

**Unused Methods:**
- `toArray()` - Defined but never called externally (DEAD CODE)
- `getSummary()` - Defined but never called externally (DEAD CODE)
- `getProcessingRate()` - Only called internally by `getSummary()` (DEAD CODE)

**Design Notes:**
- Uses PHP 8.1+ constructor property promotion with `readonly` modifier
- Static factory pattern (`success()`, `failure()`) provides clear API for creating instances
- Good separation between success and failure cases
- Immutable design prevents accidental modification

---

## Summary

### Overall Assessment

Both DTOs follow good practices:
- Immutable design using `readonly` properties
- Constructor property promotion (PHP 8.1+)
- Clear documentation via docblocks
- Static factory methods for cleaner instantiation

### Dead Code Findings

| DTO | Method/Property | Issue |
|-----|-----------------|-------|
| FileChunk | `fromArray()` | Never called |
| FileChunk | `isFirstChunk()` | Never called externally |
| FileChunk | `isLastChunk()` | Never called externally |
| ProcessingResult | `toArray()` | Never called externally |
| ProcessingResult | `getSummary()` | Never called externally |
| ProcessingResult | `getProcessingRate()` | Only called by unused `getSummary()` |
| ProcessingResult | `metadata` property | Set but never read externally |

### Recommendations

1. **Consider removing unused methods** - `fromArray()`, `isFirstChunk()`, `isLastChunk()`, `getSummary()`, `getProcessingRate()` are never called. Either use them or remove them to reduce maintenance burden.

2. **Evaluate `metadata` property on ProcessingResult** - This property is set but never read. Clarify its purpose or remove it.

3. **Consider adding `toArray()` usage** - If these DTOs need to be serialized for logging or API responses, the `toArray()` methods are ready but unused.

4. **Document intended usage** - If unused methods are for future features or external package users, add `@api` or similar annotations to indicate they are part of the public API.

---

## File Statistics

| Metric | FileChunk | ProcessingResult |
|--------|-----------|------------------|
| Lines of code | 118 | 148 |
| Properties | 9 | 7 |
| Methods | 6 | 6 |
| External dependencies | 0 | 0 |
| Implementations | N/A (concrete class) | N/A (concrete class) |
| Direct usages | 40+ | 20+ |
