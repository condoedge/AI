# AI Response Features Improvement Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fix security gaps, add context persistence, and improve robustness across 5 AI response subsystems.

**Architecture:** Phased approach starting with critical security fixes (team filtering, entity access checks), then functionality improvements (action persistence in context), and finally robustness enhancements (error handling, JS wiring tests).

**Tech Stack:** Laravel, PHP 8.2, Kompo, Neo4j, Qdrant, PHPUnit, JavaScript

---

## Overview

Based on a comprehensive audit, this plan addresses:
- **2 Critical Security Issues** (P0)
- **3 High Priority Functional Gaps** (P1)
- **6 Medium Priority Robustness Issues** (P2)

---

## PHASE 1: CRITICAL SECURITY FIXES

### Task 1: Integrate TeamFilteredQuery into QueryExecutor

**Files:**
- Modify: `src/Services/QueryExecutor.php`
- Create: `tests/Unit/Services/QueryExecutorSecurityTest.php`

**Context:**
Currently `QueryExecutor.execute()` runs raw Cypher queries without team filtering. The `TeamFilteredQuery` class exists but is never used. Users can access data from any team via AI queries.

**Step 1: Write the failing test**

Create `tests/Unit/Services/QueryExecutorSecurityTest.php`:

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Services\QueryExecutor;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Services\Security\TeamFilteredQuery;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

class QueryExecutorSecurityTest extends TestCase
{
    /** @test */
    public function it_applies_team_filter_when_provided_in_options(): void
    {
        $mockGraph = Mockery::mock(GraphStoreInterface::class);
        $mockGraph->shouldReceive('query')
            ->once()
            ->withArgs(function ($query, $params) {
                // Verify team filter is applied
                return str_contains($query, 'BELONGS_TO_TEAM')
                    && isset($params['teamIds'])
                    && $params['teamIds'] === [1, 2];
            })
            ->andReturn([['name' => 'Test']]);

        $executor = new QueryExecutor($mockGraph);

        $teamFilter = new TeamFilteredQuery([1, 2]);
        $result = $executor->execute(
            'MATCH (n:Person) RETURN n',
            [],
            ['team_filter' => $teamFilter]
        );

        $this->assertTrue($result['success']);
    }

    /** @test */
    public function it_does_not_apply_team_filter_when_not_provided(): void
    {
        $mockGraph = Mockery::mock(GraphStoreInterface::class);
        $mockGraph->shouldReceive('query')
            ->once()
            ->withArgs(function ($query, $params) {
                // No team filter in query
                return !str_contains($query, 'BELONGS_TO_TEAM');
            })
            ->andReturn([]);

        $executor = new QueryExecutor($mockGraph);

        $result = $executor->execute('MATCH (n:Person) RETURN n');

        $this->assertTrue($result['success']);
    }

