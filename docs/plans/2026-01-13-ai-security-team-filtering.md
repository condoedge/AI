# AI Security - Team-Based Query Filtering

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Filter all AI queries by user's permitted teams using Neo4j relationship traversal.

**Architecture:** Single-layer security that gets teams via permission chain, injects team filter into Cypher queries. No inference protection needed since data is scoped to user's teams.

**Tech Stack:** PHP 8.1+, Laravel, Neo4j (Cypher), Kompo Auth package

---

## Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────────────────────────────────────────┐
│                              AI SECURITY - TEAM-BASED QUERY FILTERING                                │
└─────────────────────────────────────────────────────────────────────────────────────────────────────┘

                                    User: "Show me all invoices"
                                                 │
                                                 ▼
┌─────────────────────────────────────────────────────────────────────────────────────────────────────┐
│  STEP 1: GET TEAMS WITH PERMISSION (Permission Chain)                                                │
│  ═══════════════════════════════════════════════════════════════════════════════════════════════    │
│                                                                                                      │
│  Permission chain (first match wins):                                                                │
│  ┌─────────────────────────────────────────────────────────────────────────────────────────────┐    │
│  │  1. {Entity}.AiRetrieving  →  getTeamsIdsWithPermission('Invoice.AiRetrieving')            │    │
│  │  2. {Entity} (fallback)    →  getTeamsIdsWithPermission('Invoice')                         │    │
│  └─────────────────────────────────────────────────────────────────────────────────────────────┘    │
│                                                                                                      │
│  Result: [1, 3, 7]  ← Teams where user has permission                                               │
│  Empty? → "You do not have permission to query Invoice data"                                        │
│                                                                                                      │
└─────────────────────────────────────────────────────────────────────────────────────────────────────┘
                                                 │
                                                 ▼
┌─────────────────────────────────────────────────────────────────────────────────────────────────────┐
│  STEP 2: RESOLVE TEAM PATH FROM CONFIG                                                               │
│  ═══════════════════════════════════════════════════════════════════════════════════════════════    │
│                                                                                                      │
│  Config key: entities.Invoice.security.team_path                                                    │
│                                                                                                      │
│  ┌─────────────────────────────────────────────────────────────────────────────────────────────┐    │
│  │  Option 1: Direct column       'team_path' => 'team_id'                                     │    │
│  │  Option 2: Auto-detect         'team_path' => 'auto' (reads relationships config)          │    │
│  │  Option 3: Through parent      'team_path' => 'invoice.team_id'                            │    │
│  │  Option 4: Relationship        'team_path' => ['rel' => 'MEMBER_OF', 'target' => 'Team']   │    │
│  └─────────────────────────────────────────────────────────────────────────────────────────────┘    │
│                                                                                                      │
└─────────────────────────────────────────────────────────────────────────────────────────────────────┘
                                                 │
                                                 ▼
┌─────────────────────────────────────────────────────────────────────────────────────────────────────┐
│  STEP 3: INJECT TEAM FILTER INTO CYPHER                                                              │
│  ═══════════════════════════════════════════════════════════════════════════════════════════════    │
│                                                                                                      │
│  Direct:    WHERE i.team_id IN [1, 3, 7]                                                            │
│  Parent:    MATCH (ii)-[:BELONGS_TO]->(i:Invoice) WHERE i.team_id IN [1, 3, 7]                      │
│  Relation:  MATCH (p)-[:MEMBER_OF]->(t:Team) WHERE t.id IN [1, 3, 7]                                │
│                                                                                                      │
└─────────────────────────────────────────────────────────────────────────────────────────────────────┘
                                                 │
                                                 ▼
┌─────────────────────────────────────────────────────────────────────────────────────────────────────┐
│  STEP 4: EXECUTE & RESPOND                                                                           │
│  ═══════════════════════════════════════════════════════════════════════════════════════════════    │
│                                                                                                      │
│  Query is scoped to user's teams → No inference risk → Return results directly                      │
│  Response: "Based on your permissions, here are 47 invoices..."                                     │
│                                                                                                      │
└─────────────────────────────────────────────────────────────────────────────────────────────────────┘


┌─────────────────────────────────────────────────────────────────────────────────────────────────────┐
│  SPECIAL: GLOBAL COUNT PERMISSION                                                                    │
│  ═══════════════════════════════════════════════════════════════════════════════════════════════    │
│                                                                                                      │
│  If user has {Entity}.AiGlobalCount on ANY team:                                                    │
│  → COUNT queries skip team filter                                                                   │
│  → Shows total count across all teams                                                               │
│  → LIST queries still filtered (only count is global)                                               │
│                                                                                                      │
└─────────────────────────────────────────────────────────────────────────────────────────────────────┘
```

---

## Configuration Structure

```php
// config/ai.php - security section

