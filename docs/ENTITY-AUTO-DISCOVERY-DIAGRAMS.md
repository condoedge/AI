# Entity Auto-Discovery - Visual Diagrams

## System Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────────────┐
│                         ELOQUENT MODEL                                  │
│  ┌──────────────────────────────────────────────────────────────────┐  │
│  │  class Customer extends Model implements Nodeable                │  │
│  │  {                                                                │  │
│  │      use HasNodeableConfig;                                       │  │
│  │                                                                    │  │
│  │      protected $fillable = ['name', 'email', 'status'];           │  │
│  │      protected $casts = ['created_at' => 'datetime'];             │  │
│  │      protected $table = 'customers';                              │  │
│  │                                                                    │  │
│  │      public function orders() {                                   │  │
│  │          return $this->hasMany(Order::class);                     │  │
│  │      }                                                             │  │
│  │                                                                    │  │
│  │      public function scopeActive($query) {                        │  │
│  │          return $query->where('status', 'active');                │  │
│  │      }                                                             │  │
│  │  }                                                                 │  │
│  └──────────────────────────────────────────────────────────────────┘  │
└─────────────────────────────┬───────────────────────────────────────────┘
                              │
                              │ getGraphConfig()
                              │ getVectorConfig()
                              ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                    HASNODEABLECONFIG TRAIT                              │
│  ┌──────────────────────────────────────────────────────────────────┐  │
│  │  Fallback Chain (Priority Order):                                │  │
│  │                                                                    │  │
│  │  1. Check for nodeableConfig() method (highest priority)         │  │
│  │     ↓ not found                                                   │  │
│  │  2. Check config/entities.php (legacy support)                   │  │
│  │     ↓ not found                                                   │  │
│  │  3. Auto-discover from model (default)                           │  │
│  └──────────────────────────────────────────────────────────────────┘  │
└─────────────────────────────┬───────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                   ENTITY AUTO-DISCOVERY SERVICE                         │
│  ┌──────────────────────────────────────────────────────────────────┐  │
│  │  discover(Model $entity): DiscoveredConfig                        │  │
│  │  {                                                                │  │
│  │      return cache->remember(key, function() {                    │  │
│  │          return new DiscoveredConfig(                             │  │
│  │              graph: discoverGraphConfig(),                        │  │
│  │              vector: discoverVectorConfig(),                      │  │
│  │              metadata: discoverMetadata()                         │  │
│  │          );                                                        │  │
│  │      });                                                           │  │
│  │  }                                                                 │  │
│  └──────────────────────────────────────────────────────────────────┘  │
└────┬──────────┬──────────┬───────────┬─────────────┬───────────────────┘
     │          │          │           │             │
     ▼          ▼          ▼           ▼             ▼
┌──────────┐┌──────────┐┌──────────┐┌──────────┐┌──────────────┐
│Property  ││Relation  ││Scope     ││Alias     ││EmbedField    │
│Discoverer││Discoverer││Discoverer││Generator ││Detector      │
└────┬─────┘└────┬─────┘└────┬─────┘└────┬─────┘└──────┬───────┘
     │           │           │           │             │
     │           │           │           │             │
     └───────────┴───────────┴───────────┴─────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                      DISCOVERED CONFIG (DTO)                            │
│  ┌──────────────────────────────────────────────────────────────────┐  │
│  │  GraphConfig:                                                     │  │
│  │    - label: "Customer"                                            │  │
│  │    - properties: ['id', 'name', 'email', 'status', ...]           │  │
│  │    - relationships: [BELONGS_TO_ORDER]                            │  │
│  │                                                                    │  │
│  │  VectorConfig:                                                    │  │
│  │    - collection: "customers"                                      │  │
│  │    - embed_fields: ['name', 'email']                              │  │
│  │    - metadata: ['id', 'status', 'created_at']                     │  │
│  │                                                                    │  │
│  │  Metadata:                                                        │  │
│  │    - aliases: ['customer', 'customers', 'client', 'clients']      │  │
│  │    - description: "Represents Customer entities"                  │  │
│  │    - scopes: ['active' => [...]]                                  │  │
│  └──────────────────────────────────────────────────────────────────┘  │
└─────────────────────────────┬───────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                      NEO4J / QDRANT STORAGE                             │
│  ┌──────────────────────┐              ┌──────────────────────┐        │
│  │   Neo4j Graph DB     │              │   Qdrant Vector DB   │        │
│  │                      │              │                      │        │
│  │  (:Customer)         │              │  Collection:         │        │
│  │    - id              │              │    customers         │        │
│  │    - name            │              │                      │        │
│  │    - email           │              │  Points:             │        │
│  │    - status          │              │    - vector          │        │
│  │                      │              │    - metadata        │        │
│  │  -[BELONGS_TO]->     │              │                      │        │
│  │    (Order)           │              │                      │        │
│  └──────────────────────┘              └──────────────────────┘        │
└─────────────────────────────────────────────────────────────────────────┘
```

---

## Discovery Process Flow

```
┌───────────┐
│   START   │
│ Customer  │
│   Model   │
└─────┬─────┘
      │
      ▼
