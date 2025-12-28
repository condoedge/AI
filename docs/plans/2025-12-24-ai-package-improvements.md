# AI Package Improvements Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Transform the Kompo AI package into an enterprise-ready RAG system with native Neo4j/Qdrant security, multi-level access control, and seamless auth integration.

**Architecture:** Neo4j for graph relationships + aggregates, Qdrant for semantic search, both filtered by team_ids at query time. Multi-level access tags control what the AI can reveal, with threshold protection for filtered counts.

**Tech Stack:** Laravel 10+, Neo4j (graph + aggregates), Qdrant (vector), PHP 8.2+, Kompo Auth package integration

---

## Executive Summary

This plan addresses four major improvement areas:

1. **Auto-Discovery Enhancement** - Robust command-based discovery with edge case handling
2. **Auth Integration** - Native Neo4j/Qdrant security with multi-level access tags
3. **Extensibility Improvements** - Plugin points and customization hooks
4. **Simplification** - Reduce complexity while maintaining power

Each section evaluates options with pros/cons and recommends the best approach.

---

## Part 1: Auto-Discovery Enhancement

### Problem Statement

Current auto-discovery works but:
- Complex relationships (like Person → PersonTeams → Team) need manual config
- Edge cases aren't well handled
- Config can be verbose for common patterns

**Goal:** Make `php artisan ai:discover` robust enough to handle complex cases, while keeping config simple when customization is needed.

### Recommended Approach

Keep command-based discovery (not runtime) for:
- Predictable performance in production
- Explicit control over what's indexed
- Easy debugging of discovered config

Enhance it to:
- Auto-detect team resolution patterns
- Recognize existing auth scopes (`securityRelatedTeamIds`, `scopeSecurityForTeams`)
- Generate cleaner config with smart defaults

---

### Task 1: Enhance Discovery Command to Detect Team Resolution

**Files:**
- Modify: `src/Console/Commands/DiscoverEntitiesCommand.php`
- Modify: `src/Services/Discovery/EntityAutoDiscovery.php`
- Create: `tests/Unit/Services/Discovery/TeamResolutionDiscoveryTest.php`

**Step 1: Write the failing test**

```php
<?php

namespace Condoedge\Ai\Tests\Unit\Services\Discovery;

use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Condoedge\Ai\Tests\TestCase;

class TeamResolutionDiscoveryTest extends TestCase
{
    private EntityAutoDiscovery $discovery;

    public function setUp(): void
    {
        parent::setUp();
        $this->discovery = app(EntityAutoDiscovery::class);
    }

    /** @test */
    public function it_discovers_direct_team_id_column(): void
    {
        $model = new class extends \Illuminate\Database\Eloquent\Model {
            protected $table = 'events';
            protected $fillable = ['name', 'team_id'];
        };

        $config = $this->discovery->discover($model);

        $this->assertEquals('team_id', $config['security']['team_resolution']);
    }

    /** @test */
    public function it_discovers_custom_team_id_column(): void
    {
        $model = new class extends \Illuminate\Database\Eloquent\Model {
            protected $table = 'projects';
            protected $fillable = ['name', 'organization_id'];
            protected $TEAM_ID_COLUMN = 'organization_id';
        };

        $config = $this->discovery->discover($model);

        $this->assertEquals('organization_id', $config['security']['team_resolution']);
    }

    /** @test */
    public function it_discovers_security_related_team_ids_method(): void
    {
        $model = new class extends \Illuminate\Database\Eloquent\Model {
            protected $table = 'persons';

            public function securityRelatedTeamIds()
            {
                return $this->personTeams()->pluck('team_id');
            }

            public function personTeams()
            {
                return $this->hasMany('PersonTeam');
            }
        };

        $config = $this->discovery->discover($model);

        $this->assertEquals('method:securityRelatedTeamIds', $config['security']['team_resolution']);
        $this->assertTrue($config['security']['multiple_teams']);
    }

    /** @test */
    public function it_discovers_scope_security_for_teams(): void
    {
        $model = new class extends \Illuminate\Database\Eloquent\Model {
            protected $table = 'persons';

            public function scopeSecurityForTeams($query, $teamIds)
            {
                return $query->whereHas('personTeams', fn($q) => $q->whereIn('team_id', $teamIds));
            }
        };

        $config = $this->discovery->discover($model);

        $this->assertEquals('scope:securityForTeams', $config['security']['team_query_scope']);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Services/Discovery/TeamResolutionDiscoveryTest.php -v`
Expected: FAIL

**Step 3: Implement team resolution discovery**

```php
<?php
// Add to EntityAutoDiscovery.php

/**
 * Discover security configuration for a model
 */
protected function discoverSecurityConfig(Model $model): array
{
    $config = [
        'team_resolution' => null,
        'team_query_scope' => null,
        'multiple_teams' => false,
        'has_owner_bypass' => false,
    ];

    // Strategy 1: Check for securityRelatedTeamIds method
    if (method_exists($model, 'securityRelatedTeamIds')) {
        $config['team_resolution'] = 'method:securityRelatedTeamIds';
        $config['multiple_teams'] = true; // Method typically returns collection
    }
    // Strategy 2: Check for TEAM_ID_COLUMN property
    elseif (property_exists($model, 'TEAM_ID_COLUMN')) {
        $column = $this->getModelProperty($model, 'TEAM_ID_COLUMN');
        $config['team_resolution'] = $column;
    }
    // Strategy 3: Check for team_id column
    elseif ($this->hasColumn($model, 'team_id')) {
        $config['team_resolution'] = 'team_id';
    }

    // Check for scopeSecurityForTeams
    if (method_exists($model, 'scopeSecurityForTeams')) {
        $config['team_query_scope'] = 'scope:securityForTeams';
    }

    // Check for owner bypass methods
    if (method_exists($model, 'usersIdsAllowedToManage')) {
        $config['has_owner_bypass'] = true;
    }

    return $config;
}

/**
 * Check if model's table has a column
 */
protected function hasColumn(Model $model, string $column): bool
{
    try {
        return in_array($column,
            $model->getConnection()->getSchemaBuilder()->getColumnListing($model->getTable())
        );
    } catch (\Throwable $e) {
        return false;
    }
}
```

**Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Services/Discovery/TeamResolutionDiscoveryTest.php -v`
Expected: PASS

**Step 5: Commit**

```bash
git add src/Services/Discovery/EntityAutoDiscovery.php tests/Unit/Services/Discovery/TeamResolutionDiscoveryTest.php
git commit -m "feat(discovery): auto-detect team resolution patterns from auth package"
```

---

### Task 2: Discover sensibleColumns from Models

**Files:**
- Modify: `src/Services/Discovery/EntityAutoDiscovery.php`
- Create: `tests/Unit/Services/Discovery/SensibleColumnsDiscoveryTest.php`

**Step 1: Write the failing test**

```php
<?php

namespace Condoedge\Ai\Tests\Unit\Services\Discovery;

use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Condoedge\Ai\Tests\TestCase;

class SensibleColumnsDiscoveryTest extends TestCase
{
    /** @test */
    public function it_discovers_sensible_columns_from_model(): void
    {
        $model = new class extends \Illuminate\Database\Eloquent\Model {
            protected $table = 'persons';
            protected $sensibleColumns = ['ssn', 'date_of_birth', 'salary'];
        };

        $discovery = app(EntityAutoDiscovery::class);
        $config = $discovery->discover($model);

        $this->assertEquals(['ssn', 'date_of_birth', 'salary'], $config['security']['sensible_columns']);
    }

    /** @test */
    public function it_excludes_sensible_columns_from_embed_fields(): void
    {
        $model = new class extends \Illuminate\Database\Eloquent\Model {
            protected $table = 'persons';
            protected $fillable = ['name', 'bio', 'ssn', 'medical_notes'];
            protected $sensibleColumns = ['ssn', 'medical_notes'];
        };

        $discovery = app(EntityAutoDiscovery::class);
        $config = $discovery->discover($model);

        // Sensible columns should NOT be in embed_fields
        $this->assertNotContains('ssn', $config['vector']['embed_fields']);
        $this->assertNotContains('medical_notes', $config['vector']['embed_fields']);

        // But non-sensitive text fields should be
        $this->assertContains('bio', $config['vector']['embed_fields']);
    }

    /** @test */
    public function it_marks_sensible_columns_in_neo4j_properties(): void
    {
        $model = new class extends \Illuminate\Database\Eloquent\Model {
            protected $table = 'persons';
            protected $fillable = ['name', 'email', 'salary'];
            protected $sensibleColumns = ['salary'];
        };

        $discovery = app(EntityAutoDiscovery::class);
        $config = $discovery->discover($model);

        // Sensible columns should be marked in graph properties
        $this->assertTrue($config['graph']['properties']['salary']['sensitive'] ?? false);
        $this->assertFalse($config['graph']['properties']['name']['sensitive'] ?? false);
    }
}
```

**Step 2: Implement sensible columns discovery**

```php
<?php
// Add to EntityAutoDiscovery.php

/**
 * Get sensible columns from model
 */
protected function getSensibleColumns(Model $model): array
{
    if (property_exists($model, 'sensibleColumns')) {
        return $this->getModelProperty($model, 'sensibleColumns') ?? [];
    }

    return [];
}

/**
 * Build graph config with sensitivity markers
 */
protected function buildGraphConfig(Model $model): array
{
    $sensibleColumns = $this->getSensibleColumns($model);
    $properties = $this->schemaAnalyzer->discoverProperties($model);

    // Mark sensitive properties
    foreach ($properties as $column => &$config) {
        $config['sensitive'] = in_array($column, $sensibleColumns);
    }

    // ... rest of graph config
}

/**
 * Build vector config excluding sensitive fields
 */
protected function buildVectorConfig(Model $model): array
{
    $sensibleColumns = $this->getSensibleColumns($model);
    $embedFields = $this->schemaAnalyzer->discoverEmbedFields($model);

    // Exclude sensitive columns from embedding
    $embedFields = array_diff($embedFields, $sensibleColumns);

    // ... rest of vector config
}
```

**Step 3: Commit**

```bash
git add src/Services/Discovery/EntityAutoDiscovery.php tests/Unit/Services/Discovery/SensibleColumnsDiscoveryTest.php
git commit -m "feat(discovery): detect and handle sensibleColumns from auth package"
```

---

## Part 2: Auth Integration - Multi-Level Access System

### Problem Statement

RAG queries hit Neo4j and Qdrant, not Eloquent. We can't use `scopeSecurityForTeams()` directly.

**Solution:**
1. Store team_ids in Neo4j/Qdrant at ingestion time
2. Filter queries by team_ids in both stores
3. Use multi-level access tags to control what AI can reveal
4. Apply thresholds to prevent identifying individuals through counts

### Access Level Architecture

```
Level 0: global_count        - Total counts, no filters (public)
Level 1: team_count          - Counts within user's teams
Level 2: team_filtered_count - Filtered counts (with threshold protection)
Level 3: team_details        - Record data (excluding sensibleColumns)
Level 4: team_sensitive      - Record data (including sensibleColumns)
```

### Configuration

```php
// config/ai.php

'access_control' => [
    // Count threshold - don't reveal exact count if below this
    'default_threshold' => 5,

    // Per-entity thresholds (more sensitive entities need higher thresholds)
    'thresholds' => [
        'Person' => 5,
        'Transaction' => 10,
        'HealthRecord' => 20,
    ],

    // Fields that make a count "filtered" (potentially identifying)
    'identifying_fields' => [
        '*' => ['date_of_birth', 'birth_date', 'dob', 'email', 'phone', 'ssn', 'address'],
        'Person' => ['gender', 'spoken_languages'],
    ],
],
```

---

### Task 3: Create Access Level Resolver

**Files:**
- Create: `src/Services/Security/AccessLevelResolver.php`
- Create: `tests/Unit/Services/Security/AccessLevelResolverTest.php`

**Step 1: Write the failing test**

```php
<?php

namespace Condoedge\Ai\Tests\Unit\Services\Security;

use Condoedge\Ai\Services\Security\AccessLevelResolver;
use Condoedge\Ai\Tests\TestCase;
use Kompo\Auth\Models\Model\PermissionTypeEnum;

class AccessLevelResolverTest extends TestCase
{
    private AccessLevelResolver $resolver;

    public function setUp(): void
    {
        parent::setUp();
        $this->resolver = app(AccessLevelResolver::class);
    }

    /** @test */
    public function it_returns_global_count_for_all_users(): void
    {
        $tags = $this->resolver->resolveForEntity(null, 'Person');

        $this->assertContains('Person_global_count', $tags);
    }

