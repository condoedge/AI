# Fix File Citation Clicks Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Make file citation links `[1]`, `[2]` clickable to open file preview modals

**Architecture:** Adopt the same proxy-based pattern used for action buttons (copy, feedback, regenerate). The `FileCitationHandler` creates visible link elements with `data-action-slot` attributes, while `HasStagedMessageRendering` creates hidden proxy elements with Kompo bindings. JavaScript wires the visible buttons to trigger proxy clicks.

**Tech Stack:** PHP (Kompo components, handlers), JavaScript (chat-message-injector.js)

---

## Root Cause Analysis

**Why file citations don't work:**
- `FileCitationHandler.php:61-72` creates `_Link()->selfGet('viewFile', ...)`
- `selfGet()` requires Kompo component context to work
- When rendered in `HasStagedMessageRendering`, the link is outside component context
- Result: visible `[1]` link, but clicking does nothing

**Why Person action links work:**
- `ActionLinkHandler.php` uses resolvers from `config/ai.php`
- Resolvers return `_Link()->href(route(...))` - static HTML links
- No component context needed - just regular `<a href="/path">` tags

**Existing proxy pattern (that works):**
- `HasStagedMessageRendering.php:128-164` creates hidden proxy buttons:
  - `_Link()->selfPost('feedback', ...)->class('hidden js-action-feedback-pos-proxy')`
- `chat-message-injector.js:220-281` wires visible buttons to proxies:
  - Finds `js-action-*` buttons, matches to `js-action-*-proxy` elements
  - Attaches click handler that triggers `proxy.click()`

---

## Task 1: Add File Citation Metadata to ContentLinkProcessor

**Files:**
- Modify: `src/Services/Response/ContentLinkProcessor.php`
- Test: `tests/Unit/Services/Response/ContentLinkProcessorTest.php` (create)

**Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Services\Response;

use Condoedge\Ai\Services\Response\ContentLinkProcessor;
use Condoedge\Ai\Services\Response\ActionLinkHandler;
use Condoedge\Ai\Services\Response\FileCitationHandler;
use Tests\TestCase;