┌─────────────────────────────────┐
│ Has nodeableConfig() method?    │
└─────┬─────────────────┬─────────┘
      │ YES             │ NO
      ▼                 ▼
┌─────────────┐   ┌──────────────────────┐
│ Return      │   │ Check config file    │
│ override    │   │ entities.php         │
│ config      │   └─────┬────────────┬───┘
└─────┬───────┘         │ EXISTS     │ NOT EXISTS
      │                 ▼            ▼
      │           ┌───────────┐  ┌──────────────────┐
      │           │ Return    │  │ AUTO-DISCOVERY   │
      │           │ legacy    │  │   (Default)      │
      │           │ config    │  └────────┬─────────┘
      │           └─────┬─────┘           │
      │                 │                 │
      └─────────────────┴─────────────────┘
                        │
                        ▼
              ┌─────────────────┐
              │ Check Cache     │
              └────┬────────┬───┘
                   │ HIT    │ MISS
                   ▼        ▼
            ┌──────────┐  ┌───────────────────────┐
            │ Return   │  │ Run Discovery         │
            │ cached   │  │                       │
            │ config   │  │ 1. Properties         │
            └────┬─────┘  │ 2. Relationships      │
                 │        │ 3. Scopes             │
                 │        │ 4. Aliases            │
                 │        │ 5. Embed Fields       │
                 │        └──────────┬────────────┘
                 │                   │
                 │                   ▼
                 │            ┌──────────────┐
                 │            │ Cache Result │
                 │            └──────┬───────┘
                 │                   │
                 └───────────────────┘
                          │
                          ▼
                 ┌─────────────────┐
                 │ Return          │
                 │ DiscoveredConfig│
                 └─────────────────┘
```

---

## Property Discovery Flow

```
┌──────────────┐
│ Start        │
│ Property     │
│ Discovery    │
└──────┬───────┘
       │
       ▼
┌──────────────────────────┐
│ Initialize:              │
│ properties = ['id']      │
└──────┬───────────────────┘
       │
       ▼
┌──────────────────────────┐
│ Add from $fillable       │
│ ['name', 'email', ...]   │
└──────┬───────────────────┘
       │
       ▼
┌──────────────────────────┐
│ Add from $casts          │
│ ['created_at', ...]      │
└──────┬───────────────────┘
       │
       ▼
┌──────────────────────────┐
│ Add from $dates          │
│ ['updated_at', ...]      │
└──────┬───────────────────┘
       │
       ▼
┌──────────────────────────┐
│ Add timestamps           │
│ (if $timestamps = true)  │
└──────┬───────────────────┘
       │
       ▼
┌──────────────────────────┐
│ Remove duplicates        │
└──────┬───────────────────┘
       │
       ▼
┌──────────────────────────┐
│ Exclude $hidden          │
└──────┬───────────────────┘
       │
       ▼
┌──────────────────────────┐
│ Exclude sensitive        │
│ (password, token, etc.)  │
└──────┬───────────────────┘
       │
       ▼
┌──────────────────────────┐
│ Return properties array  │
└──────────────────────────┘
```

---

## Relationship Discovery Flow

```
┌──────────────┐
│ Start        │
│ Relationship │
│ Discovery    │
└──────┬───────┘
       │
       ▼
┌──────────────────────────────────┐
│ Get all public methods           │
│ using Reflection                 │
└──────┬───────────────────────────┘
       │
       ▼
┌──────────────────────────────────┐
│ Filter:                          │
│ - No parameters                  │
│ - Not inherited                  │
│ - Not magic methods              │
└──────┬───────────────────────────┘
       │
       ▼
┌──────────────────────────────────┐
│ For each method:                 │
│   Invoke and check return type  │
└──────┬───────────────────────────┘
       │
       ▼
