# File Model Integration - Neo4j + Qdrant

## Overview

Integrate the `File` model from `condoedge/utils` into both Neo4j (metadata/relationships) and Qdrant (content chunks) using a coordinated dual-storage approach.

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│  File Model (condoedge/utils)                               │
│  - implements Nodeable                                       │
│  - uses HasNodeableConfig trait                              │
└─────────────────────────────────────────────────────────────┘
                    ↓           ↓
        ┌───────────┘           └──────────┐
        ↓                                   ↓
┌──────────────────┐              ┌─────────────────────┐
│  Neo4j Storage   │              │  Qdrant Storage     │
│  (Metadata)      │              │  (Content Chunks)   │
├──────────────────┤              ├─────────────────────┤
│ • File node      │              │ • Text chunks       │
│ • Properties:    │              │ • Embeddings        │
│   - id           │              │ • Metadata:         │
│   - name         │              │   - file_id         │
│   - size         │              │   - chunk_index     │
│   - type         │              │   - position        │
│   - mime_type    │              │                     │
│   - uploaded_at  │              │ • Semantic search   │
│                  │              │   enabled           │
│ • Relationships: │              │                     │
│   - UPLOADED_BY  │              │ • Retrieved via     │
│   - BELONGS_TO   │              │   ChunkStore        │
│   - CONTAINS     │              │                     │
│   - RELATED_TO   │              │                     │
└──────────────────┘              └─────────────────────┘
```

## Data Flow

### 1. File Upload
```
User uploads file
    ↓
File::create([...])
    ↓
[Auto-Sync to Neo4j via HasNodeableConfig]
    ↓
AI::ingest($file) → Neo4j stores File node + relationships
    ↓
[File Processing Plugin triggered]
    ↓
FileProcessor::processFile($file)
    ↓
- Extract text from file
- Chunk content
- Generate embeddings
- Store in Qdrant via ChunkStore
```

### 2. File Search
```
User searches: "Laravel configuration"
    ↓
ChunkStore::searchByContent("Laravel configuration")
    ↓
Qdrant returns matching chunks with file_ids
    ↓
Load File models from database
    ↓
[Optional] Load Neo4j relationships for context
    ↓
Return: Files + relevant chunks + relationships
```

### 3. File Deletion
```
File::delete()
    ↓
[Auto-remove from Neo4j via HasNodeableConfig]
    ↓
AI::remove($file) → Neo4j removes File node
    ↓
[File Processing Plugin triggered]
    ↓
FileProcessor::removeFile($file)
    ↓
ChunkStore::deleteFileChunks($fileId)
    ↓
Qdrant removes all chunks for file
```

## Implementation

### Step 1: Extend File Model (Plugin Pattern)

Since we can't modify `condoedge/utils` directly, we use Laravel's model extension:

```php
namespace Condoedge\Ai\Extensions;

use Kondoedge\Utils\Models\File as BaseFile;
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\Traits\HasNodeableConfig;

/**
 * Extended File Model with AI System Integration
 *
 * Extends condoedge/utils File model to add:
 * - Neo4j integration (via Nodeable)
 * - Auto-sync to graph database
 * - Automatic chunk processing
 */
class File extends BaseFile implements Nodeable
{
    use HasNodeableConfig;

    /**
     * Get the Neo4j node label
     */
    public function getNodeLabel(): string
    {
        return 'File';
    }

