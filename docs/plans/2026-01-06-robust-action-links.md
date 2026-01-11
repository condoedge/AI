# Robust Action Links Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Enable AI to correctly provide entity action links (like profile links) using IDs from conversation context, without generating unnecessary queries.

**Architecture:** Two-phase fix: (1) Query phase awareness - tell LLM that "profile link" is an action, not a field, so it returns NO QUERY REQUIRED when IDs are available in context. (2) Response phase - get entity types and IDs from conversation context instead of fragile Cypher parsing.

**Tech Stack:** PHP 8.1+, Laravel, PHPUnit

---

## Problem Analysis

When user asks "give me the profile link for that person":

1. **Current Broken Flow:**
   - QueryGenerator doesn't know "profile link" is an action
   - Generates query: `MATCH (p:Person {name: "..."}) RETURN p.user_id AS profile_link`
   - Confuses "profile link" with a database field
   - Doesn't use entity ID from previous results

2. **Desired Flow:**
   - QueryGenerator recognizes "profile link" = action (from aliases)
   - Sees entity ID available in conversation context
   - Returns "NO QUERY REQUIRED"
   - ResponseGenerator formats: `[View Profile](entity://Person/152463/profile)`

3. **Root Causes:**
   - No action awareness in query phase
   - ResponseEntityActionsSection uses fragile Cypher regex
   - Entity IDs in conversation context not used by response phase

---

## Task 1: Create EntityActionAwarenessSection for Query Phase

**Files:**
- Create: `src/Services/PromptSections/EntityActionAwarenessSection.php`
- Modify: `config/ai.php` (add to query_generator_sections)
- Test: `tests/Unit/Services/PromptSections/EntityActionAwarenessSectionTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\PromptSections;

use Condoedge\Ai\Services\PromptSections\EntityActionAwarenessSection;
use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

class EntityActionAwarenessSectionTest extends TestCase
{
    private EntityActionAwarenessSection $section;
    private $mockDiscovery;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockDiscovery = Mockery::mock(EntityAutoDiscovery::class);
        $this->app->instance(EntityAutoDiscovery::class, $this->mockDiscovery);

        $this->section = new EntityActionAwarenessSection();
    }

    /** @test */
    public function it_should_include_when_entity_actions_configured(): void
    {
        $this->mockDiscovery->shouldReceive('getEntityActions')
            ->with('Person')
            ->andReturn([
                ['action_key' => 'profile', 'aliases' => ['profile link'], 'label' => 'View Profile'],
            ]);
        $this->mockDiscovery->shouldReceive('getGenericActions')->andReturn([]);

        config(['ai.entity_actions' => ['Person' => ['profile' => []]]]);

        $result = $this->section->shouldInclude('give me the profile link', [], []);

        $this->assertTrue($result);
    }

    /** @test */
    public function it_formats_action_awareness_with_context_ids(): void
    {
        $this->mockDiscovery->shouldReceive('getEntityActions')
            ->with('Person')
            ->andReturn([
                ['action_key' => 'profile', 'aliases' => ['profile link', 'profile page'], 'label' => 'View Profile'],
            ]);
        $this->mockDiscovery->shouldReceive('getGenericActions')->andReturn([]);

        config(['ai.entity_actions' => ['Person' => ['profile' => []]]]);

        $context = [
            'conversation_context' => [
                'focused_entity' => 'Person',
                'last_result_sample' => [
                    ['id' => '152463', 'name' => 'John Doe'],
                ],
            ],
        ];

        $output = $this->section->format('give me the profile link', $context, []);

        $this->assertStringContainsString('ACTION REQUESTS', $output);
        $this->assertStringContainsString('profile link', $output);
        $this->assertStringContainsString('NOT database fields', $output);
        $this->assertStringContainsString('NO QUERY REQUIRED', $output);
        $this->assertStringContainsString('152463', $output);
    }

    /** @test */
    public function it_returns_empty_when_no_actions_configured(): void
    {
        config(['ai.entity_actions' => []]);
        config(['ai.generic_actions' => []]);

        $this->mockDiscovery->shouldReceive('getEntityActions')->andReturn([]);
        $this->mockDiscovery->shouldReceive('getGenericActions')->andReturn([]);

        $result = $this->section->shouldInclude('show me all people', [], []);

        $this->assertFalse($result);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Services/PromptSections/EntityActionAwarenessSectionTest.php -v`