┌──────────────────────────────────┐
│ instanceof Relation?             │
└──────┬────────────────┬──────────┘
       │ NO             │ YES
       ▼                ▼
   ┌────────┐    ┌──────────────────┐
   │ Skip   │    │ Check type       │
   └────────┘    └──────┬───────────┘
                        │
                        ▼
              ┌─────────────────────────────┐
              │ instanceof BelongsTo?       │
              └──────┬──────────────┬───────┘
                     │ YES          │ NO
                     ▼              ▼
            ┌─────────────────┐  ┌────────┐
            │ Extract:        │  │ Skip   │
            │ - Foreign key   │  │ (only  │
            │ - Target model  │  │ belongsTo │
            │ - Target label  │  │ auto-  │
            │                 │  │ discovered)│
            │ Build           │  └────────┘
            │ RelationshipConfig│
            └────────┬────────┘
                     │
                     ▼
            ┌─────────────────────┐
            │ Generate type:      │
            │ BELONGS_TO_{NAME}   │
            └────────┬────────────┘
                     │
                     ▼
            ┌─────────────────────┐
            │ Add to results      │
            └─────────────────────┘
```

---

## Scope Discovery Flow

```
┌──────────────┐
│ Start        │
│ Scope        │
│ Discovery    │
└──────┬───────┘
       │
       ▼
┌──────────────────────────────────┐
│ Get all public methods           │
│ starting with "scope"            │
└──────┬───────────────────────────┘
       │
       ▼
┌──────────────────────────────────┐
│ For each scope method:           │
│   Extract scope name             │
│   scopeActive -> "active"        │
└──────┬───────────────────────────┘
       │
       ▼
┌──────────────────────────────────┐
│ Read method source code          │
└──────┬───────────────────────────┘
       │
       ▼
┌──────────────────────────────────┐
│ Try to parse simple where()      │
│ Pattern: ->where('field', 'val') │
└──────┬────────────────┬──────────┘
       │ MATCHED        │ NOT MATCHED
       ▼                ▼
┌─────────────┐    ┌──────────────────┐
│ Extract:    │    │ Create basic     │
│ - field     │    │ scope metadata   │
│ - value     │    │ (no filter)      │
│             │    └──────────────────┘
│ Build:      │
│ - filter    │
│ - cypher    │
└─────┬───────┘
      │
      ▼
┌──────────────────────────────────┐
│ Generate examples:               │
│ - "Show {scope} {entity}"        │
│ - "List {scope} {entity}"        │
│ - "How many {scope} {entity}?"   │
└──────┬───────────────────────────┘
       │
       ▼
┌──────────────────────────────────┐
│ Add to scopes array              │
└──────────────────────────────────┘
```

---

## Embed Field Detection Flow

```
┌──────────────┐
│ Start        │
│ Embed Field  │
│ Detection    │
└──────┬───────┘
       │
       ▼
┌──────────────────────────────────┐
│ Input: All discovered properties │
└──────┬───────────────────────────┘
       │
       ▼
┌──────────────────────────────────┐
│ For each property:               │
└──────┬───────────────────────────┘
       │
       ▼
┌──────────────────────────────────┐
│ Exclude if:                      │
│ - Ends with "_id" (foreign key)  │
│ - Ends with "_at" (date)         │
│ - Ends with "_date" (date)       │
│ - Equals "id"                    │
└──────┬────────────────┬──────────┘
       │ EXCLUDED       │ PASS
       ▼                ▼
   ┌────────┐    ┌──────────────────┐
   │ Skip   │    │ Check patterns   │
   └────────┘    └──────┬───────────┘
                        │
                        ▼
              ┌─────────────────────────────┐
              │ Matches text patterns?      │
              │ (name, title, description,  │
              │  notes, content, body, bio, │
              │  summary, details, ...)     │
              └──────┬──────────────┬───────┘
                     │ YES          │ NO
                     ▼              ▼
            ┌─────────────┐  ┌──────────────────┐
            │ Include     │  │ Check casts      │
            └─────────────┘  └──────┬───────────┘
                                    │
                                    ▼
                           ┌─────────────────────┐
                           │ Cast as text/string?│
                           └──────┬──────────┬───┘
                                  │ YES      │ NO
                                  ▼          ▼
                           ┌──────────┐  ┌────────┐
                           │ Include  │  │ Exclude│
                           └──────────┘  └────────┘
```

---

## Override with NodeableConfig

```
┌──────────────────────┐
│ Auto-Discovery       │
│ Base Config          │
└──────┬───────────────┘
       │
       ▼