class ContentLinkProcessorTest extends TestCase
{
    private ContentLinkProcessor $processor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processor = new ContentLinkProcessor(
            new ActionLinkHandler(),
            new FileCitationHandler()
        );
    }

    public function test_processForDirectRendering_returns_file_citation_metadata()
    {
        $content = 'Based on [1] and [2], the answer is clear.';
        $files = [
            ['id' => 101, 'name' => 'report.pdf', 'morphable_type' => 'file', 'mime_type' => 'application/pdf'],
            ['id' => 102, 'name' => 'data.xlsx', 'morphable_type' => 'file', 'mime_type' => 'application/xlsx'],
        ];

        $result = $this->processor->processForDirectRendering($content, ['files' => $files]);

        $this->assertArrayHasKey('file_citations', $result);
        $this->assertCount(2, $result['file_citations']);

        $this->assertEquals([
            'slot' => 'file-citation-1',
            'id' => 101,
            'type' => 'file',
            'mime' => 'application/pdf',
        ], $result['file_citations'][0]);

        $this->assertEquals([
            'slot' => 'file-citation-2',
            'id' => 102,
            'type' => 'file',
            'mime' => 'application/xlsx',
        ], $result['file_citations'][1]);
    }

    public function test_processForDirectRendering_returns_empty_array_when_no_citations()
    {
        $content = 'No citations here.';

        $result = $this->processor->processForDirectRendering($content, ['files' => []]);

        $this->assertArrayHasKey('file_citations', $result);
        $this->assertEmpty($result['file_citations']);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Services/Response/ContentLinkProcessorTest.php --filter test_processForDirectRendering_returns_file_citation_metadata -v`
Expected: FAIL with "Failed asserting that an array has the key 'file_citations'"

**Step 3: Write minimal implementation**

Modify `src/Services/Response/ContentLinkProcessor.php` to track file citation metadata:

```php
<?php

// Add property to track citations
private array $fileCitationMetadata = [];

// In processForDirectRendering(), after processing handlers:
public function processForDirectRendering(string $content, array $context = []): array
{
    $this->fileCitationMetadata = []; // Reset

    $elements = [];
    $strippedContent = $content;

    foreach ($this->handlers as $handler) {
        if ($handler->hasLinks($strippedContent)) {
            $handlerElements = $handler->createElements($strippedContent, $context);
            $elements = array_merge($elements, $handlerElements);
            $strippedContent = $handler->stripLinks($strippedContent);

            // Collect file citation metadata for proxy creation
            if ($handler instanceof FileCitationHandler) {
                $this->fileCitationMetadata = $handler->getCitationMetadata();
            }
        }
    }

    return [
        'content' => $strippedContent,
        'elements' => $elements,
        'has_links' => !empty($elements),
        'file_citations' => $this->fileCitationMetadata,
    ];
}
```

**Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Services/Response/ContentLinkProcessorTest.php -v`
Expected: PASS

**Step 5: Commit**

```bash
git add tests/Unit/Services/Response/ContentLinkProcessorTest.php src/Services/Response/ContentLinkProcessor.php
git commit -m "$(cat <<'EOF'
feat(citations): add file citation metadata to ContentLinkProcessor

Enables HasStagedMessageRendering to create proxy elements for file
citations by exposing citation metadata (slot, id, type, mime).

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Implement getCitationMetadata() in FileCitationHandler

**Files:**
- Modify: `src/Services/Response/FileCitationHandler.php`
- Test: `tests/Unit/Services/Response/FileCitationHandlerTest.php` (create)

**Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Services\Response;

use Condoedge\Ai\Services\Response\FileCitationHandler;
use Tests\TestCase;

class FileCitationHandlerTest extends TestCase
{
    private FileCitationHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new FileCitationHandler();
    }

    public function test_getCitationMetadata_returns_metadata_after_createElements()
    {
        $content = 'See [1] and [2] for details.';
        $files = [
            ['id' => 42, 'name' => 'doc.pdf', 'morphable_type' => 'attachment', 'mime_type' => 'application/pdf'],
            ['id' => 43, 'name' => 'img.png', 'morphable_type' => 'file', 'mime_type' => 'image/png'],
        ];

        // Must call createElements first to populate metadata
        $this->handler->createElements($content, ['files' => $files]);
        $metadata = $this->handler->getCitationMetadata();

        $this->assertCount(2, $metadata);

        $this->assertEquals('file-citation-1', $metadata[0]['slot']);
        $this->assertEquals(42, $metadata[0]['id']);
        $this->assertEquals('attachment', $metadata[0]['type']);
        $this->assertEquals('application/pdf', $metadata[0]['mime']);

        $this->assertEquals('file-citation-2', $metadata[1]['slot']);
        $this->assertEquals(43, $metadata[1]['id']);
    }

    public function test_getCitationMetadata_handles_missing_files()
    {
        $content = 'See [1] and [5] for details.'; // [5] has no matching file
        $files = [
            ['id' => 42, 'name' => 'doc.pdf', 'morphable_type' => 'file', 'mime_type' => 'application/pdf'],
        ];

        $this->handler->createElements($content, ['files' => $files]);
        $metadata = $this->handler->getCitationMetadata();

        // Only [1] should have metadata, [5] skipped (no file at index 4)
        $this->assertCount(1, $metadata);
        $this->assertEquals('file-citation-1', $metadata[0]['slot']);
    }

    public function test_created_elements_have_data_action_slot_attribute()
    {
        $content = 'See [1] for details.';
        $files = [
            ['id' => 42, 'name' => 'doc.pdf', 'morphable_type' => 'file', 'mime_type' => 'application/pdf'],
        ];

        $elements = $this->handler->createElements($content, ['files' => $files]);

        $this->assertCount(1, $elements);

        // Render element to HTML and check for data attribute
        $html = $elements[0]->render();
        $this->assertStringContainsString('data-action-slot="file-citation-1"', $html);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Services/Response/FileCitationHandlerTest.php --filter test_getCitationMetadata_returns_metadata_after_createElements -v`
Expected: FAIL with "Call to undefined method ... getCitationMetadata()"

**Step 3: Write minimal implementation**

Modify `src/Services/Response/FileCitationHandler.php`:

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Response;

/**
 * Handler for file citation links [1], [2], etc.
 */
class FileCitationHandler extends AbstractContentLinkHandler
{
    private const CITATION_PATTERN = '/\[(\d+)\]/';

    /**
     * Metadata for citations processed in the last createElements() call.
     * Used by ContentLinkProcessor to create proxy elements.
     */
    private array $citationMetadata = [];

    public function getPatterns(): string
    {
        return self::CITATION_PATTERN;
    }

    protected function getDeduplicationKey(array $match): string
    {
        return "file-citation-{$match[1]}";
    }

    protected function createElementForMatch(array $match, array $context): mixed
    {
        $citationNumber = (int) $match[1];
        $files = $context['files'] ?? [];

        // Citations are 1-indexed, array is 0-indexed
        $fileIndex = $citationNumber - 1;

        if (!isset($files[$fileIndex])) {
            return null;
        }

        $file = $files[$fileIndex];
        $slot = "file-citation-{$citationNumber}";

        // Store metadata for proxy creation
        $this->citationMetadata[] = [
            'slot' => $slot,
            'id' => $file['id'] ?? null,
            'type' => $file['morphable_type'] ?? 'file',
            'mime' => $file['mime_type'] ?? null,
        ];

        return $this->createFileLink($file, $citationNumber, $slot);
    }

    protected function getStripReplacement(array $match): string
    {
        return '';
    }

    /**
     * Get metadata for citations processed in last createElements() call.
     *
     * @return array<array{slot: string, id: mixed, type: string, mime: ?string}>
     */
    public function getCitationMetadata(): array
    {
        return $this->citationMetadata;
    }

    /**
     * Create a clickable file citation link.
     *
     * Creates a visible link with data-action-slot attribute.
     * The actual selfGet() binding is created as a hidden proxy in HasStagedMessageRendering.
     */
    protected function createFileLink(array $file, int $citationNumber, string $slot): mixed
    {
        $fileName = $file['name'] ?? "File {$citationNumber}";

        return _Link("[{$citationNumber}]")
            ->class('text-indigo-600 font-medium cursor-pointer hover:underline')
            ->balloon($fileName, 'up')
            ->attr(['data-action-slot' => $slot]);
    }

    /**
     * Reset metadata before processing new content.
     * Called by AbstractContentLinkHandler::createElements().
     */
    protected function beforeCreateElements(): void
    {
        $this->citationMetadata = [];
    }
}
```

**Step 4: Add beforeCreateElements hook to AbstractContentLinkHandler**

Modify `src/Services/Response/AbstractContentLinkHandler.php`:

```php
// Add before the createElements loop:
public function createElements(string $content, array $context = []): array
{
    $this->beforeCreateElements(); // Add this hook

    // ... rest of existing code
}

/**
 * Hook called before processing elements. Override to reset state.
 */
protected function beforeCreateElements(): void
{
    // Default: do nothing. Subclasses can override.
}
```

**Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Services/Response/FileCitationHandlerTest.php -v`
Expected: PASS

**Step 6: Commit**

```bash
git add src/Services/Response/FileCitationHandler.php src/Services/Response/AbstractContentLinkHandler.php tests/Unit/Services/Response/FileCitationHandlerTest.php
git commit -m "$(cat <<'EOF'
feat(citations): add getCitationMetadata() and data-action-slot to FileCitationHandler

File citation links now have data-action-slot attributes for JS wiring.
Metadata is collected during createElements() for proxy creation.

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Create File Citation Proxies in HasStagedMessageRendering

**Files:**
- Modify: `src/Kompo/Traits/HasStagedMessageRendering.php`
- Test: `tests/Unit/Kompo/HasStagedMessageRenderingTest.php` (create or extend)

**Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Kompo;

use Condoedge\Ai\Kompo\ChatMessageForm;
use Condoedge\Ai\Models\AiMessage;
use Condoedge\Ai\Models\AiConversation;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class HasStagedMessageRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_renderStagedAssistantResponse_creates_file_citation_proxies()
    {
        // Create test conversation and message with file references
        $conversation = AiConversation::factory()->create(['user_id' => 1]);
        $message = AiMessage::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Based on [1], the answer is yes.',
            'metadata' => [
                'file_references' => [
                    ['id' => 42, 'name' => 'doc.pdf', 'morphable_type' => 'file', 'mime_type' => 'application/pdf'],
                ],
            ],
        ]);

        // Use a test component that uses the trait
        $form = new ChatMessageForm(null, ['conversation_id' => $conversation->id]);
        $this->actingAs(\App\Models\User::factory()->create());

        // Call the protected method via reflection
        $reflection = new \ReflectionClass($form);
        $method = $reflection->getMethod('renderStagedAssistantResponse');
        $method->setAccessible(true);

        $result = $method->invoke($form, $message);
        $html = $result->render();

        // Verify proxy elements exist with correct attributes
        $this->assertStringContainsString('data-action-proxy="file-citation-1"', $html);
        $this->assertStringContainsString('js-file-citation-proxy', $html);
        $this->assertStringContainsString('hidden', $html);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Kompo/HasStagedMessageRenderingTest.php --filter test_renderStagedAssistantResponse_creates_file_citation_proxies -v`
Expected: FAIL - no proxy elements in output

**Step 3: Write minimal implementation**

Modify `src/Kompo/Traits/HasStagedMessageRendering.php`:

```php
<?php

// Add new method after stagedAssistantProxies():

/**
 * Hidden proxy buttons for file citation actions (with Kompo bindings).
 * JS moves these into the assistant message element for persistence.
 *
 * @param array $fileCitations Citation metadata from ContentLinkProcessor
 */
protected function stagedFileCitationProxies(array $fileCitations)
{
    if (empty($fileCitations)) {
        return null;
    }

    $proxies = [];

    foreach ($fileCitations as $citation) {
        $proxies[] = _Link()
            ->selfGet('viewFile', [
                'id' => $citation['id'],
                'type' => $citation['type'],
                'mime' => $citation['mime'],
            ])
            ->inModal()
            ->class('hidden js-file-citation-proxy')
            ->attr(['data-action-proxy' => $citation['slot']]);
    }

    return _Rows(...$proxies)
        ->class('chat-staged-file-citation-proxies hidden');
}

// Modify renderStagedAssistantResponse() to include file citation proxies:

protected function renderStagedAssistantResponse(AiMessage $assistantMessage, ?AiMessage $userMessage = null)
{
    $renderer = new SafeMarkdownRenderer();
    $linkProcessor = app(ContentLinkProcessor::class);

    // Get file references for citation linking
    $files = $assistantMessage->hasFileReferences() ? $assistantMessage->getReferencedFiles() : [];

    // Process content: strip links, create elements (actions + file citations)
    $processed = $linkProcessor->processForDirectRendering(
        $assistantMessage->content,
        ['files' => $files]
    );

    // Render clean markdown (links replaced with plain text)
    $htmlContent = $renderer->render($processed['content']);

    return _Rows(
        // 1. Main content
        _Html($htmlContent)
            ->class('prose prose-sm max-w-none chat-staged-content'),

        // 2. Link elements rendered directly (actions + file citations)
        $this->stagedLinkElements($processed['elements']),

        // 3. Visible action bar - injected alongside content
        $this->stagedVisibleActionBar($assistantMessage),

        // 4. Hidden proxies for assistant actions - moved into assistant message element
        $this->stagedAssistantProxies($assistantMessage),

        // 5. Hidden proxies for file citations - NEW
        $this->stagedFileCitationProxies($processed['file_citations'] ?? []),

        // 6. Hidden proxy for user edit - moved into user message element
        $this->stagedUserEditProxy($userMessage),

    )->attr([
        'data-user-message-id' => $userMessage?->id,
        'data-assistant-message-id' => $assistantMessage->id,
    ]);
}
```

**Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Kompo/HasStagedMessageRenderingTest.php -v`
Expected: PASS

**Step 5: Commit**

```bash
git add src/Kompo/Traits/HasStagedMessageRendering.php tests/Unit/Kompo/HasStagedMessageRenderingTest.php
git commit -m "$(cat <<'EOF'
feat(citations): create hidden proxy elements for file citations

HasStagedMessageRendering now creates proxy elements with selfGet()
bindings for each file citation. Proxies have data-action-proxy
attributes matching the data-action-slot on visible links.

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Wire File Citation Proxies in JavaScript

**Files:**
- Modify: `resources/js/chat-message-injector.js`
- Test: Manual testing (JS tests require browser environment)

**Step 1: Understand existing wiring pattern**

The existing `wireActionButtons()` method (lines 220-281) does:
1. Find buttons with `js-action-*` class (not `*-proxy`)
2. Find corresponding `js-action-*-proxy` element
3. Attach click handler: `btn.onclick = () => proxy.click()`

**Step 2: Extend wiring to support data-action-slot pattern**

Modify `resources/js/chat-message-injector.js`:

```javascript
/**
 * Wire visible action buttons to their proxy counterparts within the same element.
 * Supports two patterns:
 * 1. Class-based: js-action-X → js-action-X-proxy
 * 2. Data attribute: data-action-slot="X" → data-action-proxy="X"
 */
wireActionButtons(messageElement) {
    if (!messageElement) return;

    // Pattern 1: Class-based wiring (existing - for copy, feedback, regenerate)
    const visibleButtons = messageElement.querySelectorAll('[class*="js-action-"]:not([class*="-proxy"])');

    visibleButtons.forEach(btn => {
        const actionClass = [...btn.classList].find(c => /^js-action-[\w-]+$/.test(c) && !c.includes('-proxy'));
        if (!actionClass) {
            console.warn('[AI Chat] Action button found but no action class extracted:', btn);
            return;
        }

        const proxyClass = actionClass + '-proxy';
        const proxy = messageElement.querySelector('.' + proxyClass);

        if (proxy) {
            btn.onclick = (e) => {
                e.preventDefault();
                e.stopPropagation();

                if (actionClass === 'js-action-feedback-pos') {
                    this.setFeedbackState(messageElement, 'positive');
                } else if (actionClass === 'js-action-feedback-neg') {
                    this.setFeedbackState(messageElement, 'negative');
                }

                if (actionClass === 'js-action-regenerate') {
                    this.showRegeneratingIndicator(messageElement);
                }

                try {
                    proxy.click();
                } catch (err) {
                    console.error('[AI Chat] Proxy click failed:', actionClass, err);
                }
            };
        } else {
            if (actionClass.includes('-entity-') || actionClass.includes('-generic-')) {
                console.warn('[AI Chat] Action proxy not found:', proxyClass, 'Button:', btn);
            }
        }
    });

    // Pattern 2: Data attribute wiring (new - for file citations)
    const slotButtons = messageElement.querySelectorAll('[data-action-slot]');

    slotButtons.forEach(btn => {
        const slot = btn.getAttribute('data-action-slot');
        if (!slot) return;

        const proxy = messageElement.querySelector(`[data-action-proxy="${slot}"]`);

        if (proxy) {
            btn.onclick = (e) => {
                e.preventDefault();
                e.stopPropagation();

                try {
                    proxy.click();
                } catch (err) {
                    console.error('[AI Chat] File citation proxy click failed:', slot, err);
                }
            };
        } else {
            console.warn('[AI Chat] File citation proxy not found for slot:', slot);
        }
    });

    // Legacy .js-edit-message class
    messageElement.querySelectorAll('.js-edit-message').forEach(btn => {
        const proxy = messageElement.querySelector('.js-action-edit-proxy');
        if (proxy) {
            btn.onclick = (e) => {
                e.preventDefault();
                e.stopPropagation();
                proxy.click();
            };
        }
    });
},
```

**Step 3: Ensure file citation proxies are moved into message element**

In `processServerResponse()`, add handling for file citation proxies:

```javascript
processServerResponse() {
    const stagingPanel = document.getElementById(this.stagingPanelId);
    if (!stagingPanel || !stagingPanel.innerHTML.trim()) {
        console.warn('Staging panel empty or not found');
        return;
    }

    // ... existing code for content and action bar ...

    // 4. Handle typing indicator element (becomes assistant message)
    const typingIndicators = document.querySelectorAll('[data-placeholder="typing-indicator"]');
    const typingIndicator = typingIndicators.length > 0 ? typingIndicators[typingIndicators.length - 1] : null;
    if (typingIndicator) {
        typingIndicator.removeAttribute('data-placeholder');
        typingIndicator.removeAttribute('data-void');

        if (assistantMessageId) {
            typingIndicator.setAttribute('data-message-id', assistantMessageId);
        }

        // Move assistant proxies INTO this element (preserves Kompo bindings)
        const assistantProxies = stagingPanel.querySelector('.chat-staged-assistant-proxies');
        if (assistantProxies) {
            typingIndicator.appendChild(assistantProxies);
        }

        // Move file citation proxies INTO this element (NEW)
        const fileCitationProxies = stagingPanel.querySelector('.chat-staged-file-citation-proxies');
        if (fileCitationProxies) {
            typingIndicator.appendChild(fileCitationProxies);
        }

        // Wire visible action buttons to proxies within same element
        this.wireActionButtons(typingIndicator);
    }

    // ... rest of existing code ...
},
```

**Step 4: Manual test**

1. Create a conversation with file uploads
2. Send a message that will trigger AI to cite files as `[1]`, `[2]`
3. Verify `[1]` link is visible and styled
4. Click `[1]` - should open file preview modal
5. Check browser console for any JS errors

**Step 5: Commit**

```bash
git add resources/js/chat-message-injector.js
git commit -m "$(cat <<'EOF'
feat(citations): wire file citation links to proxy buttons in JS

Extends wireActionButtons() to support data-action-slot/data-action-proxy
pattern. File citation proxies are moved into message element and wired
to visible [1] links.

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: Update MessagesQuery to Use Same Pattern for Rendered Messages

**Files:**
- Modify: `src/Kompo/MessagesQuery.php`
- Test: `tests/Feature/FilePreviewIntegrationTest.php` (extend)

**Step 1: Understand the issue**

`MessagesQuery::assistantBubble()` (lines 234-298) also renders file citations, but directly via `_Flex(...$processed['elements'])`. These elements have the same problem - `selfGet()` outside component context won't work if the links are rendered this way.

Wait - looking at `assistantBubble()` more closely:
- It renders inside a Kompo Query, which IS a component
- The `_Link()->selfGet()` elements should work because they're rendered within the component's render context

Let me verify this is actually broken or if it's only the staged rendering path.

**Step 2: Write the test to verify behavior**

```php
<?php

namespace Tests\Feature;

use Condoedge\Ai\Kompo\MessagesQuery;
use Condoedge\Ai\Models\AiMessage;
use Condoedge\Ai\Models\AiConversation;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class MessagesQueryFileCitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_assistantBubble_file_citations_have_correct_attributes()
    {
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);

        $conversation = AiConversation::factory()->create(['user_id' => $user->id]);
        $message = AiMessage::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Based on [1], here is the answer.',
            'metadata' => [
                'file_references' => [
                    ['id' => 42, 'name' => 'doc.pdf', 'morphable_type' => 'file', 'mime_type' => 'application/pdf'],
                ],
            ],
        ]);

        $query = new MessagesQuery(null, ['conversation_id' => $conversation->id]);

        // Get rendered bubble using reflection
        $reflection = new \ReflectionClass($query);
        $method = $reflection->getMethod('assistantBubble');
        $method->setAccessible(true);

        $bubble = $method->invoke($query, $message);
        $html = $bubble->render();

        // The link should have data-action-slot for the new pattern
        $this->assertStringContainsString('data-action-slot="file-citation-1"', $html);
    }
}
```

**Step 3: If the test fails, update MessagesQuery**

The `assistantBubble()` in `MessagesQuery` renders elements directly, so it should work differently:
- It's in component context, so `selfGet()` SHOULD work
- But we changed `FileCitationHandler` to not use `selfGet()` anymore - it uses `data-action-slot`

This means `MessagesQuery` needs to also create its own proxy elements, OR we need two paths.

**DESIGN DECISION:** Keep one pattern - both staged and rendered messages use the slot/proxy pattern.

Update `MessagesQuery::assistantBubble()`:

```php
protected function assistantBubble($message)
{
    // ... existing setup code ...

    // Get file references for citation linking
    $files = $message->hasFileReferences() ? $message->getReferencedFiles() : [];

    // Process content: strip action links, strip citations, create elements
    $processed = $this->getContentLinkProcessor()->processForDirectRendering(
        $message->content,
        ['files' => $files]
    );

    // Main content (links stripped to plain text)
    $content[] = _Html($this->renderMarkdown($processed['content']))->class('prose prose-sm max-w-none' . $contentRevealClass);

    // Link elements rendered directly (actions + file citations)
    if ($processed['has_links']) {
        $content[] = _Flex(...$processed['elements'])
            ->class('mt-3 pt-2 border-t border-gray-100 gap-2 flex-wrap');
    }

    // File citation proxy elements (hidden, with Kompo bindings)
    if (!empty($processed['file_citations'])) {
        foreach ($processed['file_citations'] as $citation) {
            $content[] = _Link()
                ->selfGet('viewFile', [
                    'id' => $citation['id'],
                    'type' => $citation['type'],
                    'mime' => $citation['mime'],
                ])
                ->inModal()
                ->class('hidden')
                ->attr(['data-action-proxy' => $citation['slot']]);
        }
    }

    // ... rest of existing code ...
}
```

**Step 4: Run tests**

Run: `vendor/bin/phpunit tests/Feature/MessagesQueryFileCitationTest.php -v`
Expected: PASS

**Step 5: Commit**

```bash
git add src/Kompo/MessagesQuery.php tests/Feature/MessagesQueryFileCitationTest.php
git commit -m "$(cat <<'EOF'
feat(citations): add file citation proxies to MessagesQuery::assistantBubble

