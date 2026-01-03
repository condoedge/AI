# Module 10: FILE_PROCESSING - Findings

> **Status:** COMPLETE

## Architecture Overview

### File Extraction Pipeline

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                         FILE PROCESSING PIPELINE                            │
└─────────────────────────────────────────────────────────────────────────────┘

┌──────────────┐     ┌───────────────────────┐     ┌──────────────────────┐
│ File Upload  │────>│ FileExtractorRegistry │────>│ Extractor Selection  │
│ (object)     │     │ (route by extension)  │     │ (PDF/DOCX/MD/TXT)    │
└──────────────┘     └───────────────────────┘     └──────────────────────┘
                                                              │
                                                              v
┌──────────────┐     ┌───────────────────────┐     ┌──────────────────────┐
│ FileChunk[]  │<────│ SemanticChunker       │<────│ Extracted Text       │
│ (with embeds)│     │ (smart splitting)     │     │ (cleaned)            │
└──────────────┘     └───────────────────────┘     └──────────────────────┘
       │
       v
┌──────────────┐     ┌───────────────────────┐     ┌──────────────────────┐
│ Qdrant Store │────>│ FileSearchService     │────>│ Hybrid Search        │
│ (vectors)    │     │ (semantic + metadata) │     │ (Qdrant + Neo4j)     │
└──────────────┘     └───────────────────────┘     └──────────────────────┘
```

### Core Components

1. **FileProcessor** (`src/Services/FileProcessor.php`)
   - Main orchestrator for the complete processing pipeline
   - Steps: validate -> extract -> chunk -> embed -> store
   - Implements `FileProcessorInterface`
   - Dependencies: `FileExtractorRegistry`, `FileChunkerInterface`, `EmbeddingProviderInterface`, `ChunkStoreInterface`

2. **FileExtractorRegistry** (`src/Services/FileExtractorRegistry.php`)
   - Registry pattern for managing multiple file extractors
   - Routes extraction requests to appropriate extractor based on extension
   - Case-insensitive extension matching
   - Provides unified `extract()` and `extractMetadata()` methods

3. **SemanticChunker** (`src/Services/SemanticChunker.php`)
   - Intelligent text chunking with semantic boundary preservation
   - Implements `FileChunkerInterface`
   - Three-tier fallback strategy: paragraphs -> sentences -> characters
   - Configurable overlap between chunks

4. **QdrantChunkStore** (`src/Services/QdrantChunkStore.php`)
   - Qdrant-based vector storage for file chunks
   - Implements `ChunkStoreInterface`
   - Auto-creates collection with cosine similarity
   - Stores chunks with full metadata payload

5. **FileSearchService** (`src/Services/FileSearchService.php`)
   - Unified search across Qdrant (content) and Neo4j (metadata)
   - Supports: content search, metadata search, hybrid search
   - Graph traversal for related files

### File Extractors

| Extractor | Supported Extensions | Library Used | Features |
|-----------|---------------------|--------------|----------|
| `PdfExtractor` | pdf | smalot/pdfparser | Text + metadata (title, author, page count) |
| `DocxExtractor` | docx | phpoffice/phpword | Hierarchical text extraction, document properties |
| `MarkdownExtractor` | md, markdown, mdown | Native PHP | Front matter parsing, structure preservation option |
| `TextExtractor` | txt, text, log | Native PHP | Encoding detection, control char cleanup |

---

## Semantic Chunking Algorithm

### Chunk Size Configuration

| File Type | Chunk Size | Overlap | Rationale |
|-----------|------------|---------|-----------|
| `pdf`     | 1200 chars | 200     | Larger to preserve document structure |
| `txt`     | 1000 chars | 150     | Standard size for plain text |
| `md`      | 1500 chars | 300     | Larger for markdown sections |
| `docx`    | 1200 chars | 200     | Similar to PDF |
| `html`    | 1500 chars | 300     | Larger for nested elements |
| `default` | 1000 chars | 200     | Conservative default |

### Chunking Strategy (Priority Order)

1. **Paragraph-Based** (highest priority)
   - Splits on double newlines (`\n\n`)
   - Groups paragraphs until chunk size reached
   - Falls back if any single paragraph exceeds chunk size

2. **Sentence-Based** (fallback)
   - Splits on sentence boundaries (`.`, `!`, `?` followed by whitespace)
   - Groups sentences until chunk size reached
   - Falls back if any single sentence exceeds chunk size

3. **Character-Based** (last resort)
   - Fixed-position splitting with overlap
   - Ensures forward progress: `overlap < chunk_size`

### Overlap Implementation

```php
// Overlap units are taken from the END of previous chunk
private function getOverlapUnits(array $units, int $overlapSize, string $separator): array
{
    $overlapUnits = [];
    $overlapLength = 0;

    for ($i = count($units) - 1; $i >= 0; $i--) {
        $unit = $units[$i];
        if ($overlapLength + strlen($unit) > $overlapSize) break;
        array_unshift($overlapUnits, $unit);
        $overlapLength += strlen($unit) + strlen($separator);
    }

    return $overlapUnits;
}
```

---

## Vector Storage (QdrantChunkStore)

### Collection Configuration
- **Collection Name:** `file_chunks` (configurable)
- **Vector Size:** 1536 (OpenAI ada-002 default, configurable via `ai.embedding.vector_size`)
- **Distance Metric:** Cosine similarity

### Point Structure
```php
[
    'id' => $chunk->getVectorId(),           // UUID5 deterministic ID
    'vector' => $chunk->embedding,           // float[1536]
    'payload' => [
        'file_id' => $chunk->fileId,
        'file_name' => $chunk->fileName,
        'content' => $chunk->content,
        'chunk_index' => $chunk->chunkIndex,
        'total_chunks' => $chunk->totalChunks,
        'start_position' => $chunk->startPosition,
        'end_position' => $chunk->endPosition,
        'metadata' => $chunk->metadata,
        'created_at' => time(),
    ],
]
```

### Vector ID Generation
Deterministic UUID5 based on file ID and chunk index:
```php
Uuid::uuid5(Uuid::NAMESPACE_URL, "file={$fileId};chunk={$chunkIndex}");
```

---

## Issues Found

| ID | Severity | Description | Evidence | Recommendation |
|----|----------|-------------|----------|----------------|
| FP-001 | LOW | Missing HTML extractor | `CHUNK_SIZES` has 'html' entry but no `HtmlExtractor` exists | Add `HtmlExtractor` or remove HTML from chunk size config |
| FP-002 | INFO | No file size limit validation | `processFile()` doesn't check file size before processing | Consider adding max file size config to prevent OOM |
| FP-003 | LOW | Chunk position tracking may be inaccurate with overlap | `createFileChunks()` increments position by chunk length, ignoring overlap | Position tracking is approximate; document this limitation |
| FP-004 | INFO | Metadata extraction overhead | `extractMetadata()` re-parses the full document | Consider caching or extracting metadata during initial parse |
| FP-005 | LOW | FileSearchService uses undefined `File` type | `getRelatedFiles(File $file)` references undefined class | Import or use `object` type hint like `FileProcessor` |
| FP-006 | INFO | No retry mechanism for embedding API failures | `embedBatch()` called without try/catch in chunking loop | Processing result captures exception but no retry logic |
| FP-007 | LOW | Potential memory issue with large files | Full text held in memory during chunking | Consider streaming approach for very large documents |

---

## Positive Observations

1. **Well-Designed Interfaces**: Clear separation via `FileProcessorInterface`, `FileChunkerInterface`, `FileExtractorInterface`, `ChunkStoreInterface`

2. **Robust Extractor Registry**: Case-insensitive, supports multiple extractors, clean routing

3. **Semantic Chunking**: Three-tier fallback ensures chunking always succeeds while preserving context

4. **Deterministic Vector IDs**: UUID5 allows safe re-processing without duplicate points

5. **Comprehensive Metadata**: Each extractor provides rich metadata (page count, authors, word counts)

6. **Clean Text Processing**: All extractors normalize line endings, remove control characters

7. **Flexible Chunk Configuration**: Per-file-type chunk sizes and overlaps

8. **ProcessingResult DTO**: Clean success/failure reporting with timing metrics

9. **Hybrid Search**: FileSearchService combines vector similarity with graph relationships

10. **Idempotent Processing**: `isProcessed()` check prevents duplicate work

---

## Data Flow Summary

```
1. FILE UPLOAD
   └── FileProcessor.processFile($file)
       ├── validateFileObject() - Check id, name, path
       ├── isProcessed() - Skip if already done
       └── getFilePath() - Handle absolute/relative paths