┌──────────────────────────────────┐
│ NodeableConfig::discover($this)  │
│                                  │
│ Returns builder with base config │
└──────┬───────────────────────────┘
       │
       ▼
┌──────────────────────────────────┐
│ Apply fluent overrides:          │
│                                  │
│ ->addAlias('custom')             │
│ ->embedFields([...])             │
│ ->addRelationship(...)           │
│ ->addScope(...)                  │
└──────┬───────────────────────────┘
       │
       ▼
┌──────────────────────────────────┐
│ Merge strategy:                  │
│                                  │
│ - Full replace: label, collection│
│ - Array merge: aliases, scopes   │
│ - Add/remove: properties, fields │
└──────┬───────────────────────────┘
       │
       ▼
┌──────────────────────────────────┐
│ build()                          │
│                                  │
│ Returns final config array       │
└──────────────────────────────────┘
```

---

## Caching Strategy

```
┌─────────────────┐
│ Request Config  │
└────────┬────────┘
         │
         ▼
┌─────────────────────────────┐
│ Check Laravel Cache         │
│ Key: ai:discovery:{Class}   │
└────────┬─────────────┬──────┘
         │ HIT         │ MISS
         ▼             ▼
    ┌─────────┐   ┌──────────────────┐
    │ Return  │   │ Run Discovery    │
    │ cached  │   │                  │
    │ (5ms)   │   │ 1. Introspect    │
    └────┬────┘   │ 2. Parse         │
         │        │ 3. Build         │
         │        │ (100ms)          │
         │        └──────┬───────────┘
         │               │
         │               ▼
         │        ┌──────────────────┐
         │        │ Store in cache   │
         │        │ TTL: 1 hour      │
         │        └──────┬───────────┘
         │               │
         └───────────────┘
                  │
                  ▼
         ┌──────────────────┐
         │ Return Config    │
         └──────────────────┘

Cache invalidation triggers:
- Manual: php artisan ai:discover:clear
- TTL expiry: 1 hour default
- Model change: Optional hook
```

---

## CLI Command Flow

### ai:discover Command

```
┌────────────────────────────┐
│ php artisan ai:discover    │
│ Customer                   │
└──────────┬─────────────────┘
           │
           ▼
┌──────────────────────────────┐
│ Resolve Customer model       │
└──────────┬───────────────────┘
           │
           ▼
┌──────────────────────────────┐
│ Run auto-discovery           │
└──────────┬───────────────────┘
           │
           ▼
┌──────────────────────────────┐
│ Format output:               │
│                              │
│ Graph Config:                │
│   Label: Customer            │
│   Properties: [...]          │
│   Relationships: [...]       │
│                              │
│ Vector Config:               │
│   Collection: customers      │
│   Embed Fields: [...]        │
│   Metadata: [...]            │
│                              │
│ Metadata:                    │
│   Aliases: [...]             │
│   Scopes: [...]              │
└──────────┬───────────────────┘
           │
           ▼
┌──────────────────────────────┐
│ Display to console           │
└──────────────────────────────┘
```

### ai:discover:compare Command

```
┌────────────────────────────┐
│ php artisan                │
│ ai:discover:compare        │
│ Customer                   │
└──────────┬─────────────────┘
           │
           ▼
┌──────────────────────────────┐
│ Load config file             │
│ config/entities.php          │
└──────────┬───────────────────┘
           │
           ▼
┌──────────────────────────────┐
│ Run auto-discovery           │
└──────────┬───────────────────┘
           │
           ▼
┌──────────────────────────────┐
│ Compare:                     │
│ - Labels                     │
│ - Properties (count & diff)  │
│ - Relationships (count)      │
│ - Embed fields (count)       │
│ - Aliases                    │
└──────────┬───────────────────┘
           │
           ▼
┌──────────────────────────────┐
│ Format diff output:          │
│                              │
│ Config File | Discovered     │
│ ------------|-------------   │
│ Label: X    | X       ✓      │
│ Props: 6    | 7       ⚠      │
│   Missing: country           │
└──────────┬───────────────────┘
           │
           ▼
┌──────────────────────────────┐
│ Show recommendations         │
└──────────────────────────────┘
```

---

## Data Flow Example: Customer Entity

```
ELOQUENT MODEL
┌─────────────────────────────────┐
│ class Customer {                │
│   $fillable = ['name','email']  │
│   $table = 'customers'          │
│   belongsTo(Team)               │
│   scopeActive()                 │
│ }                               │
└────────────┬────────────────────┘
             │
             ▼