Expected: FAIL with "Class EntityActionAwarenessSection not found"

**Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;

/**
 * EntityActionAwarenessSection
 *
 * Informs the QueryGenerator about available entity actions so it knows:
 * - "profile link", "profile page" etc. are actions, NOT database fields
 * - When user asks for actions AND context has entity IDs, return NO QUERY REQUIRED
 *
 * Priority 58 places this after ConversationContext (55) but before DetectedEntities (60).
 */
class EntityActionAwarenessSection extends BasePromptSection
{
    protected string $name = 'entity_action_awareness';
    protected int $priority = 58;

    private ?EntityAutoDiscovery $discovery = null;

    public function __construct()
    {
        $this->discovery = app(EntityAutoDiscovery::class);
    }

    /**
     * Include when there are entity actions or generic actions configured
     */
    public function shouldInclude(string $question, array $context, array $options = []): bool
    {
        $entityActions = config('ai.entity_actions', []);
        $genericActions = config('ai.generic_actions', []);

        return !empty($entityActions) || !empty($genericActions);
    }

    public function format(string $question, array $context, array $options = []): string
    {
        $output = $this->header('ACTION REQUESTS - IMPORTANT');

        $output .= "The following terms are ACTION REQUESTS, NOT database fields:\n\n";

        // Collect all action aliases
        $allAliases = [];
        $entityActions = config('ai.entity_actions', []);

        foreach ($entityActions as $entityType => $actions) {
            foreach ($actions as $actionKey => $actionConfig) {
                $aliases = $actionConfig['aliases'] ?? [];
                foreach ($aliases as $alias) {
                    $allAliases[$alias] = [
                        'entity' => $entityType,
                        'action' => $actionKey,
                        'label' => $actionConfig['label'] ?? $actionKey,
                    ];
                }
            }
        }

        // Generic actions
        $genericActions = config('ai.generic_actions', []);
        foreach ($genericActions as $actionKey => $actionConfig) {
            $aliases = $actionConfig['aliases'] ?? [];
            foreach ($aliases as $alias) {
                $allAliases[$alias] = [
                    'entity' => null,
                    'action' => $actionKey,
                    'label' => $actionConfig['label'] ?? $actionKey,
                ];
            }
        }

        if (!empty($allAliases)) {
            $output .= "**Action Aliases (these are NOT fields to query):**\n";
            foreach ($allAliases as $alias => $info) {
                $entityPart = $info['entity'] ? " ({$info['entity']})" : ' (generic)';
                $output .= "- \"{$alias}\" → {$info['label']}{$entityPart}\n";
            }
            $output .= "\n";
        }

        // Check if conversation context has entity IDs
        $conversationContext = $context['conversation_context'] ?? [];
        $lastResults = $conversationContext['last_result_sample'] ?? [];
        $focusedEntity = $conversationContext['focused_entity'] ?? null;

        if (!empty($lastResults)) {
            $output .= "**CRITICAL RULE:**\n";
            $output .= "If the user asks for any of the above action terms (like 'profile link', 'profile page'):\n";
            $output .= "1. These are UI actions, NOT database queries\n";
            $output .= "2. The entity IDs are ALREADY AVAILABLE in context below\n";
            $output .= "3. You MUST return: `NO QUERY REQUIRED`\n\n";

            $output .= "**Available Entity IDs from Previous Results:**\n";
            foreach ($lastResults as $index => $result) {
                $id = $result['id'] ?? $result['_id'] ?? $result['neo4j_id'] ?? 'unknown';
                $name = $result['name'] ?? $result['title'] ?? $result['full_name'] ?? "Item " . ($index + 1);
                $entityType = $focusedEntity ?? 'Entity';
                $output .= "- {$entityType}: {$name} (ID: {$id})\n";
            }
            $output .= "\n";
        }

        $output .= "**Example:**\n";
        $output .= "- User: \"give me the profile link for John\"\n";
        $output .= "- If John's ID is in context above → Return: `NO QUERY REQUIRED`\n";
        $output .= "- The response generator will format the action link using the ID\n\n";

        return $output;
    }
}
```

**Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Services/PromptSections/EntityActionAwarenessSectionTest.php -v`
Expected: PASS

