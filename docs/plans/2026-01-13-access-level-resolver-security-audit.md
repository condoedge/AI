# AccessLevelResolver Security Architecture Audit

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Audit and document how AccessLevelResolver integrates with the AI chat system, compare it to the ../auth package security model (HasSecurity plugin), and produce a comprehensive security improvement plan.

**Architecture:** The AI package uses AccessLevelResolver for row-level security filtering at query time, while the auth package uses HasSecurity plugin with scopes applied at model boot time. This audit will trace data flow from AiChatPanel through AiChatService and identify security gaps.

**Tech Stack:** PHP 8.x, Laravel, Neo4j (graph), Qdrant (vector), Kompo UI framework

---

## PHASE 1 - RAW FILE INVENTORY

[Inventory completed - see git history for full listing]

**Key Security Files Identified:**
- `src/Services/Security/AccessLevelResolver.php`
- `src/Services/Security/TeamFilteredQuery.php`
- `src/Services/Security/QueryResultFilter.php`
- `src/Services/Security/InputSanitizer.php`
- `src/Services/Security/PromptContextBuilder.php`
- `src/Services/Security/SensitiveDataSanitizer.php`
- `src/Policies/AiConversationPolicy.php`
- `../auth/src/Models/Plugins/HasSecurity.php`

---

## PHASE 2 - FILE-BY-FILE REVIEW

### FILE REVIEW: AccessLevelResolver.php
**Path:** `src/Services/Security/AccessLevelResolver.php`

1. **What this file does:**
   - Resolves multi-level access permissions for entities based on user permissions
   - Returns access "tags" that indicate what level of data access user has
   - 5 access levels: `global_count`, `team_count`, `team_filtered_count`, `team_details`, `team_sensitive`

2. **Inputs:**
   - `$user` (Authenticatable) - the authenticated user
   - `$entityClass` (string) - the model class to check permissions for
   - Optional config array with entity label and permission key overrides

3. **Outputs:**
   - Returns array of access tags (strings) the user has for the entity
   - Tags are used downstream to determine what data to expose

4. **Dependencies:**
   - `Illuminate\Contracts\Auth\Authenticatable`
   - Expects user model to have `hasPermission($key, $type)` method
   - Expects user model to have `getAccessibleTeamIds()` or `teams()` method

5. **Reference Status:** PARTIALLY REFERENCED
   - Called from `PromptContextBuilder::build()` to add access metadata to prompts
   - NOT called in the actual query execution pipeline
   - NOT integrated with Neo4j or Qdrant queries

6. **CRITICAL ANOMALY:**
   - This service computes what access a user SHOULD have
   - But it does NOT enforce that access - it only provides metadata
   - The actual query filtering relies on downstream consumers to use this metadata

---

### FILE REVIEW: TeamFilteredQuery.php
**Path:** `src/Services/Security/TeamFilteredQuery.php`

1. **What this file does:**
   - Provides helper methods to build team-filtered Cypher queries for Neo4j
   - Can build WHERE clauses that filter by team membership
   - Supports both direct team_id filtering and relationship-based filtering

2. **Inputs:**
   - `$user` (Authenticatable) - for team resolution
   - `$baseQuery` (string) - base Cypher query to augment
   - `$nodeAlias` (string) - the Cypher node alias to filter

3. **Outputs:**
   - Returns modified Cypher query string with team filtering applied

4. **Dependencies:**
   - User model with `getAccessibleTeamIds()` method
   - Neo4j graph store

5. **Reference Status:** PARTIALLY REFERENCED
   - Has methods available but NOT consistently called
   - NOT integrated into QueryExecutor or QueryGenerator

6. **ANOMALY:**
   - This is a utility class with good functionality
   - But it must be explicitly called - there's no automatic integration
   - The main query pipeline in `QueryGenerator`/`QueryExecutor` does NOT use this

---

### FILE REVIEW: QueryResultFilter.php
**Path:** `src/Services/Security/QueryResultFilter.php`

1. **What this file does:**
   - Filters query results AFTER execution based on user permissions
   - Acts as defense-in-depth (last line of defense)
   - Can filter out rows, mask sensitive fields, or redact values