AUTO-DISCOVERY
┌─────────────────────────────────┐
│ Properties:                     │
│   ['id','name','email',         │
│    'created_at','updated_at']   │
│                                 │
│ Relationships:                  │
│   BELONGS_TO_TEAM → Team        │
│                                 │
│ Scopes:                         │
│   active: status = 'active'     │
│                                 │
│ Aliases:                        │
│   ['customer','customers',      │
│    'client','clients']          │
│                                 │
│ Embed Fields:                   │
│   ['name','email']              │
└────────────┬────────────────────┘
             │
             ▼
DISCOVERED CONFIG
┌─────────────────────────────────┐
│ GraphConfig {                   │
│   label: "Customer"             │
│   properties: [...]             │
│   relationships: [...]          │
│ }                               │
│                                 │
│ VectorConfig {                  │
│   collection: "customers"       │
│   embedFields: ['name','email'] │
│   metadata: ['id','created_at'] │
│ }                               │
│                                 │
│ Metadata {                      │
│   aliases: [...]                │
│   scopes: {...}                 │
│ }                               │
└────────────┬────────────────────┘
             │
             ▼
STORAGE
┌──────────────────┐  ┌──────────────────┐
│ Neo4j            │  │ Qdrant           │
│                  │  │                  │
│ (:Customer)      │  │ Collection:      │
│   -name          │  │   customers      │
│   -email         │  │                  │
│                  │  │ Vector[1536]:    │
│ -[BELONGS_TO]->  │  │   embedding      │
│   (:Team)        │  │                  │
└──────────────────┘  └──────────────────┘
```

---

## Migration Path Visualization

```
PHASE 1: Current State (Manual Config)
┌─────────────────────────────────────────┐
│ Model (20 lines)                        │
│ + Config File (50+ lines)               │
│ = 70+ total lines                       │
│                                         │
│ Problems:                               │
│ • Duplication                           │
│ • Manual maintenance                    │
│ • Easy to forget updates                │
└─────────────────────────────────────────┘

                    ↓
              [Add Auto-Discovery]
                    ↓

PHASE 2: Transitional State (Both Supported)
┌─────────────────────────────────────────┐
│ Model (20 lines)                        │
│ + Config File (50+ lines) [Optional]    │
│ or Auto-Discovery [Default]             │
│                                         │
│ Fallback Chain:                         │
│ 1. nodeableConfig() method              │
│ 2. config/entities.php                  │
│ 3. Auto-discovery                       │
└─────────────────────────────────────────┘

                    ↓
         [Gradual Entity Migration]
                    ↓

PHASE 3: Future State (Auto-Discovery)
┌─────────────────────────────────────────┐
│ Model (20 lines)                        │
│ = 20 total lines                        │
│                                         │
│ Benefits:                               │
│ • Zero duplication                      │
│ • Self-updating                         │
│ • Simple maintenance                    │
│                                         │
│ Optional:                               │
│ nodeableConfig() for overrides          │
└─────────────────────────────────────────┘
```

---

## Performance Comparison

```
WITHOUT CACHING
┌────────────────────────────────────────┐
│ Request 1: 100ms (introspection)       │
│ Request 2: 100ms (introspection)       │
│ Request 3: 100ms (introspection)       │
│ ...                                    │
│                                        │
│ Total: N × 100ms                       │
└────────────────────────────────────────┘

WITH CACHING
┌────────────────────────────────────────┐
│ Request 1: 100ms (introspection + cache)│
│ Request 2: 5ms   (cache hit)           │
│ Request 3: 5ms   (cache hit)           │
│ ...                                    │
│                                        │
│ Total: 100ms + (N-1) × 5ms             │
│                                        │
│ Improvement: ~95% faster after first   │
└────────────────────────────────────────┘

CACHE WARMING
┌────────────────────────────────────────┐
│ Deployment:                            │
│   php artisan ai:discover:cache        │
│   (100ms × M entities)                 │
│                                        │
│ Runtime:                               │
│   All requests: 5ms (cache hit)        │
│                                        │
│ Improvement: 95% faster always         │
└────────────────────────────────────────┘
```

---

These diagrams provide visual representations of the entire Entity Auto-Discovery system, from architecture to data flow to performance characteristics. Use them as reference during implementation and for explaining the system to stakeholders.