**Step 5: Register in config**

Modify `config/ai.php` - add after ConversationContextSection (line ~840):

```php
\Condoedge\Ai\Services\PromptSections\EntityActionAwarenessSection::class, // Priority 58: Action awareness before DetectedEntities
```

**Step 6: Commit**

```bash
git add src/Services/PromptSections/EntityActionAwarenessSection.php tests/Unit/Services/PromptSections/EntityActionAwarenessSectionTest.php config/ai.php
git commit -m "feat(query): add EntityActionAwarenessSection for action recognition

Teaches QueryGenerator that 'profile link', 'profile page', etc. are
UI actions, not database fields. When user asks for these actions and
entity IDs are available in conversation context, returns NO QUERY REQUIRED."
```

---

## Task 2: Refactor ResponseEntityActionsSection to Use Conversation Context

**Files:**
- Modify: `src/Services/ResponseSections/ResponseEntityActionsSection.php`
- Test: `tests/Unit/Services/ResponseSections/ResponseEntityActionsSectionTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\ResponseSections;

use Condoedge\Ai\Services\ResponseSections\ResponseEntityActionsSection;
use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

class ResponseEntityActionsSectionTest extends TestCase
{
    private ResponseEntityActionsSection $section;
    private $mockDiscovery;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockDiscovery = Mockery::mock(EntityAutoDiscovery::class);
        $this->app->instance(EntityAutoDiscovery::class, $this->mockDiscovery);

        $this->section = new ResponseEntityActionsSection();
    }

    /** @test */
    public function it_uses_focused_entity_from_conversation_context(): void
    {
        $this->mockDiscovery->shouldReceive('hasEntityActions')
            ->with('Person')
            ->andReturn(true);
        $this->mockDiscovery->shouldReceive('getEntityActions')
            ->with('Person')
            ->andReturn([
                ['action_key' => 'profile', 'aliases' => ['profile link'], 'label' => 'View Profile'],
            ]);
        $this->mockDiscovery->shouldReceive('getGenericActions')->andReturn([]);

        $context = [
            'cypher' => '', // Empty cypher for NO QUERY case
            'data' => [],
            'conversation_context' => [
                'focused_entity' => 'Person',
                'last_result_sample' => [
                    ['id' => '152463', 'name' => 'John Doe'],
                    ['id' => '152464', 'name' => 'Jane Smith'],
                ],
            ],
        ];

        $result = $this->section->shouldInclude($context, []);
        $this->assertTrue($result);

        $output = $this->section->format($context, []);

        $this->assertStringContainsString('Person Actions', $output);
        $this->assertStringContainsString('entity://Person/152463/profile', $output);
        $this->assertStringContainsString('John Doe', $output);
    }

    /** @test */
    public function it_falls_back_to_cypher_parsing_when_no_conversation_context(): void
    {
        $this->mockDiscovery->shouldReceive('hasEntityActions')
            ->with('Person')
            ->andReturn(true);
        $this->mockDiscovery->shouldReceive('getEntityActions')
            ->with('Person')
            ->andReturn([
                ['action_key' => 'profile', 'aliases' => ['profile link'], 'label' => 'View Profile'],
            ]);
        $this->mockDiscovery->shouldReceive('getGenericActions')->andReturn([]);

        $context = [
            'cypher' => 'MATCH (p:Person) WHERE p.name = "John" RETURN p',
            'data' => [
                ['id' => '152463', 'name' => 'John Doe'],
            ],
        ];

        $result = $this->section->shouldInclude($context, []);
        $this->assertTrue($result);

        $output = $this->section->format($context, []);

        $this->assertStringContainsString('Person Actions', $output);
        $this->assertStringContainsString('152463', $output);
    }

    /** @test */
    public function it_includes_all_entity_ids_from_context(): void
    {
        $this->mockDiscovery->shouldReceive('hasEntityActions')
            ->with('Person')
            ->andReturn(true);
        $this->mockDiscovery->shouldReceive('getEntityActions')
            ->with('Person')
            ->andReturn([
                ['action_key' => 'profile', 'aliases' => ['profile link'], 'label' => 'View Profile'],
            ]);
        $this->mockDiscovery->shouldReceive('getGenericActions')->andReturn([]);

        $context = [
            'cypher' => '',
            'data' => [],
            'conversation_context' => [
                'focused_entity' => 'Person',
                'last_result_sample' => [
                    ['id' => '100', 'name' => 'Alice'],
                    ['id' => '101', 'name' => 'Bob'],
                    ['id' => '102', 'name' => 'Charlie'],
                ],
            ],
        ];

        $output = $this->section->format($context, []);

        $this->assertStringContainsString('Alice', $output);
        $this->assertStringContainsString('100', $output);
        $this->assertStringContainsString('Bob', $output);
        $this->assertStringContainsString('101', $output);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Services/ResponseSections/ResponseEntityActionsSectionTest.php -v`
