# File Processing System - Architecture Design

## Overview

A file processing system that chunks documents, generates embeddings, stores them in Qdrant, and enables semantic search over file contents.

## Goals

1. **Chunk files** into semantic pieces
2. **Generate embeddings** for each chunk
3. **Store in Qdrant** with metadata
4. **Enable semantic search** - "find files about X"
5. **Retrieve context** - "tell me more about Y in the files"
6. **Integrate with condoedge/utils** File model via plugins

## Architecture Overview

```
┌──────────────────────────────────────────────────────────┐
│                    User Uploads File                      │
└──────────────────────────────────────────────────────────┘
                            ↓
┌──────────────────────────────────────────────────────────┐
│  File Model (condoedge/utils)                            │
│  - Triggers processing via model plugin                  │
└──────────────────────────────────────────────────────────┘
                            ↓
┌──────────────────────────────────────────────────────────┐
│  FileProcessorInterface                                   │
│  - processFile(File $file): ProcessingResult             │
└──────────────────────────────────────────────────────────┘
                            ↓
┌──────────────────────────────────────────────────────────┐
│  FileChunkerInterface                                     │
│  - chunk(string $content, array $options): array         │
└──────────────────────────────────────────────────────────┘
                            ↓
┌──────────────────────────────────────────────────────────┐
│  EmbeddingGeneratorInterface                              │
│  - generateBatch(array $texts): array                    │
└──────────────────────────────────────────────────────────┘
                            ↓
┌──────────────────────────────────────────────────────────┐
│  ChunkStoreInterface                                      │
│  - store(FileChunk $chunk): void                         │
│  - searchByContent(string $query): array                 │
│  - getFileChunks(int $fileId): array                     │
└──────────────────────────────────────────────────────────┘
                            ↓
┌──────────────────────────────────────────────────────────┐
│  Qdrant Vector Store                                      │
│  - Stores embeddings + metadata                          │
│  - Enables semantic similarity search                    │
└──────────────────────────────────────────────────────────┘
```

## Core Components

### 1. FileChunk (Data Transfer Object)

Represents a single chunk of a file with its metadata.

```php
namespace AiSystem\Domain\DTOs;

class FileChunk
{
    public function __construct(
        public readonly int $fileId,
        public readonly string $fileName,
        public readonly string $content,
        public readonly array $embedding,
        public readonly int $chunkIndex,
        public readonly int $totalChunks,
        public readonly int $startPosition,
        public readonly int $endPosition,
        public readonly array $metadata = []
    ) {}

    public function toArray(): array
    {
        return [
            'file_id' => $this->fileId,
            'file_name' => $this->fileName,
            'content' => $this->content,
            'chunk_index' => $this->chunkIndex,
            'total_chunks' => $this->totalChunks,
            'start_position' => $this->startPosition,
            'end_position' => $this->endPosition,
            'metadata' => $this->metadata,
        ];
    }
}
```

### 2. FileChunkerInterface

Responsible for splitting file content into semantic chunks.

```php
namespace AiSystem\Contracts;

interface FileChunkerInterface
{
    /**
     * Chunk file content into smaller pieces
     *
     * @param string $content File content
     * @param array $options Chunking options:
     *                       - max_chunk_size: Maximum characters per chunk (default: 1000)
     *                       - overlap: Characters to overlap between chunks (default: 200)
     *                       - separator: Preferred split points (default: ["\n\n", "\n", ". "])
     * @return array Array of text chunks
     */
    public function chunk(string $content, array $options = []): array;
}
```

### 3. FileProcessorInterface

Orchestrates the entire file processing pipeline.

```php
namespace AiSystem\Contracts;

use Kondoedge\Utils\Models\File;
use AiSystem\Domain\DTOs\ProcessingResult;

interface FileProcessorInterface
{
    /**
     * Process a file: chunk, embed, and store
     *
     * @param File $file File model from condoedge/utils
     * @param array $options Processing options
     * @return ProcessingResult Result with statistics
     */
    public function processFile(File $file, array $options = []): ProcessingResult;

    /**
     * Reprocess a file (e.g., after content update)
     *
     * @param File $file File model
     * @return ProcessingResult Result with statistics
     */
    public function reprocessFile(File $file): ProcessingResult;

    /**
     * Remove file from vector store
     *
     * @param File $file File model
     * @return bool Success status
     */
    public function removeFile(File $file): bool;
}
```