    /** @test */
    public function it_returns_team_count_for_team_members(): void
    {
        $user = $this->createUserInTeam($teamId = 1);

        $tags = $this->resolver->resolveForEntity($user, 'Person');

        $this->assertContains('Person_team_count', $tags);
    }

    /** @test */
    public function it_returns_team_filtered_count_for_users_with_read_permission(): void
    {
        $user = $this->createUserWithPermission('Person', PermissionTypeEnum::READ);

        $tags = $this->resolver->resolveForEntity($user, 'Person');

        $this->assertContains('Person_team_filtered_count', $tags);
        $this->assertContains('Person_team_details', $tags);
    }

    /** @test */
    public function it_returns_team_sensitive_for_users_with_sensible_columns_permission(): void
    {
        $user = $this->createUserWithPermission('Person.sensibleColumns', PermissionTypeEnum::READ);

        $tags = $this->resolver->resolveForEntity($user, 'Person');

        $this->assertContains('Person_team_sensitive', $tags);
    }

    /** @test */
    public function it_does_not_include_higher_levels_without_permission(): void
    {
        $user = $this->createUserInTeam($teamId = 1); // No READ permission

        $tags = $this->resolver->resolveForEntity($user, 'Person');

        $this->assertContains('Person_team_count', $tags);
        $this->assertNotContains('Person_team_filtered_count', $tags);
        $this->assertNotContains('Person_team_details', $tags);
        $this->assertNotContains('Person_team_sensitive', $tags);
    }

    /** @test */
    public function it_reads_sensible_columns_from_model(): void
    {
        $user = $this->createUserWithPermission('Person', PermissionTypeEnum::READ);

        $context = $this->resolver->buildContextForEntity($user, 'Person');

        $this->assertArrayHasKey('sensible_columns', $context);
        $this->assertContains('date_of_birth', $context['sensible_columns']);
    }