    /**
     * Get properties to store in Neo4j
     */
    public function getNodeProperties(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'original_name' => $this->original_name ?? $this->name,
            'size' => $this->size,
            'extension' => $this->extension,
            'mime_type' => $this->mime_type,
            'path' => $this->path,
            'disk' => $this->disk ?? 'local',
            'uploaded_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Get Neo4j relationships
     */
    public function getNodeRelationships(): array
    {
        $relationships = [];

        // UPLOADED_BY relationship (if file has user)
        if ($this->user_id) {
            $relationships[] = [
                'type' => 'UPLOADED_BY',
                'direction' => 'outgoing',
                'target' => [
                    'label' => 'User',
                    'id' => $this->user_id,
                ],
            ];
        }

        // BELONGS_TO relationship (if file belongs to a model)
        if ($this->fileable_type && $this->fileable_id) {
            $relationships[] = [
                'type' => 'BELONGS_TO',
                'direction' => 'outgoing',
                'target' => [
                    'label' => class_basename($this->fileable_type),
                    'id' => $this->fileable_id,
                ],
            ];
        }

        // CONTAINS relationship (file has chunks in Qdrant)
        // This is metadata only - actual chunks are in Qdrant
        $relationships[] = [
            'type' => 'HAS_CONTENT',
            'direction' => 'outgoing',
            'target' => [
                'label' => 'FileContent',
                'id' => "file_content_{$this->id}",
            ],
            'properties' => [
                'chunk_count' => $this->getChunkCount(),
                'stored_in' => 'qdrant',
                'collection' => config('ai.file_processing.collection'),
            ],
        ];

        return $relationships;
    }

    /**
     * Get text for embedding (metadata only, not content)
     */
    public function getEmbeddingText(): string
    {
        // For vector search of file metadata (separate from chunk embeddings)
        return implode(' ', array_filter([
            $this->name,
            $this->original_name,
            $this->extension,
            $this->description ?? '',
            $this->tags ? implode(' ', $this->tags) : '',
        ]));
    }

    /**
     * Get the Neo4j node ID
     */
    public function getNodeId(): string|int
    {
        return $this->id;
    }

    /**
     * Check if file should be processed for chunks
     */
    public function shouldProcessContent(): bool
    {
        $supportedTypes = config('ai.file_processing.supported_types', []);
        return in_array($this->extension, $supportedTypes);
    }

    /**
     * Get chunk count (cached)
     */
    public function getChunkCount(): int
    {
        if (!$this->shouldProcessContent()) {
            return 0;
        }

        // Get from Qdrant or cached value
        return cache()->remember(
            "file_chunk_count_{$this->id}",
            now()->addHours(24),
            function () {
                return app(\Condoedge\Ai\Contracts\ChunkStoreInterface::class)
                    ->getFileStats($this->id)['chunk_count'] ?? 0;
            }
        );
    }

    /**
     * Get file chunks
     */
    public function chunks()
    {
        if (!$this->shouldProcessContent()) {
            return collect();
        }

        return app(\Condoedge\Ai\Contracts\ChunkStoreInterface::class)
            ->getFileChunks($this->id);
    }

    /**
     * Search within this file
     */
    public function search(string $query, int $limit = 10)
    {
        return app(\Condoedge\Ai\Contracts\ChunkStoreInterface::class)
            ->searchByContent($query, $limit, ['file_id' => $this->id]);
    }
}
```

### Step 2: Register File Extension in Service Provider

```php
namespace AiSystem;

use Illuminate\Support\ServiceProvider;
use Kondoedge\Utils\Models\File as BaseFile;
use Condoedge\Ai\Extensions\File as ExtendedFile;

class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register File model extension
        $this->app->bind(BaseFile::class, ExtendedFile::class);

        // Or use model morphing if available
        BaseFile::resolveRelationUsing('extended', function ($model) {
            return new ExtendedFile($model->getAttributes());
        });
    }
}
```

### Step 3: Enhanced File Processing Plugin

```php
namespace Condoedge\Ai\Plugins;

use Condoedge\Ai\Extensions\File;
use Kondoedge\Utils\Plugins\ModelPlugin;
use Condoedge\Ai\Contracts\FileProcessorInterface;
use Condoedge\Ai\Jobs\ProcessFileJob;

/**
 * File Processing Plugin
 *
 * Integrates File model with both Neo4j (via auto-sync) and Qdrant (via FileProcessor)
 */
class FileProcessingPlugin extends ModelPlugin
{
    protected FileProcessorInterface $processor;

    public function boot(): void
    {
        $this->processor = app(FileProcessorInterface::class);

        // Hook 1: After file created → Auto-sync to Neo4j (via HasNodeableConfig trait)
        // This happens automatically via the trait's boot method

        // Hook 2: After file created → Process content for Qdrant
        File::created(function (File $file) {
            if ($this->shouldProcessFile($file)) {
                $this->processFileContent($file);
            }
        });

        // Hook 3: After file updated → Reprocess if content changed
        File::updated(function (File $file) {
            if ($this->shouldReprocessFile($file)) {
                $this->reprocessFileContent($file);
            }
        });

        // Hook 4: Before file deleted → Remove from both stores
        File::deleting(function (File $file) {
            // Remove chunks from Qdrant
            $this->removeFileContent($file);

            // Neo4j cleanup happens via HasNodeableConfig trait's deleted hook
        });
    }

