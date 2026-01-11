# Dynamic Action Links Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Enable AI to include clickable action links inline in responses using Markdown syntax with special protocols (`entity://` and `action://`).

**Architecture:**
- AI writes `[text](entity://Type/id/action)` or `[text](action://name)` in responses
- Response processor converts these to clickable Kompo elements
- Actions defined in config with aliases for natural language matching

**Tech Stack:** PHP, Kompo components, Laravel config, regex parsing.

---

## Background

### Current State (to be changed)
- Static entity action buttons rendered below messages (Task 5 implementation)
- Entity actions config exists but with simple closure structure

### Target State
- AI includes action links inline in response text
- Links rendered as clickable elements (modal or redirect based on config)
- Both entity-specific actions (require ID) and generic actions (no ID)
- Aliases help AI understand user intent ("profile link" = "view person" = "profile page")

### URL Formats
- Entity actions: `entity://Person/123/profile`
- Generic actions: `action://settings`

---

## Task 1: Remove Static Entity Button Code

**Files:**
- Modify: `src/Kompo/MessagesQuery.php`

**Context:** Remove the static button rendering we added in Task 5. Keep the import for EntityAutoDiscovery as we'll need it later.

**What to do:**

1. Delete the `renderEntityActions` method (around lines 604-629)
2. Delete the `entityAction` method (around lines 631-645)
3. Remove the call to `$this->renderEntityActions($item)` in `renderListData` method
4. Keep the `use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;` import

**After editing:**
Run: `php -l src/Kompo/MessagesQuery.php`

---

## Task 2: Update Config Structure for Multiple Actions

**Files:**
- Modify: `config/ai.php`

**Context:** Change entity_actions to support multiple actions per entity with aliases. Add generic_actions section.

**What to do:**

Replace the `entity_actions` config section with:

```php
/*
|--------------------------------------------------------------------------
| Entity Actions
|--------------------------------------------------------------------------
|
| Define actions for specific entity types. Each entity can have multiple
| actions, each with aliases for natural language matching.
|
| Structure:
| 'EntityType' => [
|     'action_key' => [
|         'action' => fn($id) => KompoElement,
|         'aliases' => ['alias1', 'alias2'],
|         'label' => 'Display Label', // optional, for AI context
|     ],
| ],
|
| AI uses format: [text](entity://EntityType/id/action_key)
|
*/
'entity_actions' => [
    // Example (applications should define their own):
    // 'Person' => [
    //     'profile' => [
    //         'action' => fn($id) => _Link('View Profile')->href(route('people.show', $id)),
    //         'aliases' => ['profile link', 'profile page', 'profile', 'view person'],
    //         'label' => 'View Profile',
    //     ],
    //     'quick_view' => [
    //         'action' => fn($id) => _Link('Quick View')->selfGet('personModal', ['id' => $id])->inModal(),
    //         'aliases' => ['quick view', 'preview', 'details'],
    //         'label' => 'Quick View',
    //     ],
    // ],
],

/*
|--------------------------------------------------------------------------
| Generic Actions
|--------------------------------------------------------------------------
|
| Define actions not tied to specific entities. These are app-wide
| navigation or functionality links.
|
| Structure:
| 'action_key' => [
|     'action' => fn() => KompoElement,
|     'aliases' => ['alias1', 'alias2'],
|     'label' => 'Display Label',
| ],
|
| AI uses format: [text](action://action_key)
|
*/
'generic_actions' => [
    // Example:
    // 'settings' => [
    //     'action' => fn() => _Link('Settings')->href(route('settings.index')),
    //     'aliases' => ['settings', 'settings page', 'preferences'],
    //     'label' => 'Settings',
    // ],
    // 'dashboard' => [
    //     'action' => fn() => _Link('Dashboard')->href(route('dashboard')),
    //     'aliases' => ['dashboard', 'home', 'main page'],
    //     'label' => 'Dashboard',
    // ],
],
```

**After editing:**
Run: `php -l config/ai.php`

---

## Task 3: Update EntityAutoDiscovery for New Structure

**Files:**
- Modify: `src/Services/Discovery/EntityAutoDiscovery.php`

**Context:** Update getActionResolver to handle the new multi-action structure. Add methods to get all actions for an entity and to get generic actions.

**What to do:**

Replace the existing `getActionResolver` method and add new methods:

```php
/**
 * Get a specific action resolver for an entity type
 *
 * @param string $entityLabel The entity label (e.g., 'Person')
 * @param string $actionKey The action key (e.g., 'profile')
 * @return \Closure|null The action resolver closure, or null if none configured
 */
public function getEntityActionResolver(string $entityLabel, string $actionKey): ?\Closure
{
    $entityActions = config('ai.entity_actions', []);
    return $entityActions[$entityLabel][$actionKey]['action'] ?? null;
}

/**
 * Get all available actions for an entity type
 *
 * @param string $entityLabel The entity label
 * @return array Array of action configs with keys: action_key, aliases, label
 */
public function getEntityActions(string $entityLabel): array
{
    $entityActions = config('ai.entity_actions', []);
    $actions = $entityActions[$entityLabel] ?? [];

    $result = [];
    foreach ($actions as $key => $config) {
        $result[] = [
            'action_key' => $key,
            'aliases' => $config['aliases'] ?? [],
            'label' => $config['label'] ?? $key,
        ];
    }

    return $result;
}

/**
 * Get a generic action resolver
 *
 * @param string $actionKey The action key (e.g., 'settings')
 * @return \Closure|null The action resolver closure
 */
public function getGenericActionResolver(string $actionKey): ?\Closure
{
    $genericActions = config('ai.generic_actions', []);
    return $genericActions[$actionKey]['action'] ?? null;
}

/**
 * Get all available generic actions
 *
 * @return array Array of action configs
 */
public function getGenericActions(): array
{
    $genericActions = config('ai.generic_actions', []);

    $result = [];
    foreach ($genericActions as $key => $config) {
        $result[] = [
            'action_key' => $key,
            'aliases' => $config['aliases'] ?? [],
            'label' => $config['label'] ?? $key,
        ];
    }

    return $result;
}

/**
 * Check if an entity type has any actions configured
 */
public function hasEntityActions(string $entityLabel): bool
{
    $entityActions = config('ai.entity_actions', []);
    return !empty($entityActions[$entityLabel]);
}
```

Also keep the old `getActionResolver` method for backwards compatibility but mark it deprecated:

```php
/**
 * @deprecated Use getEntityActionResolver() instead
 */
public function getActionResolver(string $entityLabel): ?\Closure
{
    // Return first action's resolver for backwards compatibility
    $entityActions = config('ai.entity_actions', []);
    $actions = $entityActions[$entityLabel] ?? [];
    $firstAction = reset($actions);
    return $firstAction['action'] ?? null;
}
```

**After editing:**
Run: `php -l src/Services/Discovery/EntityAutoDiscovery.php`

---

## Task 4: Update ResponseEntityEnricher for Action Metadata

**Files:**
- Modify: `src/Services/Response/ResponseEntityEnricher.php`

**Context:** Update to include available actions (with aliases) in entity metadata so the AI knows what actions are available.

**What to do:**

Update the `enrichEntityResults` method:

```php
/**
 * Enrich entity results with action metadata
 *
 * Adds available actions info to each result that has an entity label.
 *
 * @param array $results The query results to enrich
 * @param array $options Additional options (reserved for future use)
 * @return array The enriched results
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

        $actions = $this->discovery->getEntityActions($entityLabel);

        $enriched[] = array_merge($result, [
            'has_actions' => !empty($actions),
            'available_actions' => $actions,
            'entity_type' => $entityLabel,
        ]);
    }

    return $enriched;
}
```

**After editing:**
Run: `php -l src/Services/Response/ResponseEntityEnricher.php`

---

## Task 5: Create EntityActionsPromptSection

**Files:**
- Create: `src/Services/PromptSections/EntityActionsPromptSection.php`

**Context:** A prompt section that tells the AI about available entity actions and generic actions, and how to format links.

**What to do:**

Create the file:

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;

/**
 * EntityActionsPromptSection
 *
 * Informs the AI about available entity and generic actions,
 * and how to format action links in responses.
 *
 * Priority 58 places this after conversation_context (55) but before
 * detected_entities (60).
 */
class EntityActionsPromptSection extends BasePromptSection
{
    protected string $name = 'entity_actions';
    protected int $priority = 58;

    public function __construct(
        private ?EntityAutoDiscovery $discovery = null
    ) {
        $this->discovery = $discovery ?? app(EntityAutoDiscovery::class);
    }

    /**
     * Include when there are entity results with actions OR generic actions available
     */
    public function shouldInclude(string $question, array $context, array $options = []): bool
    {
        // Check if any entity results have actions
        $results = $context['query_results']['data'] ?? [];
        foreach ($results as $result) {
            if (!empty($result['has_actions'])) {
                return true;
            }
        }

        // Check if there are generic actions configured
        $genericActions = $this->discovery->getGenericActions();
        return !empty($genericActions);
    }