'security' => [
    // Master switch
    'enabled' => env('AI_SECURITY_ENABLED', true),

    // Permission chain - checked in order, first with teams wins
    'permission_chain' => [
        '{Entity}.AiRetrieving',   // AI-specific (primary)
        '{Entity}',                // Standard entity (fallback)
    ],

    // Global count permission pattern
    'global_count_permission' => '{Entity}.AiGlobalCount',

    // Default team path strategy for entities without explicit config
    // 'auto' = detect from relationships config
    // 'team_id' = assume direct team_id column
    'default_team_path' => 'auto',

    // What to do when no team path found
    'on_missing_team_path' => 'deny',  // 'deny' or 'bypass'

    // Response scope clarification
    'clarify_scope' => true,
    'scope_prefix' => 'Based on your permissions, ',

    // Logging
    'log_denied_access' => true,
    'log_missing_team_path' => true,
    'log_channel' => env('AI_SECURITY_LOG_CHANNEL', 'ai-security'),
],
```

```php
// config/entities.php - per-entity security config

'Invoice' => [
    'model' => \App\Models\Invoice::class,

    'graph' => [
        'label' => 'Invoice',
        'properties' => ['id', 'total', 'status', 'team_id'],
        'relationships' => [
            ['type' => 'BELONGS_TO_TEAM', 'target_label' => 'Team', 'foreign_key' => 'team_id'],
        ],
    ],

    // NEW: Security configuration
    'security' => [
        // Team path - how to filter this entity by team
        // Options:
        //   'team_id'              - Direct column (default if has team_id)
        //   'auto'                 - Auto-detect from relationships
        //   'parent.team_id'       - Through parent entity
        //   ['rel' => 'X', 'target' => 'Team'] - Via relationship
        //   null                   - No team filtering (public entity)
        'team_path' => 'team_id',
    ],
],

'InvoiceItem' => [
    'model' => \App\Models\InvoiceItem::class,

    'graph' => [
        'label' => 'InvoiceItem',
        'properties' => ['id', 'description', 'amount'],
        'relationships' => [
            ['type' => 'BELONGS_TO', 'target_label' => 'Invoice', 'foreign_key' => 'invoice_id'],
        ],
    ],

    'security' => [
        // Filter through parent Invoice's team_id
        'team_path' => 'invoice.team_id',  // Traverses BELONGS_TO -> Invoice -> team_id
    ],
],

'Person' => [
    'model' => \App\Models\Person::class,

    'graph' => [
        'label' => 'Person',
        'properties' => ['id', 'name', 'email'],
        'relationships' => [
            ['type' => 'MEMBER_OF', 'target_label' => 'Team'],
        ],
    ],

    'security' => [
        // Filter via relationship traversal
        'team_path' => ['rel' => 'MEMBER_OF', 'target' => 'Team'],
    ],
],
```

---

## File Structure

```
src/
├── Contracts/
│   └── AiAuthAdapterInterface.php       # Abstract auth interface
│
├── Auth/
│   └── KompoAuthAdapter.php             # Kompo Auth implementation
│
├── Services/
│   └── Security/
│       ├── AiSecurityService.php        # Main orchestrator
│       ├── PermissionResolver.php       # Resolves permission chain
│       └── TeamPathResolver.php         # Resolves team_path config to Cypher
│
└── Exceptions/
    └── SecurityException.php            # Security exception

config/
└── ai.php                               # Security config section
```

---

## Task 1: Create AiAuthAdapterInterface

**Files:**
- Create: `src/Contracts/AiAuthAdapterInterface.php`
- Test: `tests/Unit/Contracts/AiAuthAdapterInterfaceTest.php`

**Step 1: Write the failing test**

```php
<?php
// tests/Unit/Contracts/AiAuthAdapterInterfaceTest.php

namespace Condoedge\Ai\Tests\Unit\Contracts;

use Condoedge\Ai\Contracts\AiAuthAdapterInterface;
use Condoedge\Ai\Tests\TestCase;