2. **Inputs:**
   - `$results` (array) - raw query results
   - `$user` (Authenticatable) - for permission checking
   - `$accessLevel` (array) - access tags from AccessLevelResolver

3. **Outputs:**
   - Returns filtered array of results with unauthorized data removed

4. **Dependencies:**
   - `AccessLevelResolver` for determining access level
   - Model's `sensibleColumns` property for field masking

5. **Reference Status:** REFERENCED
   - Called from `AiChatService::filterSensitiveResults()`
   - Called from `AiManager::answerQuestion()`

6. **ANOMALY:**
   - This is POST-QUERY filtering - data has already been retrieved
   - If Neo4j returns 10,000 rows but user can only see 10, we still fetched 10,000
   - This is inefficient and potentially exposes data in logs/memory

---

### FILE REVIEW: HasSecurity.php (Auth Package)
**Path:** `../auth/src/Models/Plugins/HasSecurity.php`

1. **What this file does:**
   - Laravel model plugin that adds automatic security scopes
   - Registers `authUserHasPermissions` global scope at model boot time
   - ALL queries on the model automatically get team filtering applied
   - Uses TeamSecurityService, ReadSecurityService, etc.

2. **Inputs:**
   - Model using the trait
   - Current authenticated user
   - Model's security configuration (TEAM_ID_COLUMN, securityRelatedTeamIds())

3. **Outputs:**
   - Automatically modified queries with WHERE clauses for team filtering
   - Field-level protection (hidden fields, masked values)

4. **Key Methods:**
   - `bootHasSecurity()` - registers global scope
   - `scopeSecuritySplit()` - splits query by team access
   - `securityRelatedTeamIds()` - defines which teams own the record

5. **HOW IT DIFFERS FROM AI PACKAGE:**
   - **Auth package**: Security is AUTOMATIC at model boot time. Every query is filtered.
   - **AI package**: Security is MANUAL. Must explicitly call helpers.
   - **Auth package**: Uses Eloquent global scopes - queries are modified BEFORE execution
   - **AI package**: Uses post-query filtering - data is filtered AFTER retrieval
   - **Auth package**: Works with MySQL/PostgreSQL via Eloquent
   - **AI package**: Must work with Neo4j (Cypher) and Qdrant (REST API) - no Eloquent

---

### FILE REVIEW: AiChatService.php
**Path:** `src/Services/Chat/AiChatService.php`

1. **What this file does:**
   - Main orchestrator for AI chat functionality
   - Receives user questions, processes them, returns AI responses
   - Coordinates InputSanitizer, AI::answerQuestion(), result filtering

2. **Security Flow:**
   ```
   askWithConversation()
   ├── InputSanitizer::analyze() - checks for prompt injection
   ├── AI::answerQuestion() - generates Cypher, executes, gets results
   │   ├── fileContextProvider->getFileContext() - file access (has filtering)
   │   ├── queryGenerator->generate() - NO team filtering in Cypher
   │   ├── queryExecutor->execute() - NO team filtering
   │   └── responseGenerator->generate() - NO team filtering
   └── filterSensitiveResults() - POST-query filtering
   ```

3. **SECURITY GAP IDENTIFIED:**
   - `queryGenerator->generate()` creates Cypher WITHOUT team filtering
   - `queryExecutor->execute()` runs Cypher WITHOUT team filtering
   - Only `filterSensitiveResults()` applies team filtering AFTER data retrieval
   - If query returns 10,000 rows, all 10,000 are fetched before filtering

---

### FILE REVIEW: DataIngestionService.php
**Path:** `src/Services/DataIngestionService.php`

1. **What this file does:**
   - Ingests entities into both Neo4j (graph) and Qdrant (vector)
   - Stores team_ids in vector metadata for future filtering

2. **Security-relevant code (lines 669-691):**
   ```php
   // Add security metadata
   $metadata['_entity_type'] = class_basename($entity);
   $metadata['_entity_class'] = get_class($entity);
   $metadata['_team_ids'] = $this->resolveTeamIds($entity);

   // Add owner ID if available
   $ownerId = $entity->getAttribute('user_id') ?? $entity->getAttribute('owner_id');
   if ($ownerId) {
       $metadata['_owner_id'] = $ownerId;
   }
   ```