Both rendered and staged messages now use the same slot/proxy pattern
for file citations. MessagesQuery creates inline proxy elements.

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: Add Client-Side Wiring for Rendered Messages

**Files:**
- Modify: `resources/js/chat-message-injector.js`
- Add: Initialization script that wires existing messages

**Step 1: Create wiring function for page load**

The `wireActionButtons()` function works on individual message elements. We need to run it on page load for PHP-rendered messages.

Add to `chat-message-injector.js`:

```javascript
/**
 * Wire all file citation links on the page.
 * Called on page load for PHP-rendered messages.
 */
wireAllFileCitations() {
    // Find all message containers (PHP-rendered)
    const messageContainers = document.querySelectorAll('[data-message-id]');

    messageContainers.forEach(container => {
        // Check if this message has file citation slots
        const slots = container.querySelectorAll('[data-action-slot^="file-citation-"]');
        if (slots.length > 0) {
            this.wireActionButtons(container);
        }
    });
},
```

**Step 2: Create auto-initialization**

Add at the bottom of `chat-message-injector.js`:

```javascript
/**
 * Auto-initialize on DOMContentLoaded
 */
document.addEventListener('DOMContentLoaded', () => {
    // Wire file citations for PHP-rendered messages
    ChatMessageInjector.wireAllFileCitations();
});

/**
 * Re-wire after Kompo refreshes (for paginated content)
 */
document.addEventListener('kompo:refresh', () => {
    ChatMessageInjector.wireAllFileCitations();
});
```

