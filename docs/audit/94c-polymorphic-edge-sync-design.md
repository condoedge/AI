# Polymorphic Edge Sync (94c) — Design

**Gap:** polymorphic (`morphTo`) relationships never materialize as graph edges. Discovery
emits the sentinel target label `_polymorphic` (`src/Services/Discovery/RelationshipDiscoverer.php:416`),
and every sync path then asks Neo4j for a node labeled `_polymorphic` — which can never
exist — so the edge is silently skipped on every row, forever. In CondoEdge this leaves
**Price completely edge-isolated** (its only edge, `PRICEABLE`, targets `_polymorphic`),
kills `File-[:PARENT]->X`, `Invoice-[:CUSTOMER]->X`, `Task TASKABLE`, `Note/Notification ABOUT`
and at least 12 other declared edges (§6.1 inventory), and forced tonight's property-join
workarounds in `CondoEdge/config/ai-entities/billing.php:381,432,484`
(`p.priceable_type = 'service' AND p.priceable_id = s.id`). This was flagged as the
real fix twice in `96-condoedge-recommended-questions.md:106-107` ("Needs a sync-layer
change (resolve the morph)" / "Real fix is sync-layer polymorphic resolution").

**The fix in one line:** at sync time, when a relationship's target label is
`_polymorphic`, resolve the **real** target label per row from the row's morph-type
column value (morph map → model class → entity-catalog label), then proceed exactly as a
normal edge. Unresolvable rows skip and log — never throw.

Every `file:line` below was verified against current source while writing this document.

---

## 0. Ground truth (what exists today, verified)

| Concern | Where | Fact |
|---|---|---|
| Sentinel produced | `src/Services/Discovery/RelationshipDiscoverer.php:412-422` | The `MorphTo` branch returns `'target_label' => '_polymorphic'` (`:416`), `'foreign_key' => $relation->getForeignKeyName()` (`:417`, e.g. `priceable_id`), and — crucially — **already captures the morph-type column**: `'morph' => $relation->getMorphType()` (`:418`, e.g. `priceable_type`), plus `'polymorphic_inverse' => true` (`:420`) |
| Morph key also emitted for owned morphs | `RelationshipDiscoverer.php:379-395, 397-410` | `MorphMany/MorphOne/MorphToMany` emit `'morph'` as the **base** name without `_type` (`:384, :390, :402, :407` — e.g. `'priceable'`); only the `MorphTo` branch stores the full column name. Normalization is required if the key is ever read |
| Morph key is dropped at hydration | `src/Domain/ValueObjects/RelationshipConfig.php:47-61` | `fromArray()` keeps only `type`, `target_label`, `foreign_key`, `properties` — `morph`, `direction`, `polymorphic_inverse` are all discarded. The value object (`:22-27`) has no morph field |
| Config → value object path | `src/Domain/Traits/HasNodeableConfig.php:296-316` → `src/Domain/ValueObjects/GraphConfig.php:48-62` | `getGraphConfig()` reads the entity's array config and hydrates `RelationshipConfig[]`; FK-less entries are filtered out (`RelationshipConfig.php:49-53`, `GraphConfig.php:60`) |
| Create path reads FK | `src/Services/RelationshipSyncer.php:46, 50-56` | `$data = $entity->toArray()`; `$foreignKeyValue = $data[$relationshipConfig->foreignKey] ?? null`; null → skip. **The morph-type value (e.g. `priceable_type = 'service'`) is sitting in the same `$data` array, unread** |
| Create path validates target | `RelationshipSyncer.php:60-76` | `$this->graphStore->nodeExists($relationshipConfig->targetLabel, $foreignKeyValue)` — with `targetLabel = '_polymorphic'` this is `MATCH (n:`_polymorphic` {id:$id})`, always 0, so every polymorphic row hits the "target node not found" skip (`:65-76`) and is counted in `$skipped` (`:74`) |
| Create path writes edge | `RelationshipSyncer.php:101-127` | `relationshipExists(...targetLabel...)` then `createRelationship(...targetLabel...)` — both take the label verbatim from config |
| Reconcile path (per-entity) | `RelationshipSyncer.php:162-319` | Same shape: FK read `:208-213`, `nodeExists(targetLabel, fk)` `:217-231` (missing target → `relationships_failed++` `:229`), `relationshipExists` `:234-252`, `createRelationship` `:265-272`. Reached via `DataIngestionService::syncRelationships()` (`src/Services/DataIngestionService.php:685-688`) and `AiManager::syncRelationships()` (`src/Services/AiManager.php:134-136`) |
| Reconcile path (batch — the one `ai:sync-relationships` uses) | `src/Services/BatchDataIngestionService.php:139-217` | `SyncRelationshipsCommand` injects `BatchDataIngestionService` (`src/Console/Commands/SyncRelationshipsCommand.php:56-58`) and calls its `syncRelationships()` (`:179`). Pairs are grouped by `fromLabel|targetLabel|type` (`BatchDataIngestionService.php:171`) and flushed as one `UNWIND … MATCH (to:Label {id}) MERGE` per group (`:307-332`, target MATCH at `:324`, 500-row chunks `:334-341`). With `_polymorphic` the `MATCH (to:`_polymorphic`…)` matches nothing — 0 edges, no error, **no phantom nodes** (MATCH, not MERGE, on the endpoint) |
| Bulk ingest defers all edges | `BatchDataIngestionService.php:91-93` | `ingestBatch()` Phase 1 is nodes-only ("skip relationships"); edge creation is entirely the reconcile paths' job — so the batch reconcile path is the **primary** path that must be fixed |
| Per-entity create call sites | `DataIngestionService.php:86, 311, 405, 528` | Model-event auto-sync (`HasNodeableConfig::bootHasNodeableConfig`, `HasNodeableConfig.php:39-79`) flows through `RelationshipSyncer::createRelationships` — the live-update path must be fixed too |
| nodeExists implementation | `src/GraphStore/BoltNeo4jStore.php:208-216` (HTTP twin `src/GraphStore/Neo4jStore.php:202`) | Label is escaped (`CypherSanitizer::escapeLabel`, `src/GraphStore/CypherSanitizer.php:144-154`) and interpolated; id is parameterized. `createRelationship` is MATCH-MATCH-MERGE (`BoltNeo4jStore.php:125-129`) — endpoints must pre-exist |
| Morph map (host) | `CondoEdge/app/Providers/AppServiceProvider.php:58-104` | `loadRelationsMorphMap()` (booted at `:23`) registers ~44 aliases via `Relation::morphMap([...])` (`:60`) — e.g. `'service' => \App\Models\Billing\Service::class` (`:99`), `'visit-detail' => …VisitDetail::class` (`:97`). DB morph columns store these aliases. `enforceMorphMap` is NOT used, so unmapped models store FQCN strings |
| Alias → class API | Laravel (`illuminate/database`, already a transitive dependency — `RelationshipDiscoverer.php:12-15` imports its Relation classes) | `Relation::getMorphedModel($alias)` returns the mapped class or `null`; static array lookup, no I/O |
| Class → label (the catalog) | `CondoEdge/config/entities.php:19-25` + `src/Domain/Traits/HasNodeableConfig.php:430-451` | `config('entities')` is the entity catalog, keyed by **FQCN** (CondoEdge glob-merges `config/ai-entities/*.php`); the package itself reads it FQCN-first, basename-second (`HasNodeableConfig.php:442-449`) and as `config('entities', [])` in the provider (`src/AiServiceProvider.php:379`). Label = `[$class]['graph']['label']` |
| Host configs do NOT carry `morph` | `CondoEdge/config/ai-entities/*` (full inventory §6.1) | **None of the 12+ `_polymorphic` edges in CondoEdge has a `'morph'` key** (e.g. `billing.php:375-382`, `finance.php:2475-2481`, `general.php:271-277`). They were generated/curated before the current discoverer emitted it. The fix must therefore work without the key (FK-name convention fallback, §3.1) |
| Morph-type value availability | `RelationshipSyncer.php:46` + Eloquent `attributesToArray()` | `toArray()` returns all non-hidden attributes, independent of the graph-properties whitelist — `priceable_type` is available even where not synced as a node property (in Price it also IS synced: `billing.php:365`) |
| Tonight's workaround | `CondoEdge/config/ai-entities/billing.php:381, 432, 484` | Property-join guidance (`p.priceable_type = 'service' AND p.priceable_id = s.id`) written into the PRICEABLE description, the Price entity description, and security notes — correct, but generation-fragile (flagged `[f6]` in `96-condoedge-recommended-questions.md:222`) |

Root-cause chain, in one breath: discovery captures the morph column → `RelationshipConfig::fromArray` throws it away → all three sync paths trust `targetLabel` verbatim → `'_polymorphic'` reaches `nodeExists`/`MATCH` → guaranteed miss → skip. The data needed to do the right thing is present **at every step**; it is simply never consulted.

---

## 1. The design in one paragraph

A new, tiny, memoized `PolymorphicTargetResolver` maps a row's morph-type **value**
(e.g. `'service'`) to a catalog **label** (e.g. `'Service'`) via
`Relation::getMorphedModel()` → FQCN → `config('entities')[$fqcn]['graph']['label']`
(basename key as second chance, mirroring `HasNodeableConfig.php:442-449`).
`RelationshipConfig` gains an optional `morph` field (kept by `fromArray`, normalized to
the full `*_type` column name) plus `isPolymorphic()` and a convention fallback
(`foreign_key` `_id`→`_type`) so **all existing host configs work unchanged**. The three
sync code paths — `RelationshipSyncer::createRelationships`,
`RelationshipSyncer::syncRelationships`, and `BatchDataIngestionService::syncRelationships`
— each resolve the effective target label per row when `isPolymorphic()`, then run the
**identical** existing pipeline (same `nodeExists` guard, same `relationshipExists`
dedupe, same `createRelationship`/UNWIND-MERGE, same relationship `type`). Rows whose
morph value is null, unmapped, or maps to a class with no catalog entry are skipped with
a log line — never an exception. Non-polymorphic edges take a single `isPolymorphic()`
boolean branch and are otherwise byte-identical. Raw morph values are **never**
interpolated into Cypher — only trusted catalog labels reach `escapeLabel`.

---

## 2. Component changes

### 2.1 `RelationshipConfig` — carry the morph column (additive, non-breaking)

`src/Domain/ValueObjects/RelationshipConfig.php`:

```php
public const POLYMORPHIC_TARGET = '_polymorphic';

public function __construct(
    public readonly string $type,
    public readonly string $targetLabel,
    public readonly string $foreignKey,
    public readonly array $properties = [],
    public readonly ?string $morph = null,          // NEW, last position
) { … existing validation unchanged … }

public static function fromArray(array $config): ?self
{
    …
    return new self(
        …existing…,
        morph: $config['morph'] ?? null,             // NEW: stop dropping it
    );
}

public function isPolymorphic(): bool
{
    return $this->targetLabel === self::POLYMORPHIC_TARGET;
}

/** Full morph-type column name, e.g. 'priceable_type'. */
public function morphTypeColumn(): string
{
    if ($this->morph !== null) {
        // MorphTo discovery stores the full column ('priceable_type',
        // RelationshipDiscoverer.php:418); MorphMany/MorphToMany store the
        // base name ('priceable', :390/:407). Normalize both.
        return str_ends_with($this->morph, '_type') ? $this->morph : $this->morph . '_type';
    }
    // Convention fallback — REQUIRED because no current CondoEdge config has
    // a 'morph' key (§6.1): priceable_id → priceable_type, parent_id →
    // parent_type, about_id → about_type, entity_id → entity_type, …
    return str_ends_with($this->foreignKey, '_id')
        ? substr($this->foreignKey, 0, -3) . '_type'
        : $this->foreignKey . '_type';
}
```

Notes:
- Optional last constructor param ⇒ zero impact on the ~direct constructions in tests
  (`tests/Unit/Services/RelationshipSyncerTest.php:178-183`,
  `tests/Unit/Services/BatchDataIngestionServiceTest.php`) and anywhere else.
- Non-standard morph columns (`morphTo('owner', 'x_kind', 'x_ref')`) are covered only
  via an explicit `'morph' => 'x_kind'` config key; the convention fallback covers all
  standard Laravel morphs, which is all CondoEdge has.
- `ai:discover` persists whatever the discoverer returns via `var_export`
  (`src/Console/Commands/DiscoverEntitiesCommand.php:279-298` — no key whitelist), **but
  only for newly discovered entities**. For models already present in the host config,
  `mergeConfigurations` → `deepMergePreserveExisting`
  (`DiscoverEntitiesCommand.php:222-241`, `:254-277`) keeps existing values — the
  keep-existing branch at `:270-274` preserves existing relationship arrays — so
  **existing hosts rely on the convention fallback indefinitely**; re-running
  `ai:discover` does not retrofit `'morph'` keys into curated configs. That is fine:
  the fallback is not a transition aid but the permanent path for them.

### 2.2 `PolymorphicTargetResolver` — new, `src/Services/PolymorphicTargetResolver.php`

```php
final class PolymorphicTargetResolver
{
    /** @var array<string, ?string> morph value → catalog label (null = unresolvable) */
    private array $cache = [];
    private array $logged = [];   // warn-once per distinct unresolvable value

    public function resolveLabel(mixed $morphValue): ?string
    {
        if (!is_string($morphValue) || $morphValue === '') {
            return null;
        }
        if (array_key_exists($morphValue, $this->cache)) {
            return $this->cache[$morphValue];
        }

        // 1. alias → class (morph map); raw FQCN values (no map entry) pass through
        $class = Relation::getMorphedModel($morphValue)
            ?? (class_exists($morphValue) ? $morphValue : null);

        // 2. class → catalog label; FQCN key first, basename second
        //    (mirrors HasNodeableConfig::readFromEntitiesConfig, :442-449)
        $label = null;
        if ($class !== null) {
            $entities = config('entities', []);
            $label = $entities[$class]['graph']['label']
                ?? $entities[class_basename($class)]['graph']['label']
                ?? null;
        }

        if ($label === null && !isset($this->logged[$morphValue])) {
            Log::info('Polymorphic morph value has no catalog label; edges for it will be skipped', [
                'morph_value' => $morphValue,
                'resolved_class' => $class,
            ]);
            $this->logged[$morphValue] = true;
        }

        return $this->cache[$morphValue] = $label;
    }
}
```

Design decisions, stated honestly:
- **No `class_basename($class)` label fallback when the class has no catalog entry.**
  A class outside the catalog was never ingested by this package, so `nodeExists` would
  return false on every row anyway; returning `null` saves one graph round-trip per row
  and never invents labels. This is the directive's "skip + log, never throw".
- **Negative results are memoized** and logged once per process per distinct value — a
  million Files with `parent_type = 'unmapped-thing'` produce one log line and zero
  graph calls.
- **Raw morph values never reach Cypher.** They are used only as array-lookup keys; the
  returned label comes from trusted host config and still passes
  `CypherSanitizer::escapeLabel` (`BoltNeo4jStore.php:210`, `:121-122`;
  `BatchDataIngestionService.php:309-310`) as defense-in-depth.
- Lazy `config()` read (not constructor-injected array) so construction order vs config
  loading is a non-issue; memoization makes the repeated `config()` access irrelevant.
- No kill-switch flag. This is a deterministic correctness fix whose failure modes all
  degrade to today's behavior (skip); a config toggle would add surface without a
  scenario that needs it. (If a rollout scare emerges, reverting the package version is
  equivalent.)

### 2.3 `RelationshipSyncer` — resolve per row, then identical pipeline

Constructor (pattern copied from `DataIngestionService.php:51`):

```php
public function __construct(
    private readonly GraphStoreInterface $graphStore,
    ?PolymorphicTargetResolver $polymorphicResolver = null,
) {
    $this->polymorphicResolver = $polymorphicResolver ?? new PolymorphicTargetResolver();
}
```

**Create path** (`createRelationships`, after the FK null-check at `:54-56`):

```php
$targetLabel = $relationshipConfig->targetLabel;
if ($relationshipConfig->isPolymorphic()) {
    $targetLabel = $this->polymorphicResolver->resolveLabel(
        $data[$relationshipConfig->morphTypeColumn()] ?? null
    );
    if ($targetLabel === null) {
        $skipped++;              // same counter as the existing skip at :74
        continue;                // debug log with morph column + value
    }
}
```

…then every subsequent use of `$relationshipConfig->targetLabel` in the loop body
(`nodeExists` `:60-63`, skip-log `:69`, `relationshipExists` `:104`, `createRelationship`
`:123`, error logs `:81, :113, :134`) switches to the local `$targetLabel`. For
non-polymorphic edges `$targetLabel === $relationshipConfig->targetLabel`, so behavior
is provably identical.

**Reconcile path** (`syncRelationships`, after `:211-213`): same resolution block;
unresolvable rows increment a **new additive summary key**
`relationships_unresolved_morphs` (initialized at `:164-171`) rather than
`relationships_failed` — "failed" today means *transient* (target not ingested yet,
`:229`, fixable by re-running), while an unmapped alias is *permanent* until config/morph
map changes; conflating them would make reconcile reports lie. All other uses of
`targetLabel` in `:217-301` switch to the resolved local.

### 2.4 `BatchDataIngestionService::syncRelationships` — per-row fan-out

At `:165-171`, resolve before grouping:

```php
$targetLabel = $relConfig->targetLabel;
if ($relConfig->isPolymorphic()) {
    $targetLabel = $this->polymorphicResolver->resolveLabel(
        $data[$relConfig->morphTypeColumn()] ?? null
    );
    if ($targetLabel === null) { $summary['relationships_unresolved_morphs']++; continue; }
}
$groupKey = $graphConfig->label . '|' . $targetLabel . '|' . $relConfig->type;   // was relConfig->targetLabel, :171
```

and `'toLabel' => $targetLabel` in the group seed (`:175`). One polymorphic relationship
config now fans out into one UNWIND group per **distinct resolved label actually present
in the chunk** (e.g. `Price|Service|PRICEABLE` and `Price|Plan|PRICEABLE`), each flushed
by the existing `batchMergeRelationships` (`:307-359`) whose `MATCH (to:Label {id})`
(`:324`) remains the implicit existence guard — rows whose target node is missing simply
match nothing, exactly as for normal edges today. Group count is bounded by the catalog
size, not row count.

The batch summary array initializes `relationships_unresolved_morphs` to 0 alongside
the existing counters (mirroring `RelationshipSyncer::syncRelationships`), so the key
is always present — never created on first increment.

Constructor gains the same optional `?PolymorphicTargetResolver $polymorphicResolver = null`
(named-args construction in `AiServiceProvider.php:180-188` is unaffected).

### 2.5 Wiring (as implemented)

`PolymorphicTargetResolver` is registered as a singleton in `AiServiceProvider`.
`DataIngestionService` does **not** take a resolver — its constructor takes
`?RelationshipSyncer` (`DataIngestionService.php:47`) — so the provider builds
`new RelationshipSyncer($graphStore, $resolver)` itself and passes it as the
`relationshipSyncer` argument of `DataIngestionService`; the **same** singleton resolver
instance is passed as `polymorphicResolver:` to `BatchDataIngestionService`. One memo
cache therefore genuinely serves a whole `ai:sync-relationships` run across both paths
(pinned by `PolymorphicTargetResolverTest::test_container_shares_one_resolver_across_ingestion_services`).
The default-null fallbacks (`RelationshipSyncer`, `BatchDataIngestionService`, and
`DataIngestionService`'s own `new RelationshipSyncer($this->graphStore)`) keep every
existing construction site and test compiling without it. No contract
(`DataIngestionServiceInterface`, `GraphStoreInterface`) changes.

### 2.6 Prompt/path consumers of the raw target label (fixed in the same change set)

Materializing these edges **activates a dormant prompt bug**: once a polymorphic
relationship type (e.g. `PARENT`, `PRICEABLE`) exists in Neo4j, it passes
`RelationshipsSection`'s strict type filter and the section would have rendered
`(File)-[:PARENT]->(_polymorphic)` and a Cypher template
`MATCH (x:File)-[:PARENT]->(y:_polymorphic)` — a label that can never match. Fixed
consumers, audited together:

| Consumer | Leak | Fix |
|---|---|---|
| `src/Services/PromptSections/RelationshipsSection.php` (label render + Cypher template) | `:_polymorphic` in prompt text and Cypher template | Polymorphic targets render an **unlabeled** node: `(File)-[:PARENT]->(x)  // polymorphic: x may be any entity type` with template `MATCH (x:File)-[:PARENT]->(y)` (both directions); unit-tested to contain no `_polymorphic` |
| `src/Services/Discovery/EntityAutoDiscovery.php` `discoverTraversalScopes` | `_polymorphic` as `$targetEntity` in generated traversal scopes (reachable: MorphTo configs DO pass through `enhanceWithDiscriminatorInfo` — `RelationshipDiscoverer.php:151-159` — and MorphTo's `getRelated()` returns a stand-in model, so `discriminator_fields`/`related_model` can be present) | skip sentinel before scope generation |
| `EntityAutoDiscovery.php` `discoverTeamPath` priority-4 loop | could return `'_polymorphic.team_id'` as a team path (e.g. a morph method whose type name contains `OF`) | skip sentinel before path derivation; the priority-3 loop compares `=== 'Team'` and is inherently safe |
| `src/Services/Security/TeamPathResolver.php:159, 217` | none — both compare `target_label` for equality against concrete labels (`'Team'`, `ucfirst($parentKey)`), which the sentinel can never equal | no change (audited) |

---

## 3. Failure modes and invariants

| Case | Behavior | Why it's right |
|---|---|---|
| Morph-type column null on row (orphan/optional morph) | skip row, debug log | identical semantics to FK-null skip (`RelationshipSyncer.php:54-56`) |
| Morph value unmapped & not a class (dirty data) | skip + one `Log::info` per distinct value per process | never throw — directive; dirty rows must not abort a 2000-row chunk |
| Alias maps to class with no catalog entry (e.g. `parent_type = 'attachment'` if Attachment isn't ingested) | skip + one log | target nodes cannot exist; counted in `relationships_unresolved_morphs` on reconcile |
| Morph value is FQCN (model added after morph map, `enforceMorphMap` absent) | `class_exists` passthrough → catalog lookup | matches Laravel storage behavior for unmapped models |
| Resolved label but target node not ingested yet | existing skip (`:65-76` create) / `relationships_failed` (`:229` reconcile) / MATCH-miss (batch `:324`) | **ingestion-order parity**: `ai:sync-relationships` reconciles later, exactly like normal edges; resolution itself reads only morph map + config, never the graph, so it is order-independent |
| Morph-type attribute in the model's `$hidden` | `toArray()` omits it → skip + log | document in upgrade note; none of the CondoEdge polymorphic owners hide their `*_type` columns (all 12 edges' type columns appear in their property lists, e.g. `billing.php:365`) |
| **Morph re-point leaves a stale edge** (row's `*_type`/`*_id` changes after an edge was created, e.g. a File re-parented from a Unit to a Contact) | the OLD edge stays: the update path syncs no relationships at all (`DataIngestionService.php:400-402` — the `'updated'` branch calls `updateGraph` only, never `createRelationships`), and `deleteRelationship` (`GraphStoreInterface.php:71`) is never invoked by any sync path; re-running `ai:sync-relationships` MERGEs the new edge but does not remove the old one | **parity with non-polymorphic FK changes** — a changed `team_id` strands its old `MEMBER_OF` edge identically today; this is a pre-existing reconcile gap, not introduced here. Consequence: §6.2 spot-check counts are **upper bounds** (may include stale edges from re-pointed rows). A delete-stale reconcile pass is follow-up work, deliberately out of scope |
| Non-polymorphic edge | one `isPolymorphic()` boolean check, then byte-identical path | regression-proven by keeping every existing test green unmodified (§5) |
| Phantom `_polymorphic` nodes | impossible before and after | per-entity guard is `nodeExists` + MATCH-MATCH-MERGE (`BoltNeo4jStore.php:125-129`); batch endpoint is MATCH (`BatchDataIngestionService.php:324`) |
| Relationship `type` and properties | unchanged | resolution swaps the label only; `PRICEABLE` stays `PRICEABLE` for every target, so `(p:Price)-[:PRICEABLE]->(x)` traversals work without knowing the concrete label |

**Performance.** Per row: one hash lookup (memo hit) — misses cost one static-array
lookup (`getMorphedModel`) + one config array access, once per distinct morph value per
process. Zero additional graph round-trips anywhere: the create path performs the same
`nodeExists` + `relationshipExists` it performs for normal edges; the batch path
performs the same one-UNWIND-per-group, with group fan-out bounded by `min(distinct
aliases in data, catalog size)`. Unresolvable rows are strictly *cheaper* than today
(they skip before `nodeExists`, today's code wastes a query on `_polymorphic`).

---

## 4. What this deliberately does NOT fix

- **The morph-owned inverse side stays a config no-op.** E.g. Service's `HAS_PRICES`
  entry (`billing.php:509-515`) declares `foreign_key => 'priceable_id'`, a column that
  lives on the *Price* table — `$data['priceable_id']` is always null on Service rows,
  so it skips at `RelationshipSyncer.php:54-56` today and after this change. That is
  harmless and correct: once `(:Price)-[:PRICEABLE]->(:Service)` materializes, the
  inverse direction is just the same edge traversed backwards
  (`(s:Service)<-[:PRICEABLE]-(p:Price)`). Same story for VisitDetail's `HAS_FILES`
  expectation (`96-condoedge-recommended-questions.md:107`) — the fact is materialized
  from the File side as `(:File)-[:PARENT]->(:VisitDetail)`. Teaching the Cypher
  generator to prefer the inverse traversal is a config/description concern (§6.3), not
  a sync concern.
- **MorphToMany pivot edges** (`RelationshipDiscoverer.php:397-410`) — discovery emits a
  concrete `target_label` for those (the related model), not `_polymorphic`; pivot-based
  syncing is a separate, pre-existing gap (FK lives in the pivot table) untouched here.
- **No backfill of previously-skipped edges happens implicitly** — running
  `php artisan ai:sync-relationships` after deploying is the explicit backfill (§6.2).

---

## 5. Test-first task list (per-file phpunit; write each test, watch it fail, implement)

1. **`tests/Unit/Domain/ValueObjects/RelationshipConfigTest.php`** (extend existing)
   - `fromArray` preserves `'morph'`; constructor default keeps old call sites valid
   - `isPolymorphic()` true for `_polymorphic`, false otherwise
   - `morphTypeColumn()`: explicit full column (`priceable_type`) → as-is; base name
     (`priceable`) → `priceable_type`; absent → derived from FK (`priceable_id` →
     `priceable_type`, `parent1_id` → `parent1_type`, non-`_id` FK → `<fk>_type`)
   - `vendor/bin/phpunit tests/Unit/Domain/ValueObjects/RelationshipConfigTest.php`
2. **`tests/Unit/Services/PolymorphicTargetResolverTest.php`** (new; Testbench
   `TestCase` so `Relation::morphMap` + `config()` work; reset map with
   `Relation::morphMap([], false)` in tearDown)
   - alias → label via morph map + FQCN catalog key; basename catalog key fallback
   - raw-FQCN morph value (no map entry) resolves
   - unmapped alias / mapped class without catalog entry / null / `''` / non-string → null, no throw
   - memoization: resolve, then `config(['entities' => []])`, resolve again → same label
   - `vendor/bin/phpunit tests/Unit/Services/PolymorphicTargetResolverTest.php`
3. **`tests/Unit/Services/RelationshipSyncerTest.php`** (extend; Mockery
   `GraphStoreInterface` per existing conventions, `:30-31`)
   - create: Price-like entity (`priceable_type='service'`, `priceable_id=7`) with a
     `_polymorphic` config → `nodeExists('Service', 7)`, `relationshipExists`/`
     createRelationship` called with `'Service'` and type `'PRICEABLE'`
   - create: two entities with different aliases resolve to different labels
   - create: unresolved alias → **no** graphStore calls for that row, returns 0, no throw
   - create: null morph-type value → skip
   - create: resolved label but `nodeExists` false → existing skip branch (parity)
   - reconcile (`syncRelationships`): same matrix + `relationships_unresolved_morphs`
     counter present and correct; missing-target still counts `relationships_failed`
   - **regression: every existing test in the file passes unmodified**
   - `vendor/bin/phpunit tests/Unit/Services/RelationshipSyncerTest.php`
4. **`tests/Unit/Services/BatchDataIngestionServiceTest.php`** (extend)
   - batch reconcile fan-out: rows resolving to `Service` and `Plan` under one
     `PRICEABLE` config produce two `query()` calls whose Cypher contains
     ``MATCH (to:`Service`…`` / ``MATCH (to:`Plan`…`` (capture via Mockery args)
   - unresolved row excluded from all pairs; counted in summary
   - `relationships_unresolved_morphs` initialized to 0 in the summary (empty input)
   - non-polymorphic grouping byte-identical (existing tests unmodified)
   - `vendor/bin/phpunit tests/Unit/Services/BatchDataIngestionServiceTest.php`
5. **`tests/Unit/Services/PromptSections/RelationshipsSectionTest.php`** (new, §2.6)
   - polymorphic config renders no `_polymorphic` anywhere; unlabeled target node in
     both the readable line and the Cypher template, both directions
   - concrete relationship rendering unchanged
   - `vendor/bin/phpunit tests/Unit/Services/PromptSections/RelationshipsSectionTest.php`
6. **Full suite** per-file as per repo convention, then `vendor/bin/phpunit` once.

---

## 6. Host (CondoEdge) impact

### 6.1 Config changes required: **none**

All currently-declared `_polymorphic` edges follow the `<base>_id`/`<base>_type`
convention, so the FK-derived fallback (§2.1) covers every one of them as-is:

| Config | Edge | FK → derived type column |
|---|---|---|
| `billing.php:375-382` | Price `PRICEABLE` | `priceable_id` → `priceable_type` |
| `finance.php:2475-2481` | Invoice `CUSTOMER` | `customer_id` → `customer_type` (aliases `unit`/`contact`/`union`) |
| `general.php:26-32` | `PARENT` (`HAS_ACTIVITIES` owner) | `parent_id` → `parent_type` |
| `general.php:156-162` | `PARENT` (`HAS_ADDRESSES` owner) | `parent_id` → `parent_type` |
| `general.php:271-277` | File `PARENT` | `parent_id` → `parent_type` |
| `general.php:722-728` | Notification `ABOUT` | `about_id` → `about_type` |
| `general.php:1030-1043` | `PARENT1`/`PARENT2` | `parent1_id`/`parent2_id` → `parent1_type`/`parent2_type` |
| `general.php:1132-1138` | Review `PARENT` | `parent_id` → `parent_type` |
| `meetings.php:545-550` | `PARENT` | `parent_id` → `parent_type` |
| `messaging.php:190-195` | `ENTITY` | `entity_id` → `entity_type` |
| `task.php:426-432` | Task `TASKABLE` | `taskable_id` → `taskable_type` |

Optionally add explicit `'morph' => '<column>_type'` keys for self-documentation, and
future `ai:discover` runs will emit them automatically (§2.1) — neither is required.

### 6.2 Rollout

1. Deploy the package change; run the suite.
2. `php artisan ai:sync-relationships` (optionally `--model` per owner above) — this is
   the backfill; it is idempotent (MERGE).
3. Spot-check: `MATCH (p:Price)-[r:PRICEABLE]->(x) RETURN labels(x), count(*)` —
   expect `Service` rows; `MATCH (f:File)-[:PARENT]->(x) RETURN labels(x), count(*)`;
   `MATCH (i:Invoice)-[:CUSTOMER]->(x) RETURN labels(x), count(*)` — expect
   Unit/Contact/Union split. These counts are **upper bounds**: rows whose morph was
   re-pointed after an edge was created retain the stale old edge too (§3). Logs:
   review the once-per-alias "no catalog label" lines — they enumerate exactly which
   morph targets are outside the ingested catalog.
4. Revisit the two open suspects in `96-condoedge-recommended-questions.md:106-107`:
   `Price.for_team` becomes expressible as
   `(Team)<-[:BELONGS_TO_TEAM]-(:Service)<-[:PRICEABLE]-(p:Price)`; the VisitDetail
   files question becomes `(f:File)-[:PARENT]->(v:VisitDetail)`.

### 6.3 Tonight's property-join config fixes: **keep both, do not revert**

Recommendation: **keep the property joins valid and keep the new edges — they are not in
conflict.** Reasons:
- The joins remain *correct forever*: `priceable_type`/`priceable_id` (and
  `customer_type`/`customer_id`) are synced node properties (`billing.php:365-366`), and
  property equality is label-agnostic.
- They cover windows the edges don't: pre-backfill graphs, rows whose target nodes
  aren't ingested (edge skipped, properties still present), and environments that
  haven't rerun `ai:sync-relationships`.
- The edge is strictly better when present (single-hop traversal, less
  generation-fragile than the `[f6]`-flagged join), so after step-3 verification, update
  the guidance strings (`billing.php:381,432,484`, the finance.php `CUSTOMER` comment at
  `:2475-2477`, and `ai.php` `user_entity_paths.Invoice`) to present the **edge as the
  primary pattern and the property join as the equivalent fallback** — an additive
  wording change, not a revert. Do not delete the join guidance.

---

## 7. Open questions / accepted risks

- **Alias drift:** if a morph-map alias is renamed, old rows keep the old string; the
  resolver will log-and-skip them. Detection is the warn-once log plus the
  `relationships_unresolved_morphs` counter — deliberate, visible, non-fatal.
- **`relationships_unresolved_morphs` is a new summary key** — additive; the only
  consumer of the reconcile summary is `SyncRelationshipsCommand`'s tallies
  (`:181-183`), which read existing keys by name and are unaffected (a follow-up can
  surface the new counter in the command output).
- **Memoization staleness within one process:** morph map and entity catalog are
  process-static in artisan/queue contexts; a long-lived octane-style worker that
  hot-reloads config would serve stale labels until restart. Accepted — ingestion runs
  are CLI/queue jobs in this stack.
