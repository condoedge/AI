# File Retrieval Fix Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fix file context retrieval so uploaded files (like bariloche.txt) are found and properly included in AI responses.

**Architecture:** The file context system has a pipeline: FileProcessor → QdrantChunkStore → FileSearchService → FileContextProvider → FileContextSection. Several bugs break this pipeline, plus missing access control infrastructure.

**Tech Stack:** Laravel, Qdrant vector store, OpenAI embeddings, FileModel facade, ModelPlugin system

---

## Root Cause Analysis

### Bug 1: Key Naming Mismatch (Critical)
- `FileContextProvider::searchRelevantFiles()` returns `file_name` and `relevance_score`
- `FileContextSection::format()` expects `filename` and `relevance`
- Result: All files show as "unknown" with 0% relevance in the prompt

### Bug 2: Security Filter Silently Fails
- `FileAccessResolver::getAccessibleFileIds()` calls `File::accessibleBy($user)`
- If scope doesn't exist, it returns `[]` due to try/catch
- No fallback logic for user_id/team_id filtering

### Bug 3: Hardcoded File Model Import
- `FileSearchService` imports `Condoedge\Utils\Models\Files\File` directly
- Should use `FileModel` facade which is overridable

### Bug 4: Missing accessibleBy Scope Infrastructure
- No automatic `accessibleBy` scope registration
- Each app must implement this manually

---

## Task 1: Fix Key Naming Mismatch in FileContextProvider

**Files:**
- Modify: `src/Services/Context/FileContextProvider.php:114-121`

**Step 1: Update the return array keys to match FileContextSection expectations**

```php
// In searchRelevantFiles() method, lines 105-122
// Change the return array from:
return [
    'file_id' => $fileId,
    'file_name' => $chunk->fileName,
    'snippet' => $snippet,
    'relevance_score' => $result['score'],
    'chunk_index' => $chunk->chunkIndex,
    'source' => $source,
];

// To:
return [
    'file_id' => $fileId,
    'filename' => $chunk->fileName,        // Changed key
    'snippet' => $snippet,
    'relevance' => $result['score'],       // Changed key
    'chunk_index' => $chunk->chunkIndex,
    'source' => $source,
];
```

**Step 2: Also update buildFileReference method (lines 137-154) for consistency**

```php
// Change from file_name/relevance_score to filename/relevance
return [
    'ref_number' => $refNumber,
    'file_id' => $fileId,
    'filename' => $fileName,           // Changed key
    'snippet' => $snippet,
    'relevance' => $relevanceScore,    // Changed key
    'chunk_index' => $chunkIndex,
    'source' => $source,
];
```

**Step 3: Run syntax check**

```bash
php -l src/Services/Context/FileContextProvider.php
```

**Step 4: Commit**

```bash
git add src/Services/Context/FileContextProvider.php
git commit -m "fix(file-context): use correct key names for FileContextSection compatibility"
```

---

## Task 2: Use FileModel Facade in FileSearchService

**Files:**
- Modify: `src/Services/FileSearchService.php`

**Step 1: Change import from hardcoded File to FileModel facade**

```php
// Line 7, change from:
use Condoedge\Utils\Models\Files\File;

// To:
use Condoedge\Utils\Facades\FileModel;
```

**Step 2: Replace all `File::` calls with `FileModel::`**

Replace these occurrences:
- Line 90: `File::whereIn(...)` → `FileModel::whereIn(...)`
- Line 136: `File::whereIn(...)` → `FileModel::whereIn(...)`
- Line 213: `File::whereIn(...)` → `FileModel::whereIn(...)`
- Line 246: `return File::whereIn(...)` → `return FileModel::whereIn(...)`
- Line 275: `return File::whereIn(...)` → `return FileModel::whereIn(...)`

**Step 3: Run syntax check**

```bash
php -l src/Services/FileSearchService.php
```

**Step 4: Commit**

```bash
git add src/Services/FileSearchService.php
git commit -m "refactor(file-search): use FileModel facade instead of hardcoded import"
```

