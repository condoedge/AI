# File Search

Search and query content within uploaded documents.

---

## Overview

The file search feature enables semantic search across document content:

- **PDF, DOCX, TXT** documents are automatically processed
- Content is chunked and embedded for semantic search
- Search by meaning, not just keywords
- Integration with the main AI query system

---

## Configuration

### Enable File Processing

```env
AI_FILE_PROCESSING_ENABLED=true
AI_FILE_COLLECTION=file_chunks
```

### Full Configuration

```php
// config/ai.php
'file_processing' => [
    'enabled' => true,
    'collection' => 'file_chunks',

    // Supported file types
    'supported_types' => ['pdf', 'docx', 'txt', 'md'],

    // Chunking settings
    'chunk_size' => 1000,        // Characters per chunk
    'chunk_overlap' => 200,      // Overlap between chunks
    'preserve_sentences' => true,
    'preserve_paragraphs' => true,

    // Queue settings
    'queue' => false,
    'queue_threshold_bytes' => 5 * 1024 * 1024, // 5MB

    // Search defaults
    'default_search_limit' => 10,
    'min_search_score' => 0.0,
],
```

---

## Processing Files

### Via Model Relationship

If your entity has file relationships:

```php
class Document extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = ['name', 'path', 'type'];

    public function getEmbedContent(): string
    {
        // Process file content for embedding
        return app(FileProcessingService::class)
            ->extractContent($this->path);
    }
}
```

### Via AI Facade

```php
use Condoedge\Ai\Facades\AI;

// Process a single file
AI::processFile('/path/to/document.pdf', [
    'metadata' => [
        'category' => 'reports',
        'year' => 2024,
    ],
]);

// Process multiple files
AI::processFiles([
    '/path/to/doc1.pdf',
    '/path/to/doc2.docx',
]);
```

### Via Service

```php
use Condoedge\Ai\Services\FileProcessingService;

$service = app(FileProcessingService::class);

// Process file
$service->process('/path/to/document.pdf', [
    'metadata' => ['type' => 'report'],
]);

// Get processing status
$status = $service->getStatus('/path/to/document.pdf');
```

### Via Artisan Command

```bash
# Process all files in directory
php artisan ai:process-files --path=storage/documents

# Process specific file types
php artisan ai:process-files --path=storage/docs --types=pdf,docx

# Reprocess existing files
php artisan ai:process-files --reprocess

# Preview without processing
php artisan ai:process-files --dry-run
```

---

## Searching Files

### Basic Search

```php
use Condoedge\Ai\Facades\AI;

// Search file content
$results = AI::searchFiles("quarterly revenue report");

foreach ($results as $result) {
    echo "File: {$result['file_name']}\n";
    echo "Score: {$result['score']}\n";
    echo "Content: {$result['content']}\n";
    echo "Page: {$result['metadata']['page']}\n";
}
```

### Search with Filters

```php
$results = AI::searchFiles("budget analysis", [
    'limit' => 10,
    'threshold' => 0.7,
    'filters' => [
        'file_type' => 'pdf',
        'year' => 2024,
        'category' => 'finance',
    ],
]);
```

### Search with Context

```php
// Include surrounding context
$results = AI::searchFiles("key findings", [
    'include_context' => true,
    'context_size' => 500, // Characters before/after
]);

foreach ($results as $result) {
    echo "Match: {$result['content']}\n";
    echo "Context: {$result['context']}\n";
}
```

---

## Integration with Chat

### Ask Questions About Documents

```php
use Condoedge\Ai\Facades\AI;

// Question about uploaded documents
$response = AI::chat("What were the key findings in the Q3 report?", [
    'include_files' => true,
]);

// Specify which documents to search
$response = AI::chat("Summarize the budget document", [
    'files' => ['budget-2024.pdf'],
]);
```

### File-Aware Queries

```php
// The system automatically searches relevant files
$response = AI::chat("What did the audit report say about compliance?");

// Response includes file citations
// "According to the 2024 audit report (page 15), compliance..."
```

---

## Chunking Strategies

### Sentence-Based (Default)

