# AI Security Implementation Plan (Option A: Neo4j Relationship Traversal)

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Implement team-based security filtering for AI queries using Neo4j relationship traversal.

**Architecture:** Filter queries at the Cypher level by injecting team constraints based on existing relationships config. Uses an abstract auth adapter for swappable auth implementations.

**Tech Stack:** PHP 8.1+, Laravel, Neo4j (Cypher), Kompo Auth package

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│                           AI SECURITY ARCHITECTURE - OPTION A                            │
│                              Neo4j Relationship Traversal                                │
└─────────────────────────────────────────────────────────────────────────────────────────┘

                              ┌──────────────────────┐
                              │    User Question     │
                              │  "Show me invoices"  │
                              └──────────┬───────────┘
                                         │
                                         ▼
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│  AiManager::answerQuestion()                                                             │
│  ────────────────────────────────────────────────────────────────────────────────────── │
│                                                                                          │
│  1. Detect entities from question                                                        │
│  2. INJECT: Security gate check via AiSecurityGate                                       │
│  3. Generate Cypher query via QueryGenerator                                             │
│  4. INJECT: Team filter via TeamFilterInjector                                           │
│  5. Execute query via QueryExecutor                                                      │
│  6. Apply inference protection                                                           │
│  7. Generate response with scope clarification                                           │
│                                                                                          │
└─────────────────────────────────────────────────────────────────────────────────────────┘
                                         │
           ┌─────────────────────────────┼─────────────────────────────┐
           │                             │                             │
           ▼                             ▼                             ▼
┌─────────────────────┐    ┌─────────────────────┐    ┌─────────────────────┐
│  AiSecurityGate     │    │  TeamFilterInjector │    │ InferenceProtector  │
│  ─────────────────  │    │  ─────────────────  │    │  ─────────────────  │
│                     │    │                     │    │                     │
│  - Gate check       │    │  - Find team path   │    │  - Count threshold  │
│  - Permission check │    │  - Inject WHERE     │    │  - Privacy messages │
│  - Log denied       │    │  - Log missing path │    │  - Scope clarify    │
│                     │    │                     │    │                     │
│  Uses:              │    │  Uses:              │    │  Config:            │
│  AiAuthAdapter      │    │  TeamPathResolver   │    │  ai.security.*      │
│                     │    │                     │    │                     │
└─────────────────────┘    └─────────────────────┘    └─────────────────────┘
           │                             │
           │                             │
           ▼                             ▼