---

## Task 3: Add File Access Configuration Options

**Files:**
- Modify: `config/ai.php:525-581` (file_context section)

**Step 1: Add new config options for fallback behavior**

After line 576 (`'access_scope' => 'accessibleBy',`), add:

```php
// Fallback filtering when accessibleBy scope is not available
// These are used when the configured scope fails or doesn't exist
'fallback_filters' => [
    // Always filter by user_id when security is enabled
    'use_user_filter' => env('AI_FILE_USE_USER_FILTER', true),

    // Also filter by team_id using safeCurrentTeamId()
    'use_team_filter' => env('AI_FILE_USE_TEAM_FILTER', true),
],
```

**Step 2: Lower default min_relevance_score (line 552)**

```php
// Change from:
'min_relevance_score' => 0.7,

// To:
'min_relevance_score' => 0.5,
```

**Step 3: Commit**

```bash
git add config/ai.php
git commit -m "config(file-context): add fallback filter options and lower min_relevance_score"
```

---

## Task 4: Implement Fallback Logic in FileAccessResolver

**Files:**
- Modify: `src/Services/Context/FileAccessResolver.php`

**Step 1: Add use statement for FileModel facade**

After line 7, add:
```php
use Condoedge\Utils\Facades\FileModel;
```

**Step 2: Replace getAccessibleFileIds method (lines 53-80) with fallback logic**

```php
/**
 * Get all file IDs accessible by the given user
 *
 * Priority:
 * 1. Config closure resolver (ai.file_context.access_resolver)
 * 2. File model with accessibleBy scope
 * 3. Fallback: user_id + optional team_id filtering
 *
 * @param mixed $user
 * @return array<int|string>
 */
public function getAccessibleFileIds(mixed $user): array
{
    // No user means no database file access when security is enabled
    if ($user === null) {
        return [];
    }

    // Check for closure-based resolver first (takes precedence)
    $resolver = config('ai.file_context.access_resolver');
    if ($resolver instanceof \Closure) {
        return $resolver($user);
    }

    // Try configured accessibleBy scope
    $accessScope = config('ai.file_context.access_scope', 'accessibleBy');

    try {
        return FileModel::$accessScope($user)->pluck('id')->toArray();
    } catch (\Throwable $e) {
        \Log::debug("FileAccessResolver: accessibleBy scope failed, using fallback", [
            'error' => $e->getMessage(),
            'user_id' => $user->id ?? null,
        ]);
    }

    // Fallback: use user_id and optional team_id filtering
    return $this->getFallbackAccessibleFileIds($user);
}

/**
 * Fallback method when accessibleBy scope is not available
 *
 * @param mixed $user
 * @return array<int|string>
 */
protected function getFallbackAccessibleFileIds(mixed $user): array
{
    $query = FileModel::query();
    $hasFilters = false;

    // Filter by user_id if enabled
    if (config('ai.file_context.fallback_filters.use_user_filter', true)) {
        $query->where('user_id', $user->id ?? $user->getKey());
        $hasFilters = true;
    }

    // Filter by team_id if enabled
    if (config('ai.file_context.fallback_filters.use_team_filter', true)) {
        $teamId = safeCurrentTeamId();
        if ($teamId) {
            $query->where('team_id', $teamId);
            $hasFilters = true;
        }
    }

    // If no filters applied (both disabled), return empty for security
    if (!$hasFilters) {
        \Log::warning('FileAccessResolver: No fallback filters enabled, returning empty');
        return [];
    }

    return $query->pluck('id')->toArray();
}
```

**Step 3: Run syntax check**

```bash
php -l src/Services/Context/FileAccessResolver.php
```

**Step 4: Commit**

```bash
git add src/Services/Context/FileAccessResolver.php
git commit -m "fix(file-access): add fallback filtering when accessibleBy scope unavailable"
```

---

## Task 5: Create FileAccessScopePlugin

**Files:**
- Create: `src/Models/Plugins/FileAccessScopePlugin.php`

**Step 1: Create the plugin file**