    public function format(string $question, array $context, array $options = []): string
    {
        $output = $this->header('ACTION LINKS');

        $output .= "You can include clickable action links in your response using Markdown syntax.\n\n";

        // Entity actions from results
        $results = $context['query_results']['data'] ?? [];
        $entityActionsShown = [];

        foreach ($results as $result) {
            if (empty($result['has_actions']) || empty($result['available_actions'])) {
                continue;
            }

            $entityType = $result['entity_type'];
            if (isset($entityActionsShown[$entityType])) {
                continue; // Already documented this entity type
            }

            $entityActionsShown[$entityType] = true;
            $entityId = $result['id'] ?? $result['_id'] ?? 'ID';
            $entityName = $result['name'] ?? $result['title'] ?? $entityType;

            $output .= "**{$entityType} Actions:**\n";
            foreach ($result['available_actions'] as $action) {
                $aliases = implode(', ', $action['aliases'] ?? []);
                $output .= "- `[text](entity://{$entityType}/{$entityId}/{$action['action_key']})` - {$action['label']}";
                if ($aliases) {
                    $output .= " (user may say: {$aliases})";
                }
                $output .= "\n";
            }
            $output .= "Example: `[View {$entityName}](entity://{$entityType}/{$entityId}/profile)`\n\n";
        }

        // Generic actions
        $genericActions = $this->discovery->getGenericActions();
        if (!empty($genericActions)) {
            $output .= "**Generic Actions:**\n";
            foreach ($genericActions as $action) {
                $aliases = implode(', ', $action['aliases'] ?? []);
                $output .= "- `[text](action://{$action['action_key']})` - {$action['label']}";
                if ($aliases) {
                    $output .= " (user may say: {$aliases})";
                }
                $output .= "\n";
            }
            $output .= "\n";
        }

        $output .= "**Instructions:** When the user asks for a link, profile, or action related to the above, ";
        $output .= "include the appropriate action link in your response using the Markdown format shown.\n";

        return $output;
    }
}
```

**After editing:**
Run: `php -l src/Services/PromptSections/EntityActionsPromptSection.php`

---

## Task 6: Create ResponseActionLinkProcessor

**Files:**
- Create: `src/Services/Response/ResponseActionLinkProcessor.php`

**Context:** Processes the AI response to find entity:// and action:// links and convert them to action metadata for the UI to render.

**What to do:**

Create the file:

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Response;

use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;

/**
 * Response Action Link Processor
 *
 * Extracts action links from AI responses and prepares them for rendering.
 * Handles both entity:// and action:// protocol links.
 *
 * Link formats:
 * - [text](entity://EntityType/id/action_key)
 * - [text](action://action_key)
 */
class ResponseActionLinkProcessor
{
    private const ENTITY_PATTERN = '/\[([^\]]+)\]\(entity:\/\/([^\/]+)\/([^\/]+)\/([^\)]+)\)/';
    private const ACTION_PATTERN = '/\[([^\]]+)\]\(action:\/\/([^\)]+)\)/';

    public function __construct(
        private ?EntityAutoDiscovery $discovery = null
    ) {
        $this->discovery = $discovery ?? app(EntityAutoDiscovery::class);
    }

    /**
     * Extract all action links from response text
     *
     * @param string $response The AI response text
     * @return array Array of action link metadata
     */
    public function extractActionLinks(string $response): array
    {
        $links = [];

        // Extract entity action links
        preg_match_all(self::ENTITY_PATTERN, $response, $entityMatches, PREG_SET_ORDER);
        foreach ($entityMatches as $match) {
            $links[] = [
                'type' => 'entity',
                'full_match' => $match[0],
                'text' => $match[1],
                'entity_type' => $match[2],
                'entity_id' => $match[3],
                'action_key' => $match[4],
                'has_resolver' => $this->discovery->getEntityActionResolver($match[2], $match[4]) !== null,
            ];
        }

        // Extract generic action links
        preg_match_all(self::ACTION_PATTERN, $response, $actionMatches, PREG_SET_ORDER);
        foreach ($actionMatches as $match) {
            $links[] = [
                'type' => 'generic',
                'full_match' => $match[0],
                'text' => $match[1],
                'action_key' => $match[2],
                'has_resolver' => $this->discovery->getGenericActionResolver($match[2]) !== null,
            ];
        }

        return $links;
    }

    /**
     * Process response and return enriched data
     *
     * @param string $response The AI response text
     * @return array Contains 'action_links' array and 'has_action_links' boolean
     */
    public function processResponse(string $response): array
    {
        $links = $this->extractActionLinks($response);

        return [
            'action_links' => $links,
            'has_action_links' => !empty($links),
        ];
    }

    /**
     * Enrich a response array with action link metadata
     *
     * @param array $response The response array (must contain 'answer' or 'content')
     * @return array The enriched response
     */
    public function enrichResponse(array $response): array
    {
        $content = $response['answer'] ?? $response['content'] ?? '';
        $actionData = $this->processResponse($content);

        return array_merge($response, $actionData);
    }
}
```