    /**
     * Check if file should be processed
     */
    protected function shouldProcessFile(File $file): bool
    {
        if (!config('ai.file_processing.auto_process', true)) {
            return false;
        }

        return $file->shouldProcessContent();
    }

    /**
     * Check if file should be reprocessed
     */
    protected function shouldReprocessFile(File $file): bool
    {
        if (!$this->shouldProcessFile($file)) {
            return false;
        }

        // Reprocess if file path changed (content updated)
        return $file->isDirty('path') || $file->isDirty('content');
    }

    /**
     * Process file content (chunks → Qdrant)
     */
    protected function processFileContent(File $file): void
    {
        if (config('ai.file_processing.queue', true)) {
            // Queue for async processing (recommended)
            ProcessFileJob::dispatch($file->id)
                ->onQueue(config('ai.file_processing.queue_name', 'file-processing'));
        } else {
            // Process synchronously
            $this->processor->processFile($file);
        }
    }

    /**
     * Reprocess file content
     */
    protected function reprocessFileContent(File $file): void
    {
        if (config('ai.file_processing.queue', true)) {
            ProcessFileJob::dispatch($file->id)
                ->onQueue(config('ai.file_processing.queue_name', 'file-processing'));
        } else {
            $this->processor->reprocessFile($file);
        }
    }