class AiAuthAdapterInterfaceTest extends TestCase
{
    /** @test */
    public function interface_exists_with_required_methods(): void
    {
        $this->assertTrue(interface_exists(AiAuthAdapterInterface::class));

        $reflection = new \ReflectionClass(AiAuthAdapterInterface::class);

        $this->assertTrue($reflection->hasMethod('getTeamsWithPermission'));
        $this->assertTrue($reflection->hasMethod('hasGlobalCountPermission'));
        $this->assertTrue($reflection->hasMethod('isEnabled'));
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Contracts/AiAuthAdapterInterfaceTest.php -v`
Expected: FAIL with "interface does not exist"

**Step 3: Write minimal implementation**

```php
<?php
// src/Contracts/AiAuthAdapterInterface.php

declare(strict_types=1);

namespace Condoedge\Ai\Contracts;

/**
 * Abstract interface for AI security authentication.
 * Allows swapping auth implementations.
 */
interface AiAuthAdapterInterface
{
    /**
     * Get team IDs where user has permission for entity.
     * Checks permission chain: {Entity}.AiRetrieving → {Entity}
     *
     * @param mixed $user User model
     * @param string $entity Entity name (e.g., 'Invoice')
     * @return array<int> Team IDs
     */
    public function getTeamsWithPermission($user, string $entity): array;

    /**
     * Check if user has global count permission for entity.
     * Permission: {Entity}.AiGlobalCount
     *
     * @param mixed $user User model
     * @param string $entity Entity name
     * @return bool True if can see global counts
     */
    public function hasGlobalCountPermission($user, string $entity): bool;

    /**
     * Check if security is enabled.
     */
    public function isEnabled(): bool;
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Unit/Contracts/AiAuthAdapterInterfaceTest.php -v`
Expected: PASS

---

## Task 2: Create KompoAuthAdapter

**Files:**
- Create: `src/Auth/KompoAuthAdapter.php`
- Test: `tests/Unit/Auth/KompoAuthAdapterTest.php`

**Step 1: Write the failing test**

```php
<?php
// tests/Unit/Auth/KompoAuthAdapterTest.php

namespace Condoedge\Ai\Tests\Unit\Auth;

use Condoedge\Ai\Auth\KompoAuthAdapter;
use Condoedge\Ai\Contracts\AiAuthAdapterInterface;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

class KompoAuthAdapterTest extends TestCase
{
    private KompoAuthAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new KompoAuthAdapter();
    }

    /** @test */
    public function implements_interface(): void
    {
        $this->assertInstanceOf(AiAuthAdapterInterface::class, $this->adapter);
    }

    /** @test */
    public function is_enabled_reads_config(): void
    {
        config(['ai.security.enabled' => true]);
        $this->assertTrue($this->adapter->isEnabled());

        config(['ai.security.enabled' => false]);
        $this->assertFalse($this->adapter->isEnabled());
    }

    /** @test */
    public function get_teams_checks_ai_retrieving_first(): void
    {
        config(['ai.security.enabled' => true]);
        config(['ai.security.permission_chain' => ['{Entity}.AiRetrieving', '{Entity}']]);

        $user = Mockery::mock();
        $user->shouldReceive('getTeamsIdsWithPermission')
            ->with('Invoice.AiRetrieving', Mockery::any())
            ->once()
            ->andReturn(collect([1, 3, 7]));

        $result = $this->adapter->getTeamsWithPermission($user, 'Invoice');

        $this->assertEquals([1, 3, 7], $result);
    }

    /** @test */
    public function get_teams_falls_back_to_entity_permission(): void
    {
        config(['ai.security.enabled' => true]);
        config(['ai.security.permission_chain' => ['{Entity}.AiRetrieving', '{Entity}']]);

        $user = Mockery::mock();
        $user->shouldReceive('getTeamsIdsWithPermission')
            ->with('Invoice.AiRetrieving', Mockery::any())
            ->once()
            ->andReturn(collect([]));  // Empty - no AiRetrieving permission

        $user->shouldReceive('getTeamsIdsWithPermission')
            ->with('Invoice', Mockery::any())
            ->once()
            ->andReturn(collect([1, 3]));  // Fallback works

        $result = $this->adapter->getTeamsWithPermission($user, 'Invoice');

        $this->assertEquals([1, 3], $result);
    }

    /** @test */
    public function get_teams_returns_empty_when_disabled(): void
    {
        config(['ai.security.enabled' => false]);

        $user = Mockery::mock();
        $user->shouldNotReceive('getTeamsIdsWithPermission');

        $result = $this->adapter->getTeamsWithPermission($user, 'Invoice');

        $this->assertEmpty($result);
    }

    /** @test */
    public function has_global_count_permission_checks_correctly(): void
    {
        config(['ai.security.enabled' => true]);
        config(['ai.security.global_count_permission' => '{Entity}.AiGlobalCount']);

        $user = Mockery::mock();
        $user->shouldReceive('hasPermission')
            ->with('Invoice.AiGlobalCount', Mockery::any())
            ->once()
            ->andReturn(true);

        $this->assertTrue($this->adapter->hasGlobalCountPermission($user, 'Invoice'));
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Auth/KompoAuthAdapterTest.php -v`
Expected: FAIL with "class does not exist"

**Step 3: Write minimal implementation**

```php
<?php
// src/Auth/KompoAuthAdapter.php

declare(strict_types=1);

namespace Condoedge\Ai\Auth;

use Condoedge\Ai\Contracts\AiAuthAdapterInterface;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;

/**
 * Kompo Auth adapter for AI security.
 */
class KompoAuthAdapter implements AiAuthAdapterInterface
{
    /**
     * {@inheritdoc}
     */
    public function getTeamsWithPermission($user, string $entity): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $permissionChain = config('ai.security.permission_chain', [
            '{Entity}.AiRetrieving',
            '{Entity}',
        ]);

        foreach ($permissionChain as $pattern) {
            $permission = str_replace('{Entity}', $entity, $pattern);
            $teams = $user->getTeamsIdsWithPermission($permission, PermissionTypeEnum::READ);

            if ($teams->isNotEmpty()) {
                return $teams->toArray();
            }
        }

        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function hasGlobalCountPermission($user, string $entity): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $pattern = config('ai.security.global_count_permission', '{Entity}.AiGlobalCount');
        $permission = str_replace('{Entity}', $entity, $pattern);

        return $user->hasPermission($permission, PermissionTypeEnum::READ);
    }

    /**
     * {@inheritdoc}
     */
    public function isEnabled(): bool
    {
        return config('ai.security.enabled', true);
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Unit/Auth/KompoAuthAdapterTest.php -v`
Expected: PASS

---

## Task 3: Create SecurityException

**Files:**
- Create: `src/Exceptions/SecurityException.php`

**Step 1: Write minimal implementation**

```php
<?php
// src/Exceptions/SecurityException.php

declare(strict_types=1);

namespace Condoedge\Ai\Exceptions;

/**
 * Exception thrown when AI security checks fail.
 */
class SecurityException extends \Exception
{
    public function __construct(string $message, int $code = 403, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
```

---

## Task 4: Create TeamPathResolver

**Files:**
- Create: `src/Services/Security/TeamPathResolver.php`
- Test: `tests/Unit/Services/Security/TeamPathResolverTest.php`

**Step 1: Write the failing test**

```php
<?php
// tests/Unit/Services/Security/TeamPathResolverTest.php

namespace Condoedge\Ai\Tests\Unit\Services\Security;

use Condoedge\Ai\Services\Security\TeamPathResolver;
use Condoedge\Ai\Tests\TestCase;

class TeamPathResolverTest extends TestCase
{
    private TeamPathResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new TeamPathResolver();
    }

    /** @test */
    public function resolves_direct_team_id_path(): void
    {
        config(['entities.Invoice.security.team_path' => 'team_id']);

        $result = $this->resolver->resolve('Invoice', 'i', [1, 3, 7]);

        $this->assertEquals('direct', $result['type']);
        $this->assertStringContainsString('i.team_id IN', $result['filter']);
        $this->assertStringContainsString('1, 3, 7', $result['filter']);
    }

    /** @test */
    public function resolves_parent_path(): void
    {
        config(['entities.InvoiceItem.security.team_path' => 'invoice.team_id']);
        config(['entities.InvoiceItem.graph.relationships' => [
            ['type' => 'BELONGS_TO', 'target_label' => 'Invoice'],
        ]]);

        $result = $this->resolver->resolve('InvoiceItem', 'ii', [1, 3]);

        $this->assertEquals('parent', $result['type']);
        $this->assertStringContainsString('BELONGS_TO', $result['filter']);
        $this->assertStringContainsString('Invoice', $result['filter']);
        $this->assertStringContainsString('team_id IN', $result['filter']);
    }

    /** @test */
    public function resolves_relationship_path(): void
    {
        config(['entities.Person.security.team_path' => ['rel' => 'MEMBER_OF', 'target' => 'Team']]);

        $result = $this->resolver->resolve('Person', 'p', [1, 3]);

        $this->assertEquals('relationship', $result['type']);
        $this->assertStringContainsString('MEMBER_OF', $result['filter']);
        $this->assertStringContainsString('Team', $result['filter']);
    }

    /** @test */
    public function auto_detects_from_relationships_config(): void
    {
        config(['entities.Invoice.security.team_path' => 'auto']);
        config(['entities.Invoice.graph.relationships' => [
            ['type' => 'BELONGS_TO_TEAM', 'target_label' => 'Team', 'foreign_key' => 'team_id'],
        ]]);

        $result = $this->resolver->resolve('Invoice', 'i', [1, 3, 7]);

        $this->assertEquals('direct', $result['type']);
        $this->assertStringContainsString('team_id', $result['filter']);
    }

    /** @test */
    public function returns_null_when_no_team_path(): void
    {
        config(['entities.SystemSetting.security.team_path' => null]);

        $result = $this->resolver->resolve('SystemSetting', 's', [1, 3]);

        $this->assertNull($result);
    }

    /** @test */
    public function uses_default_team_path_when_not_configured(): void
    {
        config(['ai.security.default_team_path' => 'team_id']);
        config(['entities.Invoice.security' => []]);  // No team_path set

        $result = $this->resolver->resolve('Invoice', 'i', [1, 3]);

        $this->assertEquals('direct', $result['type']);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Services/Security/TeamPathResolverTest.php -v`
Expected: FAIL with "class does not exist"

**Step 3: Write minimal implementation**

```php
<?php
// src/Services/Security/TeamPathResolver.php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Security;

/**
 * Resolves team_path config to Cypher filter clauses.
 */
class TeamPathResolver
{
    /**
     * Resolve team path config to Cypher filter.
     *
     * @param string $entity Entity name
     * @param string $alias Cypher alias for entity (e.g., 'i' for Invoice)
     * @param array<int> $teamIds Team IDs to filter by
     * @return array{type: string, filter: string}|null
     */
    public function resolve(string $entity, string $alias, array $teamIds): ?array
    {
        $teamPath = $this->getTeamPath($entity);

        if ($teamPath === null) {
            return null;
        }

        $teamIdList = implode(', ', $teamIds);

        // Direct column: 'team_id'
        if (is_string($teamPath) && !str_contains($teamPath, '.') && $teamPath !== 'auto') {
            return [
                'type' => 'direct',
                'filter' => "WHERE {$alias}.{$teamPath} IN [{$teamIdList}]",
            ];
        }

        // Auto-detect from relationships
        if ($teamPath === 'auto') {
            return $this->autoDetect($entity, $alias, $teamIds);
        }

        // Parent path: 'invoice.team_id'
        if (is_string($teamPath) && str_contains($teamPath, '.')) {
            return $this->resolveParentPath($entity, $alias, $teamPath, $teamIds);
        }

        // Relationship path: ['rel' => 'MEMBER_OF', 'target' => 'Team']
        if (is_array($teamPath) && isset($teamPath['rel'])) {
            return [
                'type' => 'relationship',
                'filter' => "MATCH ({$alias})-[:{$teamPath['rel']}]->(t:{$teamPath['target']}) WHERE t.id IN [{$teamIdList}]",
            ];
        }

        return null;
    }

    /**
     * Get team_path from config with fallback to default.
     */
    private function getTeamPath(string $entity): mixed
    {
        $entityConfig = config("entities.{$entity}.security.team_path");

        if ($entityConfig !== null) {
            return $entityConfig;
        }

        // Use default if not configured
        return config('ai.security.default_team_path', 'auto');
    }

    /**
     * Auto-detect team path from relationships config.
     */
    private function autoDetect(string $entity, string $alias, array $teamIds): ?array
    {
        $relationships = config("entities.{$entity}.graph.relationships", []);

        // Find relationship to Team
        $teamRel = collect($relationships)
            ->first(fn($r) => ($r['target_label'] ?? null) === 'Team');

        if (!$teamRel) {
            return null;
        }

        $teamIdList = implode(', ', $teamIds);

        // Has foreign_key = direct filter
        if (isset($teamRel['foreign_key'])) {
            return [
                'type' => 'direct',
                'filter' => "WHERE {$alias}.{$teamRel['foreign_key']} IN [{$teamIdList}]",
            ];
        }

        // No foreign_key = relationship traversal
        return [
            'type' => 'relationship',
            'filter' => "MATCH ({$alias})-[:{$teamRel['type']}]->(t:Team) WHERE t.id IN [{$teamIdList}]",
        ];
    }

    /**
     * Resolve parent path like 'invoice.team_id'.
     */
    private function resolveParentPath(string $entity, string $alias, string $path, array $teamIds): ?array
    {
        [$parentKey, $column] = explode('.', $path, 2);
        $parentEntity = ucfirst($parentKey);

        // Find relationship to parent
        $relationships = config("entities.{$entity}.graph.relationships", []);
        $parentRel = collect($relationships)
            ->first(fn($r) => ($r['target_label'] ?? null) === $parentEntity);

        if (!$parentRel) {
            return null;
        }

        $parentAlias = strtolower(substr($parentEntity, 0, 1));
        $teamIdList = implode(', ', $teamIds);

        return [
            'type' => 'parent',
            'filter' => "MATCH ({$alias})-[:{$parentRel['type']}]->({$parentAlias}:{$parentEntity}) WHERE {$parentAlias}.{$column} IN [{$teamIdList}]",
        ];
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Unit/Services/Security/TeamPathResolverTest.php -v`
Expected: PASS

---

## Task 5: Create AiSecurityService

**Files:**
- Create: `src/Services/Security/AiSecurityService.php`
- Test: `tests/Unit/Services/Security/AiSecurityServiceTest.php`

**Step 1: Write the failing test**

```php
<?php
// tests/Unit/Services/Security/AiSecurityServiceTest.php

namespace Condoedge\Ai\Tests\Unit\Services\Security;

use Condoedge\Ai\Contracts\AiAuthAdapterInterface;
use Condoedge\Ai\Exceptions\SecurityException;
use Condoedge\Ai\Services\Security\AiSecurityService;
use Condoedge\Ai\Services\Security\TeamPathResolver;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Support\Facades\Log;
use Mockery;

class AiSecurityServiceTest extends TestCase
{
    private AiSecurityService $service;
    private $mockAdapter;
    private $mockResolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockAdapter = Mockery::mock(AiAuthAdapterInterface::class);
        $this->mockResolver = Mockery::mock(TeamPathResolver::class);

        $this->service = new AiSecurityService($this->mockAdapter, $this->mockResolver);
    }

    /** @test */
    public function get_team_filter_returns_filter_for_entity(): void
    {
        $user = Mockery::mock();

        $this->mockAdapter->shouldReceive('isEnabled')->andReturn(true);
        $this->mockAdapter->shouldReceive('getTeamsWithPermission')
            ->with($user, 'Invoice')
            ->andReturn([1, 3, 7]);

        $this->mockResolver->shouldReceive('resolve')
            ->with('Invoice', 'i', [1, 3, 7])
            ->andReturn(['type' => 'direct', 'filter' => 'WHERE i.team_id IN [1, 3, 7]']);

        $result = $this->service->getTeamFilter($user, 'Invoice', 'i');

        $this->assertEquals('WHERE i.team_id IN [1, 3, 7]', $result);
    }

    /** @test */
    public function throws_when_no_permission(): void
    {
        $user = Mockery::mock();
        $user->id = 123;

        $this->mockAdapter->shouldReceive('isEnabled')->andReturn(true);
        $this->mockAdapter->shouldReceive('getTeamsWithPermission')
            ->with($user, 'Invoice')
            ->andReturn([]);

        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning')->once();

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('Invoice');

        $this->service->getTeamFilter($user, 'Invoice', 'i');
    }

    /** @test */
    public function handles_missing_team_path_with_deny(): void
    {
        $user = Mockery::mock();
        $user->id = 123;

        config(['ai.security.on_missing_team_path' => 'deny']);

        $this->mockAdapter->shouldReceive('isEnabled')->andReturn(true);
        $this->mockAdapter->shouldReceive('getTeamsWithPermission')
            ->andReturn([1, 3]);

        $this->mockResolver->shouldReceive('resolve')
            ->andReturn(null);  // No team path

        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning')->once();

        $this->expectException(SecurityException::class);

        $this->service->getTeamFilter($user, 'SystemSetting', 's');
    }

    /** @test */
    public function handles_missing_team_path_with_bypass(): void
    {
        $user = Mockery::mock();

        config(['ai.security.on_missing_team_path' => 'bypass']);

        $this->mockAdapter->shouldReceive('isEnabled')->andReturn(true);
        $this->mockAdapter->shouldReceive('getTeamsWithPermission')
            ->andReturn([1, 3]);

        $this->mockResolver->shouldReceive('resolve')
            ->andReturn(null);  // No team path

        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning')->once();

        $result = $this->service->getTeamFilter($user, 'SystemSetting', 's');

        $this->assertNull($result);  // No filter = bypass
    }

    /** @test */
    public function should_skip_team_filter_for_global_count(): void
    {
        $user = Mockery::mock();

        $this->mockAdapter->shouldReceive('isEnabled')->andReturn(true);
        $this->mockAdapter->shouldReceive('hasGlobalCountPermission')
            ->with($user, 'Invoice')
            ->andReturn(true);

        $result = $this->service->shouldSkipTeamFilterForCount($user, 'Invoice');

        $this->assertTrue($result);
    }

    /** @test */
    public function returns_null_when_security_disabled(): void
    {
        $user = Mockery::mock();

        $this->mockAdapter->shouldReceive('isEnabled')->andReturn(false);

        $result = $this->service->getTeamFilter($user, 'Invoice', 'i');

        $this->assertNull($result);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Services/Security/AiSecurityServiceTest.php -v`
Expected: FAIL with "class does not exist"

**Step 3: Write minimal implementation**

```php
<?php
// src/Services/Security/AiSecurityService.php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Security;

use Condoedge\Ai\Contracts\AiAuthAdapterInterface;
use Condoedge\Ai\Exceptions\SecurityException;
use Illuminate\Support\Facades\Log;

/**
 * Main security service for AI queries.
 * Orchestrates permission checking and team filter generation.
 */
class AiSecurityService
{
    public function __construct(
        private readonly AiAuthAdapterInterface $authAdapter,
        private readonly TeamPathResolver $pathResolver
    ) {}

    /**
     * Get team filter for entity query.
     *
     * @param mixed $user User model
     * @param string $entity Entity name
     * @param string $alias Cypher alias
     * @return string|null Filter clause or null if no filtering needed
     * @throws SecurityException When access denied
     */
    public function getTeamFilter($user, string $entity, string $alias): ?string
    {
        if (!$this->authAdapter->isEnabled()) {
            return null;
        }

        // Get teams where user has permission
        $teamIds = $this->authAdapter->getTeamsWithPermission($user, $entity);

        if (empty($teamIds)) {
            $this->logDeniedAccess($user, $entity, 'no_permission');
            throw new SecurityException(
                "You do not have permission to query {$entity} data"
            );
        }

        // Resolve team path to filter
        $resolved = $this->pathResolver->resolve($entity, $alias, $teamIds);

        if ($resolved === null) {
            return $this->handleMissingTeamPath($user, $entity);
        }

        return $resolved['filter'];
    }

    /**
     * Check if team filter should be skipped for COUNT query.
     */
    public function shouldSkipTeamFilterForCount($user, string $entity): bool
    {
        if (!$this->authAdapter->isEnabled()) {
            return true;
        }

        return $this->authAdapter->hasGlobalCountPermission($user, $entity);
    }

    /**
     * Get teams for entity (for use in query building).
     */
    public function getTeamsForEntity($user, string $entity): array
    {
        if (!$this->authAdapter->isEnabled()) {
            return [];
        }

        return $this->authAdapter->getTeamsWithPermission($user, $entity);
    }

    /**
     * Handle missing team path based on config.
     */
    private function handleMissingTeamPath($user, string $entity): ?string
    {
        $this->logMissingTeamPath($user, $entity);

        $action = config('ai.security.on_missing_team_path', 'deny');

        if ($action === 'deny') {
            throw new SecurityException(
                "Cannot verify permissions for {$entity} data"
            );
        }

        // 'bypass' - return null (no filter)
        return null;
    }

    /**
     * Log denied access attempt.
     */
    private function logDeniedAccess($user, string $entity, string $reason): void
    {
        if (!config('ai.security.log_denied_access', true)) {
            return;
        }

        Log::channel(config('ai.security.log_channel', 'ai-security'))->warning(
            "AI query access denied",
            [
                'user_id' => $user->id ?? null,
                'entity' => $entity,
                'reason' => $reason,
            ]
        );
    }

    /**
     * Log missing team path.
     */
    private function logMissingTeamPath($user, string $entity): void
    {
        if (!config('ai.security.log_missing_team_path', true)) {
            return;
        }

        Log::channel(config('ai.security.log_channel', 'ai-security'))->warning(
            "Entity has no team_path configured",
            [
                'user_id' => $user->id ?? null,
                'entity' => $entity,
                'action' => config('ai.security.on_missing_team_path', 'deny'),
            ]
        );
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Unit/Services/Security/AiSecurityServiceTest.php -v`
Expected: PASS

---

## Task 6: Add Security Config Section

**Files:**
- Modify: `config/ai.php`

**Step 1: Add security configuration**

Add to the end of `config/ai.php`:

```php
/*
|--------------------------------------------------------------------------
| Security Configuration
|--------------------------------------------------------------------------
|
| Team-based query filtering for AI.
|
*/
'security' => [
    // Master switch
    'enabled' => env('AI_SECURITY_ENABLED', true),

    // Permission chain - checked in order, first with teams wins
    'permission_chain' => [
        '{Entity}.AiRetrieving',   // AI-specific (primary)
        '{Entity}',                // Standard entity (fallback)
    ],

    // Global count permission - allows seeing total counts
    'global_count_permission' => '{Entity}.AiGlobalCount',

    // Default team_path for entities without explicit config
    // 'auto' = detect from relationships
    // 'team_id' = assume direct column
    'default_team_path' => 'auto',

    // What to do when no team_path found
    // 'deny' = block query
    // 'bypass' = allow without filtering
    'on_missing_team_path' => 'deny',

    // Response scope clarification
    'clarify_scope' => true,
    'scope_prefix' => 'Based on your permissions, ',

    // Logging
    'log_denied_access' => true,
    'log_missing_team_path' => true,
    'log_channel' => env('AI_SECURITY_LOG_CHANNEL', 'ai-security'),
],
```

---

## Task 7: Register Services in Provider

**Files:**
- Modify: `src/AiServiceProvider.php`

**Step 1: Add service bindings**

Add to `AiServiceProvider::register()`:

```php
// Security services
$this->app->singleton(\Condoedge\Ai\Contracts\AiAuthAdapterInterface::class, function ($app) {
    return new \Condoedge\Ai\Auth\KompoAuthAdapter();
});

$this->app->singleton(\Condoedge\Ai\Services\Security\TeamPathResolver::class);

$this->app->singleton(\Condoedge\Ai\Services\Security\AiSecurityService::class, function ($app) {
    return new \Condoedge\Ai\Services\Security\AiSecurityService(
        $app->make(\Condoedge\Ai\Contracts\AiAuthAdapterInterface::class),
        $app->make(\Condoedge\Ai\Services\Security\TeamPathResolver::class)
    );
});
```

---

## Task 8: Integrate into QueryExecutor

**Files:**
- Modify: `src/Services/QueryExecutor.php`
- Test: `tests/Unit/Services/QueryExecutorSecurityTest.php`

**Step 1: Write the integration test**

```php
<?php
// tests/Unit/Services/QueryExecutorSecurityTest.php

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Services\QueryExecutor;
use Condoedge\Ai\Services\Security\AiSecurityService;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

class QueryExecutorSecurityTest extends TestCase
{
    /** @test */
    public function injects_team_filter_before_execution(): void
    {
        $mockSecurity = Mockery::mock(AiSecurityService::class);
        $mockSecurity->shouldReceive('getTeamFilter')
            ->with(Mockery::any(), 'Invoice', 'i')
            ->once()
            ->andReturn('WHERE i.team_id IN [1, 3, 7]');

        $this->app->instance(AiSecurityService::class, $mockSecurity);

        // Mock Neo4j client...
        // Execute query...
        // Assert filter was injected...
    }

    /** @test */
    public function skips_team_filter_for_global_count(): void
    {
        $mockSecurity = Mockery::mock(AiSecurityService::class);
        $mockSecurity->shouldReceive('shouldSkipTeamFilterForCount')
            ->with(Mockery::any(), 'Invoice')
            ->andReturn(true);

        $this->app->instance(AiSecurityService::class, $mockSecurity);

        // Mock Neo4j client...
        // Execute COUNT query...
        // Assert no team filter injected...
    }
}
```

**Step 2: Modify QueryExecutor**

In `QueryExecutor::execute()`, add security integration:

```php
// At the start of execute method, after parsing the query:

$securityService = app(AiSecurityService::class);
$user = auth()->user();

// Check if this is a COUNT query with global permission
$isCountQuery = $this->isCountQuery($cypherQuery);
$entity = $this->extractPrimaryEntity($cypherQuery);
$alias = $this->extractEntityAlias($cypherQuery, $entity);

if ($isCountQuery && $securityService->shouldSkipTeamFilterForCount($user, $entity)) {
    // Skip team filter for global count
    // Execute query as-is
} else {
    // Get and inject team filter
    $teamFilter = $securityService->getTeamFilter($user, $entity, $alias);

    if ($teamFilter) {
        $cypherQuery = $this->injectTeamFilter($cypherQuery, $teamFilter);
    }
}
```

---

## Task 9: Add Scope Clarification to Responses

**Files:**
- Modify: `src/Services/AiManager.php`

**Step 1: Add scope prefix to responses**

In `AiManager::generateResponse()` or response formatting:

```php
// After getting results, before returning response

if (config('ai.security.clarify_scope', true)) {
    $prefix = config('ai.security.scope_prefix', 'Based on your permissions, ');
    $response = $prefix . $response;
}
```

---

## Summary

**Files to Create:**
1. `src/Contracts/AiAuthAdapterInterface.php` - Auth abstraction
2. `src/Auth/KompoAuthAdapter.php` - Kompo Auth implementation
3. `src/Exceptions/SecurityException.php` - Exception class
4. `src/Services/Security/TeamPathResolver.php` - Resolves team_path to Cypher
5. `src/Services/Security/AiSecurityService.php` - Main orchestrator

**Files to Modify:**
1. `config/ai.php` - Add security section
2. `src/AiServiceProvider.php` - Register services
3. `src/Services/QueryExecutor.php` - Inject team filters
4. `src/Services/AiManager.php` - Add scope clarification

**Test Files:**
1. `tests/Unit/Contracts/AiAuthAdapterInterfaceTest.php`
2. `tests/Unit/Auth/KompoAuthAdapterTest.php`
3. `tests/Unit/Services/Security/TeamPathResolverTest.php`
4. `tests/Unit/Services/Security/AiSecurityServiceTest.php`
5. `tests/Unit/Services/QueryExecutorSecurityTest.php`

---

## Key Design Points

1. **Single layer** - Permission chain resolves teams, then filter in Neo4j
2. **No inference protection** - Data scoped to user's teams = their data
3. **Configurable team_path** - Easy to customize per entity
4. **Auto-detection** - Falls back to relationships config
5. **Global count permission** - Optional bypass for COUNT queries
6. **Abstract auth adapter** - Swappable implementations

---

**Plan complete. Ready to execute?**

**Execution Options:**

1. **Subagent-Driven (this session)** - I dispatch fresh subagent per task, review between tasks
2. **Parallel Session (separate)** - Open new session with executing-plans skill

**Which approach?**
