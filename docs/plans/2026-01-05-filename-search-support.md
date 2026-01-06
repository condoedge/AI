# Filename Search Support Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Enable FileContextProvider.searchRelevantFiles to find files by filename when users explicitly reference files (e.g., "Check the bariloche.txt file").

**Architecture:** Add filename extraction from user queries using regex patterns, implement a new `searchByFilename()` method in FileSearchService that queries Qdrant payload metadata, and combine filename-based results with semantic content results in FileContextProvider with filename matches prioritized.

**Tech Stack:** PHP 8.1+, Laravel, Qdrant vector database, PHPUnit

---

## Background

### Root Cause Analysis

The current implementation only performs semantic (embedding-based) search:

```
User: "Check bariloche.txt"
  → FileContextProvider.searchRelevantFiles($question)
    → FileSearchService.searchByContent($question)
      → embeddingProvider.embed("Check bariloche.txt")  ← entire query embedded
        → vector similarity against content embeddings
          → filename never matched because it's metadata, not content
```

**Key Files:**
- `src/Services/Context/FileContextProvider.php:53-123` - orchestrates search
- `src/Services/FileSearchService.php:41-106` - calls chunk store
- `src/Services/QdrantChunkStore.php:96-137` - embedding search only
- `file_name` is stored in Qdrant payload (line 78) but never queried

### Solution Design

1. **Extract filenames from query** - Detect patterns like `*.txt`, `report.pdf`, etc.
2. **Search by filename in Qdrant** - Use payload filter on `file_name` field (whole string match)
3. **Combine results** - Merge filename matches (high priority) + semantic matches
4. **Deduplicate** - Remove duplicate file IDs, keep highest relevance

### Scoring & Threshold Strategy

**Filename matches get higher weight:**
| Match Type | Score | Threshold |
|------------|-------|-----------|
| Exact filename match | 1.0 | None (always included) |
| Partial filename match (contains) | 0.85 | 0.3 (low) |
| Content/semantic match | 0.0-1.0 | 0.7 (standard) |

**Rationale:**
- When user says "Check bariloche.txt", finding that file is the PRIMARY intent
- Filename matches bypass the normal `min_relevance_score` threshold
- Partial matches (e.g., "bariloche" finding "bariloche_trip.txt") use a lower threshold

**Whole string matching:**
- Search compares the ENTIRE extracted filename against stored `file_name`
- NOT word tokenization (searching "budget" and "2024" separately)
- Qdrant `text` match searches for substring within the field value

---

## Task 1: Add Filename Extraction Utility

**Files:**
- Create: `src/Services/Context/FilenameExtractor.php`
- Test: `tests/Unit/Services/Context/FilenameExtractorTest.php`

### Step 1.1: Write the failing test

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Context;

use Condoedge\Ai\Services\Context\FilenameExtractor;
use PHPUnit\Framework\TestCase;