**After editing:**
Run: `php -l src/Services/Response/ResponseActionLinkProcessor.php`

---

## Task 7: Add Action Link Rendering to MessagesQuery

**Files:**
- Modify: `src/Kompo/MessagesQuery.php`

**Context:** Add methods to render action links and handle action link clicks.

**What to do:**

1. Add import at top:
```php
use Condoedge\Ai\Services\Response\ResponseActionLinkProcessor;
```

2. Add method to handle entity action clicks:
```php
/**
 * Handle entity action link click
 */
public function entityActionLink($entityType, $entityId, $actionKey)
{
    $discovery = app(EntityAutoDiscovery::class);
    $resolver = $discovery->getEntityActionResolver($entityType, $actionKey);

    if (!$resolver) {
        return _Html(__('ai.action.not-configured'))->class('text-red-500 p-4');
    }

    return $resolver($entityId);
}

/**
 * Handle generic action link click
 */
public function genericActionLink($actionKey)
{
    $discovery = app(EntityAutoDiscovery::class);
    $resolver = $discovery->getGenericActionResolver($actionKey);

    if (!$resolver) {
        return _Html(__('ai.action.not-configured'))->class('text-red-500 p-4');
    }

    return $resolver();
}
```

3. Find the `formatMessageContent` or similar method that renders the message HTML/markdown and add action link processing. Look for where markdown is converted to HTML. Add a method:

```php
/**
 * Process action links in message content
 * Converts entity:// and action:// links to clickable Kompo triggers
 */
protected function processActionLinks(string $content): string
{
    // Replace entity action links
    $content = preg_replace_callback(
        '/\[([^\]]+)\]\(entity:\/\/([^\/]+)\/([^\/]+)\/([^\)]+)\)/',
        function ($matches) {
            $text = e($matches[1]);
            $entityType = e($matches[2]);
            $entityId = e($matches[3]);
            $actionKey = e($matches[4]);

            // Return a span with data attributes that JS will make clickable
            return "<span class=\"action-link cursor-pointer text-indigo-600 hover:text-indigo-800 underline\" "
                 . "data-action-type=\"entity\" "
                 . "data-entity-type=\"{$entityType}\" "
                 . "data-entity-id=\"{$entityId}\" "
                 . "data-action-key=\"{$actionKey}\">{$text}</span>";
        },
        $content
    );

    // Replace generic action links
    $content = preg_replace_callback(
        '/\[([^\]]+)\]\(action:\/\/([^\)]+)\)/',
        function ($matches) {
            $text = e($matches[1]);
            $actionKey = e($matches[2]);

            return "<span class=\"action-link cursor-pointer text-indigo-600 hover:text-indigo-800 underline\" "
                 . "data-action-type=\"generic\" "
                 . "data-action-key=\"{$actionKey}\">{$text}</span>";
        },
        $content
    );

    return $content;
}
```

4. Integrate `processActionLinks` into the message rendering flow - find where message content is output and wrap it with this processing.

**After editing:**
Run: `php -l src/Kompo/MessagesQuery.php`

---

## Task 8: Register EntityActionsPromptSection

**Files:**
- Modify: `src/Services/SemanticPromptBuilder.php` or wherever prompt sections are registered

**Context:** Register the new EntityActionsPromptSection so it's included in prompts.

**What to do:**

1. Find where prompt sections are registered (likely in SemanticPromptBuilder or AiServiceProvider)
2. Add the EntityActionsPromptSection to the list

Look for patterns like:
```php
$sections = [
    new SchemaSection(),
    new ConversationContextSection(),
    // Add here:
    new EntityActionsPromptSection(),
];
```

Or if using config-based registration, add to the sections config.