### 4. ChunkStoreInterface

Manages storage and retrieval of file chunks.

```php
namespace AiSystem\Contracts;

use AiSystem\Domain\DTOs\FileChunk;

interface ChunkStoreInterface
{
    /**
     * Store a file chunk with its embedding
     *
     * @param FileChunk $chunk Chunk to store
     * @return bool Success status
     */
    public function storeChunk(FileChunk $chunk): bool;

    /**
     * Store multiple chunks at once
     *
     * @param array $chunks Array of FileChunk objects
     * @return bool Success status
     */
    public function storeChunks(array $chunks): bool;

    /**
     * Search for chunks by semantic content
     *
     * @param string $query Search query
     * @param int $limit Maximum results
     * @param array $filters Optional filters (file_id, file_type, etc.)
     * @return array Array of FileChunk objects with similarity scores
     */
    public function searchByContent(string $query, int $limit = 10, array $filters = []): array;

    /**
     * Get all chunks for a specific file
     *
     * @param int $fileId File ID
     * @return array Array of FileChunk objects
     */
    public function getFileChunks(int $fileId): array;

    /**
     * Delete all chunks for a file
     *
     * @param int $fileId File ID
     * @return bool Success status
     */
    public function deleteFileChunks(int $fileId): bool;

    /**
     * Get file statistics
     *
     * @param int $fileId File ID
     * @return array Statistics (chunk_count, total_size, etc.)
     */
    public function getFileStats(int $fileId): array;
}
```

### 5. ProcessingResult (DTO)

Results from file processing.

```php
namespace AiSystem\Domain\DTOs;

class ProcessingResult
{
    public function __construct(
        public readonly bool $success,
        public readonly int $fileId,
        public readonly int $chunksCreated,
        public readonly int $embeddingsGenerated,
        public readonly float $processingTimeSeconds,
        public readonly ?string $error = null,
        public readonly array $metadata = []
    ) {}

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'file_id' => $this->fileId,
            'chunks_created' => $this->chunksCreated,
            'embeddings_generated' => $this->embeddingsGenerated,
            'processing_time_seconds' => $this->processingTimeSeconds,
            'error' => $this->error,
            'metadata' => $this->metadata,
        ];
    }
}
```

## Implementation Strategy

### Phase 1: Core Services

1. **SemanticChunker** (implements FileChunkerInterface)
   - Smart chunking that respects paragraphs, sentences
   - Configurable chunk size and overlap
   - Metadata extraction (headings, sections)

2. **FileProcessor** (implements FileProcessorInterface)
   - Orchestrates chunking → embedding → storage
   - Handles different file types (PDF, DOCX, TXT, MD)
   - Queue support for async processing

3. **QdrantChunkStore** (implements ChunkStoreInterface)
   - Stores chunks in Qdrant vector store
   - Implements semantic search
   - Manages chunk metadata

### Phase 2: File Model Integration

**Using Model Plugins Pattern** (condoedge/utils):

```php
namespace AiSystem\Plugins;

use Kondoedge\Utils\Models\File;
use Kondoedge\Utils\Plugins\ModelPlugin;
use AiSystem\Contracts\FileProcessorInterface;

class FileProcessingPlugin extends ModelPlugin
{
    protected FileProcessorInterface $processor;

    public function boot(): void
    {
        $this->processor = app(FileProcessorInterface::class);

        // Automatically process file after creation
        File::created(function (File $file) {
            if ($this->shouldProcessFile($file)) {
                $this->processor->processFile($file);
            }
        });

        // Reprocess on update
        File::updated(function (File $file) {
            if ($this->shouldReprocessFile($file)) {
                $this->processor->reprocessFile($file);
            }
        });

        // Remove from vector store on deletion
        File::deleted(function (File $file) {
            $this->processor->removeFile($file);
        });
    }

    protected function shouldProcessFile(File $file): bool
    {
        // Only process text-based files
        $processableTypes = ['pdf', 'docx', 'txt', 'md', 'html'];
        return in_array($file->extension, $processableTypes);
    }

    protected function shouldReprocessFile(File $file): bool
    {
        // Only reprocess if content changed
        return $file->isDirty('path') || $file->isDirty('content');
    }
}
```

### Phase 3: Search & Retrieval

**Facade for easy access:**