class FilenameExtractorTest extends TestCase
{
    private FilenameExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new FilenameExtractor();
    }

    public function test_extracts_filename_with_extension(): void
    {
        $result = $this->extractor->extract('Check the bariloche.txt file');

        $this->assertEquals(['bariloche.txt'], $result);
    }

    public function test_extracts_multiple_filenames(): void
    {
        $result = $this->extractor->extract('Compare report.pdf and data.xlsx');

        $this->assertEqualsCanonicalizing(['report.pdf', 'data.xlsx'], $result);
    }

    public function test_extracts_filename_with_path(): void
    {
        $result = $this->extractor->extract('Look at docs/readme.md');

        $this->assertEquals(['readme.md'], $result);
    }

    public function test_handles_quoted_filenames(): void
    {
        $result = $this->extractor->extract('Open "my document.docx" please');

        $this->assertEquals(['my document.docx'], $result);
    }

    public function test_returns_empty_for_no_filename(): void
    {
        $result = $this->extractor->extract('What are the sales figures?');

        $this->assertEquals([], $result);
    }

    public function test_extracts_common_extensions(): void
    {
        $extensions = ['txt', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'md', 'json', 'xml'];

        foreach ($extensions as $ext) {
            $result = $this->extractor->extract("Check file.{$ext}");
            $this->assertEquals(["file.{$ext}"], $result, "Failed for extension: {$ext}");
        }
    }

    public function test_case_insensitive_extension_matching(): void
    {
        $result = $this->extractor->extract('Open FILE.PDF');

        $this->assertEquals(['FILE.PDF'], $result);
    }

    public function test_ignores_urls(): void
    {
        $result = $this->extractor->extract('Check https://example.com/file.pdf');

        $this->assertEquals([], $result);
    }

    public function test_extracts_filename_from_natural_language(): void
    {
        $queries = [
            'What does the budget_2024.xlsx contain?' => ['budget_2024.xlsx'],
            'Can you summarize notes.txt for me?' => ['notes.txt'],
            'I need info from quarterly-report.pdf' => ['quarterly-report.pdf'],
        ];

        foreach ($queries as $query => $expected) {
            $result = $this->extractor->extract($query);
            $this->assertEquals($expected, $result, "Failed for query: {$query}");
        }
    }
}
```

### Step 1.2: Run test to verify it fails

Run: `vendor/bin/phpunit tests/Unit/Services/Context/FilenameExtractorTest.php -v`
Expected: FAIL with "Class 'FilenameExtractor' not found"

### Step 1.3: Write minimal implementation

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Context;

/**
 * Extracts filenames from natural language queries.
 *
 * Detects explicit file references like "bariloche.txt" or "report.pdf"
 * to enable filename-based search alongside semantic search.
 */
class FilenameExtractor
{
    /**
     * Common file extensions to detect
     */
    private const EXTENSIONS = [
        'txt', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv',
        'md', 'json', 'xml', 'html', 'htm', 'rtf', 'odt',
        'ppt', 'pptx', 'png', 'jpg', 'jpeg', 'gif', 'svg',
    ];

    /**
     * Extract filenames from a query string.
     *
     * @param string $query The user's query
     * @return array List of extracted filenames (without paths)
     */
    public function extract(string $query): array
    {
        // Skip if query looks like a URL
        if ($this->containsUrl($query)) {
            return [];
        }

        $filenames = [];

        // Pattern 1: Quoted filenames (can contain spaces)
        // Matches: "my document.pdf", 'report.xlsx'
        $quotedPattern = '/["\']([^"\']+\.(' . $this->getExtensionPattern() . '))["\']|/i';
        if (preg_match_all($quotedPattern, $query, $matches)) {
            foreach ($matches[1] as $match) {
                if (!empty($match)) {
                    $filenames[] = $match;
                }
            }
        }

        // Pattern 2: Unquoted filenames (word characters, hyphens, underscores)
        // Matches: report.pdf, budget_2024.xlsx, my-notes.txt
        $unquotedPattern = '/(?<![\/:])\b([\w\-_]+\.(' . $this->getExtensionPattern() . '))\b/i';
        if (preg_match_all($unquotedPattern, $query, $matches)) {
            foreach ($matches[1] as $match) {
                if (!empty($match) && !in_array($match, $filenames)) {
                    $filenames[] = $match;
                }
            }
        }

        // Pattern 3: Path with filename - extract just the filename
        // Matches: docs/readme.md -> readme.md
        $pathPattern = '/(?:^|[\/\\\\])([^\/\\\\]+\.(' . $this->getExtensionPattern() . '))\b/i';
        if (preg_match_all($pathPattern, $query, $matches)) {
            foreach ($matches[1] as $match) {
                if (!empty($match) && !in_array($match, $filenames)) {
                    $filenames[] = $match;
                }
            }
        }

        return array_values(array_unique($filenames));
    }

    /**
     * Check if query contains a URL.
     */
    private function containsUrl(string $query): bool
    {
        return (bool) preg_match('/https?:\/\/[^\s]+/i', $query);
    }

    /**
     * Build regex pattern for file extensions.
     */
    private function getExtensionPattern(): string
    {
        return implode('|', self::EXTENSIONS);
    }
}
```

### Step 1.4: Run test to verify it passes

Run: `vendor/bin/phpunit tests/Unit/Services/Context/FilenameExtractorTest.php -v`
Expected: All tests PASS

### Step 1.5: Commit

```bash
git add src/Services/Context/FilenameExtractor.php tests/Unit/Services/Context/FilenameExtractorTest.php
git commit -m "feat(file-context): add FilenameExtractor for query parsing"
```

---

## Task 2: Add searchByFilename to ChunkStoreInterface

**Files:**
- Modify: `src/Contracts/ChunkStoreInterface.php`
- Modify: `src/Services/QdrantChunkStore.php`
- Test: `tests/Unit/Services/QdrantChunkStoreTest.php` (add test)

