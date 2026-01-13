# AI Security Architecture Options

> **For Claude:** This document presents TWO security architecture options. The user will choose one, then use superpowers:executing-plans to implement the chosen option.

**Goal:** Implement security filtering for AI queries that respect team-based permissions.

**Context:** The AI package queries Neo4j/Qdrant which bypass Eloquent's HasSecurity global scopes. We need to ensure users only see data they have permission to access.

---

## Existing Auto-Discovery (Already Implemented)

The `RelationshipDiscoverer` already detects Team relationships with all the info we need:

```php
// Direct team_id relationship (from belongsTo)
[
    'type' => 'TEAM',
    'target_label' => 'Team',
    'foreign_key' => 'team_id',  // ← Direct column to filter!
    'direction' => 'outgoing',
    'inverse_type' => 'HAS_PEOPLE',
]

// Through pivot (Person → PersonTeam → Team)
// PersonTeam has:
[
    'type' => 'TEAM',
    'target_label' => 'Team',
    'foreign_key' => 'team_id',
    'direction' => 'outgoing',
]
```

**Key insight:** We don't need scopes or special security config - just look at `relationships` where `target_label === 'Team'` and use the `foreign_key`!

---

## Option A: Neo4j Relationship Traversal (Recommended)

### Overview

Filter directly in Neo4j by using the **existing relationships config** to find the path to Team. No new discovery needed - just read what's already there.

```
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│                        OPTION A: NEO4J RELATIONSHIP TRAVERSAL                            │
└─────────────────────────────────────────────────────────────────────────────────────────┘

     User Question: "Show me all invoices"
          │
          ▼
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│  STEP 1: GET USER'S ACCESSIBLE TEAMS                                                    │
│  ─────────────────────────────────────────────────────────────────────────────────────  │
│                                                                                         │
│  $authAdapter = app(AiAuthAdapterInterface::class);                                    │
│  $teamIds = $authAdapter->getAccessibleTeamIds($user, 'Invoice');                      │
│                                                                                         │
│  // Returns: [1, 3, 7] (teams where user has Invoice permission)                       │
└─────────────────────────────────────────────────────────────────────────────────────────┘
          │
          ▼
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│  STEP 2: FIND TEAM RELATIONSHIP FROM CONFIG                                             │
│  ─────────────────────────────────────────────────────────────────────────────────────  │
│                                                                                         │
│  $relationships = config("entities.Invoice.graph.relationships");                      │
│                                                                                         │
│  // Find relationship to Team                                                          │
│  $teamRel = collect($relationships)                                                    │
│      ->first(fn($r) => $r['target_label'] === 'Team');                                │
│                                                                                         │
│  // Result:                                                                             │
│  [                                                                                      │
│      'type' => 'BELONGS_TO_TEAM',                                                      │
│      'target_label' => 'Team',                                                         │
│      'foreign_key' => 'team_id',  // ← Use this!                                       │
│  ]                                                                                      │
│                                                                                         │
│  Three scenarios:                                                                       │
│  ┌─────────────────────────────────────────────────────────────────────────────────┐   │
│  │  1. Has foreign_key (team_id)  │  Filter: WHERE n.team_id IN $teamIds           │   │
│  │  2. Has relationship to Team   │  Filter: MATCH (n)-[:TYPE]->(t:Team)           │   │
│  │  3. No Team relationship       │  Configurable: deny or bypass                  │   │
│  └─────────────────────────────────────────────────────────────────────────────────┘   │
│                                                                                         │
└─────────────────────────────────────────────────────────────────────────────────────────┘
          │
          ▼
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│  STEP 3: BUILD CYPHER WITH TEAM FILTER                                                  │
│  ─────────────────────────────────────────────────────────────────────────────────────  │
│                                                                                         │
│  Original query:                                                                        │
│  MATCH (i:Invoice) RETURN i                                                            │
│                                                                                         │
│  With team filter injected:                                                             │
│                                                                                         │
│  // Option 1: Direct foreign_key (most common, most efficient)                         │
│  MATCH (i:Invoice)                                                                      │
│  WHERE i.team_id IN [1, 3, 7]                                                          │
│  RETURN i                                                                               │
│                                                                                         │
│  // Option 2: Relationship traversal (for pivots)                                      │
│  MATCH (p:Person)-[:BELONGS_TO_TEAM]->(t:Team)                                        │
│  WHERE t.id IN [1, 3, 7]                                                               │
│  RETURN p                                                                               │
│                                                                                         │
│  // Option 3: Through parent entity                                                    │
│  // InvoiceItem has no team_id, but Invoice does                                       │
│  // Find path: InvoiceItem -[:BELONGS_TO]-> Invoice (has team_id)                     │
│  MATCH (ii:InvoiceItem)-[:BELONGS_TO]->(i:Invoice)                                    │
│  WHERE i.team_id IN [1, 3, 7]                                                          │
│  RETURN ii                                                                              │
│                                                                                         │
└─────────────────────────────────────────────────────────────────────────────────────────┘
          │
          ▼
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│  STEP 4: HANDLE MISSING TEAM RELATIONSHIP                                               │
│  ─────────────────────────────────────────────────────────────────────────────────────  │
│                                                                                         │
│  If entity has no relationship to Team (directly or through parent):                   │
│                                                                                         │
│  1. LOG WARNING (always):                                                              │
│     Log::channel('ai-security')->warning(                                              │
│         "Entity {$entity} has no Team relationship in config",                         │
│         ['entity' => $entity, 'user_id' => $user->id, 'relationships' => $relationships]│
│     );                                                                                  │
│                                                                                         │
│  2. Apply configured behavior:                                                          │
│     Config: 'security.on_missing_team_path' => 'deny' | 'bypass'                       │
│                                                                                         │
│     'deny'   → Block query: "Cannot verify permissions for {entity}"                   │
│     'bypass' → Allow query without team filtering (for public/shared entities)         │
│                                                                                         │
└─────────────────────────────────────────────────────────────────────────────────────────┘
          │
          ▼
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│  STEP 5: INFERENCE PROTECTION                                                           │
│  ─────────────────────────────────────────────────────────────────────────────────────  │
│                                                                                         │
│  Count = 0  → "No results found matching your criteria."                               │
│  Count = 1  → "I cannot provide this information as it would identify an individual."  │
│  Count 2-4  → "Some results were found, but I cannot show exact count for privacy."    │
│  Count >= 5 → Return actual results with scope clarification                           │
│                                                                                         │
└─────────────────────────────────────────────────────────────────────────────────────────┘
```