```php
<?php

namespace Condoedge\Ai\Models\Plugins;

use Condoedge\Utils\Models\Plugins\ModelPlugin;

/**
 * File Access Scope Plugin
 *
 * Automatically registers the `accessibleBy` scope on File models,
 * implementing user_id and optional team_id filtering for AI file context.
 *
 * This plugin provides a default implementation that can be overridden
 * by implementing a custom `scopeAccessibleBy` on your File model.
 */
class FileAccessScopePlugin extends ModelPlugin
{
    /**
     * Boot the plugin and register the accessibleBy scope
     *
     * @return void
     */
    public function onBoot(): void
    {
        // The scope is added via model method, not event listener
    }

    /**
     * Define model methods that the plugin adds
     *
     * @return array
     */
    public function modelMethods(): array
    {
        return [
            'scopeAccessibleBy' => function ($query, $user) {
                return $this->applyAccessibleByScope($query, $user);
            },
        ];
    }

    /**
     * Apply the accessibleBy scope logic
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param mixed $user
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function applyAccessibleByScope($query, $user)
    {
        if (!$user) {
            // No user - return impossible condition
            return $query->whereRaw('1 = 0');
        }

        $useUserFilter = config('ai.file_context.fallback_filters.use_user_filter', true);
        $useTeamFilter = config('ai.file_context.fallback_filters.use_team_filter', true);

        return $query->where(function ($q) use ($user, $useUserFilter, $useTeamFilter) {
            if ($useUserFilter) {
                $q->where('user_id', $user->id ?? $user->getKey());
            }

            if ($useTeamFilter) {
                $teamId = safeCurrentTeamId();
                if ($teamId) {
                    if ($useUserFilter) {
                        // Both filters: user OR team
                        $q->orWhere('team_id', $teamId);
                    } else {
                        // Only team filter
                        $q->where('team_id', $teamId);
                    }
                }
            }
        });
    }
}
```

**Step 2: Run syntax check**

```bash
php -l src/Models/Plugins/FileAccessScopePlugin.php
```

**Step 3: Commit**

```bash
git add src/Models/Plugins/FileAccessScopePlugin.php
git commit -m "feat(plugins): add FileAccessScopePlugin for automatic accessibleBy scope"
```

---

## Task 6: Register FileAccessScopePlugin in Service Provider

**Files:**
- Modify: `src/AiServiceProvider.php`

**Step 1: Add import for new plugin**

After line 8 (near other plugin imports), add:
```php
use Condoedge\Ai\Models\Plugins\FileAccessScopePlugin;
```

**Step 2: Update FileModel::setPlugins call (around line 631)**

```php
// Change from:
FileModel::setPlugins([
    FileProcessingPlugin::class,
]);

// To:
FileModel::setPlugins([
    FileProcessingPlugin::class,
    FileAccessScopePlugin::class,
]);
```

**Step 3: Run syntax check**

```bash
php -l src/AiServiceProvider.php
```

**Step 4: Commit**

```bash
git add src/AiServiceProvider.php
git commit -m "feat(plugins): register FileAccessScopePlugin for automatic scope registration"
```

---

## Task 7: Add Integration Tests

**Files:**
- Create: `tests/Feature/FileContextIntegrationTest.php`

**Step 1: Create the test file**