### Step 2.1: Write the failing test

Add to existing test file or create if doesn't exist:

```php
<?php

// Add to tests/Unit/Services/QdrantChunkStoreTest.php

public function test_search_by_filename_finds_exact_match(): void
{
    // Setup: store a chunk with known filename
    $chunk = new FileChunk(
        fileId: 123,
        fileName: 'bariloche.txt',
        content: 'Some content about travel',
        embedding: array_fill(0, 1536, 0.1),
        chunkIndex: 0,
        totalChunks: 1,
        startPosition: 0,
        endPosition: 100,
        metadata: []
    );
    $this->chunkStore->storeChunk($chunk);

    // Act
    $results = $this->chunkStore->searchByFilename('bariloche.txt', 10);

    // Assert
    $this->assertCount(1, $results);
    $this->assertEquals(123, $results[0]['chunk']->fileId);
    $this->assertEquals('bariloche.txt', $results[0]['chunk']->fileName);
}

public function test_search_by_filename_partial_match(): void
{
    $chunk = new FileChunk(
        fileId: 456,
        fileName: 'quarterly_report_2024.pdf',
        content: 'Financial data',
        embedding: array_fill(0, 1536, 0.1),
        chunkIndex: 0,
        totalChunks: 1,
        startPosition: 0,
        endPosition: 50,
        metadata: []
    );
    $this->chunkStore->storeChunk($chunk);

    // Partial match should work
    $results = $this->chunkStore->searchByFilename('quarterly_report', 10);

    $this->assertCount(1, $results);
    $this->assertEquals(456, $results[0]['chunk']->fileId);
}

public function test_search_by_filename_returns_empty_for_no_match(): void
{
    $results = $this->chunkStore->searchByFilename('nonexistent.xyz', 10);

    $this->assertEmpty($results);
}
```

### Step 2.2: Run test to verify it fails

Run: `vendor/bin/phpunit tests/Unit/Services/QdrantChunkStoreTest.php --filter=searchByFilename -v`
Expected: FAIL with "method searchByFilename does not exist"

### Step 2.3: Add method to interface

Modify `src/Contracts/ChunkStoreInterface.php`:

```php
<?php
// Add this method to the interface after searchByContent:

    /**
     * Search for chunks by filename
     *
     * @param string $filename The filename to search for (exact or partial match)
     * @param int $limit Maximum number of results
     * @param array $filters Optional filters (same as searchByContent)
     * @return array Array of search results with 'chunk' and 'score' keys
     */
    public function searchByFilename(string $filename, int $limit = 10, array $filters = []): array;
```

### Step 2.4: Implement in QdrantChunkStore

Add to `src/Services/QdrantChunkStore.php` after `searchByContent()`:

```php
    /**
     * {@inheritdoc}
     */
    public function searchByFilename(string $filename, int $limit = 10, array $filters = []): array
    {
        // Build filter for filename matching
        $qdrantFilter = $this->buildFilenameFilter($filename, $filters);

        if (empty($qdrantFilter)) {
            return [];
        }

        // Use scroll/filter API instead of vector search
        // We need to search by payload, not by vector similarity
        $results = $this->vectorStore->scroll(
            $this->collection,
            $qdrantFilter,
            $limit
        );

        // Group by file_id and return unique files
        $seenFiles = [];
        $output = [];

        foreach ($results as $result) {
            $payload = $result['payload'] ?? [];
            $fileId = $payload['file_id'] ?? null;

            if ($fileId === null || isset($seenFiles[$fileId])) {
                continue;
            }

            $seenFiles[$fileId] = true;

            $chunk = new FileChunk(
                fileId: $payload['file_id'],
                fileName: $payload['file_name'],
                content: $payload['content'],
                embedding: [],
                chunkIndex: $payload['chunk_index'],
                totalChunks: $payload['total_chunks'],
                startPosition: $payload['start_position'],
                endPosition: $payload['end_position'],
                metadata: $payload['metadata'] ?? []
            );

            // Score based on match quality:
            // - Exact match (case-insensitive): 1.0
            // - Partial match (filename contains search term): 0.85
            $storedFilename = $payload['file_name'];
            $isExactMatch = strcasecmp($storedFilename, $filename) === 0;

            $output[] = [
                'chunk' => $chunk,
                'score' => $isExactMatch ? 1.0 : 0.85,
                'match_type' => $isExactMatch ? 'exact' : 'partial',
            ];

            if (count($output) >= $limit) {
                break;
            }
        }

        // Sort exact matches first
        usort($output, fn($a, $b) => $b['score'] <=> $a['score']);

        return $output;
    }

    /**
     * Build filter for filename search
     *
     * @param string $filename
     * @param array $filters
     * @return array
     */
    private function buildFilenameFilter(string $filename, array $filters): array
    {
        $must = [];

        // For exact match, use match. For partial, use text match with the filename
        // Qdrant text match searches within string values
        $must[] = [
            'key' => 'file_name',
            'match' => ['text' => $filename],
        ];

        // Apply additional filters
        if (isset($filters['file_id'])) {
            $must[] = [
                'key' => 'file_id',
                'match' => ['value' => $filters['file_id']],
            ];
        }

        return empty($must) ? [] : ['must' => $must];
    }
```