┌─────────────────────┐    ┌─────────────────────────────────────────────────┐
│ AiAuthAdapterInterface │ │              TeamPathResolver                    │
│ ─────────────────────  │ │  ─────────────────────────────────────────────  │
│                        │ │                                                  │
│ getAccessibleTeamIds() │ │  Reads: config("entities.{Entity}.graph.        │
│ canAccessEntity()      │ │         relationships")                          │
│ isEnabled()            │ │                                                  │
│                        │ │  Finds relationships where:                      │
│ Implementation:        │ │  target_label === 'Team'                         │
│ KompoAuthAdapter       │ │                                                  │
│ ↓                      │ │  Returns:                                        │
│ Uses:                  │ │  - direct: WHERE n.team_id IN [...]              │
│ HasTeamPermissions     │ │  - relationship: MATCH (n)-[:REL]->(t:Team)...   │
│ .getTeamsIdsWithPermission() │  - through_parent: MATCH (n)-[:REL]->(p)...  │
│                        │ │                                                  │
└────────────────────────┘ └─────────────────────────────────────────────────┘
```

---

## Data Flow Diagram

```
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│                              QUERY FLOW WITH SECURITY                                    │
└─────────────────────────────────────────────────────────────────────────────────────────┘

User: "Show me all invoices"
         │
         ▼
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│  STEP 1: ENTITY DETECTION                                                                │
├─────────────────────────────────────────────────────────────────────────────────────────┤
│                                                                                          │
│  ContextRetriever::getEntityMetadata("Show me all invoices")                            │
│                                                                                          │
│  Returns: ['detected_entities' => ['Invoice']]                                          │
│                                                                                          │
└─────────────────────────────────────────────────────────────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│  STEP 2: SECURITY GATE CHECK                                                             │
├─────────────────────────────────────────────────────────────────────────────────────────┤
│                                                                                          │
│  AiSecurityGate::checkAccess($user, ['Invoice'])                                        │
│                                                                                          │
│  For each entity:                                                                        │
│  ┌─────────────────────────────────────────────────────────────────────────────────┐    │
│  │  $adapter = app(AiAuthAdapterInterface::class);                                  │    │
│  │  $teamIds = $adapter->getAccessibleTeamIds($user, 'Invoice');                   │    │
│  │                                                                                  │    │
│  │  if ($teamIds is empty):                                                         │    │
│  │      Log denied access                                                           │    │
│  │      Return: "You do not have permission to query Invoice data"                  │    │
│  │                                                                                  │    │
│  │  Store $teamIds for later use in filter injection                               │    │
│  └─────────────────────────────────────────────────────────────────────────────────┘    │
│                                                                                          │
│  Returns: ['Invoice' => [1, 3, 7]]  // Entity => Team IDs                               │
│                                                                                          │
└─────────────────────────────────────────────────────────────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│  STEP 3: GENERATE CYPHER                                                                 │
├─────────────────────────────────────────────────────────────────────────────────────────┤
│                                                                                          │
│  QueryGenerator::generate("Show me all invoices", $context)                             │
│                                                                                          │
│  Generated Cypher (BEFORE team filter):                                                  │
│  ┌─────────────────────────────────────────────────────────────────────────────────┐    │
│  │  MATCH (i:Invoice)                                                               │    │
│  │  RETURN i.id, i.total, i.date, i.status                                         │    │
│  └─────────────────────────────────────────────────────────────────────────────────┘    │
│                                                                                          │
└─────────────────────────────────────────────────────────────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│  STEP 4: INJECT TEAM FILTER                                                              │
├─────────────────────────────────────────────────────────────────────────────────────────┤
│                                                                                          │
│  TeamFilterInjector::inject($cypher, ['Invoice' => [1, 3, 7]])                          │
│                                                                                          │
│  For entity 'Invoice':                                                                   │
│  ┌─────────────────────────────────────────────────────────────────────────────────┐    │
│  │  $resolver = new TeamPathResolver();                                             │    │
│  │  $path = $resolver->resolve('Invoice', [1, 3, 7]);                              │    │
│  │                                                                                  │    │
│  │  // Reads config("entities.Invoice.graph.relationships")                        │    │
│  │  // Finds: ['type' => 'BELONGS_TO_TEAM', 'target_label' => 'Team',              │    │
│  │  //         'foreign_key' => 'team_id']                                         │    │
│  │                                                                                  │    │
│  │  Returns: ['type' => 'direct',                                                   │    │
│  │            'filter' => 'WHERE i.team_id IN [1, 3, 7]']                          │    │
│  └─────────────────────────────────────────────────────────────────────────────────┘    │
│                                                                                          │
│  Modified Cypher (AFTER team filter):                                                    │
│  ┌─────────────────────────────────────────────────────────────────────────────────┐    │
│  │  MATCH (i:Invoice)                                                               │    │
│  │  WHERE i.team_id IN [1, 3, 7]                                                    │    │
│  │  RETURN i.id, i.total, i.date, i.status                                         │    │
│  └─────────────────────────────────────────────────────────────────────────────────┘    │
│                                                                                          │
└─────────────────────────────────────────────────────────────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│  STEP 5: EXECUTE QUERY                                                                   │
├─────────────────────────────────────────────────────────────────────────────────────────┤
│                                                                                          │
│  QueryExecutor::execute($filteredCypher, $params)                                       │
│                                                                                          │
│  Results: 15 invoices from teams [1, 3, 7]                                              │
│                                                                                          │
└─────────────────────────────────────────────────────────────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│  STEP 6: INFERENCE PROTECTION                                                            │
├─────────────────────────────────────────────────────────────────────────────────────────┤
│                                                                                          │
│  InferenceProtector::protect($results, $query)                                          │
│                                                                                          │
│  ┌─────────────────────────────────────────────────────────────────────────────────┐    │
│  │  count = 15                                                                      │    │
│  │  threshold = 5                                                                   │    │
│  │                                                                                  │    │
│  │  count >= threshold → Return actual results                                      │    │
│  │                                                                                  │    │
│  │  (If count = 1 → "Cannot identify specific individual")                         │    │
│  │  (If count < 5 → "Some results found, exact count hidden")                      │    │
│  └─────────────────────────────────────────────────────────────────────────────────┘    │
│                                                                                          │
└─────────────────────────────────────────────────────────────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│  STEP 7: RESPONSE WITH SCOPE CLARIFICATION                                               │
├─────────────────────────────────────────────────────────────────────────────────────────┤
│                                                                                          │
│  Response: "Based on your permissions, here are 15 invoices you have access to:         │
│            [invoice data...]"                                                            │
│                                                                                          │
└─────────────────────────────────────────────────────────────────────────────────────────┘
```

---

## Team Path Resolution Scenarios

```
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│                           TEAM PATH RESOLUTION SCENARIOS                                 │
└─────────────────────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────────────────────┐
│  SCENARIO 1: DIRECT FOREIGN KEY                                                          │
│  ─────────────────────────────────────────────────────────────────────────────────────  │
│                                                                                          │
│  Entity: Invoice (has team_id column)                                                    │
│                                                                                          │
│  Config:                                                                                 │
│  entities.Invoice.graph.relationships = [                                                │
│      ['type' => 'BELONGS_TO_TEAM', 'target_label' => 'Team', 'foreign_key' => 'team_id']│
│  ]                                                                                       │
│                                                                                          │
│  Neo4j Storage:          ┌─────────────────┐                                            │
│                          │  Invoice        │                                            │
│                          │  ────────────   │                                            │
│                          │  id: 101        │                                            │
│                          │  total: 500     │                                            │
│                          │  team_id: 3  ←──┼───── Direct property!                      │
│                          └─────────────────┘                                            │
│                                                                                          │
│  Generated Filter: WHERE i.team_id IN [1, 3, 7]                                         │
│                                                                                          │
│  Final Cypher:                                                                           │
│  ┌─────────────────────────────────────────────────────────────────────────────────┐    │
│  │  MATCH (i:Invoice)                                                               │    │
│  │  WHERE i.team_id IN [1, 3, 7]                                                    │    │
│  │  RETURN i                                                                        │    │
│  └─────────────────────────────────────────────────────────────────────────────────┘    │
│                                                                                          │
└─────────────────────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────────────────────┐
│  SCENARIO 2: RELATIONSHIP TRAVERSAL (PIVOT TABLE)                                        │
│  ─────────────────────────────────────────────────────────────────────────────────────  │
│                                                                                          │
│  Entity: Person (linked to Team via PersonTeam pivot)                                   │
│                                                                                          │
│  Config:                                                                                 │
│  entities.Person.graph.relationships = [                                                 │
│      ['type' => 'MEMBER_OF', 'target_label' => 'Team']  // No foreign_key!              │
│  ]                                                                                       │
│                                                                                          │
│  Neo4j Storage:                                                                          │
│                                                                                          │
│  ┌──────────────┐        ┌──────────────┐        ┌──────────────┐                       │
│  │   Person     │        │  PersonTeam  │        │    Team      │                       │
│  │   ────────   │        │  ────────    │        │   ────────   │                       │
│  │   id: 1      │──────▶ │  person_id:1 │──────▶ │   id: 3      │                       │
│  │   name: John │ HAS_ROLE team_id: 3  │ TEAM    │   name: ...  │                       │
│  └──────────────┘        │  role: admin │        └──────────────┘                       │
│                          └──────────────┘                                               │
│                                                                                          │
│  Generated Filter: MATCH path with Team constraint                                       │
│                                                                                          │
│  Final Cypher:                                                                           │
│  ┌─────────────────────────────────────────────────────────────────────────────────┐    │
│  │  MATCH (p:Person)-[:HAS_ROLE]->(:PersonTeam)-[:TEAM]->(t:Team)                  │    │
│  │  WHERE t.id IN [1, 3, 7]                                                         │    │
│  │  RETURN DISTINCT p                                                               │    │
│  └─────────────────────────────────────────────────────────────────────────────────┘    │
│                                                                                          │
└─────────────────────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────────────────────┐
│  SCENARIO 3: THROUGH PARENT ENTITY                                                       │
│  ─────────────────────────────────────────────────────────────────────────────────────  │
│                                                                                          │
│  Entity: InvoiceItem (no team_id, but parent Invoice has team_id)                       │
│                                                                                          │
│  Config:                                                                                 │
│  entities.InvoiceItem.graph.relationships = [                                            │
│      ['type' => 'BELONGS_TO', 'target_label' => 'Invoice', 'foreign_key'=>'invoice_id'] │
│  ]                                                                                       │
│  entities.Invoice.graph.relationships = [                                                │
│      ['type' => 'BELONGS_TO_TEAM', 'target_label' => 'Team', 'foreign_key' => 'team_id']│
│  ]                                                                                       │
│                                                                                          │
│  Neo4j Storage:                                                                          │
│                                                                                          │
│  ┌──────────────┐         ┌──────────────┐         ┌──────────────┐                     │
│  │ InvoiceItem  │         │   Invoice    │         │    Team      │                     │
│  │ ──────────── │         │  ──────────  │         │   ────────   │                     │
│  │ id: 1001     │──────▶  │  id: 101     │         │   id: 3      │                     │
│  │ description  │ BELONGS │  team_id: 3 ─┼────────▶│   name: ...  │                     │
│  │ amount: 50   │  _TO    │  total: 500  │ direct  └──────────────┘                     │
│  └──────────────┘         └──────────────┘ property                                     │
│                                                                                          │
│  Resolution:                                                                             │
│  1. InvoiceItem has no Team relationship                                                 │
│  2. InvoiceItem -> Invoice (parent has Team relationship)                               │
│  3. Use Invoice's team_id                                                                │
│                                                                                          │
│  Final Cypher:                                                                           │
│  ┌─────────────────────────────────────────────────────────────────────────────────┐    │
│  │  MATCH (ii:InvoiceItem)-[:BELONGS_TO]->(i:Invoice)                              │    │
│  │  WHERE i.team_id IN [1, 3, 7]                                                    │    │
│  │  RETURN ii                                                                       │    │
│  └─────────────────────────────────────────────────────────────────────────────────┘    │
│                                                                                          │
└─────────────────────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────────────────────┐
│  SCENARIO 4: NO TEAM RELATIONSHIP (MISSING PATH)                                         │
│  ─────────────────────────────────────────────────────────────────────────────────────  │
│                                                                                          │
│  Entity: SystemSetting (global, no team ownership)                                       │
│                                                                                          │
│  Config:                                                                                 │
│  entities.SystemSetting.graph.relationships = []  // No relationships!                  │
│                                                                                          │
│  Resolution:                                                                             │
│  1. No Team relationship found                                                           │
│  2. No parent with Team relationship found                                               │
│  3. LOG WARNING to ai-security channel                                                   │
│  4. Apply configured behavior:                                                           │
│                                                                                          │
│  ┌─────────────────────────────────────────────────────────────────────────────────┐    │
│  │  Config: ai.security.on_missing_team_path = 'deny' (default)                    │    │
│  │                                                                                  │    │
│  │  'deny'   → Return: "Cannot verify permissions for SystemSetting data"          │    │
│  │  'bypass' → Allow query without team filter (for public/shared entities)        │    │
│  └─────────────────────────────────────────────────────────────────────────────────┘    │
│                                                                                          │
│  Log Entry:                                                                              │
│  ┌─────────────────────────────────────────────────────────────────────────────────┐    │
│  │  [ai-security] WARNING: Entity has no Team relationship in config               │    │
│  │  {                                                                               │    │
│  │      "entity": "SystemSetting",                                                  │    │
│  │      "user_id": 123,                                                             │    │
│  │      "relationships": [],                                                        │    │
│  │      "action": "deny"                                                            │    │
│  │  }                                                                               │    │
│  └─────────────────────────────────────────────────────────────────────────────────┘    │
│                                                                                          │
└─────────────────────────────────────────────────────────────────────────────────────────┘
```

---

## File Structure

```
src/
├── Contracts/
│   └── AiAuthAdapterInterface.php      # Abstract auth adapter interface
│
├── Auth/
│   └── KompoAuthAdapter.php            # Kompo Auth implementation
│
├── Services/
│   ├── Security/
│   │   ├── AiSecurityGate.php          # Main security gate check
│   │   ├── TeamPathResolver.php        # Resolves team relationship path
│   │   ├── TeamFilterInjector.php      # Injects team filter into Cypher
│   │   └── InferenceProtector.php      # Privacy/inference protection
│   │
│   └── AiManager.php                   # Modified: integrate security
│
config/
└── ai.php                              # Modified: add security config
```

---

## Implementation Tasks

### Task 1: Create AiAuthAdapterInterface

**Files:**
- Create: `src/Contracts/AiAuthAdapterInterface.php`
- Test: `tests/Unit/Contracts/AiAuthAdapterInterfaceTest.php`

**Step 1: Write the failing test**

```php
// tests/Unit/Contracts/AiAuthAdapterInterfaceTest.php
<?php

namespace Condoedge\Ai\Tests\Unit\Contracts;

use Condoedge\Ai\Contracts\AiAuthAdapterInterface;
use Condoedge\Ai\Tests\TestCase;

class AiAuthAdapterInterfaceTest extends TestCase
{
    /** @test */
    public function interface_exists_and_defines_required_methods(): void
    {
        $this->assertTrue(interface_exists(AiAuthAdapterInterface::class));

        $reflection = new \ReflectionClass(AiAuthAdapterInterface::class);

        $this->assertTrue($reflection->hasMethod('getAccessibleTeamIds'));
        $this->assertTrue($reflection->hasMethod('canAccessEntity'));
        $this->assertTrue($reflection->hasMethod('isEnabled'));
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Contracts/AiAuthAdapterInterfaceTest.php -v`
Expected: FAIL with "interface does not exist"

**Step 3: Write minimal implementation**

```php
// src/Contracts/AiAuthAdapterInterface.php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Contracts;

/**
 * Abstract interface for AI security authentication.
 *
 * Allows swapping auth implementations (Kompo Auth, Laravel Auth, custom).
 * The AI package depends on this interface, not concrete implementations.
 */
interface AiAuthAdapterInterface
{
    /**
     * Get team IDs where user has access to the given entity type.
     *
     * @param mixed $user User model instance
     * @param string $entityType Entity type name (e.g., 'Invoice', 'Person')
     * @return array<int> Team IDs where user has permission
     */
    public function getAccessibleTeamIds($user, string $entityType): array;

    /**
     * Check if user can access the given entity type on any team.
     *
     * @param mixed $user User model instance
     * @param string $entityType Entity type name
     * @return bool True if user has access on at least one team
     */
    public function canAccessEntity($user, string $entityType): bool;

    /**
     * Check if security filtering is enabled.
     *
     * When disabled, all queries run without team filtering.
     *
     * @return bool True if security is enabled
     */
    public function isEnabled(): bool;
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Unit/Contracts/AiAuthAdapterInterfaceTest.php -v`
Expected: PASS

---

### Task 2: Create KompoAuthAdapter

**Files:**
- Create: `src/Auth/KompoAuthAdapter.php`
- Test: `tests/Unit/Auth/KompoAuthAdapterTest.php`

**Step 1: Write the failing test**

```php
// tests/Unit/Auth/KompoAuthAdapterTest.php
<?php

namespace Condoedge\Ai\Tests\Unit\Auth;

use Condoedge\Ai\Auth\KompoAuthAdapter;
use Condoedge\Ai\Contracts\AiAuthAdapterInterface;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Support\Collection;
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
    public function implements_ai_auth_adapter_interface(): void
    {
        $this->assertInstanceOf(AiAuthAdapterInterface::class, $this->adapter);
    }

    /** @test */
    public function is_enabled_returns_config_value(): void
    {
        config(['ai.security.enabled' => true]);
        $this->assertTrue($this->adapter->isEnabled());

        config(['ai.security.enabled' => false]);
        $this->assertFalse($this->adapter->isEnabled());
    }

    /** @test */
    public function is_enabled_defaults_to_true(): void
    {
        config(['ai.security.enabled' => null]);
        $this->assertTrue($this->adapter->isEnabled());
    }

    /** @test */
    public function get_accessible_team_ids_returns_empty_when_disabled(): void
    {
        config(['ai.security.enabled' => false]);

        $user = Mockery::mock();
        $user->shouldNotReceive('getTeamsIdsWithPermission');

        $result = $this->adapter->getAccessibleTeamIds($user, 'Invoice');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /** @test */
    public function get_accessible_team_ids_calls_user_method(): void
    {
        config(['ai.security.enabled' => true]);

        $user = Mockery::mock();
        $user->shouldReceive('getTeamsIdsWithPermission')
            ->with('Invoice', Mockery::any())
            ->once()
            ->andReturn(collect([1, 3, 7]));

        $result = $this->adapter->getAccessibleTeamIds($user, 'Invoice');

        $this->assertEquals([1, 3, 7], $result);
    }

    /** @test */
    public function can_access_entity_returns_true_when_disabled(): void
    {
        config(['ai.security.enabled' => false]);

        $user = Mockery::mock();

        $this->assertTrue($this->adapter->canAccessEntity($user, 'Invoice'));
    }

    /** @test */
    public function can_access_entity_returns_true_when_teams_exist(): void
    {
        config(['ai.security.enabled' => true]);

        $user = Mockery::mock();
        $user->shouldReceive('getTeamsIdsWithPermission')
            ->andReturn(collect([1, 3]));

        $this->assertTrue($this->adapter->canAccessEntity($user, 'Invoice'));
    }

    /** @test */
    public function can_access_entity_returns_false_when_no_teams(): void
    {
        config(['ai.security.enabled' => true]);

        $user = Mockery::mock();
        $user->shouldReceive('getTeamsIdsWithPermission')
            ->andReturn(collect([]));

        $this->assertFalse($this->adapter->canAccessEntity($user, 'Invoice'));
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Auth/KompoAuthAdapterTest.php -v`
Expected: FAIL with "class does not exist"

**Step 3: Write minimal implementation**

```php
// src/Auth/KompoAuthAdapter.php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Auth;

use Condoedge\Ai\Contracts\AiAuthAdapterInterface;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;

/**
 * Kompo Auth adapter for AI security.
 *
 * Integrates with Kompo Auth's HasTeamPermissions trait to get
 * team IDs where user has access to specific entity types.
 */
class KompoAuthAdapter implements AiAuthAdapterInterface
{
    /**
     * {@inheritdoc}
     */
    public function getAccessibleTeamIds($user, string $entityType): array
    {
        if (!$this->isEnabled()) {
            return []; // Empty = no filtering (bypass mode)
        }

        // Use base entity permission (e.g., 'Invoice')
        // The user's getTeamsIdsWithPermission method returns team IDs
        // where the user has READ access to this entity type
        return $user->getTeamsIdsWithPermission($entityType, PermissionTypeEnum::READ)
            ->toArray();
    }

    /**
     * {@inheritdoc}
     */
    public function canAccessEntity($user, string $entityType): bool
    {
        if (!$this->isEnabled()) {
            return true;
        }

        return !empty($this->getAccessibleTeamIds($user, $entityType));
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

### Task 3: Create TeamPathResolver

**Files:**
- Create: `src/Services/Security/TeamPathResolver.php`
- Test: `tests/Unit/Services/Security/TeamPathResolverTest.php`

**Step 1: Write the failing test**

```php
// tests/Unit/Services/Security/TeamPathResolverTest.php
<?php

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
    public function resolves_direct_team_foreign_key(): void
    {
        // Configure entity with direct team_id
        config(['entities.Invoice.graph.relationships' => [
            [
                'type' => 'BELONGS_TO_TEAM',
                'target_label' => 'Team',
                'foreign_key' => 'team_id',
            ],
        ]]);

        $result = $this->resolver->resolve('Invoice', [1, 3, 7]);

        $this->assertNotNull($result);
        $this->assertEquals('direct', $result['type']);
        $this->assertEquals('team_id', $result['foreign_key']);
        $this->assertStringContainsString('team_id IN', $result['filter']);
        $this->assertStringContainsString('[1, 3, 7]', $result['filter']);
    }

    /** @test */
    public function resolves_relationship_traversal_without_foreign_key(): void
    {
        // Configure entity with relationship but no foreign_key
        config(['entities.Person.graph.relationships' => [
            [
                'type' => 'MEMBER_OF',
                'target_label' => 'Team',
                // No foreign_key - needs relationship traversal
            ],
        ]]);

        $result = $this->resolver->resolve('Person', [1, 3]);

        $this->assertNotNull($result);
        $this->assertEquals('relationship', $result['type']);
        $this->assertEquals('MEMBER_OF', $result['relationship']);
        $this->assertStringContainsString('MEMBER_OF', $result['filter']);
        $this->assertStringContainsString('Team', $result['filter']);
    }

    /** @test */
    public function resolves_through_parent_entity(): void
    {
        // InvoiceItem has no Team relationship
        config(['entities.InvoiceItem.graph.relationships' => [
            [
                'type' => 'BELONGS_TO',
                'target_label' => 'Invoice',
                'foreign_key' => 'invoice_id',
                'direction' => 'outgoing',
            ],
        ]]);

        // But Invoice has Team relationship
        config(['entities.Invoice.graph.relationships' => [
            [
                'type' => 'BELONGS_TO_TEAM',
                'target_label' => 'Team',
                'foreign_key' => 'team_id',
            ],
        ]]);

        $result = $this->resolver->resolve('InvoiceItem', [1, 3]);

        $this->assertNotNull($result);
        $this->assertEquals('through_parent', $result['type']);
        $this->assertEquals('Invoice', $result['path']['parent_entity']);
        $this->assertStringContainsString('BELONGS_TO', $result['filter']);
        $this->assertStringContainsString('Invoice', $result['filter']);
    }

    /** @test */
    public function returns_null_when_no_team_path_exists(): void
    {
        // Entity with no relationships
        config(['entities.SystemSetting.graph.relationships' => []]);

        $result = $this->resolver->resolve('SystemSetting', [1, 3]);

        $this->assertNull($result);
    }

    /** @test */
    public function returns_null_when_entity_config_missing(): void
    {
        // Entity not in config
        config(['entities' => []]);

        $result = $this->resolver->resolve('NonExistent', [1, 3]);

        $this->assertNull($result);
    }

    /** @test */
    public function handles_trashed_team_relationship(): void
    {
        // Entity with TRASHED_TEAM relationship (special case)
        config(['entities.SomeEntity.graph.relationships' => [
            [
                'type' => 'TRASHED_TEAM',
                'target_label' => 'Team',
                'foreign_key' => 'team_id',
                'inferred' => true,
                'description' => 'Relationship to Team even if trashed',
            ],
        ]]);

        $result = $this->resolver->resolve('SomeEntity', [1, 3]);

        $this->assertNotNull($result);
        $this->assertEquals('direct', $result['type']);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Services/Security/TeamPathResolverTest.php -v`
Expected: FAIL with "class does not exist"

**Step 3: Write minimal implementation**

```php
// src/Services/Security/TeamPathResolver.php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Security;

/**
 * Resolves the path from an entity to Team using relationships config.
 *
 * Reads the existing graph.relationships configuration to determine
 * how to filter entities by team ownership. No new discovery needed -
 * just uses what's already configured.
 */
class TeamPathResolver
{
    /**
     * Find how to filter this entity by team using relationships config.
     *
     * @param string $entity Entity name (e.g., 'Invoice', 'Person')
     * @param array<int> $teamIds Team IDs to filter by
     * @return array{type: string, filter: string, ...}|null Resolution result or null if no path found
     */
    public function resolve(string $entity, array $teamIds): ?array
    {
        $config = config("entities.{$entity}");

        if (!$config) {
            return null;
        }

        $relationships = $config['graph']['relationships'] ?? [];

        if (empty($relationships)) {
            return null;
        }

        // 1. Check for direct Team relationship with foreign_key
        $teamRel = $this->findTeamRelationship($relationships);

        if ($teamRel && isset($teamRel['foreign_key'])) {
            // Direct foreign key filter (most efficient)
            return [
                'type' => 'direct',
                'foreign_key' => $teamRel['foreign_key'],
                'filter' => $this->buildDirectFilter($entity, $teamRel['foreign_key'], $teamIds),
            ];
        }

        if ($teamRel) {
            // Relationship traversal filter (no foreign_key, traverse graph)
            return [
                'type' => 'relationship',
                'relationship' => $teamRel['type'],
                'filter' => $this->buildRelationshipFilter($entity, $teamRel['type'], $teamIds),
            ];
        }

        // 2. Check for parent entity that has Team relationship
        $parentPath = $this->findParentWithTeam($entity, $relationships);

        if ($parentPath) {
            return [
                'type' => 'through_parent',
                'path' => $parentPath,
                'filter' => $this->buildParentFilter($entity, $parentPath, $teamIds),
            ];
        }

        // 3. No Team path found
        return null;
    }

    /**
     * Find a relationship to Team in the relationships array.
     *
     * Looks for relationships where target_label is 'Team'.
     * Handles both 'TEAM', 'BELONGS_TO_TEAM', 'TRASHED_TEAM' etc.
     */
    protected function findTeamRelationship(array $relationships): ?array
    {
        return collect($relationships)
            ->first(fn($r) => ($r['target_label'] ?? null) === 'Team');
    }

    /**
     * Find a parent entity that has a Team relationship.
     *
     * For entities like InvoiceItem that don't have team_id,
     * but their parent (Invoice) does.
     */
    protected function findParentWithTeam(string $entity, array $relationships): ?array
    {
        foreach ($relationships as $rel) {
            // Only check outgoing relationships (to parent entities)
            if (($rel['direction'] ?? 'outgoing') !== 'outgoing') {
                continue;
            }

            $parentEntity = $rel['target_label'] ?? null;

            // Skip Team itself and empty targets
            if (!$parentEntity || $parentEntity === 'Team') {
                continue;
            }

            // Check if parent has Team relationship
            $parentConfig = config("entities.{$parentEntity}");
            $parentRelationships = $parentConfig['graph']['relationships'] ?? [];
            $parentTeamRel = $this->findTeamRelationship($parentRelationships);

            if ($parentTeamRel) {
                return [
                    'parent_entity' => $parentEntity,
                    'parent_relationship' => $rel['type'],
                    'team_relationship' => $parentTeamRel,
                ];
            }
        }

        return null;
    }

    /**
     * Build a direct WHERE clause filter.
     *
     * Example: WHERE i.team_id IN [1, 3, 7]
     */
    protected function buildDirectFilter(string $entity, string $foreignKey, array $teamIds): string
    {
        $alias = strtolower(substr($entity, 0, 1));
        $teamIdList = implode(', ', $teamIds);

        return "WHERE {$alias}.{$foreignKey} IN [{$teamIdList}]";
    }

    /**
     * Build a relationship traversal filter.
     *
     * Example: MATCH (p:Person)-[:MEMBER_OF]->(t:Team) WHERE t.id IN [1, 3, 7]
     */
    protected function buildRelationshipFilter(string $entity, string $relType, array $teamIds): string
    {
        $alias = strtolower(substr($entity, 0, 1));
        $teamIdList = implode(', ', $teamIds);

        return "MATCH ({$alias}:{$entity})-[:{$relType}]->(t:Team) WHERE t.id IN [{$teamIdList}]";
    }

    /**
     * Build a parent traversal filter.
     *
     * Example: MATCH (ii:InvoiceItem)-[:BELONGS_TO]->(i:Invoice) WHERE i.team_id IN [1, 3, 7]
     */
    protected function buildParentFilter(string $entity, array $parentPath, array $teamIds): string
    {
        $alias = strtolower(substr($entity, 0, 1));
        $parentAlias = strtolower(substr($parentPath['parent_entity'], 0, 1));
        $teamIdList = implode(', ', $teamIds);

        $parentRel = $parentPath['parent_relationship'];
        $parentEntity = $parentPath['parent_entity'];
        $teamFk = $parentPath['team_relationship']['foreign_key'] ?? 'team_id';

        return "MATCH ({$alias}:{$entity})-[:{$parentRel}]->({$parentAlias}:{$parentEntity}) " .
               "WHERE {$parentAlias}.{$teamFk} IN [{$teamIdList}]";
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Unit/Services/Security/TeamPathResolverTest.php -v`
Expected: PASS

---

### Task 4: Create TeamFilterInjector

**Files:**
- Create: `src/Services/Security/TeamFilterInjector.php`
- Test: `tests/Unit/Services/Security/TeamFilterInjectorTest.php`

**Step 1: Write the failing test**

```php
// tests/Unit/Services/Security/TeamFilterInjectorTest.php
<?php

namespace Condoedge\Ai\Tests\Unit\Services\Security;

use Condoedge\Ai\Services\Security\TeamFilterInjector;
use Condoedge\Ai\Services\Security\TeamPathResolver;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Support\Facades\Log;
use Mockery;

class TeamFilterInjectorTest extends TestCase
{
    private TeamFilterInjector $injector;
    private $mockResolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockResolver = Mockery::mock(TeamPathResolver::class);
        $this->injector = new TeamFilterInjector($this->mockResolver);
    }

    /** @test */
    public function injects_direct_filter_into_simple_match(): void
    {
        $cypher = 'MATCH (i:Invoice) RETURN i';
        $entityTeams = ['Invoice' => [1, 3, 7]];

        $this->mockResolver->shouldReceive('resolve')
            ->with('Invoice', [1, 3, 7])
            ->once()
            ->andReturn([
                'type' => 'direct',
                'foreign_key' => 'team_id',
                'filter' => 'WHERE i.team_id IN [1, 3, 7]',
            ]);

        $result = $this->injector->inject($cypher, $entityTeams);

        $this->assertStringContainsString('WHERE i.team_id IN [1, 3, 7]', $result);
    }

    /** @test */
    public function merges_filter_with_existing_where(): void
    {
        $cypher = "MATCH (i:Invoice) WHERE i.status = 'pending' RETURN i";
        $entityTeams = ['Invoice' => [1, 3]];

        $this->mockResolver->shouldReceive('resolve')
            ->with('Invoice', [1, 3])
            ->andReturn([
                'type' => 'direct',
                'foreign_key' => 'team_id',
                'filter' => 'WHERE i.team_id IN [1, 3]',
            ]);

        $result = $this->injector->inject($cypher, $entityTeams);

        // Should combine conditions with AND
        $this->assertStringContainsString('team_id IN [1, 3]', $result);
        $this->assertStringContainsString("status = 'pending'", $result);
    }

    /** @test */
    public function logs_warning_when_no_team_path_and_denies(): void
    {
        $cypher = 'MATCH (s:SystemSetting) RETURN s';
        $entityTeams = ['SystemSetting' => [1, 3]];

        config(['ai.security.on_missing_team_path' => 'deny']);

        $this->mockResolver->shouldReceive('resolve')
            ->with('SystemSetting', [1, 3])
            ->andReturn(null);

        Log::shouldReceive('channel')
            ->with('ai-security')
            ->andReturnSelf();
        Log::shouldReceive('warning')
            ->once();

        $this->expectException(\Condoedge\Ai\Exceptions\SecurityException::class);
        $this->expectExceptionMessage('Cannot verify permissions for SystemSetting');

        $this->injector->inject($cypher, $entityTeams);
    }

    /** @test */
    public function bypasses_when_configured_and_logs(): void
    {
        $cypher = 'MATCH (s:SystemSetting) RETURN s';
        $entityTeams = ['SystemSetting' => [1, 3]];

        config(['ai.security.on_missing_team_path' => 'bypass']);

        $this->mockResolver->shouldReceive('resolve')
            ->with('SystemSetting', [1, 3])
            ->andReturn(null);

        Log::shouldReceive('channel')
            ->with('ai-security')
            ->andReturnSelf();
        Log::shouldReceive('warning')
            ->once();

        $result = $this->injector->inject($cypher, $entityTeams);

        // Query unchanged when bypassing
        $this->assertEquals($cypher, $result);
    }

    /** @test */
    public function handles_multiple_entities_in_query(): void
    {
        $cypher = 'MATCH (i:Invoice)-[:PLACED_BY]->(c:Customer) RETURN i, c';
        $entityTeams = [
            'Invoice' => [1, 3],
            'Customer' => [1, 3],
        ];

        $this->mockResolver->shouldReceive('resolve')
            ->with('Invoice', [1, 3])
            ->andReturn([
                'type' => 'direct',
                'foreign_key' => 'team_id',
                'filter' => 'WHERE i.team_id IN [1, 3]',
            ]);

        $this->mockResolver->shouldReceive('resolve')
            ->with('Customer', [1, 3])
            ->andReturn([
                'type' => 'direct',
                'foreign_key' => 'team_id',
                'filter' => 'WHERE c.team_id IN [1, 3]',
            ]);

        $result = $this->injector->inject($cypher, $entityTeams);

        // Both entities should be filtered
        $this->assertStringContainsString('i.team_id IN [1, 3]', $result);
        $this->assertStringContainsString('c.team_id IN [1, 3]', $result);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Services/Security/TeamFilterInjectorTest.php -v`
Expected: FAIL with "class does not exist"

**Step 3: Write minimal implementation**

```php
// src/Services/Security/TeamFilterInjector.php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Security;

use Condoedge\Ai\Exceptions\SecurityException;
use Illuminate\Support\Facades\Log;

/**
 * Injects team-based security filters into Cypher queries.
 *
 * Takes a generated Cypher query and entity-to-teams mapping,
 * then modifies the query to include team filtering based on
 * the resolved team paths for each entity.
 */
class TeamFilterInjector
{
    public function __construct(
        private readonly TeamPathResolver $pathResolver
    ) {}

    /**
     * Inject team filters into a Cypher query.
     *
     * @param string $cypher Original Cypher query
     * @param array<string, array<int>> $entityTeams Map of entity => team IDs
     * @return string Modified Cypher query with team filters
     * @throws SecurityException When team path missing and config is 'deny'
     */
    public function inject(string $cypher, array $entityTeams): string
    {
        $filters = [];

        foreach ($entityTeams as $entity => $teamIds) {
            $path = $this->pathResolver->resolve($entity, $teamIds);

            if ($path === null) {
                $this->handleMissingPath($entity, $teamIds);

                // If we get here, on_missing_team_path is 'bypass'
                continue;
            }

            $filters[] = $this->extractFilterCondition($path);
        }

        if (empty($filters)) {
            return $cypher;
        }

        return $this->mergeFiltersIntoCypher($cypher, $filters);
    }

    /**
     * Handle missing team path - log and either throw or bypass.
     */
    private function handleMissingPath(string $entity, array $teamIds): void
    {
        $logChannel = config('ai.security.log_channel', 'ai-security');
        $action = config('ai.security.on_missing_team_path', 'deny');

        Log::channel($logChannel)->warning(
            "Entity has no Team relationship in config",
            [
                'entity' => $entity,
                'team_ids' => $teamIds,
                'action' => $action,
            ]
        );

        if ($action === 'deny') {
            throw new SecurityException(
                "Cannot verify permissions for {$entity}"
            );
        }
    }

    /**
     * Extract the filter condition from a resolved path.
     */
    private function extractFilterCondition(array $path): string
    {
        $filter = $path['filter'] ?? '';

        // Remove leading "WHERE " if present (we'll add it back when merging)
        if (str_starts_with(strtoupper($filter), 'WHERE ')) {
            $filter = substr($filter, 6);
        }

        // Handle MATCH-based filters (relationship traversal)
        if (str_starts_with(strtoupper($filter), 'MATCH ')) {
            // Keep the full MATCH pattern - it needs special handling
            return $filter;
        }

        return $filter;
    }

    /**
     * Merge filter conditions into the Cypher query.
     */
    private function mergeFiltersIntoCypher(string $cypher, array $filters): string
    {
        $matchFilters = [];
        $whereFilters = [];

        foreach ($filters as $filter) {
            if (str_starts_with(strtoupper($filter), 'MATCH ')) {
                $matchFilters[] = $filter;
            } else {
                $whereFilters[] = $filter;
            }
        }

        // Handle MATCH-based filters (need to prepend to query)
        foreach ($matchFilters as $matchFilter) {
            // Extract WHERE from MATCH filter
            if (preg_match('/MATCH\s+(.+?)\s+WHERE\s+(.+)$/i', $matchFilter, $matches)) {
                $matchPattern = $matches[1];
                $whereCondition = $matches[2];

                // Modify the MATCH pattern in original query
                // This is complex - for now, add condition to WHERE
                $whereFilters[] = $whereCondition;
            }
        }

        if (empty($whereFilters)) {
            return $cypher;
        }

        $filterCondition = implode(' AND ', $whereFilters);

        // Check if query already has WHERE clause
        if (preg_match('/\bWHERE\b/i', $cypher)) {
            // Insert before existing WHERE conditions
            return preg_replace(
                '/\bWHERE\b/i',
                "WHERE ({$filterCondition}) AND",
                $cypher,
                1
            );
        }

        // No existing WHERE - add after MATCH
        if (preg_match('/^(MATCH\s+\([^)]+\)(?:\s*-\[.*?\]->\s*\([^)]+\))*)/i', $cypher, $matches)) {
            $matchClause = $matches[1];
            return str_replace(
                $matchClause,
                $matchClause . " WHERE {$filterCondition}",
                $cypher
            );
        }

        // Fallback: append WHERE before RETURN
        return preg_replace(
            '/\bRETURN\b/i',
            "WHERE {$filterCondition} RETURN",
            $cypher,
            1
        );
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Unit/Services/Security/TeamFilterInjectorTest.php -v`
Expected: PASS

---

### Task 5: Create InferenceProtector

**Files:**
- Create: `src/Services/Security/InferenceProtector.php`
- Test: `tests/Unit/Services/Security/InferenceProtectorTest.php`

**Step 1: Write the failing test**

```php
// tests/Unit/Services/Security/InferenceProtectorTest.php
<?php

namespace Condoedge\Ai\Tests\Unit\Services\Security;

use Condoedge\Ai\Services\Security\InferenceProtector;
use Condoedge\Ai\Tests\TestCase;

class InferenceProtectorTest extends TestCase
{
    private InferenceProtector $protector;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.security.inference_threshold' => 5]);
        $this->protector = new InferenceProtector();
    }

    /** @test */
    public function returns_results_when_above_threshold(): void
    {
        $results = [
            ['id' => 1, 'name' => 'Test 1'],
            ['id' => 2, 'name' => 'Test 2'],
            ['id' => 3, 'name' => 'Test 3'],
            ['id' => 4, 'name' => 'Test 4'],
            ['id' => 5, 'name' => 'Test 5'],
        ];

        $protected = $this->protector->protect($results, 'Invoice');

        $this->assertEquals($results, $protected['results']);
        $this->assertTrue($protected['allowed']);
        $this->assertNull($protected['message']);
    }

    /** @test */
    public function blocks_single_result(): void
    {
        $results = [['id' => 1, 'name' => 'Test']];

        $protected = $this->protector->protect($results, 'Invoice');

        $this->assertEmpty($protected['results']);
        $this->assertFalse($protected['allowed']);
        $this->assertStringContainsString('identify', $protected['message']);
    }

    /** @test */
    public function hides_count_below_threshold(): void
    {
        $results = [
            ['id' => 1, 'name' => 'Test 1'],
            ['id' => 2, 'name' => 'Test 2'],
            ['id' => 3, 'name' => 'Test 3'],
        ];

        $protected = $this->protector->protect($results, 'Invoice');

        $this->assertEmpty($protected['results']);
        $this->assertFalse($protected['allowed']);
        $this->assertStringContainsString('privacy', $protected['message']);
    }

    /** @test */
    public function returns_no_results_message_for_empty(): void
    {
        $results = [];

        $protected = $this->protector->protect($results, 'Invoice');

        $this->assertEmpty($protected['results']);
        $this->assertTrue($protected['allowed']); // Allowed but empty
        $this->assertStringContainsString('No results', $protected['message']);
    }

    /** @test */
    public function protects_count_queries(): void
    {
        // Protect a raw count result
        $protected = $this->protector->protectCount(3, 'Invoice');

        $this->assertNull($protected['count']);
        $this->assertFalse($protected['allowed']);
        $this->assertStringContainsString('privacy', $protected['message']);
    }

    /** @test */
    public function allows_count_above_threshold(): void
    {
        $protected = $this->protector->protectCount(10, 'Invoice');

        $this->assertEquals(10, $protected['count']);
        $this->assertTrue($protected['allowed']);
    }

    /** @test */
    public function uses_configurable_threshold(): void
    {
        config(['ai.security.inference_threshold' => 3]);
        $protector = new InferenceProtector();

        $results = [
            ['id' => 1],
            ['id' => 2],
            ['id' => 3],
        ];

        $protected = $protector->protect($results, 'Invoice');

        $this->assertEquals($results, $protected['results']);
        $this->assertTrue($protected['allowed']);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Services/Security/InferenceProtectorTest.php -v`
Expected: FAIL with "class does not exist"

**Step 3: Write minimal implementation**

```php
// src/Services/Security/InferenceProtector.php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Security;

/**
 * Protects against inference attacks by limiting result visibility.
 *
 * Prevents users from narrowing down to specific individuals by:
 * - Blocking single results entirely
 * - Hiding exact counts below threshold
 * - Providing vague responses for small result sets
 */
class InferenceProtector
{
    private int $threshold;
    private array $messages;

    public function __construct()
    {
        $this->threshold = config('ai.security.inference_threshold', 5);
        $this->messages = config('ai.security.messages', [
            'no_results' => 'No results found matching your criteria.',
            'single_result' => 'I cannot provide this information as it would identify a specific individual.',
            'below_threshold' => 'Some results were found, but I cannot show the exact count due to privacy policies.',
        ]);
    }

    /**
     * Protect result data from inference attacks.
     *
     * @param array $results Query results
     * @param string $entity Entity type for messaging
     * @return array{results: array, allowed: bool, message: ?string}
     */
    public function protect(array $results, string $entity): array
    {
        $count = count($results);

        // Empty results - allowed but with message
        if ($count === 0) {
            return [
                'results' => [],
                'allowed' => true,
                'message' => $this->messages['no_results'],
            ];
        }

        // Single result - blocked to prevent identification
        if ($count === 1) {
            return [
                'results' => [],
                'allowed' => false,
                'message' => $this->messages['single_result'],
            ];
        }

        // Below threshold - blocked with vague message
        if ($count < $this->threshold) {
            return [
                'results' => [],
                'allowed' => false,
                'message' => $this->messages['below_threshold'],
            ];
        }

        // Above threshold - allowed with full results
        return [
            'results' => $results,
            'allowed' => true,
            'message' => null,
        ];
    }

    /**
     * Protect count query results from inference attacks.
     *
     * @param int $count Raw count from query
     * @param string $entity Entity type for messaging
     * @return array{count: ?int, allowed: bool, message: ?string}
     */
    public function protectCount(int $count, string $entity): array
    {
        // Empty - allowed
        if ($count === 0) {
            return [
                'count' => 0,
                'allowed' => true,
                'message' => $this->messages['no_results'],
            ];
        }

        // Single - blocked
        if ($count === 1) {
            return [
                'count' => null,
                'allowed' => false,
                'message' => $this->messages['single_result'],
            ];
        }

        // Below threshold - blocked
        if ($count < $this->threshold) {
            return [
                'count' => null,
                'allowed' => false,
                'message' => $this->messages['below_threshold'],
            ];
        }

        // Above threshold - allowed
        return [
            'count' => $count,
            'allowed' => true,
            'message' => null,
        ];
    }

    /**
     * Get the current threshold.
     */
    public function getThreshold(): int
    {
        return $this->threshold;
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Unit/Services/Security/InferenceProtectorTest.php -v`
Expected: PASS

---

### Task 6: Create AiSecurityGate

**Files:**
- Create: `src/Services/Security/AiSecurityGate.php`
- Test: `tests/Unit/Services/Security/AiSecurityGateTest.php`

**Step 1: Write the failing test**

```php
// tests/Unit/Services/Security/AiSecurityGateTest.php
<?php

namespace Condoedge\Ai\Tests\Unit\Services\Security;

use Condoedge\Ai\Contracts\AiAuthAdapterInterface;
use Condoedge\Ai\Services\Security\AiSecurityGate;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Support\Facades\Log;
use Mockery;

class AiSecurityGateTest extends TestCase
{
    private AiSecurityGate $gate;
    private $mockAdapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockAdapter = Mockery::mock(AiAuthAdapterInterface::class);
        $this->gate = new AiSecurityGate($this->mockAdapter);
    }

    /** @test */
    public function check_access_returns_team_ids_for_each_entity(): void
    {
        $user = Mockery::mock();
        $entities = ['Invoice', 'Customer'];

        $this->mockAdapter->shouldReceive('isEnabled')->andReturn(true);
        $this->mockAdapter->shouldReceive('getAccessibleTeamIds')
            ->with($user, 'Invoice')
            ->andReturn([1, 3, 7]);
        $this->mockAdapter->shouldReceive('getAccessibleTeamIds')
            ->with($user, 'Customer')
            ->andReturn([1, 3]);

        $result = $this->gate->checkAccess($user, $entities);

        $this->assertEquals([1, 3, 7], $result['Invoice']);
        $this->assertEquals([1, 3], $result['Customer']);
    }

    /** @test */
    public function throws_when_user_has_no_access_to_entity(): void
    {
        $user = Mockery::mock();
        $user->id = 123;
        $entities = ['Invoice'];

        $this->mockAdapter->shouldReceive('isEnabled')->andReturn(true);
        $this->mockAdapter->shouldReceive('getAccessibleTeamIds')
            ->with($user, 'Invoice')
            ->andReturn([]);

        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning')->once();

        $this->expectException(\Condoedge\Ai\Exceptions\SecurityException::class);
        $this->expectExceptionMessage('Invoice');

        $this->gate->checkAccess($user, $entities);
    }

    /** @test */
    public function bypasses_when_security_disabled(): void
    {
        $user = Mockery::mock();
        $entities = ['Invoice'];

        $this->mockAdapter->shouldReceive('isEnabled')->andReturn(false);
        $this->mockAdapter->shouldNotReceive('getAccessibleTeamIds');

        $result = $this->gate->checkAccess($user, $entities);

        $this->assertEmpty($result);
    }

    /** @test */
    public function logs_denied_access_when_configured(): void
    {
        $user = Mockery::mock();
        $user->id = 123;
        $entities = ['SecretData'];

        config(['ai.security.log_denied_access' => true]);

        $this->mockAdapter->shouldReceive('isEnabled')->andReturn(true);
        $this->mockAdapter->shouldReceive('getAccessibleTeamIds')
            ->andReturn([]);

        Log::shouldReceive('channel')
            ->with('ai-security')
            ->andReturnSelf();
        Log::shouldReceive('warning')
            ->with(
                Mockery::on(fn($msg) => str_contains($msg, 'denied')),
                Mockery::type('array')
            )
            ->once();

        try {
            $this->gate->checkAccess($user, $entities);
        } catch (\Exception $e) {
            // Expected
        }
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Services/Security/AiSecurityGateTest.php -v`
Expected: FAIL with "class does not exist"

**Step 3: Write minimal implementation**

```php
// src/Services/Security/AiSecurityGate.php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Security;

use Condoedge\Ai\Contracts\AiAuthAdapterInterface;
use Condoedge\Ai\Exceptions\SecurityException;
use Illuminate\Support\Facades\Log;

/**
 * Security gate for AI queries.
 *
 * Checks if user has permission to query specific entity types
 * and returns the team IDs where access is granted.
 */
class AiSecurityGate
{
    public function __construct(
        private readonly AiAuthAdapterInterface $authAdapter
    ) {}

    /**
     * Check if user can access the given entities.
     *
     * @param mixed $user User model instance
     * @param array<string> $entities Entity types to check
     * @return array<string, array<int>> Map of entity => team IDs
     * @throws SecurityException When user has no access to an entity
     */
    public function checkAccess($user, array $entities): array
    {
        // Bypass if security disabled
        if (!$this->authAdapter->isEnabled()) {
            return [];
        }

        $result = [];

        foreach ($entities as $entity) {
            $teamIds = $this->authAdapter->getAccessibleTeamIds($user, $entity);

            if (empty($teamIds)) {
                $this->logDeniedAccess($user, $entity);

                throw new SecurityException(
                    config('ai.security.messages.access_denied',
                        "You do not have permission to query {$entity} data")
                );
            }

            $result[$entity] = $teamIds;
        }

        return $result;
    }

    /**
     * Check access without throwing - returns boolean.
     */
    public function canAccess($user, array $entities): bool
    {
        try {
            $this->checkAccess($user, $entities);
            return true;
        } catch (SecurityException $e) {
            return false;
        }
    }

    /**
     * Log denied access attempt.
     */
    private function logDeniedAccess($user, string $entity): void
    {
        if (!config('ai.security.log_denied_access', true)) {
            return;
        }

        $logChannel = config('ai.security.log_channel', 'ai-security');

        Log::channel($logChannel)->warning(
            "AI query access denied",
            [
                'user_id' => $user->id ?? null,
                'entity' => $entity,
                'reason' => 'no_team_access',
            ]
        );
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Unit/Services/Security/AiSecurityGateTest.php -v`
Expected: PASS

---

### Task 7: Create SecurityException

**Files:**
- Create: `src/Exceptions/SecurityException.php`

**Step 1: Write minimal implementation**

```php
// src/Exceptions/SecurityException.php
<?php

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

### Task 8: Add Security Config Section

**Files:**
- Modify: `config/ai.php`

**Step 1: Add security configuration section**

Add to `config/ai.php`:

```php
/*
|--------------------------------------------------------------------------
| Security Configuration
|--------------------------------------------------------------------------
|
| Settings for AI query security filtering.
|
*/
'security' => [
    // Master switch - enabled by default
    'enabled' => env('AI_SECURITY_ENABLED', true),

    // Auth adapter class (for abstraction)
    'auth_adapter' => \Condoedge\Ai\Auth\KompoAuthAdapter::class,

    // What to do when entity has no Team relationship in config
    // 'deny'   → Block query with error message
    // 'bypass' → Allow query without team filtering
    'on_missing_team_path' => env('AI_SECURITY_MISSING_PATH', 'deny'),

    // Inference protection threshold
    // Results with count below this are hidden
    'inference_threshold' => env('AI_SECURITY_INFERENCE_THRESHOLD', 5),

    // Response messages
    'messages' => [
        'no_results' => 'No results found matching your criteria.',
        'single_result' => 'I cannot provide this information as it would identify a specific individual.',
        'below_threshold' => 'Some results were found, but I cannot show the exact count due to privacy policies.',
        'access_denied' => 'You do not have permission to query this type of data.',
        'missing_team_path' => 'Cannot verify permissions for this data type.',
    ],

    // Response scope clarification
    'clarify_scope' => true,
    'scope_prefix' => 'Based on your permissions, ',

    // Logging
    'log_denied_access' => true,
    'log_inference_blocks' => true,
    'log_missing_team_path' => true,
    'log_channel' => env('AI_SECURITY_LOG_CHANNEL', 'ai-security'),
],
```

---

### Task 9: Register Service Provider Bindings

**Files:**
- Modify: `src/AiServiceProvider.php`

**Step 1: Add security service bindings**

Add to `AiServiceProvider::register()`:

```php
// Security services
$this->app->singleton(\Condoedge\Ai\Contracts\AiAuthAdapterInterface::class, function ($app) {
    $adapterClass = config('ai.security.auth_adapter', \Condoedge\Ai\Auth\KompoAuthAdapter::class);
    return new $adapterClass();
});

$this->app->singleton(\Condoedge\Ai\Services\Security\TeamPathResolver::class);

$this->app->singleton(\Condoedge\Ai\Services\Security\TeamFilterInjector::class, function ($app) {
    return new \Condoedge\Ai\Services\Security\TeamFilterInjector(
        $app->make(\Condoedge\Ai\Services\Security\TeamPathResolver::class)
    );
});

$this->app->singleton(\Condoedge\Ai\Services\Security\InferenceProtector::class);

$this->app->singleton(\Condoedge\Ai\Services\Security\AiSecurityGate::class, function ($app) {
    return new \Condoedge\Ai\Services\Security\AiSecurityGate(
        $app->make(\Condoedge\Ai\Contracts\AiAuthAdapterInterface::class)
    );
});
```

---

### Task 10: Integrate Security into AiManager

**Files:**
- Modify: `src/Services/AiManager.php`
- Test: `tests/Unit/Services/AiManagerSecurityIntegrationTest.php`

**Step 1: Write integration test**

```php
// tests/Unit/Services/AiManagerSecurityIntegrationTest.php
<?php

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Services\AiManager;
use Condoedge\Ai\Services\Security\AiSecurityGate;
use Condoedge\Ai\Services\Security\TeamFilterInjector;
use Condoedge\Ai\Services\Security\InferenceProtector;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

class AiManagerSecurityIntegrationTest extends TestCase
{
    /** @test */
    public function answer_question_applies_security_when_enabled(): void
    {
        config(['ai.security.enabled' => true]);

        $mockGate = Mockery::mock(AiSecurityGate::class);
        $mockGate->shouldReceive('checkAccess')
            ->once()
            ->andReturn(['Invoice' => [1, 3, 7]]);

        $mockInjector = Mockery::mock(TeamFilterInjector::class);
        $mockInjector->shouldReceive('inject')
            ->once()
            ->andReturnUsing(fn($cypher, $teams) => $cypher . " /* filtered */");

        $mockProtector = Mockery::mock(InferenceProtector::class);
        $mockProtector->shouldReceive('protect')
            ->andReturn(['results' => [], 'allowed' => true, 'message' => null]);

        $this->app->instance(AiSecurityGate::class, $mockGate);
        $this->app->instance(TeamFilterInjector::class, $mockInjector);
        $this->app->instance(InferenceProtector::class, $mockProtector);

        // ... rest of test setup
    }

    /** @test */
    public function answer_question_skips_security_when_disabled(): void
    {
        config(['ai.security.enabled' => false]);

        $mockGate = Mockery::mock(AiSecurityGate::class);
        $mockGate->shouldNotReceive('checkAccess');

        $this->app->instance(AiSecurityGate::class, $mockGate);

        // ... rest of test setup
    }
}
```

**Step 2: Modify AiManager to integrate security**

In `AiManager::answerQuestion()`, add security checks:

```php
// After entity detection, before query generation:
$entityTeams = [];
if (config('ai.security.enabled', true)) {
    $securityGate = app(AiSecurityGate::class);
    $detectedEntities = $context['entity_metadata']['detected_entities'] ?? [];

    try {
        $entityTeams = $securityGate->checkAccess(auth()->user(), $detectedEntities);
    } catch (SecurityException $e) {
        return $this->createSecurityDeniedResponse($e->getMessage());
    }
}

// After query generation, before execution:
if (!empty($entityTeams)) {
    $injector = app(TeamFilterInjector::class);
    $cypherQuery = $injector->inject($cypherQuery, $entityTeams);
}

// After query execution, before response generation:
if (config('ai.security.enabled', true)) {
    $protector = app(InferenceProtector::class);
    $protection = $protector->protect($queryResults, $primaryEntity);

    if (!$protection['allowed']) {
        return $this->createProtectedResponse($protection['message']);
    }

    $queryResults = $protection['results'];
}
```

---

## Summary

**Files to Create:**
1. `src/Contracts/AiAuthAdapterInterface.php` - Abstract auth interface
2. `src/Auth/KompoAuthAdapter.php` - Kompo Auth implementation
3. `src/Services/Security/TeamPathResolver.php` - Resolve team relationship paths
4. `src/Services/Security/TeamFilterInjector.php` - Inject team filters into Cypher
5. `src/Services/Security/InferenceProtector.php` - Privacy protection
6. `src/Services/Security/AiSecurityGate.php` - Main security gate
7. `src/Exceptions/SecurityException.php` - Security exception class

**Files to Modify:**
1. `config/ai.php` - Add security config section
2. `src/AiServiceProvider.php` - Register security services
3. `src/Services/AiManager.php` - Integrate security into query pipeline

**Test Files:**
1. `tests/Unit/Contracts/AiAuthAdapterInterfaceTest.php`
2. `tests/Unit/Auth/KompoAuthAdapterTest.php`
3. `tests/Unit/Services/Security/TeamPathResolverTest.php`
4. `tests/Unit/Services/Security/TeamFilterInjectorTest.php`
5. `tests/Unit/Services/Security/InferenceProtectorTest.php`
6. `tests/Unit/Services/Security/AiSecurityGateTest.php`
7. `tests/Unit/Services/AiManagerSecurityIntegrationTest.php`

---

**Execution Options:**

1. **Subagent-Driven (this session)** - I dispatch fresh subagent per task, review between tasks, fast iteration

2. **Parallel Session (separate)** - Open new session with executing-plans, batch execution with checkpoints

**Which approach?**