3. **Also creates BELONGS_TO_TEAM relationships in Neo4j (lines 1154-1198)**

4. **GOOD:** Security metadata IS stored during ingestion
5. **GAP:** This metadata is NOT used during queries

---

### FILE REVIEW: QdrantStore.php
**Path:** `src/VectorStore/QdrantStore.php`

1. **What this file does:**
   - REST API client for Qdrant vector database
   - Provides search, upsert, delete operations

2. **Search method (lines 108-137):**
   ```php
   public function search(
       string $collection,
       array $vector,
       int $limit = 10,
       array $filter = [],  // <-- Filter parameter exists but rarely used
       float $scoreThreshold = 0.0
   ): array
   ```

3. **SECURITY GAP:**
   - The `$filter` parameter CAN filter by `_team_ids`
   - But callers (SemanticContextSelector, etc.) don't pass team filters
   - The infrastructure exists but is not wired up

---

### FILE REVIEW: SemanticContextSelector.php
**Path:** `src/Services/SemanticContextSelector.php`

1. **What this file does:**
   - Selects relevant context from Qdrant based on semantic similarity
   - Used to determine which entities/scopes are relevant to a question

2. **Search call (lines 70-76):**
   ```php
   $results = $this->vectorStore->search(
       $collectionName,
       $questionEmbedding,
       $topK * 2,
       []  // <-- EMPTY FILTER - NO TEAM FILTERING
   );
   ```

3. **CRITICAL SECURITY GAP:**
   - This performs vector search WITHOUT any team filtering
   - Returns entities from ALL teams, not just user's accessible teams
   - Information leakage risk: user learns about entities they shouldn't know exist

---

## PHASE 3 - USAGE & FLOW TRACING

### Entry Point: AiChatPanel.php

```
User sends message via ChatMessageForm
    ↓
ChatMessageForm::sendMessage() [line 209]
    ↓
SendMessageService::sendMessage()
    ↓
AiChatService::askWithConversation() [line 48]
    ├── InputSanitizer::analyze() ← SECURITY: Prompt injection check ✓
    ├── AI::answerQuestion()
    │   ├── SemanticContextSelector::selectRelevantContext() ← NO TEAM FILTER ✗
    │   ├── ScopeSemanticMatcher::findMatchingScopes() ← NO TEAM FILTER ✗
    │   ├── FileContextProvider::getFileContext() ← HAS TEAM FILTER ✓
    │   ├── QueryGenerator::generate() ← NO TEAM FILTER ✗
    │   ├── QueryExecutor::execute() ← NO TEAM FILTER ✗
    │   └── ResponseGenerator::generate()
    └── filterSensitiveResults() ← POST-QUERY FILTER (defense in depth) ✓
```

### USAGE VERIFICATION: AccessLevelResolver

| Where SHOULD be called | Where IS called | Evidence |
|------------------------|-----------------|----------|
| QueryGenerator::generate() | NOT CALLED | No import, no usage |
| QueryExecutor::execute() | NOT CALLED | No import, no usage |
| SemanticContextSelector | NOT CALLED | No import, no usage |
| PromptContextBuilder | CALLED ✓ | Line 45 |
| filterSensitiveResults | CALLED ✓ | Via QueryResultFilter |

**Conclusion:** AccessLevelResolver is only used for metadata and post-filtering, NOT for pre-query filtering.

### USAGE VERIFICATION: TeamFilteredQuery

| Where SHOULD be called | Where IS called | Evidence |
|------------------------|-----------------|----------|
| QueryGenerator::generate() | NOT CALLED | No import |
| QueryExecutor::execute() | NOT CALLED | No import |
| AiManager::answerQuestion() | NOT CALLED | No import |

**Conclusion:** TeamFilteredQuery exists but is never used in the main query pipeline.

---

## PHASE 4 - FUNCTIONAL CATEGORIZATION

### CATEGORY: Pre-Query Security (INCOMPLETE)
Files that SHOULD filter data BEFORE it's retrieved:

| File | Status | Issue |
|------|--------|-------|
| TeamFilteredQuery.php | EXISTS BUT UNUSED | Not wired into query pipeline |
| AccessLevelResolver.php | METADATA ONLY | Computes access but doesn't enforce |

### CATEGORY: Query Execution (NO SECURITY)
Files that execute queries WITHOUT security filtering:

| File | Security Status |
|------|-----------------|
| QueryGenerator.php | NO team filtering in generated Cypher |
| QueryExecutor.php | NO team filtering before execution |
| SemanticContextSelector.php | NO team filtering in vector search |
| ScopeSemanticMatcher.php | NO team filtering in scope matching |
| QdrantStore.php | SUPPORTS filtering but callers don't use it |

### CATEGORY: Post-Query Security (DEFENSE IN DEPTH)
Files that filter AFTER data retrieval:

| File | Status |
|------|--------|
| QueryResultFilter.php | USED ✓ - filters results after query |
| SensitiveDataSanitizer.php | USED ✓ - sanitizes logs |
| AiChatService::filterSensitiveResults() | USED ✓ |

### CATEGORY: Input Security (COMPLETE)
Files that protect against malicious input:

| File | Status |
|------|--------|
| InputSanitizer.php | USED ✓ - prompt injection detection |
| CypherSanitizer.php | USED ✓ - Cypher injection prevention |

### CATEGORY: Data Ingestion Security (COMPLETE)
Files that store security metadata during ingestion:

| File | Status |
|------|--------|
| DataIngestionService.php | STORES `_team_ids` in Qdrant ✓ |
| DataIngestionService.php | CREATES `BELONGS_TO_TEAM` in Neo4j ✓ |

---

## PHASE 5 - SECURITY ARCHITECTURE COMPARISON

### Auth Package (HasSecurity) - How It Works

```php
// In model boot:
static::addGlobalScope('authUserHasPermissions', function ($query) {
    $user = auth()->user();
    if ($user && !SecurityBypassService::isBypassed()) {
        $teamIds = $user->getAccessibleTeamIds();
        $query->whereIn('team_id', $teamIds);  // Applied BEFORE query execution
    }
});
```

**Key Features:**
1. **Automatic** - No manual calls needed
2. **Pre-query** - Filtering happens in SQL WHERE clause
3. **Efficient** - Database only returns authorized rows
4. **Global scope** - Applies to ALL queries on the model

### AI Package (AccessLevelResolver) - Current State

```php
// In AiChatService:
$results = AI::answerQuestion($question);  // Returns ALL data
$filtered = $this->filterSensitiveResults($results, $user);  // Filters AFTER
```

**Current State:**
1. **Manual** - Must explicitly call filtering
2. **Post-query** - Filtering happens after data retrieval
3. **Inefficient** - Database returns ALL rows, then we filter
4. **Not enforced** - Easy to forget to call

### The Challenge

The auth package works because:
- MySQL/PostgreSQL queries go through Eloquent
- Eloquent supports global scopes
- Scopes modify the query builder before SQL execution

The AI package faces challenges because:
- Neo4j queries use Cypher (not SQL)
- Qdrant queries use REST API
- Neither has "global scope" concept
- Must manually modify queries

---

## IMPROVEMENT PLAN

### Task 1: Create QuerySecurityMiddleware

**Goal:** Intercept ALL Neo4j queries and inject team filtering automatically.

**Files:**
- Create: `src/Services/Security/QuerySecurityMiddleware.php`
- Modify: `src/Services/QueryExecutor.php:45-60`
- Test: `tests/Unit/Services/Security/QuerySecurityMiddlewareTest.php`

**Step 1: Write the failing test**

```php
public function test_middleware_injects_team_filter_into_cypher()
{
    $user = $this->createUserWithTeams([1, 2, 3]);
    $middleware = new QuerySecurityMiddleware($user);

    $query = "MATCH (p:Person) RETURN p";
    $secured = $middleware->secure($query, 'Person');

    $this->assertStringContainsString('BELONGS_TO_TEAM', $secured);
    $this->assertStringContainsString('team_id IN [1, 2, 3]', $secured);
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Services/Security/QuerySecurityMiddlewareTest.php`
Expected: FAIL with "Class not found"

**Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Security;

use Illuminate\Contracts\Auth\Authenticatable;

class QuerySecurityMiddleware
{
    public function __construct(
        private readonly ?Authenticatable $user,
        private readonly AccessLevelResolver $accessResolver
    ) {}

    public function secure(string $cypherQuery, string $primaryLabel): string
    {
        if (!$this->user) {
            throw new \RuntimeException('User required for secured queries');
        }

        $teamIds = $this->resolveTeamIds();
        if (empty($teamIds)) {
            return $this->denyAllQuery($primaryLabel);
        }

        return $this->injectTeamFilter($cypherQuery, $primaryLabel, $teamIds);
    }

    private function resolveTeamIds(): array
    {
        if (method_exists($this->user, 'getAccessibleTeamIds')) {
            return $this->user->getAccessibleTeamIds();
        }
        if (method_exists($this->user, 'teams')) {
            return $this->user->teams()->pluck('id')->toArray();
        }
        return [];
    }

    private function injectTeamFilter(string $query, string $label, array $teamIds): string
    {
        // Find the MATCH clause and add team filter
        $alias = $this->extractPrimaryAlias($query, $label);
        $teamIdList = implode(', ', $teamIds);

        $teamFilter = "({$alias})-[:BELONGS_TO_TEAM]->(t:Team) WHERE t.id IN [{$teamIdList}]";

        // Inject after MATCH clause
        return preg_replace(
            '/MATCH\s*\(([^)]+):' . $label . '\)/i',
            "MATCH ($1:{$label}), {$teamFilter}",
            $query
        );
    }

    private function extractPrimaryAlias(string $query, string $label): string
    {
        if (preg_match('/\((\w+):' . $label . '\)/i', $query, $matches)) {
            return $matches[1];
        }
        return 'n';
    }

    private function denyAllQuery(string $label): string
    {
        return "MATCH (n:{$label}) WHERE false RETURN n LIMIT 0";
    }
}
```

**Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Services/Security/QuerySecurityMiddlewareTest.php`
Expected: PASS

---

### Task 2: Integrate QuerySecurityMiddleware into QueryExecutor

**Files:**
- Modify: `src/Services/QueryExecutor.php`
- Test: `tests/Unit/Services/QueryExecutorTest.php`

**Step 1: Write the failing test**

```php
public function test_executor_applies_security_middleware_when_user_provided()
{
    $user = $this->createUserWithTeams([1, 2]);
    $executor = new QueryExecutor($this->graphStore, $user);

    // This query should be automatically secured
    $results = $executor->execute("MATCH (p:Person) RETURN p");

    // Verify only team 1 and 2 data returned
    foreach ($results as $row) {
        $this->assertContains($row['team_id'], [1, 2]);
    }
}
```

**Step 2: Modify QueryExecutor to use middleware**

```php
// In QueryExecutor::execute()
public function execute(string $query, array $params = [], array $options = []): array
{
    // Apply security middleware if user is set
    if ($this->user && !($options['bypass_security'] ?? false)) {
        $primaryLabel = $this->extractPrimaryLabel($query);
        $query = $this->securityMiddleware->secure($query, $primaryLabel);
    }

    // Continue with execution...
}
```

---

### Task 3: Add Team Filtering to Qdrant Vector Search

**Files:**
- Modify: `src/Services/SemanticContextSelector.php:70-76`
- Test: `tests/Unit/Services/SemanticContextSelectorTest.php`

**Step 1: Write the failing test**

```php
public function test_vector_search_filters_by_user_teams()
{
    $user = $this->createUserWithTeams([1, 2]);
    $selector = new SemanticContextSelector($this->vectorStore, $this->embedder, ['user' => $user]);

    $results = $selector->selectRelevantContext("Show me all people", $entityConfigs);

    // Should only return entities from teams 1 and 2
    foreach ($results['entities'] as $entity) {
        $teamIds = $entity['config']['_team_ids'] ?? [];
        $this->assertNotEmpty(array_intersect($teamIds, [1, 2]));
    }
}
```