```php
<?php

namespace Condoedge\Ai\Tests\Feature;

use Condoedge\Ai\Services\Context\FileContextProvider;
use Condoedge\Ai\Services\Context\FileAccessResolver;
use Condoedge\Ai\Services\FileSearchService;
use Condoedge\Ai\Services\PromptSections\FileContextSection;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

class FileContextIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function file_context_uses_correct_key_names(): void
    {
        // Arrange: Mock search service to return a result
        $mockSearchService = Mockery::mock(FileSearchService::class);
        $mockSearchService->shouldReceive('searchByContent')
            ->andReturn([
                [
                    'file_id' => 1,
                    'score' => 0.85,
                    'chunk_count' => 2,
                    'best_chunk' => (object) [
                        'fileName' => 'bariloche.txt',
                        'content' => 'Trip to Bariloche cost $500',
                        'chunkIndex' => 0,
                    ],
                ],
            ]);

        $mockAccessResolver = Mockery::mock(\Condoedge\Ai\Contracts\FileAccessResolverInterface::class);
        $mockAccessResolver->shouldReceive('filterAccessibleFileIds')->andReturn([1]);
        $mockAccessResolver->shouldReceive('shouldEnforceSecurity')->andReturn(false);
        $mockAccessResolver->shouldReceive('isPhysicalFile')->andReturn(false);

        $provider = new FileContextProvider($mockSearchService, $mockAccessResolver);

        // Act
        $result = $provider->searchRelevantFiles('bariloche trip cost', null);

        // Assert: Keys must match FileContextSection expectations
        $this->assertNotEmpty($result);
        $this->assertArrayHasKey('filename', $result[0], 'Should use "filename" key, not "file_name"');
        $this->assertArrayHasKey('relevance', $result[0], 'Should use "relevance" key, not "relevance_score"');
        $this->assertEquals('bariloche.txt', $result[0]['filename']);
        $this->assertEquals(0.85, $result[0]['relevance']);
    }

    /** @test */
    public function file_context_section_displays_files_correctly(): void
    {
        $section = new FileContextSection();

        $context = [
            'file_context' => [
                'relevant_files' => [
                    [
                        'filename' => 'bariloche.txt',
                        'relevance' => 0.85,
                        'snippet' => 'Trip expenses...',
                    ],
                ],
            ],
        ];

        $output = $section->format('test question', $context);

        $this->assertStringContainsString('bariloche.txt', $output);
        $this->assertStringContainsString('85%', $output);
        $this->assertStringNotContainsString('unknown', $output, 'Should not show "unknown" for filename');
        $this->assertStringNotContainsString('0%', $output, 'Should not show "0%" for relevance');
    }

    /** @test */
    public function file_access_resolver_uses_fallback_when_scope_missing(): void
    {
        // Configure fallback filters
        config([
            'ai.file_context.security_enabled' => true,
            'ai.file_context.access_scope' => 'nonExistentScope',
            'ai.file_context.fallback_filters.use_user_filter' => true,
            'ai.file_context.fallback_filters.use_team_filter' => false,
        ]);

        $resolver = new FileAccessResolver();

        // Create a mock user
        $user = (object) ['id' => 123];

        // This should not throw, should use fallback
        $result = $resolver->getAccessibleFileIds($user);

        // Result should be an array (possibly empty if no files match)
        $this->assertIsArray($result);
    }
}
```

**Step 2: Run the tests**

```bash
php vendor/bin/phpunit tests/Feature/FileContextIntegrationTest.php
```

**Step 3: Commit**

```bash
git add tests/Feature/FileContextIntegrationTest.php
git commit -m "test(file-context): add integration tests for file context retrieval"
```

---

## Summary

| Task | File | Issue | Status |
|------|------|-------|--------|
| 1 | FileContextProvider | Key mismatch (filename, relevance) | Fix |
| 2 | FileSearchService | Hardcoded File import | Use FileModel facade |
| 3 | config/ai.php | Missing fallback config + high min_score | Add config |
| 4 | FileAccessResolver | No fallback logic | Add user_id + team_id fallback |
| 5 | FileAccessScopePlugin | Missing automatic scope | Create plugin |
| 6 | AiServiceProvider | Plugin not registered | Register plugin |
| 7 | Tests | No integration tests | Create |

**Key Architecture Points:**

1. **FileModel Facade** - Used everywhere for overridability
2. **accessibleBy scope** - Primary access control method
3. **Fallback filters** - When scope unavailable: user_id (always) + team_id (configurable)
4. **Plugin system** - FileAccessScopePlugin provides default accessibleBy implementation
5. **Config options** - `use_user_filter` and `use_team_filter` control fallback behavior

**Total:** 7 tasks across 5 existing files + 2 new files