    /** @test */
    public function it_returns_entity_specific_threshold(): void
    {
        config(['ai.access_control.thresholds.Person' => 10]);

        $threshold = $this->resolver->getThreshold('Person');

        $this->assertEquals(10, $threshold);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Services/Security/AccessLevelResolverTest.php -v`
Expected: FAIL

**Step 3: Implement AccessLevelResolver**

```php
<?php

namespace Condoedge\Ai\Services\Security;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Kompo\Auth\Models\Model\PermissionTypeEnum;

class AccessLevelResolver
{
    /**
     * Access levels in order of increasing sensitivity
     */
    protected const LEVELS = [
        'global_count',        // Level 0: Anyone
        'team_count',          // Level 1: Team member
        'team_filtered_count', // Level 2: READ permission
        'team_details',        // Level 3: READ permission
        'team_sensitive',      // Level 4: sensibleColumns permission
    ];

    /**
     * Resolve access tags for a user and entity
     */
    public function resolveForEntity(?Authenticatable $user, string $entityClass): array
    {
        $entity = class_basename($entityClass);
        $tags = [];

        // Level 0: Everyone gets global counts
        $tags[] = "{$entity}_global_count";

        if (!$user) {
            return $tags;
        }

        // Level 1: Team members get team counts
        if ($this->userHasTeamAccess($user)) {
            $tags[] = "{$entity}_team_count";
        }

        // Level 2 & 3: READ permission gets filtered counts and details
        if ($this->userHasPermission($user, $entity, PermissionTypeEnum::READ)) {
            $tags[] = "{$entity}_team_filtered_count";
            $tags[] = "{$entity}_team_details";
        }

        // Level 4: sensibleColumns permission gets sensitive data
        if ($this->userHasPermission($user, "{$entity}.sensibleColumns", PermissionTypeEnum::READ)) {
            $tags[] = "{$entity}_team_sensitive";
        }

        return $tags;
    }

    /**
     * Build full context for entity including tags, thresholds, and restrictions
     */
    public function buildContextForEntity(?Authenticatable $user, string $entityClass): array
    {
        $entity = class_basename($entityClass);
        $model = $this->resolveModel($entityClass);

        return [
            'entity' => $entity,
            'access_tags' => $this->resolveForEntity($user, $entityClass),
            'threshold' => $this->getThreshold($entity),
            'sensible_columns' => $this->getSensibleColumns($model),
            'identifying_fields' => $this->getIdentifyingFields($entity),
            'user_teams' => $user ? $this->getUserTeamIds($user) : [],
        ];
    }

    /**
     * Get count threshold for an entity
     */
    public function getThreshold(string $entity): int
    {
        return config("ai.access_control.thresholds.{$entity}")
            ?? config('ai.access_control.default_threshold', 5);
    }

    /**
     * Get sensible columns from model
     */
    protected function getSensibleColumns($model): array
    {
        if (!$model) {
            return [];
        }

        if (property_exists($model, 'sensibleColumns')) {
            $reflection = new \ReflectionProperty($model, 'sensibleColumns');
            $reflection->setAccessible(true);
            return $reflection->getValue($model) ?? [];
        }

        return [];
    }

    /**
     * Get identifying fields for an entity
     */
    protected function getIdentifyingFields(string $entity): array
    {
        $global = config('ai.access_control.identifying_fields.*', []);
        $specific = config("ai.access_control.identifying_fields.{$entity}", []);

        return array_unique(array_merge($global, $specific));
    }

    /**
     * Check if user has team access
     */
    protected function userHasTeamAccess(Authenticatable $user): bool
    {
        if (method_exists($user, 'currentTeamId')) {
            return $user->currentTeamId() !== null;
        }

        return method_exists($user, 'teams') && $user->teams()->exists();
    }

    /**
     * Check if user has permission
     */
    protected function userHasPermission(Authenticatable $user, string $key, $type): bool
    {
        if (!method_exists($user, 'hasPermission')) {
            return false;
        }

        return $user->hasPermission($key, $type);
    }

    /**
     * Get user's accessible team IDs
     */
    protected function getUserTeamIds(Authenticatable $user): array
    {
        if (method_exists($user, 'getAccessibleTeamIds')) {
            return $user->getAccessibleTeamIds();
        }

        if (method_exists($user, 'teams')) {
            return $user->teams()->pluck('id')->toArray();
        }

        $currentTeamId = method_exists($user, 'currentTeamId')
            ? $user->currentTeamId()
            : null;

        return $currentTeamId ? [$currentTeamId] : [];
    }

    /**
     * Resolve model class from entity name
     */
    protected function resolveModel(string $entityClass): ?object
    {
        if (class_exists($entityClass)) {
            return new $entityClass;
        }

        // Try common namespaces
        $namespaces = config('ai.model_namespaces', ['App\\Models']);
        foreach ($namespaces as $namespace) {
            $fullClass = "{$namespace}\\{$entityClass}";
            if (class_exists($fullClass)) {
                return new $fullClass;
            }
        }

        return null;
    }
}
```

**Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Services/Security/AccessLevelResolverTest.php -v`
Expected: PASS

**Step 5: Commit**

```bash
git add src/Services/Security/AccessLevelResolver.php tests/Unit/Services/Security/AccessLevelResolverTest.php
git commit -m "feat(security): add AccessLevelResolver with multi-level access control"
```

---

### Task 4: Store Team IDs in Neo4j and Qdrant at Ingestion

**Files:**
- Modify: `src/Services/DataIngestionService.php`
- Create: `tests/Unit/Services/DataIngestionServiceSecurityTest.php`

**Step 1: Write the failing test**

```php
<?php

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Services\DataIngestionService;
use Condoedge\Ai\Tests\TestCase;

class DataIngestionServiceSecurityTest extends TestCase
{
    /** @test */
    public function it_stores_team_ids_in_qdrant_payload(): void
    {
        $model = $this->createPersonWithTeams([1, 2, 3]);

        $this->qdrant->shouldReceive('upsert')
            ->withArgs(function ($collection, $id, $vector, $payload) {
                return isset($payload['_team_ids'])
                    && $payload['_team_ids'] === [1, 2, 3];
            })
            ->once();

        $service = app(DataIngestionService::class);
        $service->ingest($model);
    }

    /** @test */
    public function it_creates_team_relationships_in_neo4j(): void
    {
        $model = $this->createPersonWithTeams([1, 2]);

        $this->neo4j->shouldReceive('createRelationship')
            ->with($model->id, 1, 'BELONGS_TO_TEAM', \Mockery::any())
            ->once();
        $this->neo4j->shouldReceive('createRelationship')
            ->with($model->id, 2, 'BELONGS_TO_TEAM', \Mockery::any())
            ->once();

        $service = app(DataIngestionService::class);
        $service->ingest($model);
    }

    /** @test */
    public function it_uses_security_related_team_ids_method_when_available(): void
    {
        $model = new class extends \Illuminate\Database\Eloquent\Model {
            public function securityRelatedTeamIds()
            {
                return collect([5, 6, 7]);
            }
        };

        $this->qdrant->shouldReceive('upsert')
            ->withArgs(function ($collection, $id, $vector, $payload) {
                return $payload['_team_ids'] === [5, 6, 7];
            })
            ->once();

        $service = app(DataIngestionService::class);
        $service->ingest($model);
    }

    /** @test */
    public function it_excludes_sensible_columns_from_vector_content(): void
    {
        $model = new class extends \Illuminate\Database\Eloquent\Model {
            protected $fillable = ['name', 'bio', 'ssn'];
            protected $sensibleColumns = ['ssn'];
            protected $attributes = [
                'name' => 'John',
                'bio' => 'Developer',
                'ssn' => '123-45-6789',
            ];
        };

        $this->qdrant->shouldReceive('upsert')
            ->withArgs(function ($collection, $id, $vector, $payload) {
                // SSN should not be in the embedded content
                return !str_contains($payload['_content'] ?? '', '123-45-6789');
            })
            ->once();

        $service = app(DataIngestionService::class);
        $service->ingest($model);
    }
}
```

**Step 2: Implement security-aware ingestion**

```php
<?php
// Add to DataIngestionService.php

/**
 * Resolve team IDs for a model using auth package patterns
 */
protected function resolveTeamIds(Nodeable $entity): array
{
    // Strategy 1: securityRelatedTeamIds method (Person, complex models)
    if (method_exists($entity, 'securityRelatedTeamIds')) {
        $teamIds = $entity->securityRelatedTeamIds();
        return $teamIds instanceof \Illuminate\Support\Collection
            ? $teamIds->toArray()
            : (array) $teamIds;
    }

    // Strategy 2: TEAM_ID_COLUMN property
    if (property_exists($entity, 'TEAM_ID_COLUMN')) {
        $column = $entity->TEAM_ID_COLUMN;
        $teamId = $entity->getAttribute($column);
        return $teamId ? [$teamId] : [];
    }

    // Strategy 3: Direct team_id column
    if ($entity->getAttribute('team_id')) {
        return [$entity->getAttribute('team_id')];
    }

    // Strategy 4: team() relationship
    if (method_exists($entity, 'team')) {
        $team = $entity->team;
        return $team ? [$team->id] : [];
    }

    return [];
}

/**
 * Build vector payload with security metadata
 */
protected function buildVectorPayload(Nodeable $entity): array
{
    $sensibleColumns = $this->getSensibleColumns($entity);

    $payload = [
        '_entity_type' => class_basename($entity),
        '_entity_class' => get_class($entity),
        '_entity_id' => $entity->getId(),
        '_team_ids' => $this->resolveTeamIds($entity),
        '_content' => $this->buildEmbeddableContent($entity, $sensibleColumns),
    ];

    // Add owner for owner-bypass support
    if ($ownerId = $entity->getAttribute('user_id') ?? $entity->getAttribute('owner_id')) {
        $payload['_owner_id'] = $ownerId;
    }

    return array_merge($payload, $this->buildMetadata($entity, $sensibleColumns));
}

/**
 * Build content for embedding, excluding sensitive fields
 */
protected function buildEmbeddableContent(Nodeable $entity, array $sensibleColumns): string
{
    $vectorConfig = $entity->getVectorConfig();
    $embedFields = $vectorConfig->getEmbedFields();

    // Filter out sensitive columns
    $safeFields = array_diff($embedFields, $sensibleColumns);

    $parts = [];
    foreach ($safeFields as $field) {
        $value = $entity->getAttribute($field);
        if ($value) {
            $parts[] = "{$field}: {$value}";
        }
    }

    return implode("\n", $parts);
}

/**
 * Get sensible columns from model
 */
protected function getSensibleColumns(Nodeable $entity): array
{
    if (property_exists($entity, 'sensibleColumns')) {
        $reflection = new \ReflectionProperty($entity, 'sensibleColumns');
        $reflection->setAccessible(true);
        return $reflection->getValue($entity) ?? [];
    }

    return [];
}

/**
 * Ingest team relationships into Neo4j
 */
protected function ingestTeamRelationships(Nodeable $entity): void
{
    $teamIds = $this->resolveTeamIds($entity);

    foreach ($teamIds as $teamId) {
        $this->graphStore->createRelationship(
            fromLabel: class_basename($entity),
            fromId: $entity->getId(),
            toLabel: 'Team',
            toId: $teamId,
            type: 'BELONGS_TO_TEAM'
        );
    }
}
```

**Step 3: Commit**

```bash
git add src/Services/DataIngestionService.php tests/Unit/Services/DataIngestionServiceSecurityTest.php
git commit -m "feat(security): store team_ids and exclude sensibleColumns at ingestion"
```

---

### Task 5: Filter Neo4j and Qdrant Queries by Team IDs

**Files:**
- Modify: `src/Services/ContextRetriever.php`
- Create: `src/Services/Security/TeamFilteredQuery.php`
- Create: `tests/Unit/Services/Security/TeamFilteredQueryTest.php`

**Step 1: Write the failing test**

```php
<?php

namespace Condoedge\Ai\Tests\Unit\Services\Security;

use Condoedge\Ai\Services\Security\TeamFilteredQuery;
use Condoedge\Ai\Tests\TestCase;

class TeamFilteredQueryTest extends TestCase
{
    /** @test */
    public function it_filters_qdrant_search_by_team_ids(): void
    {
        $filter = new TeamFilteredQuery([1, 2, 3]);

        $qdrantFilter = $filter->toQdrantFilter();

        $this->assertEquals([
            'should' => [
                ['key' => '_team_ids', 'match' => ['any' => [1, 2, 3]]],
            ]
        ], $qdrantFilter);
    }

    /** @test */
    public function it_filters_neo4j_query_by_team_ids(): void
    {
        $filter = new TeamFilteredQuery([1, 2]);

        $cypherClause = $filter->toCypherWhereClause('n');

        $this->assertStringContainsString('BELONGS_TO_TEAM', $cypherClause);
        $this->assertStringContainsString('$teamIds', $cypherClause);
    }

    /** @test */
    public function it_returns_count_from_neo4j_with_team_filter(): void
    {
        $this->neo4j->shouldReceive('query')
            ->with(\Mockery::on(function ($cypher) {
                return str_contains($cypher, 'BELONGS_TO_TEAM')
                    && str_contains($cypher, 'count(');
            }), \Mockery::any())
            ->andReturn([['count' => 42]]);

        $filter = new TeamFilteredQuery([1, 2]);
        $count = $filter->countInNeo4j($this->neo4j, 'Person');

        $this->assertEquals(42, $count);
    }

    /** @test */
    public function it_includes_global_results_when_no_team_filter(): void
    {
        $filter = new TeamFilteredQuery([]); // No team restriction

        $qdrantFilter = $filter->toQdrantFilter();

        $this->assertEmpty($qdrantFilter); // No filter = all results
    }
}
```

**Step 2: Implement TeamFilteredQuery**

```php
<?php

namespace Condoedge\Ai\Services\Security;

use Condoedge\Ai\Contracts\GraphStore;

class TeamFilteredQuery
{
    protected array $teamIds;
    protected ?int $ownerId = null;

    public function __construct(array $teamIds, ?int $ownerId = null)
    {
        $this->teamIds = $teamIds;
        $this->ownerId = $ownerId;
    }

    /**
     * Build Qdrant filter for team-based access
     */
    public function toQdrantFilter(): array
    {
        if (empty($this->teamIds) && !$this->ownerId) {
            return []; // No restrictions
        }

        $conditions = [];

        if (!empty($this->teamIds)) {
            $conditions[] = [
                'key' => '_team_ids',
                'match' => ['any' => $this->teamIds],
            ];
        }

        if ($this->ownerId) {
            $conditions[] = [
                'key' => '_owner_id',
                'match' => ['value' => $this->ownerId],
            ];
        }

        // If we have both, user can access either (OR)
        if (count($conditions) > 1) {
            return ['should' => $conditions];
        }

        return ['must' => $conditions];
    }

    /**
     * Build Cypher WHERE clause for team-based access
     */
    public function toCypherWhereClause(string $nodeAlias): string
    {
        if (empty($this->teamIds) && !$this->ownerId) {
            return ''; // No restrictions
        }

        $clauses = [];

        if (!empty($this->teamIds)) {
            $clauses[] = "({$nodeAlias})-[:BELONGS_TO_TEAM]->(t:Team) WHERE t.id IN \$teamIds";
        }

        if ($this->ownerId) {
            $clauses[] = "{$nodeAlias}._owner_id = \$ownerId";
        }

        return implode(' OR ', $clauses);
    }

    /**
     * Get count from Neo4j with team filter
     */
    public function countInNeo4j(GraphStore $graph, string $label, array $filters = []): int
    {
        $cypher = "MATCH (n:{$label})";

        if (!empty($this->teamIds)) {
            $cypher .= "-[:BELONGS_TO_TEAM]->(t:Team) WHERE t.id IN \$teamIds";
        }

        // Add additional filters
        if (!empty($filters)) {
            $filterClauses = [];
            foreach ($filters as $field => $value) {
                $filterClauses[] = "n.{$field} = \${$field}";
            }
            $cypher .= (empty($this->teamIds) ? ' WHERE ' : ' AND ') . implode(' AND ', $filterClauses);
        }

        $cypher .= " RETURN count(n) as count";

        $params = array_merge(
            ['teamIds' => $this->teamIds],
            $filters
        );

        $result = $graph->query($cypher, $params);

        return $result[0]['count'] ?? 0;
    }

    /**
     * Search Qdrant with team filter
     */
    public function searchQdrant($vectorStore, string $collection, array $vector, int $limit = 10): array
    {
        return $vectorStore->search(
            $collection,
            $vector,
            $limit,
            $this->toQdrantFilter()
        );
    }
}
```

**Step 3: Commit**

```bash
git add src/Services/Security/TeamFilteredQuery.php tests/Unit/Services/Security/TeamFilteredQueryTest.php
git commit -m "feat(security): add TeamFilteredQuery for native Neo4j/Qdrant filtering"
```

---

### Task 6: Build Access-Aware Prompt Context

**Files:**
- Create: `src/Services/Security/PromptContextBuilder.php`
- Create: `tests/Unit/Services/Security/PromptContextBuilderTest.php`

**Step 1: Write the failing test**

```php
<?php

namespace Condoedge\Ai\Tests\Unit\Services\Security;

use Condoedge\Ai\Services\Security\PromptContextBuilder;
use Condoedge\Ai\Tests\TestCase;

class PromptContextBuilderTest extends TestCase
{
    /** @test */
    public function it_builds_prompt_with_access_tags(): void
    {
        $user = $this->createUserWithPermission('Person', 'read');

        $builder = new PromptContextBuilder($user);
        $prompt = $builder->buildAccessSection(['Person']);

        $this->assertStringContainsString('Person_team_count', $prompt);
        $this->assertStringContainsString('Person_team_details', $prompt);
    }

    /** @test */
    public function it_includes_threshold_instructions(): void
    {
        config(['ai.access_control.thresholds.Person' => 10]);

        $user = $this->createUserWithPermission('Person', 'read');

        $builder = new PromptContextBuilder($user);
        $prompt = $builder->buildAccessSection(['Person']);

        $this->assertStringContainsString('threshold', strtolower($prompt));
        $this->assertStringContainsString('10', $prompt);
        $this->assertStringContainsString('fewer than 10', $prompt);
    }

    /** @test */
    public function it_lists_restricted_sensible_columns(): void
    {
        $user = $this->createUserInTeam(1); // No sensibleColumns permission

        $builder = new PromptContextBuilder($user);
        $builder->setEntitySensibleColumns('Person', ['ssn', 'salary', 'date_of_birth']);
        $prompt = $builder->buildAccessSection(['Person']);

        $this->assertStringContainsString('RESTRICTED', $prompt);
        $this->assertStringContainsString('ssn', $prompt);
        $this->assertStringContainsString('salary', $prompt);
    }

    /** @test */
    public function it_allows_sensible_columns_with_permission(): void
    {
        $user = $this->createUserWithPermission('Person.sensibleColumns', 'read');

        $builder = new PromptContextBuilder($user);
        $builder->setEntitySensibleColumns('Person', ['ssn', 'salary']);
        $prompt = $builder->buildAccessSection(['Person']);

        $this->assertStringContainsString('Person_team_sensitive', $prompt);
        $this->assertStringNotContainsString('RESTRICTED', $prompt);
    }

    /** @test */
    public function it_includes_identifying_fields_warning(): void
    {
        config(['ai.access_control.identifying_fields.Person' => ['date_of_birth', 'email']]);

        $user = $this->createUserWithPermission('Person', 'read');

        $builder = new PromptContextBuilder($user);
        $prompt = $builder->buildAccessSection(['Person']);

        $this->assertStringContainsString('date_of_birth', $prompt);
        $this->assertStringContainsString('identifying', strtolower($prompt));
    }
}
```

**Step 2: Implement PromptContextBuilder**

```php
<?php

namespace Condoedge\Ai\Services\Security;

use Illuminate\Contracts\Auth\Authenticatable;

class PromptContextBuilder
{
    protected ?Authenticatable $user;
    protected AccessLevelResolver $resolver;
    protected array $entitySensibleColumns = [];

    public function __construct(?Authenticatable $user)
    {
        $this->user = $user;
        $this->resolver = app(AccessLevelResolver::class);
    }

    /**
     * Set sensible columns for an entity
     */
    public function setEntitySensibleColumns(string $entity, array $columns): self
    {
        $this->entitySensibleColumns[$entity] = $columns;
        return $this;
    }

    /**
     * Build the access control section for the prompt
     */
    public function buildAccessSection(array $entities): string
    {
        $sections = [];

        foreach ($entities as $entity) {
            $sections[] = $this->buildEntitySection($entity);
        }

        return implode("\n\n", $sections);
    }

    /**
     * Build access section for a single entity
     */
    protected function buildEntitySection(string $entity): string
    {
        $context = $this->resolver->buildContextForEntity($this->user, $entity);
        $tags = $context['access_tags'];
        $threshold = $context['threshold'];
        $sensibleColumns = $this->entitySensibleColumns[$entity] ?? $context['sensible_columns'];
        $identifyingFields = $context['identifying_fields'];

        $lines = [];
        $lines[] = "## Data Access for {$entity}";
        $lines[] = "";

        // Allowed access levels
        $lines[] = "ALLOWED:";
        foreach ($tags as $tag) {
            $lines[] = "- {$tag}: " . $this->describeTag($tag);
        }
        $lines[] = "";

        // Restricted items
        $hasSensitiveAccess = in_array("{$entity}_team_sensitive", $tags);
        if (!$hasSensitiveAccess && !empty($sensibleColumns)) {
            $lines[] = "RESTRICTED (no access to these fields):";
            $lines[] = "- " . implode(', ', $sensibleColumns);
            $lines[] = "- Do NOT include or reference these fields in responses";
            $lines[] = "";
        }

        // Threshold rules
        $hasFilteredAccess = in_array("{$entity}_team_filtered_count", $tags);
        if ($hasFilteredAccess) {
            $lines[] = "COUNT THRESHOLD: {$threshold}";
            $lines[] = "- For filtered counts below {$threshold}, say \"fewer than {$threshold}\"";
            $lines[] = "- Never reveal exact counts under {$threshold} (prevents identifying individuals)";
            $lines[] = "";
        }

        // Identifying fields warning
        if (!empty($identifyingFields) && $hasFilteredAccess) {
            $lines[] = "IDENTIFYING FILTERS: " . implode(', ', $identifyingFields);
            $lines[] = "- Queries using these fields are sensitive";
            $lines[] = "- Apply threshold rules strictly for these";
            $lines[] = "";
        }

        // Team context
        if (!empty($context['user_teams'])) {
            $lines[] = "USER'S TEAMS: IDs " . implode(', ', $context['user_teams']);
        }

        return implode("\n", $lines);
    }

    /**
     * Describe what an access tag means
     */
    protected function describeTag(string $tag): string
    {
        $descriptions = [
            'global_count' => 'Total counts across entire application',
            'team_count' => 'Counts within accessible teams',
            'team_filtered_count' => 'Counts with specific filters (threshold applies)',
            'team_details' => 'Individual record data (non-sensitive fields)',
            'team_sensitive' => 'Full record data including sensitive fields',
        ];

        foreach ($descriptions as $suffix => $description) {
            if (str_ends_with($tag, "_{$suffix}")) {
                return $description;
            }
        }

        return 'Access granted';
    }

    /**
     * Build complete prompt context including semantic results
     */
    public function buildFullContext(array $entities, array $semanticResults, array $aggregates = []): string
    {
        $parts = [];

        // Access control section
        $parts[] = $this->buildAccessSection($entities);

        // Aggregates section (if provided)
        if (!empty($aggregates)) {
            $parts[] = $this->buildAggregatesSection($aggregates);
        }

        // Semantic context section
        if (!empty($semanticResults)) {
            $parts[] = $this->buildSemanticSection($semanticResults);
        }

        return implode("\n\n---\n\n", $parts);
    }

    /**
     * Build aggregates section (pre-computed counts)
     */
    protected function buildAggregatesSection(array $aggregates): string
    {
        $lines = ["## Available Aggregates (use these for count questions)"];

        foreach ($aggregates as $key => $value) {
            $lines[] = "- {$key}: {$value}";
        }

        return implode("\n", $lines);
    }

    /**
     * Build semantic context section
     */
    protected function buildSemanticSection(array $results): string
    {
        $lines = ["## Semantic Context"];

        foreach ($results as $result) {
            $lines[] = "";
            $lines[] = "### {$result['entity_type']} (ID: {$result['entity_id']})";
            $lines[] = $result['content'] ?? '';
        }

        return implode("\n", $lines);
    }
}
```

**Step 3: Commit**

```bash
git add src/Services/Security/PromptContextBuilder.php tests/Unit/Services/Security/PromptContextBuilderTest.php
git commit -m "feat(security): add PromptContextBuilder for access-aware prompts"
```

---

### Task 7: Add Sync Triggers for Related Model Changes

**Files:**
- Create: `src/Observers/RelatedModelSyncObserver.php`
- Modify: `src/Providers/AiServiceProvider.php`
- Create: `tests/Unit/Observers/RelatedModelSyncObserverTest.php`

**Step 1: Write the failing test**

```php
<?php

namespace Condoedge\Ai\Tests\Unit\Observers;

use Condoedge\Ai\Observers\RelatedModelSyncObserver;
use Condoedge\Ai\Tests\TestCase;
use Condoedge\Ai\Facades\AI;

class RelatedModelSyncObserverTest extends TestCase
{
    /** @test */
    public function it_triggers_resync_when_person_team_is_created(): void
    {
        config(['ai.sync_triggers' => [
            'Person' => ['on_related' => ['PersonTeam']],
        ]]);

        $person = $this->createPerson();

        AI::shouldReceive('syncRelationships')
            ->with($person)
            ->once();

        // Create PersonTeam (should trigger Person resync)
        $personTeam = $this->createPersonTeam(['person_id' => $person->id, 'team_id' => 1]);
    }

    /** @test */
    public function it_triggers_resync_when_person_team_is_deleted(): void
    {
        config(['ai.sync_triggers' => [
            'Person' => ['on_related' => ['PersonTeam']],
        ]]);

        $person = $this->createPerson();
        $personTeam = $this->createPersonTeam(['person_id' => $person->id, 'team_id' => 1]);

        AI::shouldReceive('syncRelationships')
            ->with($person)
            ->once();

        $personTeam->delete();
    }

    /** @test */
    public function it_does_not_trigger_when_no_sync_triggers_configured(): void
    {
        config(['ai.sync_triggers' => []]);

        $person = $this->createPerson();

        AI::shouldReceive('syncRelationships')->never();

        $this->createPersonTeam(['person_id' => $person->id, 'team_id' => 1]);
    }
}
```

**Step 2: Implement RelatedModelSyncObserver**

```php
<?php

namespace Condoedge\Ai\Observers;

use Condoedge\Ai\Facades\AI;
use Illuminate\Database\Eloquent\Model;

class RelatedModelSyncObserver
{
    protected array $syncTriggers;

    public function __construct()
    {
        $this->syncTriggers = config('ai.sync_triggers', []);
    }

    /**
     * Handle model created event
     */
    public function created(Model $model): void
    {
        $this->triggerRelatedSync($model);
    }

    /**
     * Handle model updated event
     */
    public function updated(Model $model): void
    {
        $this->triggerRelatedSync($model);
    }

    /**
     * Handle model deleted event
     */
    public function deleted(Model $model): void
    {
        $this->triggerRelatedSync($model);
    }

    /**
     * Find and trigger syncs for related parent models
     */
    protected function triggerRelatedSync(Model $model): void
    {
        $modelClass = class_basename($model);

        foreach ($this->syncTriggers as $parentEntity => $config) {
            $relatedModels = $config['on_related'] ?? [];

            if (in_array($modelClass, $relatedModels)) {
                $this->syncParentEntity($model, $parentEntity, $config);
            }
        }
    }

    /**
     * Sync the parent entity when related model changes
     */
    protected function syncParentEntity(Model $relatedModel, string $parentEntity, array $config): void
    {
        // Determine the foreign key to find the parent
        $foreignKey = $config['foreign_key'] ?? strtolower($parentEntity) . '_id';
        $parentId = $relatedModel->getAttribute($foreignKey);

        if (!$parentId) {
            return;
        }

        // Resolve parent model class
        $parentClass = $this->resolveParentClass($parentEntity);
        if (!$parentClass) {
            return;
        }

        // Find and sync the parent
        $parent = $parentClass::find($parentId);
        if ($parent) {
            AI::syncRelationships($parent);
        }
    }

    /**
     * Resolve full class name for parent entity
     */
    protected function resolveParentClass(string $entity): ?string
    {
        $namespaces = config('ai.model_namespaces', ['App\\Models']);

        foreach ($namespaces as $namespace) {
            $fullClass = "{$namespace}\\{$entity}";
            if (class_exists($fullClass)) {
                return $fullClass;
            }
        }

        return null;
    }
}
```

**Step 3: Register observer in service provider**

```php
// In AiServiceProvider.php boot() method

public function boot(): void
{
    // Register sync trigger observers
    $this->registerSyncTriggerObservers();
}

protected function registerSyncTriggerObservers(): void
{
    $syncTriggers = config('ai.sync_triggers', []);
    $observer = new \Condoedge\Ai\Observers\RelatedModelSyncObserver();

    // Collect all related models that need observing
    $relatedModels = [];
    foreach ($syncTriggers as $parent => $config) {
        foreach ($config['on_related'] ?? [] as $related) {
            $relatedModels[] = $related;
        }
    }

    // Register observer for each unique related model
    foreach (array_unique($relatedModels) as $modelName) {
        $modelClass = $this->resolveModelClass($modelName);
        if ($modelClass) {
            $modelClass::observe($observer);
        }
    }
}
```

**Step 4: Add config section**

```php
// config/ai.php

'sync_triggers' => [
    // When PersonTeam changes, re-sync Person's team relationships
    'Person' => [
        'on_related' => ['PersonTeam'],
        'foreign_key' => 'person_id',
    ],

    // Add more as needed
    // 'Project' => [
    //     'on_related' => ['ProjectMember'],
    //     'foreign_key' => 'project_id',
    // ],
],
```

**Step 5: Commit**

```bash
git add src/Observers/RelatedModelSyncObserver.php src/Providers/AiServiceProvider.php config/ai.php tests/Unit/Observers/RelatedModelSyncObserverTest.php
git commit -m "feat(sync): add observers for related model changes triggering resync"
```

---

## Part 3: Extensibility Improvements

*(Keep Tasks 7-8 from original plan - Interface contracts)*

---

## Part 4: Simplification

*(Keep Tasks 9-10 from original plan - Config simplification)*

---

## Part 5: Configuration

### Task 10: Complete AI Config File

**Files:**
- Modify: `config/ai.php`

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Access Control
    |--------------------------------------------------------------------------
    */
    'access_control' => [
        // Default count threshold (don't reveal exact count if below this)
        'default_threshold' => env('AI_COUNT_THRESHOLD', 5),

        // Per-entity thresholds
        'thresholds' => [
            // 'Person' => 5,
            // 'Transaction' => 10,
            // 'HealthRecord' => 20,
        ],

        // Fields that make a count "filtered" (potentially identifying)
        'identifying_fields' => [
            '*' => ['date_of_birth', 'birth_date', 'dob', 'email', 'phone', 'ssn', 'address'],
            // 'Person' => ['gender', 'spoken_languages'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sync Triggers
    |--------------------------------------------------------------------------
    | Define which related model changes should trigger a resync of parent models.
    | This ensures Neo4j/Qdrant stay up-to-date when pivot tables change.
    */
    'sync_triggers' => [
        // 'Person' => [
        //     'on_related' => ['PersonTeam'],
        //     'foreign_key' => 'person_id',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Namespaces
    |--------------------------------------------------------------------------
    | Namespaces to search when resolving model classes from entity names.
    */
    'model_namespaces' => [
        'App\\Models',
        'App\\Models\\Crm',
        'App\\Models\\Teams',
    ],

    /*
    |--------------------------------------------------------------------------
    | Auth Integration
    |--------------------------------------------------------------------------
    */
    'auth_integration' => [
        'enabled' => env('AI_AUTH_ENABLED', true),
        'allow_anonymous' => env('AI_ALLOW_ANONYMOUS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto Discovery
    |--------------------------------------------------------------------------
    */
    'auto_discovery' => [
        'enabled' => env('AI_AUTO_DISCOVERY_ENABLED', true),
        'cache_enabled' => env('AI_DISCOVERY_CACHE', true),
        'cache_ttl' => env('AI_DISCOVERY_CACHE_TTL', 3600),
    ],
];
```

---

## Summary

### Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────────┐
│                              User Question                               │
└─────────────────────────────────┬───────────────────────────────────────┘
                                  │
                                  ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                        AccessLevelResolver                               │
│  • Checks user permissions via auth package                              │
│  • Resolves access tags: global_count, team_count, team_details, etc.   │
│  • Reads sensibleColumns from model                                      │
│  • Gets threshold per entity                                             │
└─────────────────────────────────┬───────────────────────────────────────┘
                                  │
                    ┌─────────────┴─────────────┐
                    ▼                           ▼
         ┌──────────────────┐        ┌──────────────────┐
         │     Qdrant       │        │     Neo4j        │
         │  (Semantic)      │        │  (Graph + Count) │
         ├──────────────────┤        ├──────────────────┤
         │ Filter by:       │        │ Filter by:       │
         │ _team_ids        │        │ BELONGS_TO_TEAM  │
         │ _owner_id        │        │ relationships    │
         └────────┬─────────┘        └────────┬─────────┘
                  │                           │
                  └─────────────┬─────────────┘
                                ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                        PromptContextBuilder                              │
│  • Builds access control section with tags                               │
│  • Adds threshold instructions                                           │
│  • Lists restricted sensibleColumns                                      │
│  • Includes semantic context                                             │
└─────────────────────────────────┬───────────────────────────────────────┘
                                  │
                                  ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                              LLM Response                                │
│  • AI self-limits based on access tags                                   │
│  • Applies threshold for filtered counts                                 │
│  • Excludes sensibleColumns without permission                           │
└─────────────────────────────────────────────────────────────────────────┘
```

### Access Levels

| Level | Tag Suffix | Requirement | What It Allows |
|-------|-----------|-------------|----------------|
| 0 | `_global_count` | None | Total counts, no filters |
| 1 | `_team_count` | Team member | Counts within user's teams |
| 2 | `_team_filtered_count` | READ permission | Filtered counts (threshold applies) |
| 3 | `_team_details` | READ permission | Record data (no sensitive fields) |
| 4 | `_team_sensitive` | sensibleColumns permission | Full data including sensitive |

### Key Features

1. **Native Neo4j/Qdrant Security** - Team filtering happens at query time, not post-processing
2. **sensibleColumns Integration** - Reads from existing auth package property
3. **Threshold Protection** - Prevents identifying individuals through specific counts
4. **Sync Triggers** - Keeps Neo4j/Qdrant in sync when related models change
5. **Multi-Level Access** - Granular control over what AI can reveal

---

**Plan complete. Ready for execution?**

**1. Subagent-Driven (this session)** - I dispatch fresh subagent per task, review between tasks

**2. Parallel Session (separate)** - Open new session with executing-plans skill
