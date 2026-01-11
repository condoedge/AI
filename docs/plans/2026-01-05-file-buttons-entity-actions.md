# File Buttons & Entity Profile Actions Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fix broken file preview buttons and add entity profile action support in AI responses.

**Architecture:** Refactor to use utils package's existing file preview infrastructure. Add optional action configuration to entity discovery configs for profile links/modals.

**Tech Stack:** Kompo components, Laravel morphable relations, existing utils package patterns.

---

## Background

### Current Problems
1. `FilePreviewModal` has placeholder implementation - doesn't fetch actual file data
2. File preview buttons in AI responses don't work
3. No mechanism for entity profile URLs/actions in responses

### Utils Package Patterns (to reuse)
- `DisplayFileModal` - Routes to correct preview component by MIME type
- `AbstractPreview` - Morphable model resolution via `Relation::morphMap()`
- `FileTypeEnum` - MIME type to component mapping
- `FileActionsKomponents` trait - Action generation pattern

---

## Task 1: Store Morphable Type in File References

**Files:**
- Modify: `src/Services/Response/ResponseFileEnricher.php`

**Context:** The utils package uses morphable types to resolve file models. We need to include this in file references.

**Step 1: Update buildFileReference to include morphable type**

In `buildFileReference()` method around line 132, add morphable_type to the return arrays:

```php
private function buildFileReference(array $file, int $refNumber, array $options): array
{
    $fileId = $file['file_id'];
    $source = $file['source'] ?? 'database';
    $isPhysical = $this->isPhysicalFile($fileId);

    // Physical files cannot be downloaded/previewed directly
    if ($isPhysical) {
        return [
            'ref' => $refNumber,
            'id' => $fileId,
            'name' => $file['filename'],
            'snippet' => $file['snippet'],
            'relevance_score' => $file['relevance'],
            'source' => $source,
            'chunk_index' => $file['chunk_index'],
            'download_url' => null,
            'preview_url' => null,
            'can_download' => false,
            'morphable_type' => null,  // Physical files have no morphable type
            'mime_type' => $file['mime_type'] ?? null,
        ];
    }

    // Database files - resolve URLs and include morphable type
    $downloadUrl = $this->resolveUrl($fileId, $options['download_url_resolver'] ?? null);
    $previewUrl = $this->resolveUrl($fileId, $options['preview_url_resolver'] ?? null);
    $canDownload = $this->resolveCanDownload(
        $fileId,
        $options['user'] ?? null,
        $options['can_download_resolver'] ?? null
    );

    // Get morphable type - default to 'file' for File model
    $morphableType = $file['morphable_type'] ?? $options['default_morphable_type'] ?? 'file';

    return [
        'ref' => $refNumber,
        'id' => $fileId,
        'name' => $file['filename'],
        'snippet' => $file['snippet'],
        'relevance_score' => $file['relevance'],
        'source' => $source,
        'chunk_index' => $file['chunk_index'],
        'download_url' => $downloadUrl,
        'preview_url' => $previewUrl,
        'can_download' => $canDownload,
        'morphable_type' => $morphableType,
        'mime_type' => $file['mime_type'] ?? null,
    ];
}
```

**Step 2: Verify existing tests still pass**