### Team Path Resolution Logic

```php
// src/Services/Security/TeamPathResolver.php

class TeamPathResolver
{
    /**
     * Find how to filter this entity by team using relationships config.
     *
     * @return array{type: string, filter: string}|null
     *   type: 'direct' | 'relationship' | 'through_parent'
     *   filter: Cypher WHERE clause or MATCH pattern
     */
    public function resolve(string $entity, array $teamIds): ?array
    {
        $config = config("entities.{$entity}");
        $relationships = $config['graph']['relationships'] ?? [];

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
            // Relationship traversal filter
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

    protected function findTeamRelationship(array $relationships): ?array
    {
        return collect($relationships)
            ->first(fn($r) => ($r['target_label'] ?? null) === 'Team');
    }

    protected function findParentWithTeam(string $entity, array $relationships): ?array
    {
        foreach ($relationships as $rel) {
            if (($rel['direction'] ?? 'outgoing') !== 'outgoing') {
                continue;
            }

            $parentEntity = $rel['target_label'] ?? null;
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

    protected function buildDirectFilter(string $entity, string $foreignKey, array $teamIds): string
    {
        $alias = strtolower(substr($entity, 0, 1));
        $teamIdList = implode(', ', $teamIds);

        return "WHERE {$alias}.{$foreignKey} IN [{$teamIdList}]";
    }

    protected function buildRelationshipFilter(string $entity, string $relType, array $teamIds): string
    {
        $alias = strtolower(substr($entity, 0, 1));
        $teamIdList = implode(', ', $teamIds);

        return "MATCH ({$alias}:{$entity})-[:{$relType}]->(t:Team) WHERE t.id IN [{$teamIdList}]";
    }

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

### Abstract Auth Adapter

```php
// src/Contracts/AiAuthAdapterInterface.php

interface AiAuthAdapterInterface
{
    /**
     * Get team IDs where user has access to the given entity type.
     */
    public function getAccessibleTeamIds($user, string $entityType): array;

    /**
     * Check if user can access the given entity type on any team.
     */
    public function canAccessEntity($user, string $entityType): bool;

    /**
     * Check if security filtering is enabled.
     */
    public function isEnabled(): bool;
}

// src/Auth/KompoAuthAdapter.php

class KompoAuthAdapter implements AiAuthAdapterInterface
{
    public function getAccessibleTeamIds($user, string $entityType): array
    {
        if (!$this->isEnabled()) {
            return []; // Empty = no filtering (bypass mode)
        }

        // Use base entity permission (e.g., 'Invoice')
        return $user->getTeamsIdsWithPermission($entityType, PermissionTypeEnum::READ)
            ->toArray();
    }

    public function canAccessEntity($user, string $entityType): bool
    {
        if (!$this->isEnabled()) {
            return true;
        }

        return !empty($this->getAccessibleTeamIds($user, $entityType));
    }