Expected: FAIL (current implementation doesn't use conversation_context)

**Step 3: Rewrite the implementation**

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\ResponseSections;

use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;

/**
 * ResponseEntityActionsSection
 *
 * Informs the AI about available entity and generic actions,
 * and how to format action links in responses.
 *
 * Gets entity types from:
 * 1. Conversation context's focused_entity (most reliable)
 * 2. Cypher query parsing (fallback)
 *
 * Gets entity IDs from:
 * 1. Conversation context's last_result_sample
 * 2. Current response data
 *
 * Priority 55 places this after data (50) but before statistics (60).
 */
class ResponseEntityActionsSection extends BaseResponseSection
{
    protected string $name = 'entity_actions';
    protected int $priority = 55;

    private ?EntityAutoDiscovery $discovery = null;

    public function __construct()
    {
        $this->discovery = app(EntityAutoDiscovery::class);
    }

    /**
     * Include when there are entity types with actions OR generic actions
     */
    public function shouldInclude(array $context, array $options = []): bool
    {
        // Get entity types from all sources
        $entityTypes = $this->resolveEntityTypes($context);

        foreach ($entityTypes as $entityType) {
            if ($this->discovery->hasEntityActions($entityType)) {
                return true;
            }
        }

        // Check for generic actions
        $genericActions = $this->discovery->getGenericActions();
        return !empty($genericActions);
    }

    public function format(array $context, array $options = []): string
    {
        $output = "=== ACTION LINKS ===\n\n";
        $output .= "You can include clickable action links in your response using Markdown syntax.\n\n";

        // Resolve entity types and IDs
        $entityTypes = $this->resolveEntityTypes($context);
        $entityData = $this->resolveEntityData($context);

        foreach ($entityTypes as $entityType) {
            $actions = $this->discovery->getEntityActions($entityType);
            if (empty($actions)) {
                continue;
            }

            $output .= "**{$entityType} Actions:**\n";
            foreach ($actions as $action) {
                $aliases = implode(', ', $action['aliases'] ?? []);
                $output .= "- Format: `[display text](entity://{$entityType}/ID/{$action['action_key']})` - {$action['label']}";
                if ($aliases) {
                    $output .= " (user may say: {$aliases})";
                }
                $output .= "\n";
            }

            // Show available entity IDs
            if (!empty($entityData)) {
                $output .= "\n**Available {$entityType} IDs:**\n";
                foreach ($entityData as $entity) {
                    $id = $entity['id'] ?? $entity['_id'] ?? $entity['neo4j_id'] ?? null;
                    $name = $entity['name'] ?? $entity['title'] ?? $entity['full_name'] ?? 'Unknown';
                    if ($id) {
                        $firstActionKey = $actions[0]['action_key'] ?? 'profile';
                        $output .= "- {$name}: `[View {$name}](entity://{$entityType}/{$id}/{$firstActionKey})`\n";
                    }
                }
            }

            $output .= "\n";
        }

        // Generic actions
        $genericActions = $this->discovery->getGenericActions();
        if (!empty($genericActions)) {
            $output .= "**Generic Actions:**\n";
            foreach ($genericActions as $action) {
                $aliases = implode(', ', $action['aliases'] ?? []);
                $output .= "- Format: `[display text](action://{$action['action_key']})` - {$action['label']}";
                if ($aliases) {
                    $output .= " (user may say: {$aliases})";
                }
                $output .= "\n";
            }
            $output .= "\n";
        }

        $output .= "**CRITICAL:** When the user asks for a link, profile, or action listed above:\n";
        $output .= "- Use the EXACT entity IDs shown above\n";
        $output .= "- Format: `[Display Text](entity://EntityType/ID/action_key)`\n";
        $output .= "- Example: `[View John Doe](entity://Person/152463/profile)`\n\n";

        return $output;
    }

    /**
     * Resolve entity types from multiple sources (priority order)
     */
    private function resolveEntityTypes(array $context): array
    {
        $types = [];

        // 1. Conversation context focused_entity (most reliable)
        $conversationContext = $context['conversation_context'] ?? [];
        if (!empty($conversationContext['focused_entity'])) {
            $types[] = $conversationContext['focused_entity'];
        }

        // 2. Mentioned entities from conversation
        if (!empty($conversationContext['mentioned_entities'])) {
            $types = array_merge($types, $conversationContext['mentioned_entities']);
        }

        // 3. Parse from Cypher query (fallback)
        $cypher = $context['cypher'] ?? '';
        if ($cypher) {
            $cypherLabels = $this->extractLabelsFromCypher($cypher);
            $types = array_merge($types, $cypherLabels);
        }

        return array_unique($types);
    }

    /**
     * Resolve entity data (IDs and names) from multiple sources
     */
    private function resolveEntityData(array $context): array
    {
        // 1. Current response data
        $currentData = $context['data'] ?? [];

        // 2. Conversation context last_result_sample (for NO QUERY cases)
        $conversationContext = $context['conversation_context'] ?? [];
        $lastResults = $conversationContext['last_result_sample'] ?? [];

        // Prefer current data if available, otherwise use conversation context
        if (!empty($currentData)) {
            return $currentData;
        }

        return $lastResults;
    }

    /**
     * Extract entity labels from a Cypher query (fallback method)
     */
    private function extractLabelsFromCypher(string $cypher): array
    {
        $labels = [];

        // Match patterns like (variable:Label) or (:Label)
        // Handles: (p:Person), (:Person), (n:Person:Employee)
        if (preg_match_all('/\([\w]*:(\w+(?::\w+)*)\)/', $cypher, $matches)) {
            foreach ($matches[1] as $labelGroup) {
                foreach (explode(':', $labelGroup) as $label) {
                    if ($label) {
                        $labels[] = $label;
                    }
                }
            }
        }

        return array_unique($labels);
    }
}
```

**Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Services/ResponseSections/ResponseEntityActionsSectionTest.php -v`
Expected: PASS

**Step 5: Commit**

```bash
git add src/Services/ResponseSections/ResponseEntityActionsSection.php tests/Unit/Services/ResponseSections/ResponseEntityActionsSectionTest.php
git commit -m "refactor(response): use conversation context in ResponseEntityActionsSection

- Get entity types from focused_entity (reliable) instead of Cypher parsing
- Get entity IDs from last_result_sample for NO QUERY cases
- Show all available entity IDs with pre-formatted links
- Fall back to Cypher parsing only when no conversation context"
```

---

## Task 3: Ensure Conversation Context Flows to Response Phase

**Files:**
- Modify: `src/Services/ResponseGenerator.php` (verify context passing)
- Test: Integration test in existing file

**Step 1: Verify current behavior**

Check that `conversation_context` is passed to response sections. Looking at ResponseGenerator.php line 239-246:

```php
$context = [
    'question' => $originalQuestion,
    'cypher' => $cypherQuery,
    'data' => $queryResult['data'],
    'stats' => $queryResult['stats'] ?? [],
    'file_context' => $options['file_context'] ?? [],
    'conversation_context' => $options['conversation_context'] ?? [],
];
```

The conversation_context is already being passed! ✓

**Step 2: Write integration test**

Create `tests/Feature/ActionLinkIntegrationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Feature;

use Condoedge\Ai\Services\ResponseSections\ResponseEntityActionsSection;
use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

class ActionLinkIntegrationTest extends TestCase
{
    /** @test */
    public function it_provides_action_links_for_no_query_response(): void
    {
        // Setup mock discovery with Person actions
        $mockDiscovery = Mockery::mock(EntityAutoDiscovery::class);
        $mockDiscovery->shouldReceive('hasEntityActions')
            ->with('Person')
            ->andReturn(true);
        $mockDiscovery->shouldReceive('getEntityActions')
            ->with('Person')
            ->andReturn([
                ['action_key' => 'profile', 'aliases' => ['profile link', 'profile page'], 'label' => 'View Profile'],
            ]);
        $mockDiscovery->shouldReceive('getGenericActions')->andReturn([]);

        $this->app->instance(EntityAutoDiscovery::class, $mockDiscovery);

        $section = new ResponseEntityActionsSection();

        // Simulate NO QUERY scenario - cypher is empty but we have conversation context
        $context = [
            'question' => 'give me the profile link for John',
            'cypher' => '', // Empty because QueryGenerator returned NO QUERY REQUIRED
            'data' => [], // Empty because no query was executed
            'conversation_context' => [
                'focused_entity' => 'Person',
                'last_result_sample' => [
                    ['id' => '152463', 'name' => 'John Doe', 'full_name' => 'John Doe'],
                ],
                'last_result_count' => 1,
            ],
        ];

        $shouldInclude = $section->shouldInclude($context, []);
        $this->assertTrue($shouldInclude, 'Section should include based on focused_entity');

        $output = $section->format($context, []);

        // Verify the AI receives proper instructions
        $this->assertStringContainsString('Person Actions', $output);
        $this->assertStringContainsString('entity://Person/152463/profile', $output);
        $this->assertStringContainsString('John Doe', $output);
        $this->assertStringContainsString('profile link', $output); // alias shown
    }

    /** @test */
    public function it_handles_multiple_entities_from_context(): void
    {
        $mockDiscovery = Mockery::mock(EntityAutoDiscovery::class);
        $mockDiscovery->shouldReceive('hasEntityActions')
            ->with('Person')
            ->andReturn(true);
        $mockDiscovery->shouldReceive('getEntityActions')
            ->with('Person')
            ->andReturn([
                ['action_key' => 'profile', 'aliases' => ['profile link'], 'label' => 'View Profile'],
            ]);
        $mockDiscovery->shouldReceive('getGenericActions')->andReturn([]);

        $this->app->instance(EntityAutoDiscovery::class, $mockDiscovery);

        $section = new ResponseEntityActionsSection();

        $context = [
            'cypher' => '',
            'data' => [],
            'conversation_context' => [
                'focused_entity' => 'Person',
                'last_result_sample' => [
                    ['id' => '100', 'name' => 'Alice Johnson'],
                    ['id' => '101', 'name' => 'Bob Smith'],
                    ['id' => '102', 'name' => 'Carol White'],
                ],
            ],
        ];

        $output = $section->format($context, []);

        // All entity IDs should be available
        $this->assertStringContainsString('entity://Person/100/profile', $output);
        $this->assertStringContainsString('entity://Person/101/profile', $output);
        $this->assertStringContainsString('entity://Person/102/profile', $output);
        $this->assertStringContainsString('Alice Johnson', $output);
        $this->assertStringContainsString('Bob Smith', $output);
    }
}
```

**Step 3: Run integration test**

Run: `vendor/bin/phpunit tests/Feature/ActionLinkIntegrationTest.php -v`
Expected: PASS (after Task 2 implementation)

**Step 4: Commit**

```bash
git add tests/Feature/ActionLinkIntegrationTest.php
git commit -m "test(action-links): add integration tests for NO QUERY scenarios"
```

---

## Task 4: Update Config to Add EntityActionAwarenessSection

**Files:**
- Modify: `config/ai.php`

**Step 1: Add the section**

In `config/ai.php`, locate `query_generator_sections` array and add after `ConversationContextSection`:

```php
'query_generator_sections' => [
    \Condoedge\Ai\Services\PromptSections\ProjectContextSection::class,
    \Condoedge\Ai\Services\PromptSections\GenericContextSection::class,
    \Condoedge\Ai\Services\PromptSections\CurrentUserContextSection::class,
    \Condoedge\Ai\Services\PromptSections\SchemaSection::class,
    \Condoedge\Ai\Services\PromptSections\RelationshipsSection::class,
    \Condoedge\Ai\Services\PromptSections\ExampleEntitiesSection::class,
    \Condoedge\Ai\Services\PromptSections\FileContextSection::class,
    \Condoedge\Ai\Services\PromptSections\SimilarQueriesSection::class,
    \Condoedge\Ai\Services\PromptSections\ConversationContextSection::class, // Priority 55
    \Condoedge\Ai\Services\PromptSections\EntityActionAwarenessSection::class, // Priority 58: Action awareness
    \Condoedge\Ai\Services\PromptSections\DetectedEntitiesSection::class,
    \Condoedge\Ai\Services\PromptSections\DetectedScopesSection::class,
    fn(SemanticPromptBuilder $promptBuilder) => new \Condoedge\Ai\Services\PromptSections\PatternLibrarySection($promptBuilder->getPatternLibrary()),
    \Condoedge\Ai\Services\PromptSections\QueryRulesSection::class,
    \Condoedge\Ai\Services\PromptSections\QuestionSection::class,
    \Condoedge\Ai\Services\PromptSections\TaskInstructionsSection::class,
],
```

**Step 2: Clear config cache and test**

Run: `php artisan config:clear`

**Step 3: Commit**

```bash
git add config/ai.php
git commit -m "config: register EntityActionAwarenessSection in query_generator_sections"
```

---

## Task 5: Manual Testing Checklist

**Step 1: Clear caches**

```bash
php artisan config:clear
php artisan cache:clear
```

**Step 2: Test in tinker**

```php
// Verify entity actions are loaded
config('ai.entity_actions');
// Should show: ['Person' => ['profile' => [...]]]

// Verify discovery service works
$discovery = app(\Condoedge\Ai\Services\Discovery\EntityAutoDiscovery::class);
$discovery->hasEntityActions('Person');
// Should return: true

$discovery->getEntityActions('Person');
// Should return: [['action_key' => 'profile', 'aliases' => [...], 'label' => 'View Profile']]
```

**Step 3: Test full flow**

1. Ask: "Who has a birthday coming up?"
2. AI responds with names and IDs
3. Ask: "Give me the profile link for [name]"
4. AI should respond with: `[View [name]](entity://Person/ID/profile)`

**Expected behavior:**
- QueryGenerator sees "profile link" is an action alias
- Sees entity ID in conversation context
- Returns "NO QUERY REQUIRED"
- ResponseGenerator formats action link using context IDs

---

## Summary

| Task | Description | Files Changed |
|------|-------------|---------------|
| 1 | Create EntityActionAwarenessSection | New: section + test |
| 2 | Refactor ResponseEntityActionsSection | Modified: section + test |
| 3 | Verify context flow + integration test | New: integration test |
| 4 | Register new section in config | Modified: config/ai.php |
| 5 | Manual testing | N/A |

**Key Changes:**
1. Query phase now knows "profile link" = action, not field
2. Response phase uses conversation context for entity types and IDs
3. NO QUERY responses properly handled with context data