**After editing:**
Run syntax check on modified file.

---

## Task 9: Update Tests

**Files:**
- Modify: `tests/Feature/FilePreviewIntegrationTest.php`
- Create: `tests/Unit/Services/Response/ResponseActionLinkProcessorTest.php`

**Context:** Update existing tests and add tests for the new action link processor.

**What to do:**

1. Remove or update tests in FilePreviewIntegrationTest that reference the old entity action methods

2. Create ResponseActionLinkProcessorTest:

```php
<?php

namespace Condoedge\Ai\Tests\Unit\Services\Response;

use Condoedge\Ai\Services\Response\ResponseActionLinkProcessor;
use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Orchestra\Testbench\TestCase;
use Mockery;

class ResponseActionLinkProcessorTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_extracts_entity_action_links(): void
    {
        $discovery = Mockery::mock(EntityAutoDiscovery::class);
        $discovery->shouldReceive('getEntityActionResolver')
            ->with('Person', 'profile')
            ->andReturn(fn($id) => "resolver");

        $processor = new ResponseActionLinkProcessor($discovery);

        $response = 'You can [view John](entity://Person/123/profile) here.';
        $links = $processor->extractActionLinks($response);

        $this->assertCount(1, $links);
        $this->assertEquals('entity', $links[0]['type']);
        $this->assertEquals('view John', $links[0]['text']);
        $this->assertEquals('Person', $links[0]['entity_type']);
        $this->assertEquals('123', $links[0]['entity_id']);
        $this->assertEquals('profile', $links[0]['action_key']);
        $this->assertTrue($links[0]['has_resolver']);
    }

    /** @test */
    public function it_extracts_generic_action_links(): void
    {
        $discovery = Mockery::mock(EntityAutoDiscovery::class);
        $discovery->shouldReceive('getGenericActionResolver')
            ->with('settings')
            ->andReturn(fn() => "resolver");

        $processor = new ResponseActionLinkProcessor($discovery);

        $response = 'Go to [settings page](action://settings).';
        $links = $processor->extractActionLinks($response);

        $this->assertCount(1, $links);
        $this->assertEquals('generic', $links[0]['type']);
        $this->assertEquals('settings page', $links[0]['text']);
        $this->assertEquals('settings', $links[0]['action_key']);
        $this->assertTrue($links[0]['has_resolver']);
    }

    /** @test */
    public function it_extracts_multiple_links(): void
    {
        $discovery = Mockery::mock(EntityAutoDiscovery::class);
        $discovery->shouldReceive('getEntityActionResolver')->andReturn(fn($id) => "r");
        $discovery->shouldReceive('getGenericActionResolver')->andReturn(fn() => "r");

        $processor = new ResponseActionLinkProcessor($discovery);

        $response = 'See [John](entity://Person/123/profile) and [Jane](entity://Person/456/profile), or go to [dashboard](action://dashboard).';
        $links = $processor->extractActionLinks($response);

        $this->assertCount(3, $links);
    }
}
```

**After editing:**
Run: `vendor/bin/phpunit tests/Unit/Services/Response/ResponseActionLinkProcessorTest.php`

---

## Summary

| Task | Description | Files |
|------|-------------|-------|
| 1 | Remove static entity button code | MessagesQuery.php |
| 2 | Update config for multiple actions | config/ai.php |
| 3 | Update EntityAutoDiscovery | EntityAutoDiscovery.php |
| 4 | Update ResponseEntityEnricher | ResponseEntityEnricher.php |
| 5 | Create EntityActionsPromptSection | EntityActionsPromptSection.php (new) |
| 6 | Create ResponseActionLinkProcessor | ResponseActionLinkProcessor.php (new) |
| 7 | Add action link rendering | MessagesQuery.php |
| 8 | Register prompt section | SemanticPromptBuilder.php |
| 9 | Update tests | Tests |

## Dependencies

- Task 1 should be done first (cleanup)
- Tasks 2, 3, 4 can be done in parallel (config and discovery updates)
- Task 5 depends on Task 3 (needs EntityAutoDiscovery methods)
- Task 6 depends on Task 3 (needs EntityAutoDiscovery methods)
- Task 7 depends on Tasks 3, 6
- Task 8 depends on Task 5
- Task 9 depends on all other tasks

---

## IMPORTANT: No Git Operations

**Git usage is FORBIDDEN during this implementation process.**

Do not:
- Create commits
- Stage files
- Run any git commands
- Create branches

All version control will be handled separately after implementation is complete.