**Step 3: Export function**

```javascript
window.wireAllFileCitations = () => ChatMessageInjector.wireAllFileCitations();
```

**Step 4: Manual test**

1. Load a conversation with existing messages containing `[1]` citations
2. Verify links are clickable immediately (not just after new messages)
3. Scroll up to trigger pagination, verify older messages also get wired

**Step 5: Commit**

```bash
git add resources/js/chat-message-injector.js
git commit -m "$(cat <<'EOF'
feat(citations): auto-wire file citation links on page load

Adds wireAllFileCitations() that runs on DOMContentLoaded and after
Kompo refreshes. PHP-rendered messages now have clickable file links.

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: Integration Test for Full Flow

**Files:**
- Modify: `tests/Feature/FilePreviewIntegrationTest.php`

**Step 1: Write comprehensive integration test**

```php
<?php

namespace Tests\Feature;

use Condoedge\Ai\Kompo\ChatMessageForm;
use Condoedge\Ai\Kompo\MessagesQuery;
use Condoedge\Ai\Models\AiMessage;
use Condoedge\Ai\Models\AiConversation;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class FilePreviewIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_file_citations_are_clickable_in_staged_response()
    {
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);

        $conversation = AiConversation::factory()->create(['user_id' => $user->id]);
        $message = AiMessage::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'According to [1] and [2], the data shows...',
            'metadata' => [
                'file_references' => [
                    ['id' => 10, 'name' => 'report.pdf', 'morphable_type' => 'file', 'mime_type' => 'application/pdf'],
                    ['id' => 11, 'name' => 'data.csv', 'morphable_type' => 'file', 'mime_type' => 'text/csv'],
                ],
            ],
        ]);

        // Render staged response (simulates new message injection)
        $form = new ChatMessageForm(null, ['conversation_id' => $conversation->id]);
        $reflection = new \ReflectionClass($form);
        $method = $reflection->getMethod('renderStagedAssistantResponse');
        $method->setAccessible(true);

        $result = $method->invoke($form, $message);
        $html = $result->render();

        // Verify: Visible links have data-action-slot
        $this->assertStringContainsString('data-action-slot="file-citation-1"', $html);
        $this->assertStringContainsString('data-action-slot="file-citation-2"', $html);

        // Verify: Hidden proxies exist with matching data-action-proxy
        $this->assertStringContainsString('data-action-proxy="file-citation-1"', $html);
        $this->assertStringContainsString('data-action-proxy="file-citation-2"', $html);

        // Verify: Proxies have correct selfGet parameters (check for viewFile method call)
        $this->assertStringContainsString('viewFile', $html);

        // Verify: Citations are stripped from content (we render clean markdown)
        // The original [1] and [2] should be replaced with buttons
        $this->assertStringNotContainsString('According to [1]', $html);
    }

    public function test_file_citations_are_clickable_in_rendered_messages()
    {
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);

        $conversation = AiConversation::factory()->create(['user_id' => $user->id]);
        $message = AiMessage::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'See [1] for details.',
            'metadata' => [
                'file_references' => [
                    ['id' => 42, 'name' => 'manual.pdf', 'morphable_type' => 'file', 'mime_type' => 'application/pdf'],
                ],
            ],
        ]);

        $query = new MessagesQuery(null, ['conversation_id' => $conversation->id]);
        $reflection = new \ReflectionClass($query);
        $method = $reflection->getMethod('assistantBubble');
        $method->setAccessible(true);

        $bubble = $method->invoke($query, $message);
        $html = $bubble->render();

        // Verify slot/proxy pattern
        $this->assertStringContainsString('data-action-slot="file-citation-1"', $html);
        $this->assertStringContainsString('data-action-proxy="file-citation-1"', $html);
    }
}
```

**Step 2: Run integration tests**

Run: `vendor/bin/phpunit tests/Feature/FilePreviewIntegrationTest.php -v`
Expected: PASS

**Step 3: Commit**

```bash
git add tests/Feature/FilePreviewIntegrationTest.php
git commit -m "$(cat <<'EOF'
test(citations): add integration tests for file citation click flow