    /**
     * Remove file content from Qdrant
     */
    protected function removeFileContent(File $file): void
    {
        try {
            $this->processor->removeFile($file);
        } catch (\Exception $e) {
            \Log::error('Failed to remove file chunks', [
                'file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
```

### Step 4: Entity Configuration for Files

```php
// config/entities.php

return [
    'File' => [
        'graph' => [
            'label' => 'File',
            'properties' => [
                'id',
                'name',
                'original_name',
                'size',
                'extension',
                'mime_type',
                'path',
                'disk',
                'uploaded_at',
                'updated_at',
            ],
            'relationships' => [
                [
                    'type' => 'UPLOADED_BY',
                    'target_label' => 'User',
                    'foreign_key' => 'user_id',
                ],
                [
                    'type' => 'BELONGS_TO',
                    'target_label' => 'Polymorphic', // Handled dynamically
                    'foreign_key' => 'fileable_id',
                    'foreign_type' => 'fileable_type',
                ],
                [
                    'type' => 'HAS_CONTENT',
                    'target_label' => 'FileContent',
                    'foreign_key' => 'id', // Self-reference
                ],
            ],
        ],
        'vector' => [
            'collection' => 'files',
            'embed_fields' => ['name', 'original_name', 'description'],
            'metadata' => ['id', 'extension', 'mime_type', 'size', 'uploaded_at'],
        ],
        'metadata' => [
            'aliases' => ['file', 'files', 'document', 'documents', 'attachment'],
            'description' => 'Files and documents in the system',
            'scopes' => [
                'pdfs' => [
                    'specification_type' => 'property_filter',
                    'concept' => 'PDF documents',
                    'filter' => [
                        'property' => 'extension',
                        'operator' => 'equals',
                        'value' => 'pdf',
                    ],
                ],
                'documents' => [
                    'specification_type' => 'property_filter',
                    'concept' => 'Document files',
                    'filter' => [
                        'property' => 'extension',
                        'operator' => 'in',
                        'value' => ['pdf', 'docx', 'doc', 'txt', 'md'],
                    ],
                ],
                'recent' => [
                    'specification_type' => 'temporal_filter',
                    'concept' => 'Recently uploaded files',
                    'filter' => [
                        'property' => 'uploaded_at',
                        'operator' => 'within_last',
                        'value' => '30 days',
                    ],
                ],
            ],
        ],
    ],
];
```

### Step 5: Unified Search Interface

```php
namespace Condoedge\Ai\Services;

use Condoedge\Ai\Extensions\File;
use Condoedge\Ai\Contracts\ChunkStoreInterface;
use Condoedge\Ai\Facades\AI;

/**
 * Unified File Search Service
 *
 * Combines Neo4j (metadata/relationships) and Qdrant (content) searches
 */
class FileSearchService
{
    public function __construct(
        private readonly ChunkStoreInterface $chunkStore
    ) {}

    /**
     * Search files by content
     *
     * @param string $query Search query
     * @param array $options Search options:
     *                       - limit: Maximum results (default: 10)
     *                       - file_types: Filter by extensions (e.g., ['pdf', 'docx'])
     *                       - uploaded_by: Filter by user_id
     *                       - uploaded_after: Filter by date
     *                       - include_relationships: Load Neo4j relationships (default: false)
     * @return array Search results with files and relevant chunks
     */
    public function searchByContent(string $query, array $options = []): array
    {
        $limit = $options['limit'] ?? 10;
        $filters = [];

        // Build Qdrant filters
        if (!empty($options['file_types'])) {
            $filters['file_extension'] = $options['file_types'];
        }

        // Search Qdrant for relevant chunks
        $chunks = $this->chunkStore->searchByContent($query, $limit * 3, $filters);

        // Group chunks by file
        $fileChunks = collect($chunks)->groupBy('file_id');

        // Load File models
        $fileIds = $fileChunks->keys()->toArray();
        $files = File::whereIn('id', $fileIds)->get()->keyBy('id');

        // Apply additional filters (from database)
        if (!empty($options['uploaded_by'])) {
            $files = $files->where('user_id', $options['uploaded_by']);
        }

        if (!empty($options['uploaded_after'])) {
            $files = $files->where('created_at', '>=', $options['uploaded_after']);
        }

        // Build results
        $results = [];
        foreach ($files->take($limit) as $file) {
            $result = [
                'file' => $file,
                'chunks' => $fileChunks[$file->id] ?? [],
                'relevance_score' => $this->calculateRelevanceScore($fileChunks[$file->id] ?? []),
            ];

            // Optionally load Neo4j relationships
            if ($options['include_relationships'] ?? false) {
                $result['relationships'] = $this->getFileRelationships($file);
            }

            $results[] = $result;
        }

        // Sort by relevance
        usort($results, fn($a, $b) => $b['relevance_score'] <=> $a['relevance_score']);

        return $results;
    }

    /**
     * Search files by metadata (Neo4j)
     *
     * Example: "Find PDFs uploaded by user X that are related to project Y"
     */
    public function searchByMetadata(array $criteria): array
    {
        // Use Neo4j Cypher query to search by relationships
        $cypher = $this->buildMetadataQuery($criteria);
        $results = AI::executeQuery($cypher);

        // Hydrate File models
        $fileIds = collect($results['data'])->pluck('id')->toArray();
        return File::whereIn('id', $fileIds)->get();
    }

    /**
     * Hybrid search: Content + Metadata
     *
     * Example: "Find Laravel documentation PDFs uploaded in last month"
     */
    public function hybridSearch(string $contentQuery, array $metadataFilters = []): array
    {
        // Step 1: Search by content (Qdrant)
        $contentResults = $this->searchByContent($contentQuery, $metadataFilters);

        // Step 2: Enhance with graph relationships (Neo4j)
        foreach ($contentResults as &$result) {
            $result['relationships'] = $this->getFileRelationships($result['file']);
        }

        return $contentResults;
    }

    /**
     * Get related files via Neo4j relationships
     */
    public function getRelatedFiles(File $file, string $relationshipType = null): array
    {
        $cypher = "
            MATCH (f:File {id: \$file_id})-[r" . ($relationshipType ? ":{$relationshipType}" : '') . "]-(related:File)
            RETURN related.id as id, type(r) as relationship_type
            LIMIT 20
        ";

        $results = AI::executeQuery($cypher, ['file_id' => $file->id]);

        $relatedIds = collect($results['data'])->pluck('id')->toArray();
        return File::whereIn('id', $relatedIds)->get();
    }

    /**
     * Calculate relevance score from chunks
     */
    private function calculateRelevanceScore(array $chunks): float
    {
        if (empty($chunks)) {
            return 0.0;
        }

        // Average of top 3 chunk scores
        $scores = collect($chunks)->pluck('score')->take(3);
        return $scores->avg();
    }

    /**
     * Get file relationships from Neo4j
     */
    private function getFileRelationships(File $file): array
    {
        $cypher = "
            MATCH (f:File {id: \$file_id})-[r]-(connected)
            RETURN type(r) as relationship,
                   labels(connected) as target_labels,
                   connected.id as target_id,
                   connected.name as target_name
        ";

        $results = AI::executeQuery($cypher, ['file_id' => $file->id]);

        return $results['data'] ?? [];
    }

    /**
     * Build Cypher query for metadata search
     */
    private function buildMetadataQuery(array $criteria): string
    {
        // Example criteria:
        // ['extension' => 'pdf', 'user_id' => 123, 'project_id' => 456]

        $conditions = [];
        foreach ($criteria as $key => $value) {
            if ($key === 'user_id') {
                $conditions[] = "(f)-[:UPLOADED_BY]->(:User {id: {$value}})";
            } elseif ($key === 'project_id') {
                $conditions[] = "(f)-[:BELONGS_TO]->(:Project {id: {$value}})";
            } else {
                $conditions[] = "f.{$key} = '{$value}'";
            }
        }

        $where = implode(' AND ', $conditions);

        return "
            MATCH (f:File)
            WHERE {$where}
            RETURN f.id as id
            LIMIT 100
        ";
    }
}
```

### Step 6: Enhanced FileSearch Facade

```php
namespace Condoedge\Ai\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array searchByContent(string $query, array $options = [])
 * @method static array searchByMetadata(array $criteria)
 * @method static array hybridSearch(string $contentQuery, array $metadataFilters = [])
 * @method static array getRelatedFiles(\Condoedge\Ai\Extensions\File $file, string $relationshipType = null)
 */
class FileSearch extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Condoedge\Ai\Services\FileSearchService::class;
    }
}
```

## Usage Examples

### Example 1: Simple Content Search

```php
// Search for files containing "Laravel configuration"
$results = FileSearch::searchByContent("Laravel configuration", [
    'limit' => 5,
    'file_types' => ['pdf', 'md'],
]);

foreach ($results as $result) {
    echo "File: {$result['file']->name}\n";
    echo "Relevance: {$result['relevance_score']}\n";
    echo "Top chunks:\n";
    foreach ($result['chunks'] as $chunk) {
        echo "  - {$chunk->content}\n";
    }
}
```

### Example 2: Metadata Search (Neo4j)

```php
// Find all PDFs uploaded by specific user
$files = FileSearch::searchByMetadata([
    'extension' => 'pdf',
    'user_id' => 123,
]);
```

### Example 3: Hybrid Search

```php
// Content + Metadata: "Laravel docs" AND "uploaded last month" AND "PDF"
$results = FileSearch::hybridSearch("Laravel queues documentation", [
    'file_types' => ['pdf'],
    'uploaded_after' => now()->subMonth(),
    'include_relationships' => true,
]);

foreach ($results as $result) {
    echo "File: {$result['file']->name}\n";

    // Show relationships from Neo4j
    foreach ($result['relationships'] as $rel) {
        echo "  Related: {$rel['target_name']} ({$rel['relationship']})\n";
    }
}
```

### Example 4: Related Files (Graph Traversal)

```php
$file = File::find(123);

// Find files related via any relationship
$related = FileSearch::getRelatedFiles($file);

// Find files related via specific relationship
$belongsTo = FileSearch::getRelatedFiles($file, 'BELONGS_TO');
```

### Example 5: Combined with AI Query

```php
// User asks: "What do my documentation files say about Redis?"

// Step 1: Find relevant files and chunks
$fileResults = FileSearch::searchByContent("Redis configuration", [
    'file_types' => ['pdf', 'md'],
    'limit' => 3,
]);

// Step 2: Extract relevant chunks as context
$context = [];
foreach ($fileResults as $result) {
    foreach ($result['chunks'] as $chunk) {
        $context[] = $chunk->content;
    }
}

// Step 3: Ask AI with file context
$answer = AI::answerQuestion(
    "What do my documentation files say about Redis?",
    [
        'file_context' => $context,
        'sources' => collect($fileResults)->pluck('file.name')->toArray(),
    ]
);

echo $answer['answer'];
echo "\nSources:\n";
foreach ($answer['sources'] as $source) {
    echo "- {$source}\n";
}
```

## Data Synchronization Strategy

### Consistency Guarantees

1. **Neo4j is source of truth for metadata**
   - File properties (name, size, type)
   - Relationships (uploaded by, belongs to)

2. **Qdrant is source of truth for content**
   - Text chunks
   - Embeddings
   - Semantic search

3. **Both stores synchronized via events**
   - File created → Neo4j + Qdrant
   - File updated → Both stores updated
   - File deleted → Both stores cleaned

### Handling Failures

```php
// In FileProcessingPlugin

protected function processFileContent(File $file): void
{
    try {
        if (config('ai.file_processing.queue', true)) {
            ProcessFileJob::dispatch($file->id)
                ->onQueue('file-processing');
        } else {
            $this->processor->processFile($file);
        }
    } catch (\Exception $e) {
        // Log error but don't fail the file creation
        \Log::error('File processing failed', [
            'file_id' => $file->id,
            'error' => $e->getMessage(),
        ]);

        // Mark for retry
        dispatch(function () use ($file) {
            $this->processor->processFile($file);
        })->delay(now()->addMinutes(5));
    }
}
```

### Cleanup & Maintenance

```php
// Artisan command for cleanup
php artisan ai:cleanup-orphaned-chunks

// Checks for chunks in Qdrant without corresponding File in database
// Removes orphaned chunks

php artisan ai:reindex-files

// Reprocesses all files (useful after algorithm changes)
```

## Configuration

```php
// config/ai.php

return [
    // ... existing config

    'file_processing' => [
        // Enable automatic processing
        'auto_process' => env('AI_FILE_AUTO_PROCESS', true),

        // Queue settings
        'queue' => env('AI_FILE_QUEUE', true),
        'queue_connection' => env('AI_FILE_QUEUE_CONNECTION', 'redis'),
        'queue_name' => env('AI_FILE_QUEUE_NAME', 'file-processing'),

        // Storage settings
        'store_in_neo4j' => env('AI_FILE_NEO4J', true),      // File metadata
        'store_in_qdrant' => env('AI_FILE_QDRANT', true),    // File chunks

        // Chunking
        'chunking' => [
            'max_chunk_size' => 1000,
            'overlap' => 200,
        ],

        // Supported file types
        'supported_types' => ['pdf', 'docx', 'doc', 'txt', 'md', 'html'],

        // Collections
        'file_metadata_collection' => 'files',      // Qdrant collection for file metadata
        'file_chunks_collection' => 'file_chunks',  // Qdrant collection for chunks

        // Limits
        'max_file_size_mb' => 50,
        'max_chunks_per_file' => 1000,
    ],
];
```

## Testing Strategy

```php
// tests/Integration/FileModelIntegrationTest.php

class FileModelIntegrationTest extends TestCase
{
    public function test_file_syncs_to_both_stores()
    {
        // Create file
        $file = File::create([
            'name' => 'test.pdf',
            'size' => 1024,
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
        ]);

        // Assert Neo4j has file node
        $nodeExists = AI::executeQuery(
            "MATCH (f:File {id: \$id}) RETURN f",
            ['id' => $file->id]
        );
        $this->assertNotEmpty($nodeExists['data']);

        // Process file content
        ProcessFileJob::dispatchSync($file->id);

        // Assert Qdrant has chunks
        $chunks = app(ChunkStoreInterface::class)->getFileChunks($file->id);
        $this->assertNotEmpty($chunks);
    }

    public function test_file_deletion_cleans_both_stores()
    {
        $file = File::factory()->create();
        ProcessFileJob::dispatchSync($file->id);

        $fileId = $file->id;
        $file->delete();

        // Assert Neo4j removed
        $result = AI::executeQuery(
            "MATCH (f:File {id: \$id}) RETURN f",
            ['id' => $fileId]
        );
        $this->assertEmpty($result['data']);

        // Assert Qdrant removed
        $chunks = app(ChunkStoreInterface::class)->getFileChunks($fileId);
        $this->assertEmpty($chunks);
    }

    public function test_hybrid_search_works()
    {
        // Seed test files with content
        $this->seedTestFiles();

        // Search by content
        $results = FileSearch::hybridSearch("Laravel configuration", [
            'file_types' => ['pdf'],
            'include_relationships' => true,
        ]);

        $this->assertNotEmpty($results);
        $this->assertArrayHasKey('file', $results[0]);
        $this->assertArrayHasKey('chunks', $results[0]);
        $this->assertArrayHasKey('relationships', $results[0]);
    }
}
```

## Summary

### What Gets Stored Where

**Neo4j (Graph)**:
- File metadata (name, size, type, timestamps)
- Relationships (UPLOADED_BY, BELONGS_TO, HAS_CONTENT)
- Graph traversal queries

**Qdrant (Vector)**:
- File content chunks (text)
- Chunk embeddings
- Semantic similarity search

### Key Benefits

1. ✅ **Best of both worlds** - Graph relationships + semantic search
2. ✅ **Single source** - File model manages both stores
3. ✅ **Automatic sync** - Hooks handle coordination
4. ✅ **Isolated services** - Each service has single responsibility
5. ✅ **Easy to use** - Simple facades hide complexity

### Next Steps

Should I implement:
1. The File model extension?
2. The FileSearchService?
3. The coordinated sync logic?
4. Tests for the integration?

Let me know and I'll build it!