**Step 2: Modify SemanticContextSelector to pass team filter**

```php
// In selectRelevantContext()
$teamIds = $this->resolveUserTeamIds();
$filter = empty($teamIds) ? [] : [
    'must' => [
        [
            'key' => '_team_ids',
            'match' => ['any' => $teamIds]
        ]
    ]
];

$results = $this->vectorStore->search(
    $collectionName,
    $questionEmbedding,
    $topK * 2,
    $filter  // Now passes team filter
);
```

---

### Task 4: Create SecuredAiManager Wrapper

**Goal:** Provide a secure-by-default entry point that cannot be bypassed.

**Files:**
- Create: `src/Services/SecuredAiManager.php`
- Test: `tests/Unit/Services/SecuredAiManagerTest.php`

**Step 1: Write the failing test**

```php
public function test_secured_manager_requires_user()
{
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Authenticated user required');

    $manager = new SecuredAiManager($this->aiManager);
    $manager->answerQuestion("Show me people", ['user' => null]);
}

public function test_secured_manager_enforces_team_filtering()
{
    $user = $this->createUserWithTeams([1]);
    $manager = new SecuredAiManager($this->aiManager);

    $result = $manager->answerQuestion("Show me all people", ['user' => $user]);

    // All returned data must belong to team 1
    foreach ($result['data'] as $row) {
        $this->assertEquals(1, $row['team_id']);
    }
}
```

**Step 2: Write implementation**

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

use Illuminate\Contracts\Auth\Authenticatable;

class SecuredAiManager
{
    public function __construct(
        private readonly AiManager $aiManager,
        private readonly QuerySecurityMiddleware $securityMiddleware
    ) {}

    public function answerQuestion(string $question, array $options = []): array
    {
        $user = $options['user'] ?? auth()->user();

        if (!$user instanceof Authenticatable) {
            throw new \RuntimeException('Authenticated user required for AI queries');
        }

        // Inject security context
        $options['security_context'] = [
            'user' => $user,
            'team_ids' => $this->resolveTeamIds($user),
            'access_levels' => $this->accessResolver->resolve($user, $options['entity_class'] ?? null),
        ];

        return $this->aiManager->answerQuestion($question, $options);
    }

    private function resolveTeamIds(Authenticatable $user): array
    {
        if (method_exists($user, 'getAccessibleTeamIds')) {
            return $user->getAccessibleTeamIds();
        }
        return [];
    }
}
```

---

### Task 5: Update AiChatService to Use SecuredAiManager

**Files:**
- Modify: `src/Services/Chat/AiChatService.php`
- Modify: `src/AiServiceProvider.php`

**Step 1: Update service provider bindings**

```php
// In AiServiceProvider::register()
$this->app->bind(AiManagerInterface::class, function ($app) {
    $baseManager = new AiManager(...);
    return new SecuredAiManager($baseManager, $app->make(QuerySecurityMiddleware::class));
});
```

**Step 2: Update AiChatService to require user**

```php
// In AiChatService::askWithConversation()
public function askWithConversation(string $question, AiConversation $conversation, array $options = []): array
{
    $user = $options['user'] ?? auth()->user();

    if (!$user) {
        throw new \RuntimeException('Authenticated user required for chat');
    }

    // User is now REQUIRED, not optional
    $options['user'] = $user;

    // Continue...
}
```

---

### Task 6: Add Integration Tests

**Files:**
- Create: `tests/Integration/SecurityIntegrationTest.php`

**Test cases:**

```php
public function test_user_cannot_see_other_team_data_via_chat()
{
    // Create user in team 1
    $user = User::factory()->create();
    $user->teams()->attach(1);

    // Create data in team 2
    Person::factory()->create(['team_id' => 2, 'name' => 'Secret Person']);

    // Query via chat
    $response = $this->actingAs($user)
        ->post('/ai/chat', ['message' => 'Show me all people']);

    // Should not see team 2 data
    $this->assertStringNotContainsString('Secret Person', $response->json('answer'));
}