Preserves sentence boundaries:

```php
'file_processing' => [
    'preserve_sentences' => true,
    'chunk_size' => 1000,
],
```

### Paragraph-Based

Preserves paragraph boundaries:

```php
'file_processing' => [
    'preserve_paragraphs' => true,
    'chunk_size' => 2000,
],
```

### Fixed Size

Simple character-based chunking:

```php
'file_processing' => [
    'preserve_sentences' => false,
    'preserve_paragraphs' => false,
    'chunk_size' => 1000,
    'chunk_overlap' => 200,
],
```

### Page-Based (PDFs)

Chunk by page:

```php
$service->process($path, [
    'chunk_strategy' => 'page',
]);
```

---

## Metadata

### Automatic Metadata

Extracted automatically:
- `file_name` - Original filename
- `file_path` - Storage path
- `file_type` - Extension
- `file_size` - Size in bytes
- `created_at` - Processing timestamp
- `chunk_index` - Position in document
- `page` - Page number (PDFs)

### Custom Metadata

Add custom metadata for filtering:

```php
AI::processFile($path, [
    'metadata' => [
        'category' => 'legal',
        'department' => 'hr',
        'confidential' => true,
        'year' => 2024,
    ],
]);
```

---

## Queue Processing

For large files, enable queue processing:

```env
AI_FILE_QUEUE=true
AI_FILE_QUEUE_CONNECTION=redis
AI_FILE_QUEUE_THRESHOLD=5242880  # 5MB
```

```php
// Files larger than threshold are queued automatically
AI::processFile('/path/to/large-document.pdf');

// Force queue processing
AI::processFile($path, ['queue' => true]);

// Process synchronously
AI::processFile($path, ['queue' => false]);
```

---

## Supported File Types

### Built-in Extractors

| Type | Extensions | Description |
|------|------------|-------------|
| PDF | `.pdf` | Adobe PDF documents |
| Word | `.docx` | Microsoft Word (modern) |
| Text | `.txt`, `.text` | Plain text files |
| Markdown | `.md`, `.markdown` | Markdown documents |
| Log | `.log` | Log files |

### Adding Custom Extractors

See: [Custom File Extractors](/docs/{{version}}/extending/file-extractors)

---

## Error Handling

```php
use Condoedge\Ai\Exceptions\FileProcessingException;

try {
    AI::processFile('/path/to/document.pdf');
} catch (FileProcessingException $e) {
    // Handle extraction errors
    Log::error("File processing failed: " . $e->getMessage());
}
```

### Fail Silently

```php
// config/ai.php
'file_processing' => [
    'fail_silently' => true,
    'log_errors' => true,
],
```

---

## Removing Files

```php
// Remove file from search index
AI::removeFile('/path/to/document.pdf');

// Remove by ID
AI::removeFileById($fileId);

// Remove all files
AI::clearFiles();
```

---

## Best Practices

### 1. Chunk Size

- **Small chunks (500-800)**: Better precision, more results
- **Large chunks (1500-2000)**: Better context, fewer results
- **Recommended**: 1000 characters with 200 overlap

### 2. Metadata

Add useful metadata for filtering:

```php
AI::processFile($path, [
    'metadata' => [
        'project' => 'project-alpha',
        'document_type' => 'specification',
        'version' => '2.0',
    ],
]);
```

### 3. Search Thresholds

- **0.8+**: Very relevant results only
- **0.6-0.7**: Balanced (recommended)
- **<0.5**: Include loosely related content

### 4. Large Documents

Queue large files to avoid timeouts:

```php
'file_processing' => [
    'queue' => true,
    'queue_threshold_bytes' => 5 * 1024 * 1024,
],
```

---

## Related Documentation

- [Custom File Extractors](/docs/{{version}}/extending/file-extractors) - Custom extractors
- [Data Ingestion](/docs/{{version}}/usage/data-ingestion) - Ingestion overview
- [AI Facade](/docs/{{version}}/usage/simple-usage) - Facade usage
- [Environment Variables](/docs/{{version}}/configuration/environment) - All settings