### Step 2.5: Add scroll method to VectorStoreInterface if missing

Check if `scroll()` exists in `src/Contracts/VectorStoreInterface.php`. If not, add:

```php
    /**
     * Scroll through points matching a filter (no vector search)
     *
     * @param string $collection Collection name
     * @param array $filter Filter conditions
     * @param int $limit Maximum results
     * @return array Points matching the filter
     */
    public function scroll(string $collection, array $filter, int $limit = 10): array;
```

And implement in `src/Services/Qdrant/QdrantVectorStore.php`.

### Step 2.6: Run test to verify it passes

Run: `vendor/bin/phpunit tests/Unit/Services/QdrantChunkStoreTest.php --filter=searchByFilename -v`
Expected: All filename search tests PASS

### Step 2.7: Commit

```bash
git add src/Contracts/ChunkStoreInterface.php src/Services/QdrantChunkStore.php tests/Unit/Services/QdrantChunkStoreTest.php
git commit -m "feat(chunk-store): add searchByFilename method for explicit file lookups"
```

---

## Task 3: Add searchByFilename to FileSearchService

**Files:**
- Modify: `src/Services/FileSearchService.php`
- Test: `tests/Unit/Services/FileSearchServiceTest.php`

### Step 3.1: Write the failing test

```php
<?php

// Add to tests/Unit/Services/FileSearchServiceTest.php

public function test_search_by_filename_returns_matching_files(): void
{
    // Mock chunk store
    $this->chunkStore->expects($this->once())
        ->method('searchByFilename')
        ->with('report.pdf', 10, [])
        ->willReturn([
            [
                'chunk' => new FileChunk(
                    fileId: 1,
                    fileName: 'report.pdf',
                    content: 'Content',
                    embedding: [],
                    chunkIndex: 0,
                    totalChunks: 1,
                    startPosition: 0,
                    endPosition: 100,
                    metadata: []
                ),
                'score' => 1.0,
            ],
        ]);

    $results = $this->searchService->searchByFilename('report.pdf');

    $this->assertCount(1, $results);
    $this->assertEquals(1, $results[0]['file_id']);
    $this->assertEquals(1.0, $results[0]['score']);
}
```

### Step 3.2: Run test to verify it fails

Run: `vendor/bin/phpunit tests/Unit/Services/FileSearchServiceTest.php --filter=searchByFilename -v`
Expected: FAIL with "method searchByFilename does not exist"

### Step 3.3: Implement searchByFilename in FileSearchService

Add to `src/Services/FileSearchService.php`:

```php
    /**
     * Search files by filename
     *
     * @param string $filename The filename to search for
     * @param array $options Options:
     *   - limit: Maximum results (default: 10)
     *   - include_relationships: Load Neo4j relationships (default: false)
     * @return array
     */
    public function searchByFilename(string $filename, array $options = []): array
    {
        $limit = $options['limit'] ?? 10;
        $includeRelationships = $options['include_relationships'] ?? false;

        // Search chunk store by filename
        $chunks = $this->chunkStore->searchByFilename($filename, $limit * 2);

        if (empty($chunks)) {
            return [];
        }

        // Group by file ID (chunks already deduplicated, but ensure uniqueness)
        $fileResults = [];
        foreach ($chunks as $result) {
            $fileId = $result['chunk']->fileId;

            if (!isset($fileResults[$fileId])) {
                $fileResults[$fileId] = [
                    'file_id' => $fileId,
                    'score' => $result['score'],
                    'best_chunk' => $result['chunk'],
                    'chunk_count' => 1,
                    'chunks' => [$result['chunk']],
                ];
            }
        }

        $results = array_values($fileResults);

        // Sort by score descending and limit
        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);
        $results = array_slice($results, 0, $limit);

        // Load File models
        $fileIds = array_column($results, 'file_id');
        $files = FileModel::whereIn('id', $fileIds)->get()->keyBy('id');

        // Enhance with File models
        foreach ($results as &$result) {
            $file = $files->get($result['file_id']);
            if ($file) {
                $result['file'] = $file;

                if ($includeRelationships) {
                    $result['relationships'] = $this->getFileRelationships($file);
                }
            }
        }

        return $results;
    }
```

### Step 3.4: Run test to verify it passes

Run: `vendor/bin/phpunit tests/Unit/Services/FileSearchServiceTest.php --filter=searchByFilename -v`
Expected: PASS

### Step 3.5: Commit

```bash
git add src/Services/FileSearchService.php tests/Unit/Services/FileSearchServiceTest.php
git commit -m "feat(file-search): add searchByFilename method to FileSearchService"
```

---

## Task 4: Update FileContextProvider to use combined search

**Files:**
- Modify: `src/Services/Context/FileContextProvider.php`
- Test: `tests/Unit/Services/Context/FileContextProviderTest.php`

### Step 4.1: Write the failing test

```php
<?php

// Add to tests/Unit/Services/Context/FileContextProviderTest.php

public function test_search_finds_file_by_explicit_filename_reference(): void
{
    // User explicitly mentions "bariloche.txt"
    $question = 'Check the bariloche.txt file';

    // Mock: filename search returns the file
    $this->searchService->expects($this->once())
        ->method('searchByFilename')
        ->with('bariloche.txt', $this->anything())
        ->willReturn([
            [
                'file_id' => 42,
                'score' => 1.0,
                'best_chunk' => new FileChunk(
                    fileId: 42,
                    fileName: 'bariloche.txt',
                    content: 'Travel notes for Bariloche trip',
                    embedding: [],
                    chunkIndex: 0,
                    totalChunks: 1,
                    startPosition: 0,
                    endPosition: 100,
                    metadata: []
                ),
                'chunk_count' => 1,
                'chunks' => [],
            ],
        ]);

    // Content search may or may not find it (doesn't matter for this test)
    $this->searchService->expects($this->once())
        ->method('searchByContent')
        ->willReturn([]);

    $results = $this->provider->searchRelevantFiles($question, $this->mockUser);

    $this->assertCount(1, $results);
    $this->assertEquals(42, $results[0]['file_id']);
    $this->assertEquals('bariloche.txt', $results[0]['filename']);
}

public function test_search_combines_filename_and_content_results(): void
{
    $question = 'What does report.pdf say about sales?';

    // Filename search finds report.pdf
    $this->searchService->expects($this->once())
        ->method('searchByFilename')
        ->willReturn([
            [
                'file_id' => 1,
                'score' => 1.0,
                'best_chunk' => new FileChunk(1, 'report.pdf', 'Sales data', [], 0, 1, 0, 50, []),
                'chunk_count' => 1,
                'chunks' => [],
            ],
        ]);

    // Content search finds a different relevant file
    $this->searchService->expects($this->once())
        ->method('searchByContent')
        ->willReturn([
            [
                'file_id' => 2,
                'score' => 0.85,
                'best_chunk' => new FileChunk(2, 'sales_summary.xlsx', 'Sales figures', [], 0, 1, 0, 50, []),
                'chunk_count' => 1,
                'chunks' => [],
            ],
        ]);

    $results = $this->provider->searchRelevantFiles($question, $this->mockUser);

    // Should have both files, filename match first
    $this->assertCount(2, $results);
    $this->assertEquals(1, $results[0]['file_id']); // Filename match prioritized
    $this->assertEquals(2, $results[1]['file_id']); // Content match second
}

public function test_search_deduplicates_when_same_file_in_both_results(): void
{
    $question = 'Summarize report.pdf';

    $sharedChunk = new FileChunk(1, 'report.pdf', 'Content', [], 0, 1, 0, 50, []);

    // Both searches return the same file
    $this->searchService->method('searchByFilename')->willReturn([
        ['file_id' => 1, 'score' => 1.0, 'best_chunk' => $sharedChunk, 'chunk_count' => 1, 'chunks' => []],
    ]);
    $this->searchService->method('searchByContent')->willReturn([
        ['file_id' => 1, 'score' => 0.9, 'best_chunk' => $sharedChunk, 'chunk_count' => 1, 'chunks' => []],
    ]);

    $results = $this->provider->searchRelevantFiles($question, $this->mockUser);

    // Should only have one entry, with the higher score
    $this->assertCount(1, $results);
    $this->assertEquals(1, $results[0]['file_id']);
    $this->assertEquals(1.0, $results[0]['relevance']); // Filename match score preserved
}

public function test_partial_filename_match_uses_lower_threshold(): void
{
    // User mentions partial filename - should match with lower threshold
    $question = 'Look at the bariloche file';

    // Partial match returns score 0.85 (below standard 0.7 wouldn't matter,
    // but partial matches have their own low threshold of 0.3)
    $this->searchService->method('searchByFilename')->willReturn([
        [
            'file_id' => 42,
            'score' => 0.85, // Partial match score
            'best_chunk' => new FileChunk(42, 'bariloche_trip_notes.txt', 'Content', [], 0, 1, 0, 50, []),
            'chunk_count' => 1,
            'chunks' => [],
        ],
    ]);
    $this->searchService->method('searchByContent')->willReturn([]);

    $results = $this->provider->searchRelevantFiles($question, $this->mockUser);

    // Partial filename match (0.85) should be included
    $this->assertCount(1, $results);
    $this->assertEquals(42, $results[0]['file_id']);
    $this->assertEquals(0.85, $results[0]['relevance']);
    $this->assertEquals('filename', $results[0]['match_type']);
}

public function test_exact_filename_match_always_included(): void
{
    // Even with a hypothetically low score, exact match should be included
    $question = 'Open report.pdf';

    $this->searchService->method('searchByFilename')->willReturn([
        [
            'file_id' => 1,
            'score' => 1.0, // Exact match always gets 1.0
            'best_chunk' => new FileChunk(1, 'report.pdf', 'Content', [], 0, 1, 0, 50, []),
            'chunk_count' => 1,
            'chunks' => [],
        ],
    ]);
    $this->searchService->method('searchByContent')->willReturn([]);

    $results = $this->provider->searchRelevantFiles($question, $this->mockUser);

    $this->assertCount(1, $results);
    $this->assertEquals(1.0, $results[0]['relevance']);
}
```

