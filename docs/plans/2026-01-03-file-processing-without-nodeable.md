# File Processing Without Nodeable Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Enable file processing for models that cannot implement the Nodeable interface (e.g., models from external packages).

**Architecture:** Make Neo4j synchronization optional in FileProcessingPlugin. Files that implement Nodeable get full dual-store (Neo4j + Qdrant). Files that don't implement Nodeable get Qdrant-only storage. The semantic search and file references work 100% because they rely on Qdrant chunk payloads, not Neo4j.

**Tech Stack:** Laravel PHP, Neo4j (graph - optional for files), Qdrant (vector - required), existing FileProcessor service

---

## Why This Works (Architecture Analysis)

**File reference flow (traced from code):**

```
AI Response ← FileContextProvider ← FileSearchService ← QdrantChunkStore
                                                              ↓
                                                    Qdrant chunks with:
                                                    - file_id
                                                    - file_name
                                                    - content
                                                    (NO Neo4j involved!)
```

**What the chunks store (QdrantChunkStore.php:76-84):**
```php
'payload' => [
    'file_id' => $chunk->fileId,      // From $file->id
    'file_name' => $chunk->fileName,  // From $file->name
    'content' => $chunk->content,     // Chunk text
    ...
]
```

**Semantic search path (FileContextProvider.php:65-68):**
```php
$searchResults = $this->searchService->searchByContent($question, [
    'include_relationships' => false,  // Neo4j NOT used by default
]);
```

**Conclusion:** File references in AI responses come from Qdrant payload metadata, not Neo4j.

---

## Task 1: Make FileProcessingPlugin Check for Nodeable

**Files:**
- Modify: `src/Models/Plugins/FileProcessingPlugin.php`

**Step 1: Add import at top of file (after line 8)**

```php
use Condoedge\Ai\Domain\Contracts\Nodeable;
```

**Step 2: Add helper method (before closing brace of class)**

```php
/**
 * Check if file implements Nodeable interface
 *
 * @param object $file
 * @return bool
 */
protected function isNodeable($file): bool
{
    return $file instanceof Nodeable;
}
```

**Step 3: Replace syncToNeo4j method (lines 117-124)**

```php
/**
 * Sync file metadata to Neo4j
 *
 * Only syncs if file implements Nodeable interface.
 * Files from external packages that don't implement Nodeable
 * will skip Neo4j and still get Qdrant content storage.
 *
 * @param object $file
 * @param string $operation 'create' or 'update'
 * @return void
 */
protected function syncToNeo4j($file, string $operation): void
{
    // Skip Neo4j for non-Nodeable files (they still get Qdrant storage)
    if (!$this->isNodeable($file)) {
        Log::debug('Skipping Neo4j for non-Nodeable file (Qdrant content storage still works)', [
            'file_id' => $file->id,
            'file_class' => get_class($file),
        ]);
        return;
    }

    if ($operation === 'create') {
        AI::ingest($file);
    } else {
        AI::sync($file);
    }
}
```

**Step 4: Replace removeFromNeo4j method (lines 127-135)**

```php
/**
 * Remove file from Neo4j
 *
 * Only removes if file implements Nodeable interface.
 *
 * @param object $file
 * @return void
 */
protected function removeFromNeo4j($file): void
{
    if (!$this->isNodeable($file)) {
        Log::debug('Skipping Neo4j removal for non-Nodeable file', [
            'file_id' => $file->id,
        ]);
        return;
    }

    AI::remove($file);
}
```

**Step 5: Run syntax check**

Run: `php -l src/Models/Plugins/FileProcessingPlugin.php`
Expected: `No syntax errors detected`

**Step 6: Commit**

```bash
git add src/Models/Plugins/FileProcessingPlugin.php
git commit -m "feat(plugin): make Neo4j sync optional for non-Nodeable files

Files that don't implement Nodeable now skip Neo4j but still get:
- Content extraction and chunking
- Qdrant vector storage
- Full semantic search support
- File references in AI responses"
```

---

## Task 2: Add Unit Test

**Files:**
- Create: `tests/Unit/Models/Plugins/FileProcessingPluginTest.php`

**Step 1: Create test file**

```php
<?php

namespace Tests\Unit\Models\Plugins;

use Condoedge\Ai\Models\Plugins\FileProcessingPlugin;
use Condoedge\Ai\Contracts\FileProcessorInterface;
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\ValueObjects\GraphConfig;
use Condoedge\Ai\Domain\ValueObjects\VectorConfig;
use Condoedge\Ai\DTOs\ProcessingResult;
use Condoedge\Ai\Facades\AI;
use Mockery;
use Tests\TestCase;

class FileProcessingPluginTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_skips_neo4j_for_non_nodeable_files()
    {
        // Non-Nodeable file from external package
        $file = new class {
            public $id = 123;
            public $name = 'report.pdf';
            public $path = '/tmp/report.pdf';
        };

        // AI facade should NOT be called
        AI::shouldReceive('ingest')->never();
        AI::shouldReceive('sync')->never();
        AI::shouldReceive('remove')->never();

        $plugin = new FileProcessingPlugin(\stdClass::class);

        $method = new \ReflectionMethod($plugin, 'syncToNeo4j');
        $method->setAccessible(true);
        $method->invoke($plugin, $file, 'create');

        // No exception = success
        $this->assertTrue(true);
    }

    /** @test */
    public function it_syncs_to_neo4j_for_nodeable_files()
    {
        $file = Mockery::mock(Nodeable::class);
        $file->id = 456;
        $file->shouldReceive('getId')->andReturn(456);
        $file->shouldReceive('toArray')->andReturn(['id' => 456]);
        $file->shouldReceive('getGraphConfig')->andReturn(
            new GraphConfig('File', ['id', 'name'], [])
        );
        $file->shouldReceive('getVectorConfig')->andReturn(
            new VectorConfig('files', ['name'], ['id'])
        );

        AI::shouldReceive('ingest')->once()->with($file)->andReturn([
            'graph_stored' => true,
            'vector_stored' => true,
            'errors' => [],
        ]);

        $plugin = new FileProcessingPlugin(\stdClass::class);

        $method = new \ReflectionMethod($plugin, 'syncToNeo4j');
        $method->setAccessible(true);
        $method->invoke($plugin, $file, 'create');

        $this->assertTrue(true);
    }
}
```

**Step 2: Run tests**

Run: `php vendor/bin/phpunit tests/Unit/Models/Plugins/FileProcessingPluginTest.php`
Expected: 2 tests pass

**Step 3: Commit**

```bash
git add tests/Unit/Models/Plugins/FileProcessingPluginTest.php
git commit -m "test(plugin): add tests for non-Nodeable file handling"
```

---

## Summary

| File Type | Neo4j (Graph) | Qdrant (Chunks) | Semantic Search | AI File References |
|-----------|---------------|-----------------|-----------------|-------------------|
| Implements Nodeable | YES | YES | YES | YES |
| External (non-Nodeable) | SKIPPED | YES | YES | YES |

**Key insight:** File references work WITHOUT Neo4j because:
1. `QdrantChunkStore` stores `file_id` and `file_name` in chunk payload
2. `FileSearchService::searchByContent()` uses Qdrant only (no Neo4j)
3. `FileContextProvider` defaults to `include_relationships: false`

**Only thing lost for external files:** Graph relationship queries (e.g., "find files by user via graph traversal"). This is rarely needed for file content search.
