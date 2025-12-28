# File Context System Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Enable the AI to reference files in responses with inline citations, supporting both physical documentation files and database files with access control.

**Architecture:** A unified FileContextProvider handles two modes: (1) Physical files indexed from glob patterns for documentation projects, (2) Database files with security via extendable access resolver. File references appear as inline citations [1][2] in AI responses, with full metadata in response data for frontend rendering. Integrates with existing AccessLevelResolver and conversation context tracking.

**Tech Stack:** PHP 8.2+, Laravel, PHPUnit, Qdrant (existing), existing FileProcessor/SemanticChunker infrastructure

---

## Background

### Current State
- SemanticChunker, QdrantChunkStore, FileProcessor exist and work
- FileSearchService provides semantic search over file chunks
- AccessLevelResolver handles entity-level security with multi-level tags
- No file reference tracking in AI responses
- No physical file (docs) indexing mode
- File access checks not integrated with chat

### What We're Building
```
User: "How do I configure authentication?"
AI: "You can configure authentication by setting up the auth middleware [1].
     The guard system handles sessions [2]."

Response Data:
{
  "answer": "...",
  "referenced_files": [
    {"ref": 1, "id": "physical:docs/auth.mdx", "name": "auth.mdx", "snippet": "...", "download_url": null},
    {"ref": 2, "id": 45, "name": "guards.md", "snippet": "...", "download_url": "/files/45/download", "can_download": true}
  ]
}
```

### Two Operating Modes