### Step 4.2: Run test to verify it fails

Run: `vendor/bin/phpunit tests/Unit/Services/Context/FileContextProviderTest.php --filter=filename -v`
Expected: FAIL - current implementation doesn't call searchByFilename

### Step 4.3: Update FileContextProvider constructor

Modify `src/Services/Context/FileContextProvider.php`:

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Context;

use Condoedge\Ai\Contracts\FileAccessResolverInterface;
use Condoedge\Ai\Services\FileSearchService;

class FileContextProvider
{
    /**
     * Threshold for partial filename matches (lower than semantic)
     * Partial matches (e.g., "bariloche" finding "bariloche_trip.txt") use this threshold
     */
    private const FILENAME_PARTIAL_THRESHOLD = 0.3;

    /**
     * Filename extractor for detecting explicit file references
     */
    private readonly FilenameExtractor $filenameExtractor;

    public function __construct(
        private readonly FileSearchService $searchService,
        private readonly FileAccessResolverInterface $accessResolver,
        ?FilenameExtractor $filenameExtractor = null
    ) {
        $this->filenameExtractor = $filenameExtractor ?? new FilenameExtractor();
    }
```

### Step 4.4: Rewrite searchRelevantFiles method

Replace the `searchRelevantFiles` method in `src/Services/Context/FileContextProvider.php`:

```php
    /**
     * Search for relevant files based on a question
     *
     * Searches across physical documentation and database file collections,
     * filters by access control, and returns standardized file references.
     *
     * Search strategy:
     * 1. Extract explicit filename references from query
     * 2. Search by filename if references found (high priority)
     * 3. Search by content similarity (semantic search)
     * 4. Combine and deduplicate results, prioritizing filename matches
     *
     * @param string $question The search query/question
     * @param mixed $user The user to check access for
     * @param array $options Additional search options
     * @return array Array of file references with standardized format
     */
    public function searchRelevantFiles(string $question, mixed $user, array $options = []): array
    {
        $this->validateUserForSecurity($user);

        $minScore = $options['min_score'] ?? config('ai.file_context.min_relevance_score', 0.7);
        $maxReferences = $options['limit'] ?? config('ai.file_context.max_references', 5);
        $snippetLength = config('ai.file_context.snippet_length', 200);

        // Step 1: Extract explicit filename references
        $extractedFilenames = $this->filenameExtractor->extract($question);

        // Step 2: Search by filename (if references found)
        $filenameResults = [];
        foreach ($extractedFilenames as $filename) {
            $results = $this->searchService->searchByFilename($filename, [
                'limit' => $maxReferences,
                'include_relationships' => false,
            ]);
            $filenameResults = array_merge($filenameResults, $results);
        }

        // Step 3: Search by content (semantic search)
        $contentResults = $this->searchService->searchByContent($question, [
            'limit' => $maxReferences * 3,
            'include_relationships' => false,
        ]);

        // Step 4: Combine results, filename matches first
        $combinedResults = $this->combineSearchResults($filenameResults, $contentResults);

        if (empty($combinedResults)) {
            return [];
        }

        // Step 5: Apply access control
        $fileIds = array_column($combinedResults, 'file_id');
        $accessibleFileIds = $this->accessResolver->filterAccessibleFileIds($fileIds, $user);

        // Step 6: Filter by access and apply score thresholds
        // Thresholds differ by match type:
        // - Exact filename match (score=1.0): No threshold (always included)
        // - Partial filename match (score=0.85): Low threshold (0.3)
        // - Content/semantic match: Standard threshold (0.7)
        $filteredResults = [];
        foreach ($combinedResults as $result) {
            $fileId = $result['file_id'];

            if (!in_array($fileId, $accessibleFileIds, false)) {
                continue;
            }

            $isFilenameMatch = $result['match_type'] === 'filename';
            $score = $result['score'];

            if ($isFilenameMatch) {
                // Exact filename match (1.0) always included
                // Partial filename match (0.85) uses lower threshold
                $isExactMatch = $score >= 1.0;
                if (!$isExactMatch && $score < self::FILENAME_PARTIAL_THRESHOLD) {
                    continue;
                }
            } else {
                // Content match uses standard threshold
                if ($score < $minScore) {
                    continue;
                }
            }

            $filteredResults[] = $result;
        }

        // Sort by score descending
        usort($filteredResults, fn($a, $b) => $b['score'] <=> $a['score']);

        // Limit results
        $filteredResults = array_slice($filteredResults, 0, $maxReferences);

        // Transform to standard format
        return array_map(function ($result) use ($snippetLength) {
            $chunk = $result['best_chunk'];
            $fileId = $result['file_id'];
            $source = $this->accessResolver->isPhysicalFile($fileId) ? 'physical' : 'database';

            $content = $chunk->content;
            $snippet = $this->truncateSnippet($content, $snippetLength);

            return [
                'file_id' => $fileId,
                'filename' => $chunk->fileName,
                'snippet' => $snippet,
                'relevance' => $result['score'],
                'chunk_index' => $chunk->chunkIndex,
                'source' => $source,
                'match_type' => $result['match_type'] ?? 'content',
            ];
        }, $filteredResults);
    }

