# Module 10: FILE_PROCESSING - Documentation Updates

> **Status:** COMPLETE

## Recommended Documentation Additions

### 1. File Processing Pipeline Documentation

**Location:** `docs/file-processing.md` (new file)

**Content to document:**
- Complete pipeline flow from upload to searchable chunks
- Supported file formats and their extractors
- Chunk size configuration per file type
- How semantic chunking preserves context

### 2. Extractor Development Guide

**Location:** `docs/extending-extractors.md` (new file)

**Content to document:**
- How to implement `FileExtractorInterface`
- Registering new extractors with `FileExtractorRegistry`
- Best practices for text cleaning and metadata extraction

### 3. Chunking Configuration

**Location:** `config/ai.php` (additions needed)

**Missing configuration options:**
```php
'chunking' => [
    'default_chunk_size' => 1000,
    'default_overlap' => 200,
    'max_file_size' => 50 * 1024 * 1024, // 50MB
    'file_types' => [
        'pdf' => ['chunk_size' => 1200, 'overlap' => 200],
        'txt' => ['chunk_size' => 1000, 'overlap' => 150],
        'md' => ['chunk_size' => 1500, 'overlap' => 300],
        'docx' => ['chunk_size' => 1200, 'overlap' => 200],
    ],
],
```

### 4. API Reference Updates

**FileProcessor Methods:**
| Method | Description |
|--------|-------------|
| `processFile($file, $options)` | Process file through complete pipeline |
| `reprocessFile($file)` | Delete existing chunks and reprocess |
| `removeFile($file)` | Delete all chunks for a file |
| `isProcessed($file)` | Check if file has been processed |
| `getFileStats($file)` | Get chunk count and size statistics |
| `supportsFileType($ext)` | Check if extension is supported |

**SemanticChunker Options:**
| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `chunk_size` | int | 1000 | Maximum characters per chunk |
| `overlap` | int | 200 | Characters overlapping between chunks |
| `preserve_sentences` | bool | true | Try to break at sentence boundaries |
| `preserve_paragraphs` | bool | true | Try to break at paragraph boundaries |

### 5. Vector Storage Schema Documentation

**Location:** `docs/vector-storage.md` (new section)

**Qdrant Collection Schema:**
```
Collection: file_chunks
├── Vector: float[1536] (cosine distance)
└── Payload:
    ├── file_id: int
    ├── file_name: string
    ├── content: string
    ├── chunk_index: int
    ├── total_chunks: int
    ├── start_position: int
    ├── end_position: int
    ├── metadata: object
    └── created_at: timestamp
```

### 6. Inline Code Documentation Improvements

**FileProcessor.php (line 301-331):**
Add docblock explaining position tracking limitation:
```php
/**
 * Create FileChunk objects from text chunks and embeddings
 *
 * NOTE: Position tracking is approximate when using paragraph/sentence
 * chunking with overlap. The startPosition/endPosition values track
 * cumulative character count, not actual positions in original text.
 * For precise position mapping, use chunk_index instead.
 */
```

**SemanticChunker.php (line 203-206):**
Add comment explaining the fallback condition:
```php
// If a single unit (paragraph/sentence) exceeds chunk size,
// we cannot use this chunking method because we'd have to
// split the unit mid-content. Return empty to trigger fallback.
```

### 7. Error Handling Documentation

**ProcessingResult States:**
| State | Description | Recovery Action |
|-------|-------------|-----------------|
| Success | File processed completely | None needed |
| `File already processed` | Duplicate processing attempt | Use `force: true` option |
| `File not found` | File path invalid | Verify file exists at path |
| `Unsupported file type` | No extractor registered | Add extractor or reject file |
| `No text content extracted` | Empty or binary file | Check file content |
| `Failed to chunk text` | Chunking algorithm failed | Check file encoding |
| `Failed to store chunks` | Qdrant connection issue | Check vector store health |

### 8. Performance Considerations

**Add to documentation:**
- Maximum recommended file size: 50MB
- Expected processing time: ~1 second per MB for PDF
- Memory usage: approximately 3x file size during processing
- Batch embedding: chunks are embedded in batch for efficiency
- Qdrant upsert: use batch sizes of 100+ points for best performance

### 9. Security Considerations

**Add to documentation:**
- File path validation prevents directory traversal
- Control characters stripped from extracted text
- No execution of embedded content (macros, scripts)
- PDF/DOCX parsing does not execute any embedded code

---

## Implementation Notes

1. The chunk size constants in `SemanticChunker` should be moved to config for runtime flexibility
2. Consider adding `HtmlExtractor` to match the chunk size configuration
3. Add file size validation before processing to prevent memory exhaustion
4. Document the deterministic vector ID scheme for debugging purposes