public function test_user_can_see_own_team_data()
{
    $user = User::factory()->create();
    $user->teams()->attach(1);

    Person::factory()->create(['team_id' => 1, 'name' => 'My Person']);

    $response = $this->actingAs($user)
        ->post('/ai/chat', ['message' => 'Show me all people']);

    $this->assertStringContainsString('My Person', $response->json('answer'));
}

public function test_vector_search_respects_team_boundaries()
{
    $user = User::factory()->create();
    $user->teams()->attach(1);

    // Index data from different teams
    $this->ingestEntity(Person::factory()->create(['team_id' => 1]));
    $this->ingestEntity(Person::factory()->create(['team_id' => 2]));

    // Semantic search should only return team 1 data
    $selector = app(SemanticContextSelector::class);
    $results = $selector->selectRelevantContext('people', $configs, ['user' => $user]);

    foreach ($results['entities'] as $entity) {
        $this->assertContains(1, $entity['_team_ids']);
    }
}
```

---

## SUMMARY: SECURITY GAP ANALYSIS

### Current State (VULNERABLE)

```
┌─────────────────────────────────────────────────────────────────┐
│                        CURRENT FLOW                              │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  User Question                                                   │
│       │                                                          │
│       ▼                                                          │
│  InputSanitizer ──────────────── ✓ Blocks injection             │
│       │                                                          │
│       ▼                                                          │
│  SemanticContextSelector ──────── ✗ NO TEAM FILTER              │
│       │                           Returns ALL entities           │
│       ▼                                                          │
│  QueryGenerator ──────────────── ✗ NO TEAM FILTER               │
│       │                           Generates unfiltered Cypher    │
│       ▼                                                          │
│  QueryExecutor ──────────────── ✗ NO TEAM FILTER                │
│       │                           Executes against ALL data      │
│       ▼                                                          │
│  QueryResultFilter ───────────── ✓ POST-QUERY filter            │
│       │                           (defense in depth only)        │
│       ▼                                                          │
│  Response                                                        │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### Target State (SECURE)

```
┌─────────────────────────────────────────────────────────────────┐
│                        TARGET FLOW                               │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  User Question                                                   │
│       │                                                          │
│       ▼                                                          │
│  SecuredAiManager ───────────── ✓ REQUIRES authenticated user   │
│       │                                                          │
│       ▼                                                          │
│  InputSanitizer ──────────────── ✓ Blocks injection             │
│       │                                                          │
│       ▼                                                          │
│  SemanticContextSelector ──────── ✓ FILTERS by _team_ids        │
│       │                           Only returns user's entities   │
│       ▼                                                          │
│  QuerySecurityMiddleware ──────── ✓ INJECTS team filter         │
│       │                           Modifies Cypher before exec    │
│       ▼                                                          │
│  QueryExecutor ──────────────── ✓ Executes FILTERED query       │
│       │                           Only retrieves authorized data │
│       ▼                                                          │
│  QueryResultFilter ───────────── ✓ Defense in depth             │
│       │                           Double-checks filtering        │
│       ▼                                                          │
│  Response                                                        │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

---

## IMPLEMENTATION PRIORITY

| Task | Priority | Risk Mitigated | Effort |
|------|----------|----------------|--------|
| Task 1: QuerySecurityMiddleware | HIGH | Data leakage via Neo4j | 4 hours |
| Task 2: Integrate into QueryExecutor | HIGH | Unfiltered query execution | 2 hours |
| Task 3: Qdrant team filtering | HIGH | Vector search data leakage | 2 hours |
| Task 4: SecuredAiManager | MEDIUM | Accidental security bypass | 3 hours |
| Task 5: Update AiChatService | MEDIUM | Entry point hardening | 1 hour |
| Task 6: Integration tests | HIGH | Regression prevention | 4 hours |

**Total Estimated Effort:** 16 hours

---

## EXECUTION

**Plan complete and saved to `docs/plans/2026-01-13-access-level-resolver-security-audit.md`.**

Two execution options:

1. **Subagent-Driven (this session)** - I dispatch fresh subagent per task, review between tasks, fast iteration

2. **Parallel Session (separate)** - Open new session with executing-plans, batch execution with checkpoints

Which approach?