Run: `vendor/bin/phpunit tests/Unit/Services/Response/ResponseFileEnricherTest.php`
Expected: All tests pass (new fields don't break existing behavior)

---

## Task 2: Update MessagesQuery to Use DisplayFileModal

**Files:**
- Modify: `src/Kompo/MessagesQuery.php`

**Context:** Replace broken `FilePreviewModal` with utils package's `DisplayFileModal`.

**Step 1: Update viewFile method**

Find `viewFile` method around line 584 and update:

```php
use Condoedge\Utils\Kompo\Files\DisplayFileModal;

// ... in the class ...

public function viewFile($id, $type = 'file', $mime = null)
{
    // Use utils package's DisplayFileModal which handles all preview types
    return new DisplayFileModal(null, [
        'mime' => $mime ?? 'application/octet-stream',
        'type' => $type,
        'id' => $id,
    ]);
}
```

**Step 2: Update fileReferenceCard to pass morphable type and mime**

Find `fileReferenceCard` method around line 453 and update the selfGet call:

```php
protected function fileReferenceCard(array $file)
{
    $icon = match($file['type'] ?? 'file') {
        'pdf' => 'document-text',
        'image', 'png', 'jpg' => 'photo',
        'spreadsheet', 'xlsx', 'csv' => 'table-cells',
        default => 'document',
    };

    return _Flex(
        _Sax($icon, 16)->class($this->theme()->primaryText()),
        _Html($file['name'])->class('text-sm text-gray-700 font-medium'),
    )->class('inline-flex items-center gap-2 px-3 py-2 rounded-lg ' . $this->theme()->primaryLightBg() . ' ' . $this->theme()->primaryLightBgHover() . ' cursor-pointer transition-all')
     ->balloon(__('ai.messages.click-to-view'), 'up')
     ->selfGet('viewFile', [
         'id' => $file['id'],
         'type' => $file['morphable_type'] ?? 'file',
         'mime' => $file['mime_type'] ?? null,
     ])->inModal();
}
```

**Step 3: Test file preview manually**

Test by clicking a file reference card in the AI chat - should open DisplayFileModal with actual file content.

---

## Task 3: Add Entity Action Config to Discovery

**Files:**
- Modify: `src/Services/Discovery/EntityAutoDiscovery.php`
- Modify: `config/ai.php`

**Context:** Allow entity configs to specify optional profile actions.

**Step 1: Update entity config structure in ai.php**

Add example of entity with profile action in config/ai.php entities section:

```php
'entities' => [
    // Example with profile action (applications should define their own)
    // 'Person' => [
    //     'label' => 'Person',
    //     'profileAction' => fn($id) => _Link('View Profile')
    //         ->href(route('people.show', $id)),
    // ],
],

// Alternatively, a dedicated section for entity actions
'entity_actions' => [
    // 'Person' => fn($id) => _Link('View Profile')->href(route('people.show', $id)),
    // 'Unit' => fn($id) => _Link('Details')->selfGet('showUnit', ['id' => $id])->inModal(),
],
```

**Step 2: Update EntityAutoDiscovery to include action resolver**

In `getEntityConfig` or equivalent method, merge in action config:

```php
public function getActionResolver(string $entityLabel): ?\Closure
{
    $actions = config('ai.entity_actions', []);
    return $actions[$entityLabel] ?? null;
}
```

---

## Task 4: Store Entity Actions with Query Results

**Files:**
- Modify: `src/Services/ResponseGenerator.php` (or `ResponseEntityEnricher.php` if exists)

**Context:** When returning entity results, include action metadata if configured.

**Step 1: Check if ResponseEntityEnricher exists, create if needed**

Search for existing entity enrichment. If none exists, create similar to ResponseFileEnricher:

```php
// src/Services/Response/ResponseEntityEnricher.php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Response;

use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;

class ResponseEntityEnricher
{
    public function __construct(
        private EntityAutoDiscovery $discovery
    ) {}

    /**
     * Enrich entity results with action metadata
     */
    public function enrichEntityResults(array $results, array $options = []): array
    {
        $enriched = [];

        foreach ($results as $result) {
            $entityLabel = $result['_label'] ?? $result['type'] ?? null;
            if (!$entityLabel) {
                $enriched[] = $result;
                continue;
            }

            $actionResolver = $this->discovery->getActionResolver($entityLabel);

            $enriched[] = array_merge($result, [
                'has_profile_action' => $actionResolver !== null,
                'entity_type' => $entityLabel,
            ]);
        }

        return $enriched;
    }
}
```

**Step 2: Integrate into response flow**

In ResponseGenerator or AiChatService, call entity enricher on results before returning.

---

## Task 5: Render Entity Action Buttons in MessagesQuery

**Files:**
- Modify: `src/Kompo/MessagesQuery.php`

**Context:** Display action buttons for entities that have profile actions configured.

**Step 1: Add entity action rendering method**

```php
protected function renderEntityActions(array $result)
{
    if (empty($result['has_profile_action'])) {
        return null;
    }

    $entityType = $result['entity_type'];
    $entityId = $result['id'] ?? $result['_id'] ?? null;

    if (!$entityId) {
        return null;
    }

    return _Flex(
        _Link(__('ai.entity.view-profile'))
            ->icon('arrow-top-right-on-square')
            ->class('text-sm ' . $this->theme()->primaryText())
            ->selfGet('entityAction', [
                'type' => $entityType,
                'id' => $entityId,
            ])->inModal(),
    )->class('mt-2');
}

public function entityAction($type, $id)
{
    $discovery = app(EntityAutoDiscovery::class);
    $actionResolver = $discovery->getActionResolver($type);

    if (!$actionResolver) {
        return _Html(__('ai.entity.no-action-configured'));
    }

    // The resolver returns a Kompo element
    return $actionResolver($id);
}
```

**Step 2: Integrate into result rendering**

Find where query results are rendered and add entity action buttons.

---

## Task 6: Delete or Simplify FilePreviewModal

**Files:**
- Delete or archive: `src/Kompo/Modals/FilePreviewModal.php`

**Context:** Now that we use DisplayFileModal from utils, the custom modal is unnecessary.

**Step 1: Check for other usages**

Search codebase for FilePreviewModal references beyond MessagesQuery.

**Step 2: Delete if unused elsewhere**

If only used in MessagesQuery (now updated), delete the file `src/Kompo/Modals/FilePreviewModal.php`.

Or if there are edge cases, simplify to redirect to DisplayFileModal.

---

## Task 7: Add Integration Test

**Files:**
- Create: `tests/Feature/FilePreviewIntegrationTest.php`

**Context:** Verify file preview works end-to-end.

**Step 1: Create integration test**

```php
<?php

namespace Condoedge\Ai\Tests\Feature;

use Condoedge\Ai\Kompo\MessagesQuery;
use Orchestra\Testbench\TestCase;

class FilePreviewIntegrationTest extends TestCase
{
    /** @test */
    public function it_returns_display_file_modal_for_database_files(): void
    {
        $query = new MessagesQuery();

        $modal = $query->viewFile(123, 'file', 'application/pdf');

        $this->assertInstanceOf(
            \Condoedge\Utils\Kompo\Files\DisplayFileModal::class,
            $modal
        );
    }

    /** @test */
    public function file_reference_card_includes_morphable_type(): void
    {
        $query = new MessagesQuery();

        // Use reflection to test protected method
        $method = new \ReflectionMethod($query, 'fileReferenceCard');
        $method->setAccessible(true);

        $card = $method->invoke($query, [
            'id' => 123,
            'name' => 'test.pdf',
            'morphable_type' => 'file',
            'mime_type' => 'application/pdf',
        ]);

        $this->assertNotNull($card);
    }
}
```

**Step 2: Run tests**

Run: `vendor/bin/phpunit tests/Feature/FilePreviewIntegrationTest.php`
Expected: All tests pass

---

## Summary

| Task | Description | Files |
|------|-------------|-------|
| 1 | Store morphable type in file references | ResponseFileEnricher.php |
| 2 | Use DisplayFileModal from utils | MessagesQuery.php |
| 3 | Add entity action config | EntityAutoDiscovery.php, config/ai.php |
| 4 | Store entity actions with results | ResponseEntityEnricher.php (new) |
| 5 | Render entity action buttons | MessagesQuery.php |
| 6 | Delete unused FilePreviewModal | FilePreviewModal.php |
| 7 | Add integration test | FilePreviewIntegrationTest.php |

## Dependencies

- Tasks 1, 2 must complete before file buttons work
- Tasks 3, 4, 5 are for entity actions (can be done in parallel with 1-2)
- Task 6 after task 2 verified working
- Task 7 after all other tasks

---

## IMPORTANT: No Git Operations

**Git usage is FORBIDDEN during this implementation process.**

Do not:
- Create commits
- Stage files
- Run any git commands
- Create branches

All version control will be handled separately after implementation is complete.