2. TEXT EXTRACTION
   └── FileExtractorRegistry.extract($filePath)
       ├── getFileExtension() - Normalize to lowercase
       ├── getExtractor() - Route to correct extractor
       └── extractor.extract() - Parse file, clean text

3. CHUNKING
   └── SemanticChunker.chunk($text, $options)
       ├── normalizeLineEndings()
       ├── chunkByParagraphs() - Try first
       ├── chunkBySentences() - Fallback
       └── chunkByCharacters() - Last resort

4. EMBEDDING
   └── EmbeddingProvider.embedBatch($textChunks)
       └── Returns float[][] (one vector per chunk)

5. STORAGE
   └── QdrantChunkStore.storeChunks($fileChunks)
       ├── Create points with vectors + payloads
       └── vectorStore.upsert() - Persist to Qdrant

6. SEARCH
   └── FileSearchService.searchByContent($query)
       ├── chunkStore.searchByContent() - Qdrant similarity
       ├── Group by file, aggregate scores
       └── Load File models, optionally add Neo4j relationships
```

---

## Test Recommendations

1. **Unit Tests**
   - Test each extractor with sample files
   - Test chunking edge cases (empty, single paragraph, very long sentences)
   - Test chunk size boundary conditions

2. **Integration Tests**
   - Full pipeline with real Qdrant instance
   - Search result relevance tests
   - Hybrid search combining Qdrant + Neo4j

3. **Performance Tests**
   - Large file processing (>10MB)
   - Batch processing multiple files
   - Search performance with 100k+ chunks