    /** @test */
    public function it_passes_team_ids_as_parameters(): void
    {
        $mockGraph = Mockery::mock(GraphStoreInterface::class);
        $mockGraph->shouldReceive('query')
            ->once()
            ->withArgs(function ($query, $params) {
                return isset($params['teamIds']) && $params['teamIds'] === [5, 10, 15];
            })
            ->andReturn([]);

        $executor = new QueryExecutor($mockGraph);

        $teamFilter = new TeamFilteredQuery([5, 10, 15]);
        $executor->execute(
            'MATCH (n:Customer) RETURN n',
            [],
            ['team_filter' => $teamFilter]
        );
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Services/QueryExecutorSecurityTest.php -v`
Expected: FAIL - team filter not applied

**Step 3: Implement team filtering in QueryExecutor**

Modify `src/Services/QueryExecutor.php`. Add after line 75 (after `$includeStats`):

```php
        // Get team filter if provided
        $teamFilter = $options['team_filter'] ?? null;
```

Then modify the query execution section (around line 102-112). Replace:

```php
        // Apply limit if not present
        if (!preg_match('/\bLIMIT\b/i', $cypherQuery)) {
            $cypherQuery .= " LIMIT {$limit}";
        }

        // Track execution time
        $startTime = microtime(true);

        try {
            // Execute query
            $rawResults = $this->graphStore->query($cypherQuery, $parameters);
```

With:

```php
        // Apply team filtering if provided
        if ($teamFilter instanceof \Condoedge\Ai\Services\Security\TeamFilteredQuery && $teamFilter->hasFilters()) {
            $cypherQuery = $this->applyTeamFilter($cypherQuery, $teamFilter);
            $parameters = array_merge($parameters, [
                'teamIds' => $teamFilter->getTeamIds(),
                'ownerId' => $teamFilter->getOwnerId(),
            ]);
        }

        // Apply limit if not present
        if (!preg_match('/\bLIMIT\b/i', $cypherQuery)) {
            $cypherQuery .= " LIMIT {$limit}";
        }

        // Track execution time
        $startTime = microtime(true);

        try {
            // Execute query
            $rawResults = $this->graphStore->query($cypherQuery, $parameters);
```

Add new method at end of class (before closing brace):

```php
    /**
     * Apply team filter to a Cypher query
     *
     * Injects team filtering into MATCH clauses by requiring nodes
     * to have a BELONGS_TO_TEAM relationship to an authorized team.
     *
     * @param string $cypherQuery Original query
     * @param \Condoedge\Ai\Services\Security\TeamFilteredQuery $teamFilter Team filter
     * @return string Modified query with team filtering
     */
    protected function applyTeamFilter(string $cypherQuery, \Condoedge\Ai\Services\Security\TeamFilteredQuery $teamFilter): string
    {
        // Extract node alias from first MATCH clause
        if (!preg_match('/MATCH\s*\((\w+)(?::\w+)?\)/i', $cypherQuery, $matches)) {
            return $cypherQuery; // Can't parse, return unchanged
        }

        $nodeAlias = $matches[1];

        // Build team filter clause
        $teamClause = '';
        if (!empty($teamFilter->getTeamIds())) {
            $teamClause = "-[:BELONGS_TO_TEAM]->(t:Team) WHERE t.id IN \$teamIds";
        }

        if (empty($teamClause)) {
            return $cypherQuery;
        }

        // Inject team relationship into first MATCH
        $cypherQuery = preg_replace(
            '/MATCH\s*\((\w+)(:\w+)?\)/i',
            "MATCH ($1$2){$teamClause}",
            $cypherQuery,
            1 // Only first MATCH
        );

        // If query had WHERE clause, change our WHERE to AND
        if (preg_match('/WHERE\s+t\.id\s+IN.*?WHERE\s/i', $cypherQuery)) {
            $cypherQuery = preg_replace(
                '/WHERE\s+t\.id\s+IN\s+\$teamIds\s+WHERE\s/i',
                'WHERE t.id IN $teamIds AND ',
                $cypherQuery
            );
        }

        return $cypherQuery;
    }
```

**Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Services/QueryExecutorSecurityTest.php -v`
Expected: PASS (3 tests)

**Step 5: Commit**

```bash
git add src/Services/QueryExecutor.php tests/Unit/Services/QueryExecutorSecurityTest.php
git commit -m "security: integrate TeamFilteredQuery into QueryExecutor

Add team_filter option to QueryExecutor.execute() that applies
BELONGS_TO_TEAM relationship filtering to Cypher queries.

Closes security gap where users could access data from any team.

🤖 Generated with [Claude Code](https://claude.com/claude-code)

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>"
```

---

### Task 2: Add Entity Access Check to Action Links

**Files:**
- Modify: `src/Services/Response/ResponseActionLinkProcessor.php`
- Create: `tests/Unit/Services/Response/ResponseActionLinkProcessorSecurityTest.php`

**Context:**
`ResponseActionLinkProcessor.extractActionLinks()` extracts entity IDs from AI responses but doesn't verify the user can access those entities. Users could see action links for unauthorized entities.

**Step 1: Write the failing test**

Create `tests/Unit/Services/Response/ResponseActionLinkProcessorSecurityTest.php`:

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Response;

use Condoedge\Ai\Services\Response\ResponseActionLinkProcessor;
use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Condoedge\Ai\Contracts\EntityAccessCheckerInterface;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

class ResponseActionLinkProcessorSecurityTest extends TestCase
{
    /** @test */
    public function it_filters_out_inaccessible_entity_links(): void
    {
        $mockDiscovery = Mockery::mock(EntityAutoDiscovery::class);
        $mockDiscovery->shouldReceive('getEntityActionResolver')
            ->andReturn(fn($id) => 'link');
        $mockDiscovery->shouldReceive('getGenericActionResolver')
            ->andReturn(fn() => 'link');

        $mockAccessChecker = Mockery::mock(EntityAccessCheckerInterface::class);
        $mockAccessChecker->shouldReceive('canAccess')
            ->with('Person', '123', Mockery::any())
            ->andReturn(true); // Can access 123
        $mockAccessChecker->shouldReceive('canAccess')
            ->with('Person', '456', Mockery::any())
            ->andReturn(false); // Cannot access 456

        $processor = new ResponseActionLinkProcessor($mockDiscovery, $mockAccessChecker);

        $response = 'Here are profiles: [John](entity://Person/123/profile) and [Jane](entity://Person/456/profile)';

        $user = (object) ['id' => 1];
        $links = $processor->extractActionLinks($response, $user);

        // Should only include link for entity 123
        $this->assertCount(1, $links);
        $this->assertEquals('123', $links[0]['entity_id']);
    }

    /** @test */
    public function it_includes_all_links_when_no_access_checker_provided(): void
    {
        $mockDiscovery = Mockery::mock(EntityAutoDiscovery::class);
        $mockDiscovery->shouldReceive('getEntityActionResolver')
            ->andReturn(fn($id) => 'link');

        $processor = new ResponseActionLinkProcessor($mockDiscovery); // No access checker

        $response = '[John](entity://Person/123/profile) [Jane](entity://Person/456/profile)';

        $links = $processor->extractActionLinks($response);

        // Both links included when no access checker
        $this->assertCount(2, $links);
    }

    /** @test */
    public function it_always_includes_generic_action_links(): void
    {
        $mockDiscovery = Mockery::mock(EntityAutoDiscovery::class);
        $mockDiscovery->shouldReceive('getEntityActionResolver')
            ->andReturn(fn($id) => 'link');
        $mockDiscovery->shouldReceive('getGenericActionResolver')
            ->andReturn(fn() => 'link');

        $mockAccessChecker = Mockery::mock(EntityAccessCheckerInterface::class);
        $mockAccessChecker->shouldReceive('canAccess')
            ->andReturn(false); // Block all entity access

        $processor = new ResponseActionLinkProcessor($mockDiscovery, $mockAccessChecker);

        $response = '[Settings](action://settings) [John](entity://Person/123/profile)';

        $user = (object) ['id' => 1];
        $links = $processor->extractActionLinks($response, $user);

        // Generic link always included, entity link filtered
        $this->assertCount(1, $links);
        $this->assertEquals('generic', $links[0]['type']);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Services/Response/ResponseActionLinkProcessorSecurityTest.php -v`
Expected: FAIL - constructor signature doesn't match

**Step 3: Create the EntityAccessCheckerInterface**

Create `src/Contracts/EntityAccessCheckerInterface.php`:

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Contracts;

/**
 * Entity Access Checker Interface
 *
 * Determines if a user can access a specific entity.
 * Implementations should check against your application's permission system.
 */
interface EntityAccessCheckerInterface
{
    /**
     * Check if user can access an entity
     *
     * @param string $entityType The entity type (e.g., 'Person', 'Customer')
     * @param string|int $entityId The entity ID
     * @param mixed $user The user to check access for
     * @return bool True if user can access the entity
     */
    public function canAccess(string $entityType, string|int $entityId, mixed $user): bool;
}
```

**Step 4: Update ResponseActionLinkProcessor**

Replace `src/Services/Response/ResponseActionLinkProcessor.php`:

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Response;

use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Condoedge\Ai\Contracts\EntityAccessCheckerInterface;

/**
 * Response Action Link Processor
 *
 * Extracts action links from AI responses and prepares them for rendering.
 * Handles both entity:// and action:// protocol links.
 * Optionally filters entity links by user access permissions.
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
        private ?EntityAutoDiscovery $discovery = null,
        private ?EntityAccessCheckerInterface $accessChecker = null
    ) {
        $this->discovery = $discovery ?? app(EntityAutoDiscovery::class);
    }

    /**
     * Extract all action links from response text
     *
     * @param string $response The AI response text
     * @param mixed $user Optional user for access checking (null = no filtering)
     * @return array Array of action link metadata
     */
    public function extractActionLinks(string $response, mixed $user = null): array
    {
        $links = [];

        // Extract entity action links
        preg_match_all(self::ENTITY_PATTERN, $response, $entityMatches, PREG_SET_ORDER);
        foreach ($entityMatches as $match) {
            $entityType = $match[2];
            $entityId = $match[3];

            // Check access if checker is available and user is provided
            if ($this->accessChecker !== null && $user !== null) {
                if (!$this->accessChecker->canAccess($entityType, $entityId, $user)) {
                    continue; // Skip inaccessible entities
                }
            }

            $links[] = [
                'type' => 'entity',
                'full_match' => $match[0],
                'text' => $match[1],
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'action_key' => $match[4],
                'has_resolver' => $this->discovery->getEntityActionResolver($entityType, $match[4]) !== null,
            ];
        }

        // Extract generic action links (always included - no access check needed)
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
     * @param mixed $user Optional user for access checking
     * @return array Contains 'action_links' array and 'has_action_links' boolean
     */
    public function processResponse(string $response, mixed $user = null): array
    {
        $links = $this->extractActionLinks($response, $user);

        return [
            'action_links' => $links,
            'has_action_links' => !empty($links),
        ];
    }

    /**
     * Enrich a response array with action link metadata
     *
     * @param array $response The response array (must contain 'answer' or 'content')
     * @param mixed $user Optional user for access checking
     * @return array The enriched response
     */
    public function enrichResponse(array $response, mixed $user = null): array
    {
        $content = $response['answer'] ?? $response['content'] ?? '';
        $actionData = $this->processResponse($content, $user);

        return array_merge($response, $actionData);
    }
}
```

**Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Services/Response/ResponseActionLinkProcessorSecurityTest.php -v`
Expected: PASS (3 tests)

**Step 6: Commit**

```bash
git add src/Contracts/EntityAccessCheckerInterface.php src/Services/Response/ResponseActionLinkProcessor.php tests/Unit/Services/Response/ResponseActionLinkProcessorSecurityTest.php
git commit -m "security: add entity access checking to action link processor

Add optional EntityAccessCheckerInterface to ResponseActionLinkProcessor
that filters out action links for entities the user cannot access.

- Create EntityAccessCheckerInterface contract
- Update extractActionLinks() to accept user parameter
- Filter entity links when access checker is provided
- Generic actions always pass through (no entity to check)

🤖 Generated with [Claude Code](https://claude.com/claude-code)

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>"
```

---

## PHASE 2: CONTEXT PERSISTENCE

### Task 3: Persist Entity Actions in Conversation Context

**Files:**
- Modify: `src/Services/Context/ConversationContextManager.php`
- Modify: `src/Services/ResponseSections/ResponseEntityActionsSection.php`
- Modify: `tests/Unit/Services/Context/ConversationContextManagerTest.php`

**Context:**
Entity actions are computed fresh each response but never stored in `context_snapshot`. Users cannot ask "what actions are available?" in follow-up questions because the actions aren't in context.

**Step 1: Write the failing test**

Add to `tests/Unit/Services/Context/ConversationContextManagerTest.php`:

```php
    /** @test */
    public function it_persists_available_actions_in_context_snapshot(): void
    {
        $conversation = AiConversation::create([
            'user_id' => 1,
            'title' => 'Test',
        ]);

        $queryResult = [
            'data' => [
                ['id' => '123', 'name' => 'John', '_label' => 'Person'],
            ],
            'available_actions' => [
                'Person' => [
                    ['action_key' => 'profile', 'label' => 'View Profile'],
                    ['action_key' => 'edit', 'label' => 'Edit'],
                ],
            ],
        ];

        $this->manager->recordResponse(
            $conversation,
            'Here is John.',
            'MATCH (p:Person) RETURN p',
            $queryResult
        );

        $conversation->refresh();
        $snapshot = $conversation->context_snapshot;

        $this->assertArrayHasKey('last_available_actions', $snapshot);
        $this->assertArrayHasKey('Person', $snapshot['last_available_actions']);
        $this->assertCount(2, $snapshot['last_available_actions']['Person']);
    }

    /** @test */
    public function it_preserves_actions_when_new_response_has_none(): void
    {
        $conversation = AiConversation::create([
            'user_id' => 1,
            'title' => 'Test',
            'context_snapshot' => [
                'last_available_actions' => [
                    'Person' => [['action_key' => 'profile', 'label' => 'View Profile']],
                ],
            ],
        ]);

        // New response has no actions
        $this->manager->recordResponse(
            $conversation,
            'Count is 5.',
            'MATCH (p:Person) RETURN count(p)',
            ['data' => [['count' => 5]]]
        );

        $conversation->refresh();
        $snapshot = $conversation->context_snapshot;

        // Previous actions should be preserved
        $this->assertArrayHasKey('last_available_actions', $snapshot);
        $this->assertArrayHasKey('Person', $snapshot['last_available_actions']);
    }
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Services/Context/ConversationContextManagerTest.php --filter "persists_available_actions" -v`
Expected: FAIL - key 'last_available_actions' not found

**Step 3: Update ConversationContextManager to persist actions**

Modify `src/Services/Context/ConversationContextManager.php`. In the `recordResponse()` method, add after line 129 (after `$executionStats`):

```php
        // Extract available actions from query result
        $availableActions = $queryResult['available_actions'] ?? [];
```

Then in the `$snapshotUpdate` array build section (around line 132), add preservation logic. After line 168 (after execution stats), add:

```php
        // Only update last_available_actions if there are new actions
        // Otherwise preserve the previous actions for follow-up context
        if (!empty($availableActions)) {
            $snapshotUpdate['last_available_actions'] = $availableActions;
        }
```

Also update `buildPromptContext()` method. Add to the return array (around line 221):

```php
            'last_available_actions' => $snapshot['last_available_actions'] ?? [],
```

**Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Services/Context/ConversationContextManagerTest.php --filter "actions" -v`
Expected: PASS (2 tests)

**Step 5: Commit**

```bash
git add src/Services/Context/ConversationContextManager.php tests/Unit/Services/Context/ConversationContextManagerTest.php
git commit -m "feat(context): persist entity actions in conversation snapshot

Store available_actions from query results in context_snapshot as
last_available_actions. Preserved across turns when new response
has no actions, enabling 'what actions?' follow-up questions.

🤖 Generated with [Claude Code](https://claude.com/claude-code)

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>"
```

---

### Task 4: Update ConversationContextSection to Display Actions

**Files:**
- Modify: `src/Services/PromptSections/ConversationContextSection.php`
- Modify: `tests/Unit/Services/PromptSections/ConversationContextSectionTest.php`

**Context:**
Now that actions are persisted, the prompt section needs to display them so the AI can answer "what actions are available?"

**Step 1: Write the failing test**

Add to `tests/Unit/Services/PromptSections/ConversationContextSectionTest.php`:

```php
    /** @test */
    public function it_formats_available_actions_in_output(): void
    {
        $section = new ConversationContextSection();

        $context = [
            'conversation_context' => [
                'focused_entity' => 'Person',
                'last_available_actions' => [
                    'Person' => [
                        ['action_key' => 'profile', 'label' => 'View Profile'],
                        ['action_key' => 'edit', 'label' => 'Edit Person'],
                    ],
                ],
            ],
        ];

        $output = $section->format('what actions can I do?', $context);

        $this->assertStringContainsString('Available Actions', $output);
        $this->assertStringContainsString('View Profile', $output);
        $this->assertStringContainsString('Edit Person', $output);
        $this->assertStringContainsString('profile', $output);
    }
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Services/PromptSections/ConversationContextSectionTest.php --filter "formats_available_actions" -v`
Expected: FAIL - 'Available Actions' not in output

**Step 3: Update ConversationContextSection**

In `src/Services/PromptSections/ConversationContextSection.php`, add a new method and call it in `format()`.

Add this method before the closing brace:

```php
    /**
     * Format available actions for display in prompt
     */
    protected function formatAvailableActions(array $actions): string
    {
        if (empty($actions)) {
            return '';
        }

        $output = "\n**Available Actions from Previous Response:**\n";

        foreach ($actions as $entityType => $entityActions) {
            $output .= "- **{$entityType}:**\n";
            foreach ($entityActions as $action) {
                $actionKey = $action['action_key'] ?? 'unknown';
                $label = $action['label'] ?? $actionKey;
                $output .= "  - `{$actionKey}`: {$label}\n";
            }
        }

        $output .= "\nIf user asks about actions, reference the above.\n";

        return $output;
    }
```

Then in the `format()` method, add a call to this method. Find where the output is being built (look for the return or final output construction) and add:

```php
        // Available actions from previous response
        $availableActions = $conversationContext['last_available_actions'] ?? [];
        if (!empty($availableActions)) {
            $output .= $this->formatAvailableActions($availableActions);
        }
```

**Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Services/PromptSections/ConversationContextSectionTest.php --filter "actions" -v`
Expected: PASS

**Step 5: Commit**

```bash
git add src/Services/PromptSections/ConversationContextSection.php tests/Unit/Services/PromptSections/ConversationContextSectionTest.php
git commit -m "feat(context): display available actions in conversation prompt

Format last_available_actions in ConversationContextSection so AI
can answer follow-up questions like 'what actions can I do?'

🤖 Generated with [Claude Code](https://claude.com/claude-code)

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>"
```

---

### Task 5: Populate Visualization Metadata in Context

**Files:**
- Modify: `src/Services/Context/ConversationContextManager.php`
- Modify: `tests/Unit/Services/Context/ConversationContextManagerTest.php`

**Context:**
The `last_visualizations` field is declared in `buildPromptContext()` but never populated by `recordResponse()`. Users can't reference charts from earlier questions.

**Step 1: Write the failing test**

Add to `tests/Unit/Services/Context/ConversationContextManagerTest.php`:

```php
    /** @test */
    public function it_persists_visualization_metadata(): void
    {
        $conversation = AiConversation::create([
            'user_id' => 1,
            'title' => 'Test',
        ]);

        $queryResult = [
            'data' => [['month' => 'Jan', 'count' => 10]],
            'visualizations' => [
                ['type' => 'bar_chart', 'title' => 'Monthly Counts', 'x_axis' => 'month', 'y_axis' => 'count'],
            ],
        ];

        $this->manager->recordResponse(
            $conversation,
            'Here is a chart.',
            'MATCH (n) RETURN n.month, count(n)',
            $queryResult
        );

        $conversation->refresh();
        $snapshot = $conversation->context_snapshot;

        $this->assertArrayHasKey('last_visualizations', $snapshot);
        $this->assertCount(1, $snapshot['last_visualizations']);
        $this->assertEquals('bar_chart', $snapshot['last_visualizations'][0]['type']);
    }
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Services/Context/ConversationContextManagerTest.php --filter "visualization" -v`
Expected: FAIL - last_visualizations empty or not set

**Step 3: Update ConversationContextManager**

In `src/Services/Context/ConversationContextManager.php`, in the `recordResponse()` method, add after the `$availableActions` extraction (around line 130):

```php
        // Extract visualization metadata from query result
        $visualizations = $queryResult['visualizations'] ?? [];
```

Then after the `last_available_actions` preservation logic, add:

```php
        // Only update last_visualizations if there are new visualizations
        // Otherwise preserve the previous visualizations for follow-up context
        if (!empty($visualizations)) {
            $snapshotUpdate['last_visualizations'] = $visualizations;
        }
```

**Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Services/Context/ConversationContextManagerTest.php --filter "visualization" -v`
Expected: PASS

**Step 5: Commit**

```bash
git add src/Services/Context/ConversationContextManager.php tests/Unit/Services/Context/ConversationContextManagerTest.php
git commit -m "feat(context): populate visualization metadata in snapshot

Extract and persist visualizations from query results, enabling
follow-up questions like 'show that chart differently'

🤖 Generated with [Claude Code](https://claude.com/claude-code)

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>"
```

---

## PHASE 3: ROBUSTNESS IMPROVEMENTS

### Task 6: Add Error Handling to Action Link Proxy Extraction

**Files:**
- Modify: `src/Kompo/MessagesQuery.php`
- Create: `tests/Unit/Kompo/MessagesQueryActionLinksTest.php`

**Context:**
`extractActionLinkProxies()` calls resolver closures without try-catch. If a resolver throws an exception, the entire message fails to render.

**Step 1: Write the failing test**

Create `tests/Unit/Kompo/MessagesQueryActionLinksTest.php`:

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Kompo;

use Condoedge\Ai\Kompo\MessagesQuery;
use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

class MessagesQueryActionLinksTest extends TestCase
{
    /** @test */
    public function it_handles_resolver_exceptions_gracefully(): void
    {
        $mockDiscovery = Mockery::mock(EntityAutoDiscovery::class);
        $mockDiscovery->shouldReceive('getEntityActionResolver')
            ->andReturn(function ($id) {
                throw new \RuntimeException('Resolver failed');
            });
        $mockDiscovery->shouldReceive('getGenericActionResolver')
            ->andReturn(null);

        $this->app->instance(EntityAutoDiscovery::class, $mockDiscovery);

        // Use reflection to test protected method
        $query = new MessagesQuery();
        $method = new \ReflectionMethod($query, 'extractActionLinkProxies');
        $method->setAccessible(true);

        $content = '[John](entity://Person/123/profile)';

        // Should not throw, should return empty array
        $proxies = $method->invoke($query, $content);

        $this->assertIsArray($proxies);
        $this->assertEmpty($proxies);
    }

    /** @test */
    public function it_logs_resolver_failures(): void
    {
        $mockDiscovery = Mockery::mock(EntityAutoDiscovery::class);
        $mockDiscovery->shouldReceive('getEntityActionResolver')
            ->andReturn(function ($id) {
                throw new \RuntimeException('Test error');
            });
        $mockDiscovery->shouldReceive('getGenericActionResolver')
            ->andReturn(null);

        $this->app->instance(EntityAutoDiscovery::class, $mockDiscovery);

        \Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context) {
                return str_contains($message, 'Action link resolver failed')
                    && $context['entity_type'] === 'Person'
                    && $context['entity_id'] === '123';
            });

        $query = new MessagesQuery();
        $method = new \ReflectionMethod($query, 'extractActionLinkProxies');
        $method->setAccessible(true);

        $method->invoke($query, '[John](entity://Person/123/profile)');
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Kompo/MessagesQueryActionLinksTest.php -v`
Expected: FAIL - exception thrown instead of caught

**Step 3: Add try-catch to extractActionLinkProxies**

In `src/Kompo/MessagesQuery.php`, find the `extractActionLinkProxies()` method. Wrap the resolver execution in try-catch.

Find this code (around line 714-722):

```php
                $resolver = $discovery->getEntityActionResolver($entityType, $actionKey);
                if ($resolver) {
                    $element = $resolver($entityId);
                    if ($element) {
                        // Add proxy class and hide it
                        $proxyClass = "js-action-entity-{$entityType}-{$entityId}-{$actionKey}-proxy";
                        $proxies[$key] = $element->class($proxyClass . ' hidden');
                    }
                }
```

Replace with:

```php
                $resolver = $discovery->getEntityActionResolver($entityType, $actionKey);
                if ($resolver) {
                    try {
                        $element = $resolver($entityId);
                        if ($element) {
                            // Add proxy class and hide it
                            $proxyClass = "js-action-entity-{$entityType}-{$entityId}-{$actionKey}-proxy";
                            $proxies[$key] = $element->class($proxyClass . ' hidden');
                        }
                    } catch (\Throwable $e) {
                        \Log::warning('Action link resolver failed', [
                            'entity_type' => $entityType,
                            'entity_id' => $entityId,
                            'action_key' => $actionKey,
                            'error' => $e->getMessage(),
                        ]);
                        // Continue processing other links
                    }
                }
```

Do the same for generic action links (around line 738-746):

```php
                $resolver = $discovery->getGenericActionResolver($actionKey);
                if ($resolver) {
                    try {
                        $element = $resolver();
                        if ($element) {
                            // Add proxy class and hide it
                            $proxyClass = "js-action-generic-{$actionKey}-proxy";
                            $proxies[$key] = $element->class($proxyClass . ' hidden');
                        }
                    } catch (\Throwable $e) {
                        \Log::warning('Generic action link resolver failed', [
                            'action_key' => $actionKey,
                            'error' => $e->getMessage(),
                        ]);
                        // Continue processing other links
                    }
                }
```

**Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Kompo/MessagesQueryActionLinksTest.php -v`
Expected: PASS (2 tests)

**Step 5: Commit**

```bash
git add src/Kompo/MessagesQuery.php tests/Unit/Kompo/MessagesQueryActionLinksTest.php
git commit -m "fix(action-links): add error handling to proxy extraction

Wrap resolver execution in try-catch to prevent single bad resolver
from breaking entire message render. Log failures for debugging.

🤖 Generated with [Claude Code](https://claude.com/claude-code)

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>"
```

---

### Task 7: Add Integration Test for Action Link JS Wiring

**Files:**
- Create: `tests/Feature/ActionLinkWiringTest.php`

**Context:**
The JS wiring (`wireActionButtons` connecting spans to proxies) is completely untested. We need an integration test that verifies the HTML output has correct class patterns.

**Step 1: Write the test**

Create `tests/Feature/ActionLinkWiringTest.php`:

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Feature;

use Condoedge\Ai\Kompo\MessagesQuery;
use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Condoedge\Ai\Tests\TestCase;
use Kompo\Elements\Link;
use Mockery;

class ActionLinkWiringTest extends TestCase
{
    /** @test */
    public function it_creates_matching_class_patterns_for_wiring(): void
    {
        $mockDiscovery = Mockery::mock(EntityAutoDiscovery::class);
        $mockDiscovery->shouldReceive('getEntityActionResolver')
            ->with('Person', 'profile')
            ->andReturn(fn($id) => (new Link('View Profile'))->href("/person/{$id}"));
        $mockDiscovery->shouldReceive('getGenericActionResolver')
            ->andReturn(null);

        $this->app->instance(EntityAutoDiscovery::class, $mockDiscovery);

        $query = new MessagesQuery();

        // Test processActionLinks produces correct visible element class
        $processMethod = new \ReflectionMethod($query, 'processActionLinks');
        $processMethod->setAccessible(true);

        $html = $processMethod->invoke($query, '[John](entity://Person/123/profile)');

        // Visible span should have js-action-entity-Person-123-profile (no -proxy)
        $this->assertStringContainsString('js-action-entity-Person-123-profile', $html);
        $this->assertStringNotContainsString('js-action-entity-Person-123-profile-proxy', $html);

        // Test extractActionLinkProxies produces correct proxy element class
        $extractMethod = new \ReflectionMethod($query, 'extractActionLinkProxies');
        $extractMethod->setAccessible(true);

        $proxies = $extractMethod->invoke($query, '[John](entity://Person/123/profile)');

        $this->assertCount(1, $proxies);

        // Get the rendered proxy HTML
        $proxyElement = $proxies[0];

        // The proxy should have the -proxy suffix class
        // We check the element's class contains the proxy pattern
        $elementClasses = $proxyElement->data['class'] ?? '';
        $this->assertStringContainsString('js-action-entity-Person-123-profile-proxy', $elementClasses);
        $this->assertStringContainsString('hidden', $elementClasses);
    }

    /** @test */
    public function it_handles_multiple_action_links_with_unique_classes(): void
    {
        $mockDiscovery = Mockery::mock(EntityAutoDiscovery::class);
        $mockDiscovery->shouldReceive('getEntityActionResolver')
            ->andReturn(fn($id) => (new Link('View'))->href("/view/{$id}"));
        $mockDiscovery->shouldReceive('getGenericActionResolver')
            ->andReturn(null);

        $this->app->instance(EntityAutoDiscovery::class, $mockDiscovery);

        $query = new MessagesQuery();

        $extractMethod = new \ReflectionMethod($query, 'extractActionLinkProxies');
        $extractMethod->setAccessible(true);

        $content = '[John](entity://Person/123/profile) and [Jane](entity://Person/456/profile)';
        $proxies = $extractMethod->invoke($query, $content);

        // Should have 2 unique proxies
        $this->assertCount(2, $proxies);

        // Each should have unique class
        $classes = array_map(fn($p) => $p->data['class'] ?? '', $proxies);
        $this->assertStringContainsString('Person-123-profile-proxy', $classes[0]);
        $this->assertStringContainsString('Person-456-profile-proxy', $classes[1]);
    }

    /** @test */
    public function it_deduplicates_same_action_links(): void
    {
        $mockDiscovery = Mockery::mock(EntityAutoDiscovery::class);
        $mockDiscovery->shouldReceive('getEntityActionResolver')
            ->andReturn(fn($id) => (new Link('View'))->href("/view/{$id}"));
        $mockDiscovery->shouldReceive('getGenericActionResolver')
            ->andReturn(null);

        $this->app->instance(EntityAutoDiscovery::class, $mockDiscovery);

        $query = new MessagesQuery();

        $extractMethod = new \ReflectionMethod($query, 'extractActionLinkProxies');
        $extractMethod->setAccessible(true);

        // Same link twice
        $content = '[John](entity://Person/123/profile) [John Again](entity://Person/123/profile)';
        $proxies = $extractMethod->invoke($query, $content);

        // Should deduplicate to 1 proxy
        $this->assertCount(1, $proxies);
    }
}
```

**Step 2: Run tests**

Run: `vendor/bin/phpunit tests/Feature/ActionLinkWiringTest.php -v`
Expected: PASS (3 tests) - these test the existing implementation

**Step 3: Commit**

```bash
git add tests/Feature/ActionLinkWiringTest.php
git commit -m "test(action-links): add integration tests for JS wiring patterns

Verify that processActionLinks and extractActionLinkProxies create
matching class patterns (js-action-X and js-action-X-proxy) that
wireActionButtons can connect on the frontend.

🤖 Generated with [Claude Code](https://claude.com/claude-code)

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>"
```

---

### Task 8: Add Console Error Logging for Missing Proxies

**Files:**
- Modify: `resources/js/chat-message-injector.js`

**Context:**
`wireActionButtons()` silently fails when proxy elements aren't found. Add console warnings for debugging.

**Step 1: Update wireActionButtons**

In `resources/js/chat-message-injector.js`, find `wireActionButtons()` method (around line 220).

Replace the current implementation:

```javascript
    wireActionButtons(messageElement) {
        if (!messageElement) return;

        // Find all visible action buttons (have js-action-* but not *-proxy)
        const visibleButtons = messageElement.querySelectorAll('[class*="js-action-"]:not([class*="-proxy"])');

        visibleButtons.forEach(btn => {
            // Extract action class (e.g., "js-action-copy" → look for "js-action-copy-proxy")
            const actionClass = [...btn.classList].find(c => /^js-action-[\w-]+$/.test(c) && !c.includes('-proxy'));
            if (!actionClass) return;

            const proxyClass = actionClass + '-proxy';
            const proxy = messageElement.querySelector('.' + proxyClass);

            if (proxy) {
                btn.onclick = (e) => {
                    e.preventDefault();
                    e.stopPropagation();

                    // Handle feedback button color persistence
                    if (actionClass === 'js-action-feedback-pos') {
                        this.setFeedbackState(messageElement, 'positive');
                    } else if (actionClass === 'js-action-feedback-neg') {
                        this.setFeedbackState(messageElement, 'negative');
                    }

                    // Handle regenerate loading indicator
                    if (actionClass === 'js-action-regenerate') {
                        this.showRegeneratingIndicator(messageElement);
                    }

                    // Trigger the proxy click
                    proxy.click();
                };
            }
        });
```

With:

```javascript
    wireActionButtons(messageElement) {
        if (!messageElement) return;

        // Find all visible action buttons (have js-action-* but not *-proxy)
        const visibleButtons = messageElement.querySelectorAll('[class*="js-action-"]:not([class*="-proxy"])');

        visibleButtons.forEach(btn => {
            // Extract action class (e.g., "js-action-copy" → look for "js-action-copy-proxy")
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

                    // Handle feedback button color persistence
                    if (actionClass === 'js-action-feedback-pos') {
                        this.setFeedbackState(messageElement, 'positive');
                    } else if (actionClass === 'js-action-feedback-neg') {
                        this.setFeedbackState(messageElement, 'negative');
                    }

                    // Handle regenerate loading indicator
                    if (actionClass === 'js-action-regenerate') {
                        this.showRegeneratingIndicator(messageElement);
                    }

                    // Trigger the proxy click
                    try {
                        proxy.click();
                    } catch (err) {
                        console.error('[AI Chat] Proxy click failed:', actionClass, err);
                    }
                };
            } else {
                // Log warning for entity/generic action links that have no proxy
                // (Standard buttons like copy/feedback/regenerate may not have proxies)
                if (actionClass.includes('-entity-') || actionClass.includes('-generic-')) {
                    console.warn('[AI Chat] Action proxy not found:', proxyClass, 'Button:', btn);
                }
            }
        });
```

**Step 2: Commit**

```bash
git add resources/js/chat-message-injector.js
git commit -m "fix(js): add console warnings for missing action proxies

Log warnings when entity/generic action link proxies aren't found,
and when proxy.click() fails. Helps debug wiring issues.

🤖 Generated with [Claude Code](https://claude.com/claude-code)

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>"
```

---

### Task 9: Make Entity ID Fields Configurable

**Files:**
- Modify: `config/ai.php`
- Modify: `src/Services/PromptSections/EntityActionAwarenessSection.php`
- Modify: `src/Services/ResponseSections/ResponseEntityActionsSection.php`
- Create: `tests/Unit/Services/PromptSections/EntityIdFieldsTest.php`

**Context:**
Entity ID extraction uses hardcoded fallback `['id', '_id', 'neo4j_id']`. Apps with custom ID fields (e.g., `uuid`, `person_id`) can't be detected.

**Step 1: Add config option**

In `config/ai.php`, find the `entity_actions` section (around line 783) and add before it:

```php
    /*
    |--------------------------------------------------------------------------
    | Entity ID Field Names
    |--------------------------------------------------------------------------
    |
    | Field names to check when extracting entity IDs from query results.
    | Checked in order; first match wins. Add custom fields for your entities.
    |
    */
    'entity_id_fields' => [
        'id',
        '_id',
        'neo4j_id',
        'uuid',
    ],
```

**Step 2: Write the failing test**

Create `tests/Unit/Services/PromptSections/EntityIdFieldsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\PromptSections;

use Condoedge\Ai\Services\PromptSections\EntityActionAwarenessSection;
use Condoedge\Ai\Tests\TestCase;

class EntityIdFieldsTest extends TestCase
{
    /** @test */
    public function it_uses_configured_id_fields(): void
    {
        config(['ai.entity_id_fields' => ['custom_id', 'reference_id']]);
        config(['ai.entity_actions' => [
            'Person' => [
                'profile' => ['action' => fn($id) => null, 'aliases' => [], 'label' => 'View'],
            ],
        ]]);

        $section = new EntityActionAwarenessSection();

        $context = [
            'conversation_context' => [
                'focused_entity' => 'Person',
                'last_result_sample' => [
                    ['custom_id' => 'ABC123', 'name' => 'John'],  // Uses custom_id
                ],
            ],
        ];

        $output = $section->format('show profile', $context);

        // Should find the ID using custom_id field
        $this->assertStringContainsString('ABC123', $output);
    }

    /** @test */
    public function it_falls_back_to_default_id_fields(): void
    {
        config(['ai.entity_id_fields' => null]);  // No config
        config(['ai.entity_actions' => [
            'Person' => [
                'profile' => ['action' => fn($id) => null, 'aliases' => [], 'label' => 'View'],
            ],
        ]]);

        $section = new EntityActionAwarenessSection();

        $context = [
            'conversation_context' => [
                'focused_entity' => 'Person',
                'last_result_sample' => [
                    ['id' => '999', 'name' => 'Jane'],
                ],
            ],
        ];

        $output = $section->format('show profile', $context);

        // Should find using default 'id' field
        $this->assertStringContainsString('999', $output);
    }
}
```

**Step 3: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Services/PromptSections/EntityIdFieldsTest.php -v`
Expected: FAIL - custom_id not recognized

**Step 4: Update EntityActionAwarenessSection**

In `src/Services/PromptSections/EntityActionAwarenessSection.php`, add a helper method:

```php
    /**
     * Extract entity ID from result using configured field names
     */
    protected function extractEntityId(array $result): ?string
    {
        $idFields = config('ai.entity_id_fields', ['id', '_id', 'neo4j_id']);

        foreach ($idFields as $field) {
            if (isset($result[$field]) && $result[$field] !== null) {
                return (string) $result[$field];
            }
        }

        return null;
    }
```

Then update the existing ID extraction code (around line 91-92). Replace:

```php
                $id = $result['id'] ?? $result['_id'] ?? $result['neo4j_id'] ?? null;
                if ($id === null) {
```

With:

```php
                $id = $this->extractEntityId($result);
                if ($id === null) {
```

**Step 5: Update ResponseEntityActionsSection similarly**

In `src/Services/ResponseSections/ResponseEntityActionsSection.php`, add the same helper method and update the ID extraction (around line 87):

Replace:

```php
                    $id = $entity['id'] ?? $entity['_id'] ?? $entity['neo4j_id'] ?? null;
```

With:

```php
                    $id = $this->extractEntityId($entity);
```

And add the helper method:

```php
    /**
     * Extract entity ID from result using configured field names
     */
    protected function extractEntityId(array $entity): ?string
    {
        $idFields = config('ai.entity_id_fields', ['id', '_id', 'neo4j_id']);

        foreach ($idFields as $field) {
            if (isset($entity[$field]) && $entity[$field] !== null) {
                return (string) $entity[$field];
            }
        }

        return null;
    }
```

**Step 6: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Services/PromptSections/EntityIdFieldsTest.php -v`
Expected: PASS (2 tests)

**Step 7: Commit**

```bash
git add config/ai.php src/Services/PromptSections/EntityActionAwarenessSection.php src/Services/ResponseSections/ResponseEntityActionsSection.php tests/Unit/Services/PromptSections/EntityIdFieldsTest.php
git commit -m "feat(config): make entity ID field names configurable

Add ai.entity_id_fields config option to customize which fields
are checked when extracting entity IDs from query results.

Defaults to ['id', '_id', 'neo4j_id'] for backwards compatibility.
Apps can add custom fields like 'uuid' or 'person_id'.

🤖 Generated with [Claude Code](https://claude.com/claude-code)

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>"
```

---

## Summary

This plan covers:

| Task | Priority | Type | Description |
|------|----------|------|-------------|
| 1 | P0 | Security | Integrate TeamFilteredQuery into QueryExecutor |
| 2 | P0 | Security | Add entity access checks to action links |
| 3 | P1 | Feature | Persist entity actions in conversation context |
| 4 | P1 | Feature | Display actions in conversation prompt |
| 5 | P1 | Feature | Populate visualization metadata |
| 6 | P2 | Robustness | Add error handling to proxy extraction |
| 7 | P2 | Testing | Add integration tests for JS wiring |
| 8 | P2 | Debugging | Add console warnings for missing proxies |
| 9 | P2 | Config | Make entity ID fields configurable |

Total: 9 tasks across 3 phases.

---

## Files Modified/Created

**New Files:**
- `src/Contracts/EntityAccessCheckerInterface.php`
- `tests/Unit/Services/QueryExecutorSecurityTest.php`
- `tests/Unit/Services/Response/ResponseActionLinkProcessorSecurityTest.php`
- `tests/Unit/Kompo/MessagesQueryActionLinksTest.php`
- `tests/Feature/ActionLinkWiringTest.php`
- `tests/Unit/Services/PromptSections/EntityIdFieldsTest.php`

**Modified Files:**
- `src/Services/QueryExecutor.php`
- `src/Services/Response/ResponseActionLinkProcessor.php`
- `src/Services/Context/ConversationContextManager.php`
- `src/Services/PromptSections/ConversationContextSection.php`
- `src/Services/PromptSections/EntityActionAwarenessSection.php`
- `src/Services/ResponseSections/ResponseEntityActionsSection.php`
- `src/Kompo/MessagesQuery.php`
- `resources/js/chat-message-injector.js`
- `config/ai.php`
- `tests/Unit/Services/Context/ConversationContextManagerTest.php`
- `tests/Unit/Services/PromptSections/ConversationContextSectionTest.php`