    /**
     * Combine filename and content search results.
     *
     * Deduplicates by file_id, keeping the higher score.
     * Filename matches get priority (score boost doesn't change, but match_type preserved).
     *
     * @param array $filenameResults Results from filename search
     * @param array $contentResults Results from content search
     * @return array Combined results
     */
    private function combineSearchResults(array $filenameResults, array $contentResults): array
    {
        $combined = [];

        // Add filename results first (they have priority)
        foreach ($filenameResults as $result) {
            $fileId = $result['file_id'];
            $combined[$fileId] = array_merge($result, ['match_type' => 'filename']);
        }

        // Add content results, but don't override filename matches
        foreach ($contentResults as $result) {
            $fileId = $result['file_id'];

            if (!isset($combined[$fileId])) {
                $combined[$fileId] = array_merge($result, ['match_type' => 'content']);
            }
            // If already exists from filename match, keep that (higher priority)
        }

        return array_values($combined);
    }
```

### Step 4.5: Run tests to verify they pass

Run: `vendor/bin/phpunit tests/Unit/Services/Context/FileContextProviderTest.php -v`
Expected: All tests PASS

### Step 4.6: Commit

```bash
git add src/Services/Context/FileContextProvider.php tests/Unit/Services/Context/FileContextProviderTest.php
git commit -m "feat(file-context): combine filename and content search for better file discovery"
```

---

## Task 5: Integration Test

**Files:**
- Modify: `tests/Feature/FileContextIntegrationTest.php`

### Step 5.1: Write integration test

```php
<?php

// Add to tests/Feature/FileContextIntegrationTest.php

public function test_explicit_filename_reference_finds_file(): void
{
    // Setup: Create and index a file
    $file = File::factory()->create(['name' => 'bariloche_trip.txt']);
    $this->processFile($file, 'Notes about my trip to Bariloche, Argentina');

    // Act: Query with explicit filename
    $provider = app(FileContextProvider::class);
    $results = $provider->searchRelevantFiles(
        'What is in the bariloche_trip.txt file?',
        $this->user
    );

    // Assert
    $this->assertNotEmpty($results);
    $this->assertEquals('bariloche_trip.txt', $results[0]['filename']);
    $this->assertEquals('filename', $results[0]['match_type']);
}

public function test_semantic_search_still_works_without_filename(): void
{
    // Setup
    $file = File::factory()->create(['name' => 'vacation_notes.txt']);
    $this->processFile($file, 'Beautiful mountains and lakes in Patagonia region');

    // Act: Query without explicit filename but with relevant content
    $provider = app(FileContextProvider::class);
    $results = $provider->searchRelevantFiles(
        'Tell me about the Patagonia trip',
        $this->user
    );

    // Assert: Should find via content similarity
    $this->assertNotEmpty($results);
    $this->assertEquals('vacation_notes.txt', $results[0]['filename']);
    $this->assertEquals('content', $results[0]['match_type']);
}
```

### Step 5.2: Run integration tests

Run: `vendor/bin/phpunit tests/Feature/FileContextIntegrationTest.php -v`
Expected: All integration tests PASS

### Step 5.3: Commit

```bash
git add tests/Feature/FileContextIntegrationTest.php
git commit -m "test(file-context): add integration tests for filename search"
```

---

## Task 6: Update Documentation

**Files:**
- Modify: `resources/docs/1.0/chat/file-context-system.md`

### Step 6.1: Add section about filename search

Add to the documentation:

```markdown
## Search Strategies

### Filename Search

When users explicitly reference a file by name, the system detects and prioritizes that file:

```
User: "What's in the budget_2024.xlsx file?"
→ Detects "budget_2024.xlsx" as explicit reference
→ Searches by filename (exact/partial match)
→ Returns file with match_type: "filename"
```

**Detected patterns:**
- `filename.ext` - Direct filename reference
- `"my document.pdf"` - Quoted filename (supports spaces)
- `path/to/file.txt` - Path reference (extracts filename)

**Supported extensions:** txt, pdf, doc, docx, xls, xlsx, csv, md, json, xml, html, rtf, ppt, pptx, png, jpg, gif, svg

### Content Search

Semantic similarity search using embeddings:

```
User: "What are the Q3 sales figures?"
→ No explicit filename detected
→ Searches by content similarity
→ Returns files with relevant content
```

### Combined Search

When a filename is mentioned along with a question, both strategies are used:

```
User: "Summarize the key points from report.pdf"
→ Filename search finds "report.pdf" (score: 1.0)
→ Content search may find additional relevant files
→ Results deduplicated, filename match prioritized
```
```

### Step 6.2: Commit

```bash
git add resources/docs/1.0/chat/file-context-system.md
git commit -m "docs: document filename search feature in file-context-system.md"
```

---

## Summary

| Task | Description | Files Changed |
|------|-------------|---------------|
| 1 | FilenameExtractor utility | +2 files |
| 2 | ChunkStore searchByFilename | ~3 files |
| 3 | FileSearchService searchByFilename | ~2 files |
| 4 | FileContextProvider combined search | ~2 files |
| 5 | Integration tests | ~1 file |
| 6 | Documentation | ~1 file |

**Total commits:** 6

**Estimated testing:**
- Unit tests: ~15 new tests
- Integration tests: ~2 new tests