**Physical Mode (Documentation):**
- Files defined via glob patterns in config
- No database File model - path-based IDs
- No security checks (you configure what's available)
- Indexed via `ai:ingest --docs`

**Database Mode (Dynamic Files):**
- Files from Eloquent File model
- Security via `getAccessibleFileIds()` method
- Full access control with AccessLevelResolver
- Already indexed via existing FileProcessor

---

## Task 1: Add File Context Configuration

**Files:**
- Modify: `config/ai.php`
- Test: `tests/Unit/Config/FileContextConfigTest.php`

**Step 1: Write the failing test**

```php
<?php

namespace Condoedge\Ai\Tests\Unit\Config;

use Condoedge\Ai\Tests\TestCase;

class FileContextConfigTest extends TestCase
{
    /** @test */
    public function it_has_file_context_config_section(): void
    {
        $config = config('ai.file_context');

        $this->assertIsArray($config);
        $this->assertArrayHasKey('enabled', $config);
        $this->assertArrayHasKey('security_enabled', $config);
        $this->assertArrayHasKey('physical_paths', $config);
        $this->assertArrayHasKey('supported_extensions', $config);
    }

    /** @test */
    public function physical_paths_supports_glob_patterns(): void
    {
        config(['ai.file_context.physical_paths' => [
            'docs/**/*.mdx',
            'guides/*.md',
        ]]);

        $paths = config('ai.file_context.physical_paths');

        $this->assertCount(2, $paths);
        $this->assertContains('docs/**/*.mdx', $paths);
    }

    /** @test */
    public function security_can_be_disabled_globally(): void
    {
        config(['ai.file_context.security_enabled' => false]);

        $this->assertFalse(config('ai.file_context.security_enabled'));
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Config/FileContextConfigTest.php`
Expected: FAIL with "config key not found"

**Step 3: Add configuration to config/ai.php**

Add this section after `'file_processing'` (around line 150):

```php
/*
|--------------------------------------------------------------------------
| File Context Configuration
|--------------------------------------------------------------------------
|
| Configure how files are used as context for AI responses.
| Supports two modes: physical files (docs) and database files.
|
*/

'file_context' => [
    // Enable file context in AI responses
    'enabled' => env('AI_FILE_CONTEXT_ENABLED', true),

    // Security mode for database files (can be overridden via boot method)
    'security_enabled' => env('AI_FILE_SECURITY_ENABLED', true),

    // Physical file paths (glob patterns) - no security, you define what's available
    // These are indexed via `ai:ingest --docs`
    'physical_paths' => [
        // 'docs/**/*.mdx',
        // 'resources/docs/**/*.md',
    ],

    // Supported extensions for physical files
    'supported_extensions' => ['md', 'mdx', 'txt', 'rst'],

    // Base path for physical files (relative to project root)
    'base_path' => env('AI_DOCS_BASE_PATH', base_path()),

    // Collection name for physical file chunks in Qdrant
    'physical_collection' => 'documentation_chunks',

    // Maximum file references to include in response
    'max_references' => 5,

    // Minimum relevance score for file inclusion
    'min_relevance_score' => 0.7,

    // Include file snippets in response metadata
    'include_snippets' => true,

    // Maximum snippet length (characters)
    'snippet_length' => 200,

    /*
    |--------------------------------------------------------------------------
    | Database File Access Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how database files are accessed and filtered.
    | Single entry point: specify your File model and the scope to use.
    |
    */

    // The Eloquent File model class (your app's File model)
    'file_model' => env('AI_FILE_MODEL', 'App\\Models\\File'),

    // The scope method to call for user-accessible files
    // Will be called as: File::accessibleBy($user)->pluck('id')
    // Set to null to allow all files (when security_enabled is true but no scope)
    'access_scope' => 'accessibleBy',

    // Alternative: closure-based resolver (takes precedence over scope)
    // Set in boot method: config(['ai.file_context.access_resolver' => fn($user) => [...]])
    'access_resolver' => null,
],
```

**Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Unit/Config/FileContextConfigTest.php`
Expected: PASS (3 tests)

**Step 5: Commit**

```bash
git add config/ai.php tests/Unit/Config/FileContextConfigTest.php
git commit -m "feat(config): add file context configuration section"
```

---

## Task 2: Create FileAccessResolver Service

**Files:**
- Create: `src/Services/Context/FileAccessResolver.php`
- Create: `src/Contracts/FileAccessResolverInterface.php`
- Test: `tests/Unit/Services/Context/FileAccessResolverTest.php`

**Step 1: Write the failing test**

```php
<?php

namespace Condoedge\Ai\Tests\Unit\Services\Context;

use Condoedge\Ai\Services\Context\FileAccessResolver;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Foundation\Auth\User;

class FileAccessResolverTest extends TestCase
{
    private FileAccessResolver $resolver;

    public function setUp(): void
    {
        parent::setUp();
        $this->resolver = new FileAccessResolver();
    }

    /** @test */
    public function it_returns_true_when_security_disabled(): void
    {
        config(['ai.file_context.security_enabled' => false]);

        $result = $this->resolver->shouldEnforceSecurity();

        $this->assertFalse($result);
    }

    /** @test */
    public function it_returns_security_config_by_default(): void
    {
        config(['ai.file_context.security_enabled' => true]);

        $result = $this->resolver->shouldEnforceSecurity();

        $this->assertTrue($result);
    }

    /** @test */
    public function it_returns_all_file_ids_when_security_disabled(): void
    {
        config(['ai.file_context.security_enabled' => false]);

        $requestedIds = [1, 2, 3, 4, 5];
        $result = $this->resolver->filterAccessibleFileIds($requestedIds, null);

        $this->assertEquals($requestedIds, $result);
    }

    /** @test */
    public function it_uses_config_closure_resolver_when_set(): void
    {
        config([
            'ai.file_context.security_enabled' => true,
            'ai.file_context.access_resolver' => fn($user) => [1, 3, 5],
        ]);

        $requestedIds = [1, 2, 3, 4, 5];
        $result = $this->resolver->filterAccessibleFileIds($requestedIds, (object)['id' => 1]);

        $this->assertEquals([1, 3, 5], $result);
    }

    /** @test */
    public function it_returns_empty_when_security_enabled_and_no_user(): void
    {
        config([
            'ai.file_context.security_enabled' => true,
            'ai.file_context.access_resolver' => null,
            'ai.file_context.access_scope' => 'accessibleBy',
        ]);

        // Without user, should return empty when security enabled with scope
        $requestedIds = [1, 2, 3];
        $result = $this->resolver->filterAccessibleFileIds($requestedIds, null);

        $this->assertEmpty($result);
    }

    /** @test */
    public function physical_files_bypass_security(): void
    {
        config(['ai.file_context.security_enabled' => true]);

        // Physical file IDs start with "physical:"
        $fileIds = ['physical:docs/auth.mdx', 'physical:docs/guide.md'];
        $result = $this->resolver->filterAccessibleFileIds($fileIds, null);

        $this->assertEquals($fileIds, $result);
    }

    /** @test */
    public function it_provides_extendable_get_accessible_file_ids(): void
    {
        $this->assertTrue(method_exists($this->resolver, 'getAccessibleFileIds'));
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Services/Context/FileAccessResolverTest.php`
Expected: FAIL with "Class FileAccessResolver not found"

**Step 3: Create the interface**

```php
<?php
// src/Contracts/FileAccessResolverInterface.php

declare(strict_types=1);

namespace Condoedge\Ai\Contracts;

interface FileAccessResolverInterface
{
    /**
     * Determine if security checks should be enforced.
     * Override this method for custom logic.
     */
    public function shouldEnforceSecurity(): bool;

    /**
     * Get file IDs the user can access.
     * Override this method to implement your access logic.
     *
     * @param mixed $user The authenticated user (or null)
     * @return array<int|string> Array of accessible file IDs
     */
    public function getAccessibleFileIds(mixed $user): array;

    /**
     * Filter a list of file IDs to only those the user can access.
     *
     * @param array<int|string> $fileIds File IDs to filter
     * @param mixed $user The authenticated user (or null)
     * @return array<int|string> Filtered file IDs
     */
    public function filterAccessibleFileIds(array $fileIds, mixed $user): array;

    /**
     * Check if a specific file is accessible.
     *
     * @param int|string $fileId The file ID to check
     * @param mixed $user The authenticated user (or null)
     */
    public function canAccessFile(int|string $fileId, mixed $user): bool;
}
```

**Step 4: Create the implementation**

```php
<?php
// src/Services/Context/FileAccessResolver.php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Context;

use Condoedge\Ai\Contracts\FileAccessResolverInterface;

/**
 * FileAccessResolver
 *
 * Determines which files a user can access. Integrates with the existing
 * AccessLevelResolver pattern and provides extendable methods for custom logic.
 *
 * Physical files (prefixed with "physical:") always bypass security.
 * Database files respect the security_enabled config and getAccessibleFileIds().
 *
 * To customize access logic, extend this class and override:
 * - shouldEnforceSecurity() - for custom security toggle logic
 * - getAccessibleFileIds() - for custom file access resolution
 */
class FileAccessResolver implements FileAccessResolverInterface
{
    /**
     * Prefix for physical file IDs
     */
    public const PHYSICAL_PREFIX = 'physical:';

    /**
     * Determine if security checks should be enforced.
     * Override this method for custom logic (e.g., admin bypass, environment check).
     */
    public function shouldEnforceSecurity(): bool
    {
        return config('ai.file_context.security_enabled', true);
    }

    /**
     * Get file IDs the user can access.
     *
     * Uses config to determine File model and access scope.
     * Priority:
     * 1. Config closure (access_resolver) if set
     * 2. File model scope (access_scope) if set
     * 3. All files if no scope configured
     *
     * Override this method in your app's service provider for custom logic.
     */
    public function getAccessibleFileIds(mixed $user): array
    {
        // Priority 1: Closure-based resolver from config/boot
        $resolver = config('ai.file_context.access_resolver');
        if ($resolver && is_callable($resolver)) {
            return $resolver($user);
        }

        // Priority 2: Model scope from config
        $modelClass = config('ai.file_context.file_model');
        $scopeName = config('ai.file_context.access_scope');

        if (!$modelClass || !class_exists($modelClass)) {
            return [];
        }

        // If no scope configured, return all file IDs (security relies on config flag)
        if (!$scopeName) {
            return $modelClass::pluck('id')->toArray();
        }

        // User required for scoped access
        if (!$user) {
            return [];
        }

        // Call the configured scope: File::accessibleBy($user)->pluck('id')
        try {
            return $modelClass::$scopeName($user)->pluck('id')->toArray();
        } catch (\Throwable $e) {
            \Log::warning("File access scope '{$scopeName}' failed: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Filter a list of file IDs to only those the user can access.
     */
    public function filterAccessibleFileIds(array $fileIds, mixed $user): array
    {
        if (empty($fileIds)) {
            return [];
        }

        // Separate physical and database file IDs
        $physicalIds = [];
        $databaseIds = [];

        foreach ($fileIds as $fileId) {
            if ($this->isPhysicalFile($fileId)) {
                $physicalIds[] = $fileId;
            } else {
                $databaseIds[] = $fileId;
            }
        }

        // Physical files always pass through (security is via config, not runtime)
        $accessible = $physicalIds;

        // Database files respect security settings
        if (!empty($databaseIds)) {
            if (!$this->shouldEnforceSecurity()) {
                // Security disabled - all database files accessible
                $accessible = array_merge($accessible, $databaseIds);
            } else {
                // Security enabled - filter by accessible IDs
                $allowedIds = $this->getAccessibleFileIds($user);
                $filteredDbIds = array_intersect($databaseIds, $allowedIds);
                $accessible = array_merge($accessible, $filteredDbIds);
            }
        }

        return array_values($accessible);
    }

    /**
     * Check if a specific file is accessible.
     */
    public function canAccessFile(int|string $fileId, mixed $user): bool
    {
        $result = $this->filterAccessibleFileIds([$fileId], $user);
        return !empty($result);
    }

    /**
     * Check if a file ID represents a physical file.
     */
    public function isPhysicalFile(int|string $fileId): bool
    {
        return is_string($fileId) && str_starts_with($fileId, self::PHYSICAL_PREFIX);
    }

    /**
     * Generate a physical file ID from a path.
     */
    public function makePhysicalFileId(string $path): string
    {
        return self::PHYSICAL_PREFIX . $path;
    }

    /**
     * Extract the path from a physical file ID.
     */
    public function getPhysicalFilePath(string $physicalFileId): ?string
    {
        if (!$this->isPhysicalFile($physicalFileId)) {
            return null;
        }

        return substr($physicalFileId, strlen(self::PHYSICAL_PREFIX));
    }
}
```

**Step 5: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Unit/Services/Context/FileAccessResolverTest.php`
Expected: PASS (6 tests)

**Step 6: Commit**

```bash
git add src/Contracts/FileAccessResolverInterface.php
git add src/Services/Context/FileAccessResolver.php
git add tests/Unit/Services/Context/FileAccessResolverTest.php
git commit -m "feat(context): add FileAccessResolver with extendable security"
```

---

## Task 3: Create PhysicalFileIndexer Service

**Files:**
- Create: `src/Services/Files/PhysicalFileIndexer.php`
- Test: `tests/Unit/Services/Files/PhysicalFileIndexerTest.php`

**Step 1: Write the failing test**

```php
<?php

namespace Condoedge\Ai\Tests\Unit\Services\Files;

use Condoedge\Ai\Services\Files\PhysicalFileIndexer;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Support\Facades\File;

class PhysicalFileIndexerTest extends TestCase
{
    private PhysicalFileIndexer $indexer;
    private string $tempDir;

    public function setUp(): void
    {
        parent::setUp();
        $this->indexer = app(PhysicalFileIndexer::class);

        // Create temp directory with test files
        $this->tempDir = sys_get_temp_dir() . '/ai_test_docs_' . uniqid();
        File::makeDirectory($this->tempDir, 0755, true);
        File::makeDirectory($this->tempDir . '/guides', 0755, true);

        File::put($this->tempDir . '/auth.md', '# Authentication Guide\n\nHow to configure auth.');
        File::put($this->tempDir . '/guides/intro.md', '# Introduction\n\nWelcome to the docs.');
    }

    public function tearDown(): void
    {
        File::deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    /** @test */
    public function it_discovers_files_from_glob_patterns(): void
    {
        config(['ai.file_context.base_path' => $this->tempDir]);

        $files = $this->indexer->discoverFiles(['**/*.md']);

        $this->assertCount(2, $files);
    }

    /** @test */
    public function it_generates_physical_file_ids(): void
    {
        $fileId = $this->indexer->generateFileId('/path/to/auth.md');

        $this->assertStringStartsWith('physical:', $fileId);
        $this->assertStringContainsString('auth.md', $fileId);
    }

    /** @test */
    public function it_creates_file_object_for_indexing(): void
    {
        config(['ai.file_context.base_path' => $this->tempDir]);

        $fileObject = $this->indexer->createFileObject($this->tempDir . '/auth.md');

        $this->assertIsObject($fileObject);
        $this->assertEquals('auth.md', $fileObject->name);
        $this->assertStringStartsWith('physical:', $fileObject->id);
    }

    /** @test */
    public function it_respects_supported_extensions(): void
    {
        File::put($this->tempDir . '/script.js', 'console.log("test");');

        config([
            'ai.file_context.base_path' => $this->tempDir,
            'ai.file_context.supported_extensions' => ['md'],
        ]);

        $files = $this->indexer->discoverFiles(['**/*']);

        // Should only find .md files, not .js
        foreach ($files as $file) {
            $this->assertStringEndsWith('.md', $file);
        }
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Services/Files/PhysicalFileIndexerTest.php`
Expected: FAIL with "Class PhysicalFileIndexer not found"

**Step 3: Create the implementation**

```php
<?php
// src/Services/Files/PhysicalFileIndexer.php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Files;

use Condoedge\Ai\Services\Context\FileAccessResolver;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;

/**
 * PhysicalFileIndexer
 *
 * Discovers and prepares physical files (documentation) for indexing.
 * Works with glob patterns defined in config to find files.
 * Creates file objects compatible with the existing FileProcessor.
 */
class PhysicalFileIndexer
{
    /**
     * Discover files matching the configured glob patterns.
     *
     * @param array|null $patterns Glob patterns (uses config if null)
     * @return array<string> Array of absolute file paths
     */
    public function discoverFiles(?array $patterns = null): array
    {
        $patterns = $patterns ?? config('ai.file_context.physical_paths', []);
        $basePath = config('ai.file_context.base_path', base_path());
        $supportedExtensions = config('ai.file_context.supported_extensions', ['md', 'mdx', 'txt']);

        if (empty($patterns)) {
            return [];
        }

        $files = [];

        foreach ($patterns as $pattern) {
            $discovered = $this->globPattern($basePath, $pattern, $supportedExtensions);
            $files = array_merge($files, $discovered);
        }

        return array_unique($files);
    }

    /**
     * Find files matching a single glob pattern.
     */
    private function globPattern(string $basePath, string $pattern, array $supportedExtensions): array
    {
        // Handle ** patterns with Symfony Finder
        if (str_contains($pattern, '**')) {
            return $this->findWithFinder($basePath, $pattern, $supportedExtensions);
        }

        // Simple glob
        $fullPattern = rtrim($basePath, '/') . '/' . ltrim($pattern, '/');
        $matches = glob($fullPattern);

        return $this->filterByExtension($matches ?: [], $supportedExtensions);
    }

    /**
     * Use Symfony Finder for recursive patterns.
     */
    private function findWithFinder(string $basePath, string $pattern, array $supportedExtensions): array
    {
        // Parse pattern into directory and file pattern
        $parts = explode('/', $pattern);
        $filePattern = array_pop($parts);
        $dirPattern = implode('/', $parts);

        // Determine search directory
        $searchDir = $basePath;
        if ($dirPattern && !str_contains($dirPattern, '*')) {
            $searchDir = rtrim($basePath, '/') . '/' . $dirPattern;
        }

        if (!File::isDirectory($searchDir)) {
            return [];
        }

        $finder = new Finder();
        $finder->files()
            ->in($searchDir)
            ->name($this->convertGlobToRegex($filePattern));

        $files = [];
        foreach ($finder as $file) {
            $files[] = $file->getRealPath();
        }

        return $this->filterByExtension($files, $supportedExtensions);
    }

    /**
     * Convert glob pattern to regex for Finder.
     */
    private function convertGlobToRegex(string $pattern): string
    {
        // Simple conversion: *.md -> /\.md$/
        if (str_starts_with($pattern, '*.')) {
            return '/\\' . substr($pattern, 1) . '$/i';
        }

        return '/' . preg_quote($pattern, '/') . '/i';
    }

    /**
     * Filter files by supported extensions.
     */
    private function filterByExtension(array $files, array $supportedExtensions): array
    {
        return array_filter($files, function ($file) use ($supportedExtensions) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            return in_array($ext, $supportedExtensions);
        });
    }

    /**
     * Generate a physical file ID from a path.
     */
    public function generateFileId(string $path): string
    {
        $basePath = config('ai.file_context.base_path', base_path());
        $relativePath = str_replace($basePath . '/', '', $path);
        $relativePath = str_replace($basePath . '\\', '', $relativePath);
        $relativePath = ltrim($relativePath, '/\\');

        return FileAccessResolver::PHYSICAL_PREFIX . $relativePath;
    }

    /**
     * Create a file object compatible with FileProcessor.
     *
     * @param string $path Absolute path to the file
     * @return object File object with id, name, path properties
     */
    public function createFileObject(string $path): object
    {
        return (object) [
            'id' => $this->generateFileId($path),
            'name' => basename($path),
            'path' => $path,
            'extension' => pathinfo($path, PATHINFO_EXTENSION),
            'size' => File::size($path),
            'is_physical' => true,
        ];
    }

    /**
     * Create file objects for all discovered files.
     *
     * @param array|null $patterns Glob patterns (uses config if null)
     * @return array<object> Array of file objects
     */
    public function createFileObjects(?array $patterns = null): array
    {
        $paths = $this->discoverFiles($patterns);

        return array_map(
            fn($path) => $this->createFileObject($path),
            $paths
        );
    }
}
```

**Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Unit/Services/Files/PhysicalFileIndexerTest.php`
Expected: PASS (4 tests)

**Step 5: Commit**

```bash
git add src/Services/Files/PhysicalFileIndexer.php
git add tests/Unit/Services/Files/PhysicalFileIndexerTest.php
git commit -m "feat(files): add PhysicalFileIndexer for documentation files"
```

---

## Task 4: Update ai:ingest Command for Physical Files

**Files:**
- Modify: `src/Console/Commands/IngestCommand.php`
- Test: `tests/Feature/Commands/IngestPhysicalFilesTest.php`

**Step 1: Write the failing test**

```php
<?php

namespace Condoedge\Ai\Tests\Feature\Commands;

use Condoedge\Ai\Tests\TestCase;
use Illuminate\Support\Facades\File;

class IngestPhysicalFilesTest extends TestCase
{
    private string $tempDir;

    public function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/ai_ingest_test_' . uniqid();
        File::makeDirectory($this->tempDir, 0755, true);
        File::put($this->tempDir . '/test.md', '# Test Document\n\nThis is test content.');

        config([
            'ai.file_context.base_path' => $this->tempDir,
            'ai.file_context.physical_paths' => ['**/*.md'],
            'ai.file_context.physical_collection' => 'test_docs',
        ]);
    }

    public function tearDown(): void
    {
        File::deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    /** @test */
    public function it_has_docs_option(): void
    {
        $this->artisan('ai:ingest --help')
            ->expectsOutputToContain('--docs');
    }

    /** @test */
    public function it_discovers_physical_files_with_docs_flag(): void
    {
        // This test verifies the command accepts --docs flag
        // Full integration would require mocking Qdrant
        $this->artisan('ai:ingest --docs --dry-run')
            ->assertSuccessful();
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Feature/Commands/IngestPhysicalFilesTest.php`
Expected: FAIL with "--docs option not recognized"

**Step 3: Update IngestCommand**

Add to `src/Console/Commands/IngestCommand.php`:

```php
<?php
// Add to the $signature property:
protected $signature = 'ai:ingest
    {--model= : Specific model class to ingest}
    {--docs : Index physical documentation files from config paths}
    {--dry-run : Show what would be indexed without actually indexing}
    {--force : Re-index even if already indexed}';

// Add to handle() method, at the beginning:
public function handle(): int
{
    if ($this->option('docs')) {
        return $this->handleDocsIngestion();
    }

    // ... existing entity ingestion code
}

// Add new method:
/**
 * Handle physical documentation file ingestion.
 */
protected function handleDocsIngestion(): int
{
    $this->info('Discovering physical documentation files...');

    $indexer = app(\Condoedge\Ai\Services\Files\PhysicalFileIndexer::class);
    $fileObjects = $indexer->createFileObjects();

    if (empty($fileObjects)) {
        $this->warn('No files found matching configured patterns.');
        $this->line('Configure patterns in config/ai.php: file_context.physical_paths');
        return self::SUCCESS;
    }

    $this->info(sprintf('Found %d files to index.', count($fileObjects)));

    if ($this->option('dry-run')) {
        $this->table(['ID', 'Name', 'Path'], array_map(fn($f) => [
            $f->id,
            $f->name,
            $f->path,
        ], $fileObjects));
        return self::SUCCESS;
    }

    $fileProcessor = app(\Condoedge\Ai\Contracts\FileProcessorInterface::class);
    $collection = config('ai.file_context.physical_collection', 'documentation_chunks');
    $force = $this->option('force');

    $bar = $this->output->createProgressBar(count($fileObjects));
    $bar->start();

    $results = ['success' => 0, 'skipped' => 0, 'failed' => 0];

    foreach ($fileObjects as $fileObject) {
        try {
            $result = $fileProcessor->process($fileObject, [
                'collection' => $collection,
                'force' => $force,
            ]);

            if ($result->wasSkipped()) {
                $results['skipped']++;
            } else {
                $results['success']++;
            }
        } catch (\Throwable $e) {
            $results['failed']++;
            $this->newLine();
            $this->error("Failed to index {$fileObject->name}: {$e->getMessage()}");
        }

        $bar->advance();
    }

    $bar->finish();
    $this->newLine(2);

    $this->info("Indexing complete:");
    $this->line("  Success: {$results['success']}");
    $this->line("  Skipped: {$results['skipped']}");
    $this->line("  Failed: {$results['failed']}");

    return $results['failed'] > 0 ? self::FAILURE : self::SUCCESS;
}
```

**Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Feature/Commands/IngestPhysicalFilesTest.php`
Expected: PASS (2 tests)

**Step 5: Commit**

```bash
git add src/Console/Commands/IngestCommand.php
git add tests/Feature/Commands/IngestPhysicalFilesTest.php
git commit -m "feat(command): add --docs flag to ai:ingest for physical files"
```

---

## Task 5: Create FileContextProvider Service

**Files:**
- Create: `src/Services/Context/FileContextProvider.php`
- Test: `tests/Unit/Services/Context/FileContextProviderTest.php`

**Step 1: Write the failing test**

```php
<?php

namespace Condoedge\Ai\Tests\Unit\Services\Context;

use Condoedge\Ai\Services\Context\FileContextProvider;
use Condoedge\Ai\Services\Context\FileAccessResolver;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

class FileContextProviderTest extends TestCase
{
    /** @test */
    public function it_searches_for_relevant_files(): void
    {
        $provider = app(FileContextProvider::class);

        $this->assertTrue(method_exists($provider, 'searchRelevantFiles'));
    }

    /** @test */
    public function it_returns_file_references_structure(): void
    {
        $mockSearchService = Mockery::mock(\Condoedge\Ai\Services\FileSearchService::class);
        $mockSearchService->shouldReceive('searchByContent')
            ->andReturn([
                [
                    'file_id' => 'physical:docs/auth.md',
                    'file_name' => 'auth.md',
                    'content' => 'Authentication guide content here...',
                    'score' => 0.85,
                    'chunk_index' => 0,
                ],
            ]);

        $provider = new FileContextProvider(
            $mockSearchService,
            new FileAccessResolver()
        );

        config(['ai.file_context.security_enabled' => false]);

        $results = $provider->searchRelevantFiles('how to authenticate', null);

        $this->assertIsArray($results);
        $this->assertNotEmpty($results);
        $this->assertArrayHasKey('file_id', $results[0]);
        $this->assertArrayHasKey('file_name', $results[0]);
        $this->assertArrayHasKey('snippet', $results[0]);
        $this->assertArrayHasKey('relevance_score', $results[0]);
    }

    /** @test */
    public function it_filters_by_access_when_security_enabled(): void
    {
        $mockSearchService = Mockery::mock(\Condoedge\Ai\Services\FileSearchService::class);
        $mockSearchService->shouldReceive('searchByContent')
            ->andReturn([
                ['file_id' => 1, 'file_name' => 'secret.md', 'content' => '...', 'score' => 0.9],
                ['file_id' => 'physical:docs/public.md', 'file_name' => 'public.md', 'content' => '...', 'score' => 0.8],
            ]);

        $provider = new FileContextProvider(
            $mockSearchService,
            new FileAccessResolver()
        );

        config(['ai.file_context.security_enabled' => true]);

        // Without user and with security, only physical files should pass
        $results = $provider->searchRelevantFiles('test query', null);

        $this->assertCount(1, $results);
        $this->assertEquals('physical:docs/public.md', $results[0]['file_id']);
    }

    /** @test */
    public function it_builds_file_reference_for_response(): void
    {
        $provider = app(FileContextProvider::class);

        $reference = $provider->buildFileReference(
            refNumber: 1,
            fileId: 'physical:docs/auth.md',
            fileName: 'auth.md',
            snippet: 'How to configure authentication...',
            score: 0.85
        );

        $this->assertEquals(1, $reference['ref']);
        $this->assertEquals('physical:docs/auth.md', $reference['id']);
        $this->assertEquals('auth.md', $reference['name']);
        $this->assertArrayHasKey('snippet', $reference);
        $this->assertArrayHasKey('relevance_score', $reference);
        $this->assertArrayHasKey('source', $reference);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Services/Context/FileContextProviderTest.php`
Expected: FAIL with "Class FileContextProvider not found"

**Step 3: Create the implementation**

```php
<?php
// src/Services/Context/FileContextProvider.php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Context;

use Condoedge\Ai\Contracts\FileAccessResolverInterface;
use Condoedge\Ai\Services\FileSearchService;

/**
 * FileContextProvider
 *
 * Unified provider for file context in AI responses.
 * Handles both physical files and database files with appropriate security.
 *
 * Usage:
 * - searchRelevantFiles() to find files related to a question
 * - buildFileReference() to create response metadata structure
 * - getFileContext() to get context for prompt building
 */
class FileContextProvider
{
    public function __construct(
        private readonly FileSearchService $searchService,
        private readonly FileAccessResolverInterface $accessResolver
    ) {
    }

    /**
     * Search for files relevant to a question.
     *
     * @param string $question The user's question
     * @param mixed $user The authenticated user (for access filtering)
     * @param array $options Search options
     * @return array<array> Array of file results with metadata
     */
    public function searchRelevantFiles(string $question, mixed $user, array $options = []): array
    {
        $limit = $options['limit'] ?? config('ai.file_context.max_references', 5);
        $minScore = $options['min_score'] ?? config('ai.file_context.min_relevance_score', 0.7);
        $collections = $options['collections'] ?? $this->getSearchCollections();

        // Search across all relevant collections
        $allResults = [];
        foreach ($collections as $collection) {
            try {
                $results = $this->searchService->searchByContent($question, [
                    'collection' => $collection,
                    'limit' => $limit * 2, // Get extra for filtering
                ]);
                $allResults = array_merge($allResults, $results);
            } catch (\Throwable $e) {
                // Log but continue with other collections
                \Log::warning("File search failed for collection {$collection}: " . $e->getMessage());
            }
        }

        // Filter by minimum score
        $filtered = array_filter($allResults, fn($r) => ($r['score'] ?? 0) >= $minScore);

        // Sort by score descending
        usort($filtered, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));

        // Apply access control
        $fileIds = array_column($filtered, 'file_id');
        $accessibleIds = $this->accessResolver->filterAccessibleFileIds($fileIds, $user);

        // Filter to accessible files and limit
        $accessible = array_filter($filtered, fn($r) => in_array($r['file_id'], $accessibleIds));
        $accessible = array_slice(array_values($accessible), 0, $limit);

        // Transform to standard format
        return array_map(fn($r) => $this->transformSearchResult($r), $accessible);
    }

    /**
     * Transform a search result to standard file reference format.
     */
    private function transformSearchResult(array $result): array
    {
        $fileId = $result['file_id'];
        $isPhysical = $this->accessResolver->isPhysicalFile($fileId);
        $snippetLength = config('ai.file_context.snippet_length', 200);

        return [
            'file_id' => $fileId,
            'file_name' => $result['file_name'] ?? basename($fileId),
            'snippet' => $this->truncateSnippet($result['content'] ?? '', $snippetLength),
            'relevance_score' => round($result['score'] ?? 0, 3),
            'chunk_index' => $result['chunk_index'] ?? 0,
            'source' => $isPhysical ? 'physical' : 'database',
        ];
    }

    /**
     * Truncate snippet to max length, preserving word boundaries.
     */
    private function truncateSnippet(string $content, int $maxLength): string
    {
        $content = trim(preg_replace('/\s+/', ' ', $content));

        if (strlen($content) <= $maxLength) {
            return $content;
        }

        $truncated = substr($content, 0, $maxLength);
        $lastSpace = strrpos($truncated, ' ');

        if ($lastSpace !== false && $lastSpace > $maxLength * 0.7) {
            $truncated = substr($truncated, 0, $lastSpace);
        }

        return $truncated . '...';
    }

    /**
     * Build a file reference for response metadata.
     */
    public function buildFileReference(
        int $refNumber,
        int|string $fileId,
        string $fileName,
        string $snippet,
        float $score,
        ?string $downloadUrl = null,
        ?bool $canDownload = null
    ): array {
        $isPhysical = $this->accessResolver->isPhysicalFile($fileId);

        return [
            'ref' => $refNumber,
            'id' => $fileId,
            'name' => $fileName,
            'snippet' => $snippet,
            'relevance_score' => round($score, 3),
            'source' => $isPhysical ? 'physical' : 'database',
            'download_url' => $isPhysical ? null : $downloadUrl,
            'preview_url' => $isPhysical ? null : $this->getPreviewUrl($fileId),
            'can_download' => $isPhysical ? false : ($canDownload ?? false),
        ];
    }

    /**
     * Get collections to search.
     */
    private function getSearchCollections(): array
    {
        $collections = [];

        // Physical docs collection
        if (config('ai.file_context.enabled', true)) {
            $collections[] = config('ai.file_context.physical_collection', 'documentation_chunks');
        }

        // Standard file chunks collection
        $collections[] = config('ai.file_processing.collection', 'file_chunks');

        return array_unique($collections);
    }

    /**
     * Get preview URL for a database file.
     */
    private function getPreviewUrl(int|string $fileId): ?string
    {
        if ($this->accessResolver->isPhysicalFile($fileId)) {
            return null;
        }

        // Override this method or configure route for your app
        return route('files.preview', ['file' => $fileId], false);
    }

    /**
     * Build file context for prompt building.
     * Used by FileContextSection.
     */
    public function getFileContext(string $question, mixed $user): array
    {
        $files = $this->searchRelevantFiles($question, $user);

        if (empty($files)) {
            return [];
        }

        return [
            'relevant_files' => $files,
            'file_count' => count($files),
            'has_physical' => collect($files)->contains('source', 'physical'),
            'has_database' => collect($files)->contains('source', 'database'),
        ];
    }
}
```

**Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Unit/Services/Context/FileContextProviderTest.php`
Expected: PASS (4 tests)

**Step 5: Commit**

```bash
git add src/Services/Context/FileContextProvider.php
git add tests/Unit/Services/Context/FileContextProviderTest.php
git commit -m "feat(context): add FileContextProvider for unified file search"
```

---

## Task 6: Create FileContextSection for Prompts

**Files:**
- Create: `src/Services/PromptSections/FileContextSection.php`
- Test: `tests/Unit/Services/PromptSections/FileContextSectionTest.php`

**Step 1: Write the failing test**

```php
<?php

namespace Condoedge\Ai\Tests\Unit\Services\PromptSections;

use Condoedge\Ai\Services\PromptSections\FileContextSection;
use Condoedge\Ai\Tests\TestCase;

class FileContextSectionTest extends TestCase
{
    private FileContextSection $section;

    public function setUp(): void
    {
        parent::setUp();
        $this->section = new FileContextSection();
    }

    /** @test */
    public function it_has_correct_name_and_priority(): void
    {
        $this->assertEquals('file_context', $this->section->getName());
        $this->assertEquals(45, $this->section->getPriority()); // Before similar_queries (50)
    }

    /** @test */
    public function it_should_not_include_when_no_files(): void
    {
        $result = $this->section->shouldInclude('question', [], []);
        $this->assertFalse($result);

        $result = $this->section->shouldInclude('question', ['file_context' => []], []);
        $this->assertFalse($result);
    }

    /** @test */
    public function it_should_include_when_files_present(): void
    {
        $context = [
            'file_context' => [
                'relevant_files' => [
                    ['file_name' => 'auth.md', 'snippet' => 'Authentication guide...'],
                ],
            ],
        ];

        $result = $this->section->shouldInclude('question', $context, []);
        $this->assertTrue($result);
    }

    /** @test */
    public function it_formats_file_context_with_citations(): void
    {
        $context = [
            'file_context' => [
                'relevant_files' => [
                    [
                        'file_id' => 'physical:docs/auth.md',
                        'file_name' => 'auth.md',
                        'snippet' => 'Configure authentication using middleware...',
                        'relevance_score' => 0.85,
                    ],
                    [
                        'file_id' => 45,
                        'file_name' => 'guards.md',
                        'snippet' => 'Guards handle session management...',
                        'relevance_score' => 0.78,
                    ],
                ],
            ],
        ];

        $output = $this->section->format('how to authenticate', $context);

        $this->assertStringContainsString('FILE CONTEXT', $output);
        $this->assertStringContainsString('[1]', $output);
        $this->assertStringContainsString('[2]', $output);
        $this->assertStringContainsString('auth.md', $output);
        $this->assertStringContainsString('guards.md', $output);
        $this->assertStringContainsString('citation', strtolower($output));
    }

    /** @test */
    public function it_includes_citation_instructions(): void
    {
        $context = [
            'file_context' => [
                'relevant_files' => [
                    ['file_name' => 'test.md', 'snippet' => 'Test content...'],
                ],
            ],
        ];

        $output = $this->section->format('question', $context);

        $this->assertStringContainsString('[1]', $output);
        $this->assertStringContainsString('cite', strtolower($output));
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Services/PromptSections/FileContextSectionTest.php`
Expected: FAIL with "Class FileContextSection not found"

**Step 3: Create the implementation**

```php
<?php
// src/Services/PromptSections/FileContextSection.php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

/**
 * FileContextSection
 *
 * Adds relevant file content to the LLM prompt with citation instructions.
 * The LLM should use inline citations [1], [2], etc. when referencing file content.
 *
 * Priority 45 places this before similar_queries (50) so file context
 * is established before example queries.
 */
class FileContextSection extends BasePromptSection
{
    protected string $name = 'file_context';
    protected int $priority = 45;

    /**
     * Only include when there are relevant files.
     */
    public function shouldInclude(string $question, array $context, array $options = []): bool
    {
        $fileContext = $context['file_context'] ?? [];
        return !empty($fileContext['relevant_files']);
    }

    /**
     * Format file context with citation instructions.
     */
    public function format(string $question, array $context, array $options = []): string
    {
        $fileContext = $context['file_context'] ?? [];
        $files = $fileContext['relevant_files'] ?? [];

        if (empty($files)) {
            return '';
        }

        $output = $this->header('FILE CONTEXT');
        $output .= $this->formatCitationInstructions();
        $output .= "\n";
        $output .= $this->formatFileList($files);

        return $output;
    }

    /**
     * Format instructions for citation usage.
     */
    private function formatCitationInstructions(): string
    {
        return <<<INSTRUCTIONS
**Citation Instructions:**
When using information from the files below, cite your sources using inline markers like [1], [2], etc.
Place the citation marker at the end of the relevant sentence or phrase.
Only cite files when you actually use their content in your response.

**Example:**
"Authentication can be configured using middleware [1]. The guard system handles sessions [2]."

INSTRUCTIONS;
    }

    /**
     * Format the list of relevant files.
     */
    private function formatFileList(array $files): string
    {
        $output = "**Relevant Files:**\n\n";

        foreach ($files as $index => $file) {
            $refNumber = $index + 1;
            $fileName = $file['file_name'] ?? 'Unknown';
            $snippet = $file['snippet'] ?? '';
            $score = $file['relevance_score'] ?? 0;

            $output .= "**[{$refNumber}] {$fileName}** (relevance: " . round($score * 100) . "%)\n";

            if (!empty($snippet)) {
                // Indent snippet for clarity
                $indentedSnippet = preg_replace('/^/m', '  ', trim($snippet));
                $output .= $indentedSnippet . "\n";
            }

            $output .= "\n";
        }

        return $output;
    }
}
```

**Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Unit/Services/PromptSections/FileContextSectionTest.php`
Expected: PASS (5 tests)

**Step 5: Commit**

```bash
git add src/Services/PromptSections/FileContextSection.php
git add tests/Unit/Services/PromptSections/FileContextSectionTest.php
git commit -m "feat(prompts): add FileContextSection with citation instructions"
```

---

## Task 7: Create ResponseFileEnricher Service

**Files:**
- Create: `src/Services/Response/ResponseFileEnricher.php`
- Test: `tests/Unit/Services/Response/ResponseFileEnricherTest.php`

**Step 1: Write the failing test**

```php
<?php

namespace Condoedge\Ai\Tests\Unit\Services\Response;

use Condoedge\Ai\Services\Response\ResponseFileEnricher;
use Condoedge\Ai\Tests\TestCase;

class ResponseFileEnricherTest extends TestCase
{
    private ResponseFileEnricher $enricher;

    public function setUp(): void
    {
        parent::setUp();
        $this->enricher = new ResponseFileEnricher();
    }

    /** @test */
    public function it_extracts_citation_markers_from_response(): void
    {
        $response = "Configure auth using middleware [1]. Guards handle sessions [2].";

        $markers = $this->enricher->extractCitationMarkers($response);

        $this->assertContains(1, $markers);
        $this->assertContains(2, $markers);
    }

    /** @test */
    public function it_handles_response_without_citations(): void
    {
        $response = "This is a response without any citations.";

        $markers = $this->enricher->extractCitationMarkers($response);

        $this->assertEmpty($markers);
    }

    /** @test */
    public function it_builds_referenced_files_array(): void
    {
        $response = "Authentication guide [1]. Session handling [2].";
        $fileContext = [
            'relevant_files' => [
                [
                    'file_id' => 'physical:docs/auth.md',
                    'file_name' => 'auth.md',
                    'snippet' => 'Authentication using middleware...',
                    'relevance_score' => 0.85,
                    'source' => 'physical',
                ],
                [
                    'file_id' => 45,
                    'file_name' => 'sessions.md',
                    'snippet' => 'Session management...',
                    'relevance_score' => 0.78,
                    'source' => 'database',
                ],
            ],
        ];

        $references = $this->enricher->buildReferencedFiles($response, $fileContext);

        $this->assertCount(2, $references);
        $this->assertEquals(1, $references[0]['ref']);
        $this->assertEquals('auth.md', $references[0]['name']);
        $this->assertEquals(2, $references[1]['ref']);
        $this->assertEquals('sessions.md', $references[1]['name']);
    }

    /** @test */
    public function it_only_includes_cited_files(): void
    {
        $response = "Only the first file is cited [1].";
        $fileContext = [
            'relevant_files' => [
                ['file_id' => 1, 'file_name' => 'cited.md', 'snippet' => '...', 'source' => 'database'],
                ['file_id' => 2, 'file_name' => 'not-cited.md', 'snippet' => '...', 'source' => 'database'],
            ],
        ];

        $references = $this->enricher->buildReferencedFiles($response, $fileContext);

        $this->assertCount(1, $references);
        $this->assertEquals('cited.md', $references[0]['name']);
    }

    /** @test */
    public function it_includes_actionable_metadata(): void
    {
        $response = "Database file referenced [1].";
        $fileContext = [
            'relevant_files' => [
                [
                    'file_id' => 45,
                    'file_name' => 'guide.md',
                    'snippet' => 'Content here...',
                    'relevance_score' => 0.9,
                    'source' => 'database',
                ],
            ],
        ];

        $references = $this->enricher->buildReferencedFiles($response, $fileContext, [
            'user' => (object)['id' => 1],
            'download_url_resolver' => fn($id) => "/files/{$id}/download",
            'can_download_resolver' => fn($id, $user) => true,
        ]);

        $this->assertArrayHasKey('download_url', $references[0]);
        $this->assertArrayHasKey('can_download', $references[0]);
        $this->assertTrue($references[0]['can_download']);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Services/Response/ResponseFileEnricherTest.php`
Expected: FAIL with "Class ResponseFileEnricher not found"

**Step 3: Create the implementation**

```php
<?php
// src/Services/Response/ResponseFileEnricher.php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Response;

use Condoedge\Ai\Services\Context\FileAccessResolver;

/**
 * ResponseFileEnricher
 *
 * Extracts citation markers from AI responses and builds the referenced_files
 * array for response metadata. This enables the frontend to render file
 * references with proper links and actions.
 */
class ResponseFileEnricher
{
    /**
     * Extract citation markers [1], [2], etc. from response text.
     *
     * @param string $response The AI response text
     * @return array<int> Array of citation numbers found
     */
    public function extractCitationMarkers(string $response): array
    {
        preg_match_all('/\[(\d+)\]/', $response, $matches);

        if (empty($matches[1])) {
            return [];
        }

        return array_unique(array_map('intval', $matches[1]));
    }

    /**
     * Build the referenced_files array for response metadata.
     *
     * @param string $response The AI response text
     * @param array $fileContext The file context from FileContextProvider
     * @param array $options Options for URL resolution
     * @return array<array> Array of file references
     */
    public function buildReferencedFiles(string $response, array $fileContext, array $options = []): array
    {
        $citedMarkers = $this->extractCitationMarkers($response);
        $relevantFiles = $fileContext['relevant_files'] ?? [];

        if (empty($citedMarkers) || empty($relevantFiles)) {
            return [];
        }

        $references = [];

        foreach ($citedMarkers as $marker) {
            $index = $marker - 1; // Markers are 1-indexed

            if (!isset($relevantFiles[$index])) {
                continue;
            }

            $file = $relevantFiles[$index];
            $references[] = $this->buildSingleReference($marker, $file, $options);
        }

        return $references;
    }

    /**
     * Build a single file reference entry.
     */
    private function buildSingleReference(int $refNumber, array $file, array $options): array
    {
        $fileId = $file['file_id'];
        $isPhysical = is_string($fileId) && str_starts_with($fileId, FileAccessResolver::PHYSICAL_PREFIX);

        $reference = [
            'ref' => $refNumber,
            'id' => $fileId,
            'name' => $file['file_name'] ?? 'Unknown',
            'snippet' => $file['snippet'] ?? '',
            'relevance_score' => $file['relevance_score'] ?? 0,
            'source' => $file['source'] ?? ($isPhysical ? 'physical' : 'database'),
            'chunk_index' => $file['chunk_index'] ?? 0,
        ];

        // Add actionable URLs for database files
        if (!$isPhysical) {
            $reference['download_url'] = $this->resolveDownloadUrl($fileId, $options);
            $reference['preview_url'] = $this->resolvePreviewUrl($fileId, $options);
            $reference['can_download'] = $this->resolveCanDownload($fileId, $options);
        } else {
            $reference['download_url'] = null;
            $reference['preview_url'] = null;
            $reference['can_download'] = false;
        }

        return $reference;
    }

    /**
     * Resolve download URL for a file.
     */
    private function resolveDownloadUrl(int|string $fileId, array $options): ?string
    {
        if (isset($options['download_url_resolver']) && is_callable($options['download_url_resolver'])) {
            return $options['download_url_resolver']($fileId);
        }

        // Default: try to use route if it exists
        try {
            return route('files.download', ['file' => $fileId], false);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Resolve preview URL for a file.
     */
    private function resolvePreviewUrl(int|string $fileId, array $options): ?string
    {
        if (isset($options['preview_url_resolver']) && is_callable($options['preview_url_resolver'])) {
            return $options['preview_url_resolver']($fileId);
        }

        try {
            return route('files.preview', ['file' => $fileId], false);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Resolve if user can download the file.
     */
    private function resolveCanDownload(int|string $fileId, array $options): bool
    {
        if (isset($options['can_download_resolver']) && is_callable($options['can_download_resolver'])) {
            $user = $options['user'] ?? null;
            return $options['can_download_resolver']($fileId, $user);
        }

        // Default: allow download for database files
        return true;
    }

    /**
     * Enrich a response array with file references.
     *
     * @param array $response The response data array
     * @param array $fileContext The file context
     * @param array $options Options for URL resolution
     * @return array The enriched response
     */
    public function enrichResponse(array $response, array $fileContext, array $options = []): array
    {
        $answerText = $response['answer'] ?? '';
        $references = $this->buildReferencedFiles($answerText, $fileContext, $options);

        $response['referenced_files'] = $references;
        $response['has_file_references'] = !empty($references);

        return $response;
    }
}
```

**Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Unit/Services/Response/ResponseFileEnricherTest.php`
Expected: PASS (5 tests)

**Step 5: Commit**

```bash
git add src/Services/Response/ResponseFileEnricher.php
git add tests/Unit/Services/Response/ResponseFileEnricherTest.php
git commit -m "feat(response): add ResponseFileEnricher for citation extraction"
```

---

## Task 8: Integrate File Context with AiManager

**Files:**
- Modify: `src/Services/AiManager.php`
- Test: `tests/Unit/Services/AiManagerFileContextTest.php`

**Step 1: Write the failing test**

```php
<?php

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Tests\TestCase;

class AiManagerFileContextTest extends TestCase
{
    /** @test */
    public function it_accepts_file_context_option(): void
    {
        $manager = app(\Condoedge\Ai\Services\AiManager::class);

        // The answerQuestion method should accept file_context in options
        $this->assertTrue(method_exists($manager, 'answerQuestion'));
    }

    /** @test */
    public function it_passes_file_context_to_context_retrieval(): void
    {
        // This verifies the integration point exists
        $manager = app(\Condoedge\Ai\Services\AiManager::class);

        $reflection = new \ReflectionClass($manager);
        $method = $reflection->getMethod('retrieveContext');

        // Method should be callable
        $this->assertTrue($method->isPublic() || $method->isProtected());
    }
}
```

**Step 2: Run test to verify current state**

Run: `./vendor/bin/phpunit tests/Unit/Services/AiManagerFileContextTest.php`

**Step 3: Update AiManager::answerQuestion**

In `src/Services/AiManager.php`, update the `answerQuestion` method (around line 683):

```php
public function answerQuestion(string $question, array $options = []): array
{
    try {
        // Step 1: Retrieve context
        $context = $this->retrieveContext($question);

        // Merge conversation context if provided
        if (!empty($options['conversation_context'])) {
            $context['conversation_context'] = $options['conversation_context'];
        }

        // ADD THIS: Merge file context if enabled
        if (config('ai.file_context.enabled', true)) {
            $fileContext = $this->retrieveFileContext($question, $options['user'] ?? null);
            if (!empty($fileContext)) {
                $context['file_context'] = $fileContext;
            }
        }

        // Step 2: Generate query (context now includes file_context)
        $queryResult = $this->generateQuery($question, $context, $options);

        // Step 3: Execute query
        $executionResult = $this->executeQuery($queryResult['cypher'], [], $options);

        // Step 4: Generate natural language response
        $responseResult = $this->generateResponse(
            $question,
            $executionResult,
            $queryResult['cypher'],
            $options
        );

        // ADD THIS: Enrich response with file references
        if (!empty($context['file_context'])) {
            $responseResult = $this->enrichResponseWithFiles($responseResult, $context['file_context'], $options);
        }

        return [
            'question' => $question,
            'answer' => $responseResult['answer'],
            'insights' => $responseResult['insights'],
            'visualizations' => $responseResult['visualizations'],
            'cypher' => $queryResult['cypher'],
            'data' => $executionResult['data'],
            'stats' => $executionResult['stats'],
            'referenced_files' => $responseResult['referenced_files'] ?? [], // ADD THIS
            'metadata' => [
                'query' => $queryResult['metadata'],
                'execution' => $executionResult['metadata'],
                'response' => $responseResult['metadata'],
            ],
        ];

    } catch (\Throwable $e) {
        // ... existing error handling
    }
}

// ADD THESE NEW METHODS:

/**
 * Retrieve file context for the question.
 */
protected function retrieveFileContext(string $question, mixed $user): array
{
    try {
        $provider = app(\Condoedge\Ai\Services\Context\FileContextProvider::class);
        return $provider->getFileContext($question, $user);
    } catch (\Throwable $e) {
        \Log::warning('Failed to retrieve file context: ' . $e->getMessage());
        return [];
    }
}

/**
 * Enrich response with file references.
 */
protected function enrichResponseWithFiles(array $response, array $fileContext, array $options): array
{
    $enricher = app(\Condoedge\Ai\Services\Response\ResponseFileEnricher::class);
    return $enricher->enrichResponse($response, $fileContext, $options);
}
```

**Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Unit/Services/AiManagerFileContextTest.php`
Expected: PASS (2 tests)

**Step 5: Commit**

```bash
git add src/Services/AiManager.php
git add tests/Unit/Services/AiManagerFileContextTest.php
git commit -m "feat(manager): integrate file context into AI pipeline"
```

---

## Task 9: Track File References in Conversation History

**Files:**
- Modify: `src/Services/Context/ConversationContextManager.php`
- Modify: `src/Models/AiMessage.php`
- Test: `tests/Unit/Services/Context/ConversationFileTrackingTest.php`

**Step 1: Write the failing test**

```php
<?php

namespace Condoedge\Ai\Tests\Unit\Services\Context;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Services\Context\ConversationContextManager;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ConversationFileTrackingTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_stores_referenced_files_in_message(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);

        $referencedFiles = [
            ['ref' => 1, 'id' => 'physical:docs/auth.md', 'name' => 'auth.md'],
        ];

        $message = $conversation->addMessage('assistant', 'Check the auth guide [1].', [
            'referenced_files' => $referencedFiles,
        ]);

        $message->refresh();

        $this->assertNotNull($message->metadata);
        $this->assertArrayHasKey('referenced_files', $message->metadata);
        $this->assertCount(1, $message->metadata['referenced_files']);
    }

    /** @test */
    public function it_tracks_files_in_context_snapshot(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);

        $conversation->updateContextSnapshot([
            'referenced_files' => ['physical:docs/auth.md', 'physical:docs/guards.md'],
        ]);

        $conversation->refresh();

        $files = $conversation->context_snapshot['referenced_files'] ?? [];
        $this->assertCount(2, $files);
    }

    /** @test */
    public function it_includes_file_history_in_prompt_context(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);

        $conversation->addMessage('assistant', 'Auth guide explains this [1].', [
            'metadata' => [
                'referenced_files' => [
                    ['ref' => 1, 'id' => 'physical:docs/auth.md', 'name' => 'auth.md'],
                ],
            ],
        ]);

        $manager = app(ConversationContextManager::class);
        $promptContext = $manager->buildPromptContext($conversation);

        $this->assertArrayHasKey('recent_exchanges', $promptContext);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Services/Context/ConversationFileTrackingTest.php`

**Step 3: Update AiMessage model**

In `src/Models/AiMessage.php`, add `referenced_files` handling:

```php
<?php
// src/Models/AiMessage.php - update the create/fill logic

// The metadata field already exists and is cast to array
// referenced_files will be stored within metadata

// Add helper method:
public function getReferencedFiles(): array
{
    return $this->metadata['referenced_files'] ?? [];
}

public function hasFileReferences(): bool
{
    return !empty($this->getReferencedFiles());
}
```

**Step 4: Update AiConversation::addMessage**

In `src/Models/AiConversation.php`, update `addMessage` to handle referenced_files:

```php
public function addMessage(string $role, string $content, array $data = []): AiMessage
{
    // Extract referenced_files and merge into metadata
    $metadata = $data['metadata'] ?? [];
    if (isset($data['referenced_files'])) {
        $metadata['referenced_files'] = $data['referenced_files'];
    }

    $message = $this->messages()->create([
        'role' => $role,
        'content' => $content,
        'response_data' => $data['response_data'] ?? null,
        'context_used' => $data['context_used'] ?? null,
        'cypher_query' => $data['cypher_query'] ?? null,
        'execution_time_ms' => $data['execution_time_ms'] ?? null,
        'confidence_score' => $data['confidence_score'] ?? null,
        'metadata' => !empty($metadata) ? $metadata : null,
    ]);

    $this->update(['last_message_at' => now()]);

    // Auto-generate title from first user message
    if ($this->title === null && $role === 'user') {
        $this->update(['title' => \Illuminate\Support\Str::limit($content, 50)]);
    }

    // Track referenced files in context snapshot
    if (!empty($metadata['referenced_files'])) {
        $existingFiles = $this->context_snapshot['referenced_files'] ?? [];
        $newFileIds = array_column($metadata['referenced_files'], 'id');
        $allFiles = array_unique(array_merge($existingFiles, $newFileIds));

        $this->updateContextSnapshot([
            'referenced_files' => array_values($allFiles),
        ]);
    }

    return $message;
}
```

**Step 5: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Unit/Services/Context/ConversationFileTrackingTest.php`
Expected: PASS (3 tests)

**Step 6: Commit**

```bash
git add src/Models/AiMessage.php
git add src/Models/AiConversation.php
git add tests/Unit/Services/Context/ConversationFileTrackingTest.php
git commit -m "feat(conversation): track file references in message history"
```

---

## Task 10: Register Services in ServiceProvider

**Files:**
- Modify: `src/AiServiceProvider.php`
- Test: `tests/Unit/ServiceProviderFileContextTest.php`

**Step 1: Write the failing test**

```php
<?php

namespace Condoedge\Ai\Tests\Unit;

use Condoedge\Ai\Contracts\FileAccessResolverInterface;
use Condoedge\Ai\Services\Context\FileAccessResolver;
use Condoedge\Ai\Services\Context\FileContextProvider;
use Condoedge\Ai\Services\Files\PhysicalFileIndexer;
use Condoedge\Ai\Services\Response\ResponseFileEnricher;
use Condoedge\Ai\Tests\TestCase;

class ServiceProviderFileContextTest extends TestCase
{
    /** @test */
    public function it_registers_file_access_resolver(): void
    {
        $resolver = app(FileAccessResolverInterface::class);
        $this->assertInstanceOf(FileAccessResolver::class, $resolver);
    }

    /** @test */
    public function it_registers_file_context_provider(): void
    {
        $provider = app(FileContextProvider::class);
        $this->assertInstanceOf(FileContextProvider::class, $provider);
    }

    /** @test */
    public function it_registers_physical_file_indexer(): void
    {
        $indexer = app(PhysicalFileIndexer::class);
        $this->assertInstanceOf(PhysicalFileIndexer::class, $indexer);
    }

    /** @test */
    public function it_registers_response_file_enricher(): void
    {
        $enricher = app(ResponseFileEnricher::class);
        $this->assertInstanceOf(ResponseFileEnricher::class, $enricher);
    }

    /** @test */
    public function file_access_resolver_is_singleton(): void
    {
        $resolver1 = app(FileAccessResolverInterface::class);
        $resolver2 = app(FileAccessResolverInterface::class);
        $this->assertSame($resolver1, $resolver2);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/ServiceProviderFileContextTest.php`
Expected: FAIL with "Target class not instantiable"

**Step 3: Update AiServiceProvider**

Add to `src/AiServiceProvider.php`:

```php
use Condoedge\Ai\Contracts\FileAccessResolverInterface;
use Condoedge\Ai\Services\Context\FileAccessResolver;
use Condoedge\Ai\Services\Context\FileContextProvider;
use Condoedge\Ai\Services\Files\PhysicalFileIndexer;
use Condoedge\Ai\Services\Response\ResponseFileEnricher;

// In the register() method, add:

/**
 * Register file context services.
 */
private function registerFileContextServices(): void
{
    // File access resolver (singleton for consistent security checks)
    $this->app->singleton(FileAccessResolverInterface::class, FileAccessResolver::class);
    $this->app->singleton(FileAccessResolver::class);

    // Physical file indexer
    $this->app->singleton(PhysicalFileIndexer::class);

    // File context provider
    $this->app->singleton(FileContextProvider::class, function ($app) {
        return new FileContextProvider(
            $app->make(\Condoedge\Ai\Services\FileSearchService::class),
            $app->make(FileAccessResolverInterface::class)
        );
    });

    // Response enricher
    $this->app->singleton(ResponseFileEnricher::class);
}

// Call it in register():
public function register(): void
{
    // ... existing registrations
    $this->registerFileContextServices();
}

// Update provides() to include new services:
public function provides(): array
{
    return [
        // ... existing services
        FileAccessResolverInterface::class,
        FileAccessResolver::class,
        FileContextProvider::class,
        PhysicalFileIndexer::class,
        ResponseFileEnricher::class,
    ];
}
```

**Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Unit/ServiceProviderFileContextTest.php`
Expected: PASS (5 tests)

**Step 5: Commit**

```bash
git add src/AiServiceProvider.php
git add tests/Unit/ServiceProviderFileContextTest.php
git commit -m "feat(provider): register file context services"
```

---

## Task 11: Register FileContextSection in Config

**Files:**
- Modify: `config/ai.php`

**Step 1: Update config**

In `config/ai.php`, add `FileContextSection` to `query_generator_sections`:

```php
'query_generator_sections' => [
    \Condoedge\Ai\Services\PromptSections\ProjectContextSection::class,
    \Condoedge\Ai\Services\PromptSections\GenericContextSection::class,
    \Condoedge\Ai\Services\PromptSections\SchemaSection::class,
    \Condoedge\Ai\Services\PromptSections\RelationshipsSection::class,
    \Condoedge\Ai\Services\PromptSections\ExampleEntitiesSection::class,
    \Condoedge\Ai\Services\PromptSections\FileContextSection::class,  // ADD THIS (priority 45)
    \Condoedge\Ai\Services\PromptSections\SimilarQueriesSection::class,
    \Condoedge\Ai\Services\PromptSections\ConversationContextSection::class,
    \Condoedge\Ai\Services\PromptSections\DetectedEntitiesSection::class,
    // ... rest of sections
],
```

**Step 2: Commit**

```bash
git add config/ai.php
git commit -m "feat(config): register FileContextSection in prompt builder"
```

---

## Task 12: Create Frontend FileReference Component

**Files:**
- Create: `resources/js/components/FileReference.vue` (or React equivalent)
- Create: `resources/docs/1.0/chat/file-references.md`

**Step 1: Create Vue component**

```vue
<!-- resources/js/components/FileReference.vue -->
<template>
  <div class="file-references" v-if="references.length > 0">
    <div class="file-references-header">
      <span class="icon">📎</span>
      <span>Sources</span>
    </div>
    <div class="file-references-list">
      <div
        v-for="ref in references"
        :key="ref.ref"
        class="file-reference-item"
        @click="handleClick(ref)"
      >
        <span class="ref-marker">[{{ ref.ref }}]</span>
        <span class="file-name">{{ ref.name }}</span>
        <div class="file-actions" v-if="ref.source === 'database'">
          <button
            v-if="ref.can_download && ref.download_url"
            @click.stop="download(ref)"
            class="action-btn"
            title="Download"
          >
            ⬇️
          </button>
          <button
            v-if="ref.preview_url"
            @click.stop="preview(ref)"
            class="action-btn"
            title="Preview"
          >
            👁️
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: 'FileReference',
  props: {
    references: {
      type: Array,
      default: () => [],
    },
  },
  methods: {
    handleClick(ref) {
      this.$emit('reference-click', ref);
    },
    download(ref) {
      if (ref.download_url) {
        window.open(ref.download_url, '_blank');
      }
    },
    preview(ref) {
      if (ref.preview_url) {
        this.$emit('preview', ref);
      }
    },
  },
};
</script>

<style scoped>
.file-references {
  margin-top: 12px;
  padding: 12px;
  background: var(--bg-secondary, #f5f5f5);
  border-radius: 8px;
}

.file-references-header {
  font-weight: 600;
  margin-bottom: 8px;
  display: flex;
  align-items: center;
  gap: 6px;
}

.file-references-list {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.file-reference-item {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 6px 10px;
  background: var(--bg-primary, white);
  border-radius: 6px;
  cursor: pointer;
  transition: background 0.2s;
}

.file-reference-item:hover {
  background: var(--bg-hover, #e8e8e8);
}

.ref-marker {
  color: var(--text-secondary, #666);
  font-size: 0.85em;
}

.file-name {
  font-weight: 500;
}

.file-actions {
  display: flex;
  gap: 4px;
  margin-left: 4px;
}

.action-btn {
  background: none;
  border: none;
  cursor: pointer;
  padding: 2px 4px;
  border-radius: 4px;
  opacity: 0.7;
}

.action-btn:hover {
  opacity: 1;
  background: var(--bg-hover, #ddd);
}
</style>
```

**Step 2: Create documentation**

```markdown
<!-- resources/docs/1.0/chat/file-references.md -->
# File References in AI Responses

This document explains how file references work in AI chat responses.

---

## Overview

When the AI uses content from indexed files to answer questions, it cites sources using inline markers like `[1]`, `[2]`. These citations appear in the response text and are linked to full file metadata in the response data.

---

## Response Structure

AI responses that reference files include a `referenced_files` array:

```json
{
  "answer": "Configure authentication using middleware [1]. Guards handle sessions [2].",
  "referenced_files": [
    {
      "ref": 1,
      "id": "physical:docs/auth.mdx",
      "name": "auth.mdx",
      "snippet": "Authentication can be configured by...",
      "relevance_score": 0.85,
      "source": "physical",
      "download_url": null,
      "can_download": false
    },
    {
      "ref": 2,
      "id": 45,
      "name": "guards.md",
      "snippet": "Guards manage session state...",
      "relevance_score": 0.78,
      "source": "database",
      "download_url": "/files/45/download",
      "can_download": true
    }
  ]
}
```

---

## File Sources

### Physical Files
- Documentation files indexed from configured glob patterns
- `source: "physical"`
- No download/preview actions (content is in snippet)
- ID format: `physical:path/to/file.md`

### Database Files
- User-uploaded files with access control
- `source: "database"`
- Download/preview actions available if user has access
- Numeric ID from File model

---

## Frontend Integration

Use the `FileReference` component to display references:

```vue
<template>
  <div class="chat-message">
    <div class="message-content" v-html="formattedAnswer"></div>
    <FileReference
      :references="message.referenced_files"
      @reference-click="showDetails"
      @preview="openPreview"
    />
  </div>
</template>
```

---

## Configuration

Configure file context in `config/ai.php`:

```php
'file_context' => [
    'enabled' => true,
    'security_enabled' => true,
    'physical_paths' => ['docs/**/*.mdx'],
    'max_references' => 5,
    'min_relevance_score' => 0.7,
],
```

---

## Indexing Files

### Physical Files (Documentation)
```bash
php artisan ai:ingest --docs
```

### Database Files
Files are indexed automatically via the FileProcessor when uploaded.
```

**Step 3: Commit**

```bash
git add resources/js/components/FileReference.vue
git add resources/docs/1.0/chat/file-references.md
git commit -m "feat(frontend): add FileReference component and documentation"
```

---

## Summary

After completing all 12 tasks, you will have:

1. **File Context Configuration** - Flexible config for both modes
2. **FileAccessResolver** - Extendable security with boot method
3. **PhysicalFileIndexer** - Discovers and indexes docs from glob patterns
4. **ai:ingest --docs** - Command to index physical files
5. **FileContextProvider** - Unified file search across modes
6. **FileContextSection** - Adds files to LLM prompts with citation instructions
7. **ResponseFileEnricher** - Extracts citations and builds response metadata
8. **AiManager integration** - File context in AI pipeline
9. **Conversation tracking** - File references in message history
10. **Service registration** - All services in container
11. **Config registration** - FileContextSection in prompt builder
12. **Frontend component** - Visual file references with actions

### Usage After Implementation

```php
// Configure physical docs
config(['ai.file_context.physical_paths' => ['docs/**/*.mdx']]);

// Index documentation
php artisan ai:ingest --docs

// Ask questions - files are automatically referenced
$response = AI::answerQuestion('How do I configure authentication?', [
    'user' => auth()->user(),
]);

// Response includes:
// - answer: "Configure auth using middleware [1]. Guards handle sessions [2]."
// - referenced_files: [{ref: 1, name: "auth.mdx", ...}, {ref: 2, name: "guards.md", ...}]
```

### Test Commands

```bash
# All file context tests
./vendor/bin/phpunit tests/Unit/Services/Context/FileAccessResolverTest.php
./vendor/bin/phpunit tests/Unit/Services/Context/FileContextProviderTest.php
./vendor/bin/phpunit tests/Unit/Services/Files/PhysicalFileIndexerTest.php
./vendor/bin/phpunit tests/Unit/Services/Response/ResponseFileEnricherTest.php
./vendor/bin/phpunit tests/Unit/Services/PromptSections/FileContextSectionTest.php
```