    public function isEnabled(): bool
    {
        return config('ai.security.enabled', true);
    }
}
```

### Pros & Cons

| Pros | Cons |
|------|------|
| Uses existing relationships config | Must inject filter into generated Cypher |
| No new discovery needed | Must handle parent traversal |
| Single query - efficient | |
| No data duplication | |
| Accurate COUNT queries | |
| Abstract auth adapter (swappable) | |

---

## Option B: Eloquent Post-Filtering

### Overview

Query Neo4j for IDs only, then fetch actual data through Eloquent where HasSecurity global scope automatically filters by team. No changes to Neo4j queries.

```
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│                           OPTION B: ELOQUENT POST-FILTERING                              │
└─────────────────────────────────────────────────────────────────────────────────────────┘

     User Question: "Show me all invoices"
          │
          ▼
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│  STEP 1: GATE CHECK (Fast Fail)                                                         │
│  ─────────────────────────────────────────────────────────────────────────────────────  │
│                                                                                         │
│  Does user have Invoice permission on ANY team?                                         │
│  $teamIds = $user->getTeamsIdsWithPermission('Invoice', READ);                         │
│                                                                                         │
│  if ($teamIds->isEmpty()) → Block query, return "Access denied"                        │
└─────────────────────────────────────────────────────────────────────────────────────────┘
          │
          ▼
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│  STEP 2: QUERY EXECUTION (Unfiltered)                                                   │
│  ─────────────────────────────────────────────────────────────────────────────────────  │
│                                                                                         │
│  Neo4j query (no team filter):                                                          │
│  MATCH (i:Invoice) RETURN i.id, i.amount, i.date                                       │
│                                                                                         │
│  Returns ALL invoice IDs: [101, 102, 103, 104, 105, ...]                               │
│  (May include invoices from teams user can't access)                                   │
└─────────────────────────────────────────────────────────────────────────────────────────┘
          │
          ▼
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│  STEP 3: ELOQUENT SECURITY FILTER                                                       │
│  ─────────────────────────────────────────────────────────────────────────────────────  │
│                                                                                         │
│  $ids = [101, 102, 103, 104, 105];                                                     │
│  $modelClass = config("entities.Invoice.model");                                       │
│  $accessible = $modelClass::whereIn('id', $ids)->get();                                │
│                                                                                         │
│  HasSecurity global scope automatically adds:                                           │
│  WHERE team_id IN (1, 3, 7)  // User's accessible teams                                │
│                                                                                         │
│  Result: Only IDs [101, 103, 105] returned (102, 104 filtered out)                     │
└─────────────────────────────────────────────────────────────────────────────────────────┘
          │
          ▼
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│  STEP 4: COUNT QUERY HANDLING                                                           │
│  ─────────────────────────────────────────────────────────────────────────────────────  │
│                                                                                         │
│  For COUNT queries (MATCH (n:X) RETURN count(n)):                                      │
│                                                                                         │
│  Step A: Run fast COUNT to estimate size                                               │
│  MATCH (i:Invoice) RETURN count(i) → 50,000                                            │
│                                                                                         │
│  Step B: Decision based on threshold (default 5000)                                    │
│                                                                                         │
│  Total < 5000  → Transform to ID query, filter via Eloquent, count accurately         │
│  Total >= 5000 → Return "many records" with disclaimer                                 │
│                                                                                         │
└─────────────────────────────────────────────────────────────────────────────────────────┘
          │
          ▼
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│  STEP 5: INFERENCE PROTECTION (same as Option A)                                        │
└─────────────────────────────────────────────────────────────────────────────────────────┘
```

### Pros & Cons

| Pros | Cons |
|------|------|
| Simpler implementation | Two queries (Neo4j + Eloquent) |
| Leverages existing HasSecurity | Performance degrades for large sets |
| No changes to Neo4j queries | COUNT queries need special handling |
| Single source of truth | Large ID sets problematic (>65k) |
| | Can't filter relationships in Neo4j |

---

## Shared Components (Both Options)

### Multi-Query Request Handling

```
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│  MULTI-QUERY DETECTION & RESPONSE                                                       │
├─────────────────────────────────────────────────────────────────────────────────────────┤
│                                                                                         │
│  User: "Show me all invoices and list all customers and count all products"            │
│                                                                                         │
│  Detection: Query requires 3 separate Cypher queries                                   │
│                                                                                         │
│  Response:                                                                              │
│  ┌─────────────────────────────────────────────────────────────────────────────────┐   │
│  │  1. Execute FIRST query only (invoices)                                         │   │
│  │                                                                                  │   │
│  │  2. Return results with clarification:                                          │   │
│  │     "Here are your invoices. I noticed you also asked about customers           │   │
│  │      and products. Please ask about each separately for accurate results:       │   │
│  │      • 'List all customers'                                                     │   │
│  │      • 'Count all products'"                                                    │   │
│  └─────────────────────────────────────────────────────────────────────────────────┘   │
│                                                                                         │
└─────────────────────────────────────────────────────────────────────────────────────────┘
```

### Response Scope Clarification

```php
// All responses should clarify that results are permission-filtered

$scopePhrases = [
    'count' => 'Based on your permissions, you have access to {count} {entity}.',
    'list' => 'Here are the {entity} you have access to:',
    'large_dataset' => 'There are many {entity} in the system. Results shown are filtered based on your team permissions.',
    'no_results' => 'No {entity} found matching your criteria in your accessible teams.',
];
```

### Inference Protection

```php
// Identical for both options

$count = count($results);
$threshold = config('ai.security.inference_threshold', 5);

match (true) {
    $count === 0 => "No results found matching your criteria.",
    $count === 1 => "I cannot provide this information as it would identify a specific individual.",
    $count < $threshold => "Some results were found, but I cannot show the exact count due to privacy policies.",
    default => $results, // Return actual data
};
```

### Missing Team Path Logging

```php
// Both options should log when team path is missing

if ($teamPath === null) {
    Log::channel(config('ai.security.log_channel'))->warning(
        "Entity has no Team relationship in config",
        [
            'entity' => $entity,
            'user_id' => $user->id,
            'relationships' => $relationships,
            'action' => config('ai.security.on_missing_team_path'), // 'deny' or 'bypass'
        ]
    );
}
```

---

## Configuration

```php
// config/ai.php

'security' => [
    // Master switch - enabled by default
    'enabled' => true,

    // Which strategy to use
    'strategy' => 'neo4j',  // 'neo4j' (Option A) or 'eloquent' (Option B)

    // Auth adapter class (for abstraction)
    'auth_adapter' => \Kompo\Ai\Auth\KompoAuthAdapter::class,

    // What to do when entity has no Team relationship in config
    // 'deny'   → Block query with error message
    // 'bypass' → Allow query without team filtering
    'on_missing_team_path' => 'deny',

    // Inference protection
    'inference_threshold' => 5,
    'messages' => [
        'no_results' => 'No results found matching your criteria.',
        'single_result' => 'I cannot provide this information as it would identify a specific individual.',
        'below_threshold' => 'Some results were found, but I cannot show the exact count due to privacy policies.',
        'access_denied' => 'You do not have permission to query this type of data.',
        'missing_team_path' => 'Cannot verify permissions for this data type.',
    ],

    // Large dataset handling (Option B only)
    'large_dataset_threshold' => 5000,
    'large_dataset_message' => 'There are many records. Results shown are filtered based on your team permissions.',

    // Multi-query handling
    'split_multi_queries' => true,
    'multi_query_message' => 'I noticed you asked about multiple things. Please ask about each separately for accurate results.',

    // Response scope clarification
    'clarify_scope' => true,
    'scope_prefix' => 'Based on your permissions, ',

    // Logging
    'log_denied_access' => true,
    'log_inference_blocks' => true,
    'log_missing_team_path' => true,  // Always log missing paths
    'log_channel' => 'ai-security',   // null for default channel
],
```

---

## Decision Matrix

| Criteria | Option A (Neo4j Traversal) | Option B (Eloquent Filter) |
|----------|---------------------------|---------------------------|
| **Performance** | Excellent (single query) | Good for <5k, degrades for larger |
| **COUNT Accuracy** | Accurate always | Approximate for large sets |
| **Implementation** | Use existing relationships config | Post-process with Eloquent |
| **Data Flow** | Filter at source | Filter after fetch |
| **Large Datasets** | Handles well | Problematic (>65k IDs) |
| **Auth Abstraction** | Full (swappable adapters) | Coupled to HasSecurity |
| **Relationship Filtering** | Yes (in Neo4j) | No |

---

## Recommendation

### Option A: Neo4j Relationship Traversal

**Recommended because:**
1. Uses existing relationships config (no new discovery)
2. Just look for `target_label === 'Team'` and use `foreign_key`
3. Single query = better performance
4. Accurate counts always
5. Scales to large datasets
6. Abstract auth adapter for future flexibility

**Implementation effort:** Moderate
- Create `TeamPathResolver` to read relationships config
- Inject team filter into generated Cypher
- Create `AiAuthAdapterInterface` + `KompoAuthAdapter`
- Add logging for missing team paths

---

## Next Steps

1. **Confirm Option A** (or choose Option B)
2. I will create detailed implementation tasks
3. Execute using superpowers:executing-plans

**Which option do you prefer?**
