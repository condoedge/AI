# Condoedge AI Package - Architecture Documentation

> Complete technical guide for developers working with the AI package.

---

## Table of Contents

1. [Overview](#overview)
2. [The 6 Phases](#the-6-phases)
3. [Phase 1: Configuration](#phase-1-configuration)
4. [Phase 2: Ingestion](#phase-2-ingestion)
5. [Phase 3: Context Retrieval](#phase-3-context-retrieval)
6. [Phase 4: Prompt Building](#phase-4-prompt-building)
7. [Phase 5: Query Generation](#phase-5-query-generation)
8. [Phase 6: Response](#phase-6-response)
9. [Code Structure](#code-structure)
10. [Developer Quick Start](#developer-quick-start)

---

## Overview

The AI package enables natural language queries against your Laravel application data using:
- **Neo4j** for graph relationships and scope patterns
- **Qdrant** for semantic vector search
- **LLMs** (Claude/GPT) for query generation and response formatting

```
┌─────────────┐    ┌─────────────┐    ┌─────────────┐
│ CONFIGURE   │───▶│   INGEST    │───▶│   QUERY     │
│ (One-time)  │    │ (Sync/Batch)│    │(Per Request)│
└─────────────┘    └─────────────┘    └─────────────┘
      │                   │                  │
      ▼                   ▼                  ▼
┌─────────────┐    ┌─────────────┐    ┌─────────────┐
│ Model props │    │ Neo4j nodes │    │ Context     │
│ nodeableConfig│  │ Qdrant vecs │    │ Prompt      │
│ entities.php│    │ Scope index │    │ Query → Ans │
└─────────────┘    └─────────────┘    └─────────────┘
```

---

## The 6 Phases

| Phase | Name | When | Purpose |
|-------|------|------|---------|
| 1 | Configuration | Setup | Define how entities are structured |
| 2 | Ingestion | Sync/Batch | Populate Neo4j and Qdrant |
| 3 | Context Retrieval | Per Question | Find relevant data |
| 4 | Prompt Building | Per Question | Build LLM prompt |
| 5 | Query Generation | Per Question | LLM generates query |
| 6 | Response | Per Question | Execute, filter, format |

---

## Phase 1: Configuration

**Purpose:** Define how your Eloquent models integrate with the AI system.

### Configuration Resolution Order (Layered)

```
┌─────────────────────────────────────────────────────────┐
│                  CONFIG RESOLUTION                       │
│              (later layers override earlier)             │
└─────────────────────────────────────────────────────────┘
                           │
     ┌─────────────────────┼─────────────────────┐
     ▼                     ▼                     ▼
┌──────────┐        ┌──────────────┐      ┌────────────┐
│ Layer 1  │        │   Layer 2    │      │  Layer 3   │
│ Model    │   +    │ nodeableConfig│  +  │entities.php│
│Properties│        │   Method     │      │  (Legacy)  │
└──────────┘        └──────────────┘      └────────────┘
     │                     │                     │
     └─────────────────────┼─────────────────────┘
                           ▼
                ┌─────────────────────┐
                │     Layer 4         │
                │  Auto-Discovery     │
                │ (fills missing)     │
                └─────────────────────┘
```

### Layer 1: Model Properties (Convention-Based)

```php
class Person extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = ['name', 'bio', 'email', 'ssn'];

    // Convention-based properties (optional)
    protected array $embedFields = ['name', 'bio'];        // For vector search
    protected string $graphLabel = 'Person';               // Neo4j node label
    protected array $sensibleColumns = ['ssn', 'salary'];  // Access-controlled
    protected array $nodeableAliases = ['person', 'people', 'individual'];
    protected array $graphRelationships = [
        ['name' => 'teams', 'type' => 'HAS_TEAM', 'target' => 'Team'],
    ];
}
```

**Supported Properties:**

| Property | Type | Purpose |
|----------|------|---------|
| `$embedFields` | `array` | Fields to embed for semantic search |
| `$graphLabel` | `string` | Neo4j node label (defaults to class name) |
| `$sensibleColumns` | `array` | Columns requiring special permission |
| `$nodeableAliases` | `array` | Alternative names for entity detection |
| `$graphRelationships` | `array` | Explicit relationship definitions |

### Layer 2: nodeableConfig() Method (Explicit Override)

```php
class Person extends Model implements Nodeable
{
    use HasNodeableConfig;

    public function nodeableConfig(): array
    {
        return [
            'graph' => [
                'label' => 'Person',
                'properties' => ['id', 'name', 'bio', 'email'],
                'relationships' => [
                    [
                        'name' => 'teams',
                        'type' => 'HAS_TEAM',
                        'target_entity' => 'Team',
                        'foreign_key' => 'team_id',
                    ],
                ],
            ],
            'vector' => [
                'collection' => 'persons',
                'embed_fields' => ['name', 'bio'],
            ],
            'security' => [
                'sensible_columns' => ['ssn', 'salary'],
                'team_resolution' => 'team_id',
            ],
            'metadata' => [
                'aliases' => ['person', 'people'],
                'description' => 'A person in the system',
            ],
        ];
    }
}
```

### Layer 3: config/entities.php (Legacy/Global)

```php
// config/entities.php
return [
    'Person' => [
        'graph' => [
            'label' => 'Person',
            'properties' => ['id', 'name', 'bio'],
        ],
        'vector' => [
            'embed_fields' => ['name', 'bio'],
        ],
    ],
];
```

### Layer 4: Auto-Discovery (Runtime)

When enabled, automatically discovers:
- Properties from `$fillable`, `$casts`, etc.
- Relationships from `belongsTo()`, `hasMany()`, etc.
- Scopes like `scopeActive()`, `scopeVolunteers()`

```php
// config/ai.php
'auto_discovery' => [
    'runtime_enabled' => true,  // Enable for zero-config
],
```

---

## Phase 2: Ingestion

**Purpose:** Populate Neo4j with nodes/relationships and Qdrant with embeddings.

### Data Flow

```
┌─────────────┐         ┌─────────────┐         ┌─────────────┐
│   Model     │────────▶│   Neo4j     │         │   Qdrant    │
│  (Person)   │         │  (Graph)    │         │  (Vectors)  │
└─────────────┘         └─────────────┘         └─────────────┘
       │                       │                       │
       ▼                       ▼                       ▼
┌─────────────┐         ┌─────────────┐         ┌─────────────┐
│ Properties  │         │ (:Person    │         │ collection: │
│ - id: 1     │         │  {id: 1,    │         │  persons    │
│ - name: John│         │   name: ... │         │             │
│ - bio: ...  │         │  })         │         │ vector[1536]│
└─────────────┘         └─────────────┘         └─────────────┘
       │                       │
       ▼                       ▼
┌─────────────┐         ┌─────────────┐
│Relationships│         │ Relationships│
│ - teams()   │────────▶│ -[:HAS_TEAM]│
│ - roles()   │         │ -[:HAS_ROLE]│
└─────────────┘         └─────────────┘
```

### Commands

```bash
# Discover entities and generate cache
php artisan ai:discover

# Create Neo4j nodes from entities
php artisan ai:ingest

# Create Neo4j relationships
php artisan ai:sync-rels

# Index Eloquent scopes as Cypher patterns
php artisan ai:index-scopes

# Generate embeddings for semantic search
php artisan ai:index-semantic
```

### Scope Discovery (Complex Scopes)

The system automatically converts Eloquent scopes to Cypher patterns:

```php
// Your Eloquent scope
public function scopeVolunteers($query)
{
    return $query->whereHas('personTeams', fn($q) => $q->volunteer());
}

public function scopeVolunteer($query)  // On PersonTeam model
{
    return $query->where('role_type', 3);
}
```

**Becomes this Cypher pattern:**

```cypher
MATCH (n:Person)-[:HAS_TEAM]->(t:PersonTeam)
WHERE t.role_type = 3
RETURN n
```

**How it works:**

1. `CypherScopeAdapter` finds scope methods
2. `CypherQueryBuilderSpy` records query builder calls
3. For nested scopes (like `$q->volunteer()`), `__call()` resolves them:
   - Gets related model from relationship
   - Finds scope method on related model
   - Executes with spy to capture calls
4. `CypherPatternGenerator` converts calls to Cypher

---

## Phase 3: Context Retrieval

**Purpose:** Find relevant data for the user's question.

### Flow

```
"Show me all active volunteers"
              │
              ▼
┌─────────────────────────────────────┐
│      1. Entity Detection            │
│  "volunteers" → Person, PersonTeam  │
└─────────────────────────────────────┘
              │
      ┌───────┴───────┐
      ▼               ▼
┌──────────────┐ ┌──────────────┐
│2A. Semantic  │ │2B. Scope     │
│   Search     │ │   Matching   │
│   (Qdrant)   │ │  (Patterns)  │
└──────────────┘ └──────────────┘
      │               │
      │ Similar       │ Matched:
      │ entities      │ scopeVolunteers
      │               │ + Cypher
      └───────┬───────┘
              ▼
┌─────────────────────────────────────┐
│      3. Access Control Filter       │
│  User permissions → Access Tags     │
└─────────────────────────────────────┘
              │
              ▼
┌─────────────────────────────────────┐
│      4. Aggregate Pre-calculation   │
│  - Total count: 150                 │
│  - Team count: 45                   │
│  - Filtered count: 12               │
└─────────────────────────────────────┘
```

### Access Control

```php
// AccessLevelResolver determines what user can see
$resolver = new AccessLevelResolver();
$tags = $resolver->resolveForEntity($user, 'Person');

// Returns based on permissions:
// Guest:     ['Person_global_count']
// Team Mem:  ['Person_global_count', 'Person_team_count']
// Has READ:  ['...', 'Person_team_filtered_count', 'Person_team_details']
// Has sens:  ['...', 'Person_team_sensitive']
```

### Access Levels

| Level | Tag | Requires | What AI Can Reveal |
|-------|-----|----------|-------------------|
| 0 | `global_count` | Nothing | "There are 150 people total" |
| 1 | `team_count` | Team member | "45 in your teams" |
| 2 | `team_filtered_count` | READ permission | "12 active volunteers" |
| 3 | `team_details` | READ permission | Names, emails, etc. |
| 4 | `team_sensitive` | sensibleColumns perm | SSN, salary, etc. |

---

## Phase 4: Prompt Building

**Purpose:** Construct the LLM prompt with context and access rules.

### Prompt Structure

```
┌─────────────────────────────────────────────────────────┐
│                    SYSTEM PROMPT                         │
├─────────────────────────────────────────────────────────┤
│ You are an AI assistant with access control.            │
│                                                          │
│ IMPORTANT RULES:                                         │
│ - Respect count thresholds                              │
│ - Never reveal sensibleColumns without permission       │
│ - Only access data within user's teams                  │
└─────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────┐
│                    CONTEXT SECTION                       │
├─────────────────────────────────────────────────────────┤
│ <context>                                                │
│   Semantic Results:                                      │
│   - Person(id:5): "John Doe is a software developer"    │
│   - Person(id:12): "Jane Smith works in marketing"      │
│                                                          │
│   Available Aggregates:                                  │
│   - Total Persons: 150                                   │
│   - Active Volunteers: 12                                │
│                                                          │
│   Matched Scopes:                                        │
│   - volunteers: MATCH (n:Person)-[:HAS_TEAM]->(t)...    │
│ </context>                                               │
└─────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────┐
│                   ACCESS RULES SECTION                   │
├─────────────────────────────────────────────────────────┤
│ <access_rules>                                           │
│   User Access Tags: [Person_team_details]               │
│                                                          │
│   THRESHOLD: 5                                           │
│   If filtered count < 5, say "fewer than 5"             │
│                                                          │
│   RESTRICTED COLUMNS: [ssn, salary]                      │
│   Never include these in responses                       │
│ </access_rules>                                          │
└─────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────┐
│                    USER MESSAGE                          │
├─────────────────────────────────────────────────────────┤
│ Show me all active volunteers                            │
└─────────────────────────────────────────────────────────┘
```

---

## Phase 5: Query Generation

**Purpose:** LLM generates the appropriate query based on context.

### Decision Flow

```
┌─────────────────────────────────────┐
│         LLM Analyzes Request        │
└─────────────────────────────────────┘
                   │
       ┌───────────┴───────────┐
       ▼                       ▼
┌──────────────┐        ┌──────────────┐
│ Pattern Match│        │ Generate New │
│ (Use Scope)  │        │   (Custom)   │
└──────────────┘        └──────────────┘
       │                       │
       ▼                       ▼
┌──────────────┐        ┌──────────────┐
│ Found:       │        │ LLM generates│
│scopeVolunteer│        │ Cypher:      │
│              │        │              │
│Use pre-indexed│       │ MATCH (p:Per-│
│Cypher pattern│        │ son)-[:HAS_  │
│              │        │ TEAM]->(t)   │
│              │        │ WHERE ...    │
└──────────────┘        └──────────────┘
```

---

## Phase 6: Response

**Purpose:** Execute query, filter results, format response.

### Flow

```
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│ Execute Query   │───▶│ Filter Results  │───▶│ Format Response │
└─────────────────┘    └─────────────────┘    └─────────────────┘
         │                      │                      │
         ▼                      ▼                      ▼
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│ Neo4j/MySQL     │    │ Apply:          │    │ LLM formats:    │
│ returns raw     │    │ - Team filter   │    │ natural language│
│ results         │    │ - Remove sens.  │    │ response        │
│                 │    │   columns       │    │                 │
│                 │    │ - Check thresh  │    │                 │
└─────────────────┘    └─────────────────┘    └─────────────────┘
```

### Threshold Protection

```php
// If filtered count < threshold
if ($count < $threshold) {
    return "There are fewer than {$threshold} {$entity}s matching your criteria";
}
// Otherwise show actual count
return "Found {$count} {$entity}s";
```

---

## Code Structure

```
src/
├── Domain/
│   ├── Contracts/
│   │   └── Nodeable.php                 # Interface for AI-enabled models
│   │
│   ├── Traits/
│   │   └── HasNodeableConfig.php        # Main trait (config resolution)
│   │       ├── readModelProperties()    # Reads $embedFields, etc.
│   │       ├── resolveConfig()          # 4-layer config merge
│   │       ├── getGraphConfig()         # Returns GraphConfig VO
│   │       └── getVectorConfig()        # Returns VectorConfig VO
│   │
│   └── ValueObjects/
│       ├── GraphConfig.php              # Neo4j config VO
│       ├── VectorConfig.php             # Qdrant config VO
│       └── NodeableConfig.php           # Fluent builder
│
├── Services/
│   ├── Discovery/
│   │   ├── EntityAutoDiscovery.php      # Main discovery orchestrator
│   │   ├── PropertyDiscoverer.php       # Discovers model properties
│   │   ├── RelationshipDiscoverer.php   # Discovers relationships
│   │   ├── CypherScopeAdapter.php       # Converts scopes to Cypher
│   │   ├── CypherQueryBuilderSpy.php    # Records QB calls
│   │   └── CypherPatternGenerator.php   # Generates Cypher
│   │
│   ├── Security/
│   │   ├── AccessLevelResolver.php      # Determines access tags
│   │   └── PromptContextBuilder.php     # Builds access-aware prompts
│   │
│   ├── Ingestion/
│   │   └── DataIngestionService.php     # Orchestrates ingestion
│   │
│   ├── Context/
│   │   └── ContextRetriever.php         # Retrieves relevant context
│   │
│   ├── Query/
│   │   ├── QueryGenerator.php           # LLM generates queries
│   │   └── QueryExecutor.php            # Executes & filters
│   │
│   └── Response/
│       └── ResponseGenerator.php        # Formats final response
│
├── Stores/
│   ├── Neo4j/
│   │   └── Neo4jStore.php               # Neo4j operations
│   └── Qdrant/
│       └── QdrantStore.php              # Qdrant operations
│
└── Console/Commands/
    ├── DiscoverEntitiesCommand.php      # ai:discover
    ├── IngestEntitiesCommand.php        # ai:ingest
    ├── SyncRelationshipsCommand.php     # ai:sync-rels
    ├── IndexScopesCommand.php           # ai:index-scopes
    └── IndexSemanticCommand.php         # ai:index-semantic
```

---

## Developer Quick Start

### 1. Minimum Setup (Zero-Config)

```php
// Just implement Nodeable and use the trait
class Person extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = ['name', 'bio', 'email'];
}
```

That's it! The system will:
- Auto-discover properties from `$fillable`
- Use class name as graph label
- Generate embeddings from all string fields

### 2. Recommended Setup

```php
class Person extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = ['name', 'bio', 'email', 'ssn'];

    // Be explicit about what to embed (better for semantic search)
    protected array $embedFields = ['name', 'bio'];

    // Protect sensitive data
    protected array $sensibleColumns = ['ssn'];

    // Define scopes for AI to use
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeVolunteers($query)
    {
        return $query->whereHas('personTeams', fn($q) => $q->volunteer());
    }
}
```

### 3. Run Ingestion

```bash
# First time or after model changes
php artisan ai:discover
php artisan ai:ingest
php artisan ai:sync-rels
php artisan ai:index-scopes
php artisan ai:index-semantic
```

### 4. Query

```php
$response = app('ai')->ask('Show me all active volunteers', $user);
```

---

## Key Concepts Summary

| Concept | Purpose | File |
|---------|---------|------|
| **Nodeable** | Interface marking AI-enabled models | `Domain/Contracts/Nodeable.php` |
| **HasNodeableConfig** | Main trait with config resolution | `Domain/Traits/HasNodeableConfig.php` |
| **CypherScopeAdapter** | Converts Eloquent scopes to Cypher | `Services/Discovery/CypherScopeAdapter.php` |
| **AccessLevelResolver** | Determines user's access tags | `Services/Security/AccessLevelResolver.php` |
| **PromptContextBuilder** | Builds prompts with access rules | `Services/Security/PromptContextBuilder.php` |

---

## Configuration Reference

See `config/ai.php` for all configuration options:

```php
'auto_discovery' => [
    'enabled' => true,           // Enable ai:discover command
    'runtime_enabled' => true,   // Enable runtime discovery (zero-config)
],

'access_control' => [
    'default_threshold' => 5,    // Minimum count before revealing numbers
    'thresholds' => [
        'Person' => 10,          // Entity-specific thresholds
    ],
    'identifying_fields' => [
        '*' => ['date_of_birth', 'email'],  // Fields that could identify people
    ],
],
```