```php
namespace AiSystem\Facades;

use Illuminate\Support\Facades\Facade;

class FileSearch extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'ai.file-search';
    }
}

// Usage:
$results = FileSearch::query("How to configure Laravel queues")
    ->limit(5)
    ->filter(['file_type' => 'pdf'])
    ->get();

foreach ($results as $result) {
    echo "File: {$result->file_name}\n";
    echo "Chunk: {$result->content}\n";
    echo "Similarity: {$result->score}\n";
}
```

## File Type Support

### Extractors (Strategy Pattern)

```php
namespace AiSystem\Services\Extractors;

interface FileExtractorInterface
{
    public function supports(string $extension): bool;
    public function extract(string $filePath): string;
}

// Implementations:
class PdfExtractor implements FileExtractorInterface { }
class DocxExtractor implements FileExtractorInterface { }
class TextExtractor implements FileExtractorInterface { }
class MarkdownExtractor implements FileExtractorInterface { }
```

### File Type Registry

```php
namespace AiSystem\Services;

class FileExtractorRegistry
{
    private array $extractors = [];

    public function register(FileExtractorInterface $extractor): void
    {
        $this->extractors[] = $extractor;
    }

    public function extract(File $file): string
    {
        foreach ($this->extractors as $extractor) {
            if ($extractor->supports($file->extension)) {
                return $extractor->extract($file->getRealPath());
            }
        }

        throw new UnsupportedFileTypeException($file->extension);
    }
}
```

## Configuration

**config/ai.php** additions:

```php
'file_processing' => [
    // Enable automatic file processing
    'auto_process' => env('AI_FILE_AUTO_PROCESS', true),

    // Queue processing (recommended for large files)
    'queue' => env('AI_FILE_QUEUE', true),
    'queue_connection' => env('AI_FILE_QUEUE_CONNECTION', 'redis'),
    'queue_name' => env('AI_FILE_QUEUE_NAME', 'file-processing'),

    // Chunking settings
    'chunking' => [
        'max_chunk_size' => env('AI_FILE_CHUNK_SIZE', 1000),      // characters
        'overlap' => env('AI_FILE_CHUNK_OVERLAP', 200),           // characters
        'separators' => ["\n\n", "\n", ". ", " "],                // preferred split points
    ],

    // File type support
    'supported_types' => [
        'pdf', 'docx', 'doc', 'txt', 'md', 'html', 'rtf', 'odt'
    ],

    // Vector store settings
    'collection' => env('AI_FILE_COLLECTION', 'file_chunks'),

    // Processing limits
    'max_file_size_mb' => env('AI_FILE_MAX_SIZE', 50),           // MB
    'max_chunks_per_file' => env('AI_FILE_MAX_CHUNKS', 1000),

    // Metadata extraction
    'extract_metadata' => true,                                   // headings, sections, etc.
],
```

## Use Cases

### Use Case 1: Search Files by Content

```php
// User searches: "Laravel queue configuration"
$results = FileSearch::query("Laravel queue configuration")->get();

// Returns:
// File: "laravel-docs.pdf", Chunk: "Configuring Queues...", Score: 0.92
// File: "setup-guide.md", Chunk: "Queue Setup Steps...", Score: 0.87
```

### Use Case 2: Get More Information

```php
// User asks: "Tell me more about Redis configuration"
$context = FileSearch::query("Redis configuration")
    ->limit(5)
    ->get();

// Use chunks as context for LLM
$answer = AI::answerQuestion(
    "How do I configure Redis?",
    ['file_context' => $context]
);
```

### Use Case 3: File-Scoped Search

```php
// Search within specific file
$results = FileSearch::query("authentication")
    ->filter(['file_id' => 123])
    ->get();
```

### Use Case 4: Contextual Recommendations

```php
// "Show me related documentation"
$fileChunks = FileSearch::query($currentPageContent)
    ->exclude(['file_id' => $currentFileId])
    ->limit(3)
    ->get();
```

## Database Schema (Optional)

For tracking processing status:

```sql
CREATE TABLE file_processing_status (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    file_id BIGINT NOT NULL,
    status VARCHAR(50) NOT NULL, -- pending, processing, completed, failed
    chunks_created INT DEFAULT 0,
    embeddings_generated INT DEFAULT 0,
    processing_started_at TIMESTAMP NULL,
    processing_completed_at TIMESTAMP NULL,
    error_message TEXT NULL,
    metadata JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    INDEX idx_file_status (file_id, status)
);
```

## Queue Jobs