Verifies both staged (new message) and rendered (existing message) paths
produce correct slot/proxy structure for JS wiring.

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: Final Cleanup and Run Full Test Suite

**Step 1: Run full test suite**

Run: `vendor/bin/phpunit --testdox`
Expected: All tests pass

**Step 2: Manual end-to-end test**

1. Start fresh conversation
2. Upload a file
3. Send message that will trigger AI to cite the file
4. Verify `[1]` appears styled as a link
5. Click `[1]` - modal should open with file preview
6. Refresh page, verify `[1]` is still clickable
7. Scroll up (if paginated), verify older file citations work

**Step 3: Final commit**

```bash
git add -A
git commit -m "$(cat <<'EOF'
feat(citations): file citation links are now clickable

Complete implementation of file citation click functionality using the
proxy pattern. File citations [1], [2], etc. now open the file preview
modal when clicked, matching the behavior of entity action links.

Changes:
- FileCitationHandler: creates visible links with data-action-slot
- ContentLinkProcessor: exposes file_citations metadata
- HasStagedMessageRendering: creates hidden proxy elements
- MessagesQuery: adds inline proxy elements for rendered messages
- chat-message-injector.js: wires slots to proxies on click

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

## Summary

The fix follows the existing proxy pattern used for action buttons:

1. **FileCitationHandler** creates visible `[1]` links with `data-action-slot="file-citation-1"`
2. **ContentLinkProcessor** collects citation metadata (id, type, mime, slot)
3. **HasStagedMessageRendering** creates hidden proxy `_Link()->selfGet('viewFile', ...)->attr(['data-action-proxy' => 'file-citation-1'])`
4. **MessagesQuery** creates inline proxies for PHP-rendered messages
5. **JavaScript** wires visible buttons to proxy clicks via `wireActionButtons()`

This is the same pattern that makes copy, feedback, and regenerate buttons work - just extended to file citations.