```php
namespace AiSystem\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kondoedge\Utils\Models\File;
use AiSystem\Contracts\FileProcessorInterface;

class ProcessFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 300; // 5 minutes

    public function __construct(
        private readonly int $fileId,
        private readonly array $options = []
    ) {}

    public function handle(FileProcessorInterface $processor): void
    {
        $file = File::findOrFail($this->fileId);

        $result = $processor->processFile($file, $this->options);

        if (!$result->success) {
            throw new \RuntimeException($result->error);
        }
    }
}
```

## SOLID Principles Applied

### Single Responsibility
- `FileChunker` - Only chunks text
- `FileExtractor` - Only extracts text from files
- `FileProcessor` - Only orchestrates processing
- `ChunkStore` - Only manages chunk storage

### Open/Closed
- New file extractors can be added without modifying existing code
- New chunking strategies via strategy pattern

### Liskov Substitution
- All interfaces can be swapped with different implementations
- QdrantChunkStore can be replaced with ElasticsearchChunkStore

### Interface Segregation
- Small, focused interfaces
- Clients only depend on methods they use

### Dependency Inversion
- All services depend on interfaces
- No direct dependencies on concrete implementations

## Testing Strategy

```php
// Unit Tests
class SemanticChunkerTest extends TestCase
{
    public function test_chunks_text_by_paragraphs()
    {
        $chunker = new SemanticChunker();
        $chunks = $chunker->chunk($longText, ['max_chunk_size' => 500]);

        $this->assertCount(5, $chunks);
        $this->assertLessThan(500, strlen($chunks[0]));
    }
}

// Integration Tests
class FileProcessorTest extends TestCase
{
    public function test_processes_pdf_file()
    {
        $file = File::factory()->create(['extension' => 'pdf']);
        $result = $this->processor->processFile($file);

        $this->assertTrue($result->success);
        $this->assertGreaterThan(0, $result->chunksCreated);
    }
}

// Feature Tests
class FileSearchTest extends TestCase
{
    public function test_finds_relevant_content()
    {
        // Seed test files
        $this->seedTestFiles();

        // Search
        $results = FileSearch::query("Laravel installation")->get();

        $this->assertCount(3, $results);
        $this->assertStringContainsString('Laravel', $results[0]->content);
    }
}
```

## Performance Considerations

### Chunking Performance
- **Large files**: Process in background queue
- **Batch embeddings**: Generate 100 embeddings at once (API efficiency)
- **Caching**: Cache extracted text for reprocessing

### Storage Optimization
- **Compression**: Store chunk content compressed
- **Deduplication**: Skip duplicate chunks
- **Cleanup**: Remove old/deleted file chunks

### Search Performance
- **Index optimization**: Qdrant HNSW index for fast search
- **Result caching**: Cache frequent queries
- **Pagination**: Limit results to prevent large response

## Migration Path

### Step 1: Install Dependencies
```bash
composer require kondoedge/utils
composer require smalot/pdfparser  # PDF extraction
composer require phpoffice/phpword  # DOCX extraction
```

### Step 2: Implement Core Services
1. FileChunkerInterface + SemanticChunker
2. FileProcessorInterface + FileProcessor
3. ChunkStoreInterface + QdrantChunkStore

### Step 3: File Extractors
1. PdfExtractor
2. DocxExtractor
3. TextExtractor
4. MarkdownExtractor

### Step 4: Model Plugin
1. Create FileProcessingPlugin
2. Register in service provider

### Step 5: Facades & Helpers
1. FileSearch facade
2. Helper functions

### Step 6: Testing
1. Unit tests for each service
2. Integration tests with real files
3. Feature tests for search

## Next Steps

Would you like me to:

1. ✅ Implement the core interfaces (FileChunkerInterface, FileProcessorInterface, ChunkStoreInterface)?
2. ✅ Implement SemanticChunker service?
3. ✅ Implement FileProcessor service?
4. ✅ Implement QdrantChunkStore service?
5. ✅ Create FileProcessingPlugin for condoedge/utils integration?
6. ✅ Set up queue jobs?
7. ✅ Create file extractors (PDF, DOCX, etc.)?
8. ✅ Write comprehensive tests?

Let me know which parts you'd like me to implement first!

---

**Status**: Design Complete, Ready for Implementation
**Estimated Implementation Time**: 8-12 hours for full system
**Dependencies**: condoedge/utils, PDF/DOCX parsers
**SOLID Compliance**: ✅ All principles followed
