# 03 — Host Integration: Installing `condoedge/ai` in a Laravel App

This document takes a Laravel developer from `composer require` to a working, grounded AI
assistant inside their app. Every step is derived from the package source and from the one
production reference implementation, **CondoEdge** (`C:/Users/jkend/Documents/Projects/kompo/CondoEdge`),
whose real config and mounts are cited throughout as the pattern to copy.

Architecture background lives in `docs/audit/00-overview.md` (C4) and the 17 layer docs in
`docs/audit/layers/`; this document covers only what a *host* must do. Contracts a host can
implement are catalogued in `docs/audit/91-contracts-and-abstraction.md`.

---

## 1. Install the package

```bash
composer require condoedge/ai
```

The service provider is auto-discovered — `extra.laravel.providers` lists
`Condoedge\Ai\AiServiceProvider` (package `composer.json`), so there is nothing to register
manually. CondoEdge requires it as `"condoedge/ai": "*"` (`CondoEdge/composer.json:16`),
resolved in its lockfile to `v0.0.4` from the `condoedge/AI` GitHub repository.

What `composer require` pulls in, and why (all hard requires — `composer.json:17-25`):

| Dependency | Why it exists |
|---|---|
| `php ^8.1`, `ext-curl`, `ext-json` | All external HTTP (Qdrant, OpenAI, Anthropic) is raw cURL — there is no Guzzle. |
| `condoedge/utils ^0.2.100` | Host framework glue: the chat components extend `Condoedge\Utils\Kompo\Common\Form`, and the `FileModel` facade gets the file-processing plugins auto-attached at boot (`src/AiServiceProvider.php:696-705`). The chat UI and file pipeline do not work without it. |
| `laudis/neo4j-php-client ^3.4` | Bolt driver for Neo4j (`bolt://`, `neo4j://`, `neo4j+s://` URIs). If the class is missing the factory falls back to the HTTP API on :7474 with a warning (`src/GraphStore/Neo4jStoreFactory.php:64-75`). |
| `smalot/pdfparser ^2.12`, `phpoffice/phpword ^1.4` | Native PDF text and DOCX extraction for file ingestion. |
| `thiagoalessio/tesseract_ocr ^2.13` | Scanned-PDF OCR fallback only. Needs the external `tesseract` binary plus Ghostscript or imagick — without them only scanned PDFs fail (`src/Services/Extractors/OcrPdfParser.php:294-320, 477-495`). Note `OCR_LANG` defaults to **`'fra'`** (`OcrPdfParser.php:244`). |
| `binarytorch/larecipe ^2.8` | Optional in-app docs site (the `ai-docs` publish tag) — a hard require that ships even if never published. |

External services the host must run:

- **Neo4j 5.x** — the Bolt store uses `elementId()` (Neo4j ≥ 5, `src/GraphStore/BoltNeo4jStore.php:78`)
  and generated patterns use `NOT EXISTS { ... }` subqueries (≥ 4.4). **No APOC is required**
  by the package (CondoEdge has one APOC-dependent scope flagged as a suspect, not package code —
  `docs/audit/96-condoedge-recommended-questions.md`).
- **Qdrant** — REST over **plain `http://` only**; the base URL is hardcoded
  (`src/VectorStore/QdrantStore.php:30`), so Qdrant Cloud (https) is effectively unsupported and
  an `api-key` header travels cleartext unless the network is private.
- **OpenAI API** — default LLM *and* the only working embedding provider
  (`AI_EMBEDDING_PROVIDER=anthropic` is a placeholder whose `embed()`/`embedBatch()` always
  throw, `src/EmbeddingProviders/AnthropicEmbeddingProvider.php:82-100`).
- **Redis/Memcached** (recommended) — the LLM circuit breaker needs a shared atomic cache for
  cross-process state (`config/ai.php:533-535`); on file/array drivers each worker trips its
  own breaker.

---

## 2. Publish and migrate

```bash
php artisan vendor:publish --tag=ai-config     # config/ai.php — required, you WILL edit it
php artisan vendor:publish --tag=ai-entities   # config/entities.php — optional; ai:discover generates it
php artisan vendor:publish --tag=ai-lang       # optional; en/fr JSON is auto-loaded anyway
php artisan vendor:publish --tag=ai-assets     # optional; see note below
php artisan vendor:publish --tag=ai-docs       # optional LaRecipe documentation site
php artisan migrate
```

Tag sources: `src/AiServiceProvider.php:649-671`.

- **Migrations are auto-loaded, not published** (`loadMigrationsFrom`, `src/AiServiceProvider.php:645`).
  `php artisan migrate` creates: `ai_conversations`, `ai_messages`, `ai_query_logs`,
  `ai_user_settings`, `ai_file_access_logs` (+ follow-up column migrations), and — via an *unprefixed* guarded
  migration — adds a nullable indexed `processed_at` column to the **host's** `files` table,
  no-op when the table is absent (`database/migrations/add_processed_at_to_files_table.php:11-17`).
  `ai:process-files` uses that column to skip already-processed files.
- **Routes are auto-loaded** (`src/AiServiceProvider.php:642-643`): `GET /api/ai/health`
  (**unauthenticated**, returns raw connection error strings — consider blocking it at the proxy;
  `src/Http/Controllers/HealthController.php:14-49`) and `GET /ai/chat`
  (route `ai.chat.screen`, behind `['web','auth']`, `routes/web.php:7-13`).
- **`ai-assets` is optional in practice**: the chat scroll/injector JS is inlined from the
  vendor directory at render time (`src/Kompo/AbstractChatSurface.php:187-188`). CondoEdge has
  *not* published the tag (no `public/vendor/condoedge/ai` exists in the host) and the chat works.
  Nothing in `src/` loads the published `ai-chat.css` at runtime — see open questions.
- **`php artisan config:cache` will break** with the default published config: a Closure sits in
  `query_generator_sections` (`config/ai.php:1333`), and the documented `entity_actions` /
  `generic_actions` style (closures) is equally unserializable. CondoEdge runs without config
  caching. Plan for that, or refactor the closures out before caching.

---

## 3. Minimal config to a first answer

Three things must be true before the assistant can answer anything: an LLM key, a reachable
Neo4j + Qdrant, and at least one entity in the catalog.

`.env`:

```env
# LLM — default provider is openai (config/ai.php:372)
OPENAI_API_KEY=sk-...        # REQUIRED: constructor throws "API key is required" on first
                             # use, not at boot (src/LlmProviders/AbstractHttpLlmProvider.php:43-45)
# OPENAI_MODEL=gpt-4o        # default

# Embeddings — leave the default 'openai' (text-embedding-3-small, 1536 dims).
# AI_EMBEDDING_PROVIDER=anthropic is a non-functional placeholder.

# Graph store (config/ai.php:341-345)
NEO4J_URI=bolt://localhost:7687
NEO4J_USERNAME=neo4j
NEO4J_PASSWORD=...           # or NEO4J_PASSWORD_ENCRYPTED (Laravel-encrypted, takes precedence)

# Vector store (config/ai.php:359-361)
QDRANT_HOST=localhost
QDRANT_PORT=6333
```

(CondoEdge's `.env.example:85-100` carries this same block. Ignore its `NEO4J_HOST`/`NEO4J_PORT`
entries — the published config reads `NEO4J_URI` (`CondoEdge/config/ai.php:384`).)

Two more decisions before first answer:

**a) Security adapter.** `AI_SECURITY_ENABLED` defaults to `true`, and without `kompo/auth`
installed the package binds `NullAuthAdapter`, which returns zero teams while reporting that
permissions exist — so **every secured entity query is denied** (fail closed,
`src/AiServiceProvider.php:609-621`, `src/Auth/NullAuthAdapter.php:23-54`). A standalone host
must bind its own `AiAuthAdapterInterface`. CondoEdge's adapter is deliberately minimal —
current team only, no global permissions:

```php
// CondoEdge/app/Auth/AiAuthAdapter.php
class AiAuthAdapter implements AiAuthAdapterInterface
{
    public function getTeamsWithPermission($user, string $entity): array { return [currentTeamId()]; }
    public function hasGlobalCountPermission($user, string $entity): bool { return false; }
    public function isEnabled(): bool { return true; }
    public function checkIfPermissionsExist($user, string $entity): bool { return true; }
}
```

bound in `CondoEdge/app/Providers/AppServiceProvider.php:118-121`. Also define the
`ai-security` log channel (`config/ai.php:283`) or security logging errors.

**b) One catalog entity.** The entity catalog (`config/entities.php`, read as
`config('entities')`) is what every ingest/index command and the query generator consume. Either
run `php artisan ai:discover` (section 11) or hand-author one entry — the anatomy is in
section 4.

Smoke test, in order:

```bash
php artisan ai:diagnose                                   # config, Neo4j, Qdrant, LLM, embeddings
php artisan ai:test-question "How many units are there?" --debug
```

`ai:diagnose` exits non-zero on any failed check (`src/Console/Commands/DiagnoseCommand.php:32-57`).
LLM/embedding singletons are lazy — a missing key surfaces on first use, which is exactly why
you smoke-test after deploy. `ai:test-question` warns that `currentTeamId()` is null in CLI, so
team-security results can differ from the web (`TestQuestionCommand.php:44-47`).

---

## 4. Authoring the entity catalog — THE critical section

The catalog is an array keyed by model FQCN. CondoEdge splits it into per-domain files under
`config/ai-entities/` (16 files: `condo.php`, `finance.php`, `billing.php`, …) merged by a glob
in `config/entities.php` (`CondoEdge/config/entities.php:21-23`) — copy that layout once the
catalog grows past a few entities.

A complete real entry (`CondoEdge/config/ai-entities/condo.php:1213` — Reservation, abridged):

```php
'App\\Models\\Condo\\Reservation' => [
    'graph' => [
        'label' => 'Reservation',
        'properties' => ['id', 'created_at', 'updated_at', 'start_date', 'end_date',
                         'deleted_at', 'status', 'num_people', 'note'],
        'relationships' => [
            ['type' => 'RESOURCE', 'target_label' => 'Resource', 'foreign_key' => 'resource_id',
             'direction' => 'outgoing', 'inverse_type' => 'HAS_RESERVATIONS'],
            ['type' => 'USER',     'target_label' => 'User',     'foreign_key' => 'user_id', /* … */],
            ['type' => 'CONTACT',  'target_label' => 'Contact',  'foreign_key' => 'contact_id', /* … */],
        ],
    ],
    'vector' => [
        'collection' => 'reservations',
        'embed_fields' => ['note'],
        'metadata' => ['id', 'start_date', 'end_date', 'status', 'num_people', /* … */],
    ],
    'security' => [
        'team_resolution' => 'union',
        'team_path' => 'resource.union.team_id',
        // …
    ],
    'metadata' => [
        'aliases' => ['reservation', 'réservation', 'booking', /* … */],
        'description' => 'Booking of a shared resource (e.g. party room, pool, BBQ) by a condo resident',
        'scopes' => [ /* section 4.2 */ ],
        'common_properties' => ['status' => 'Reservation status (1=Pending, 2=Approved, 3=Rejected)', /* … */],
    ],
],
```

### 4.1 The two sync laws

Everything else in this section follows from two facts about how the ingestion layer works.
Violating either produces scopes and paths that **silently match zero rows** (or, worse, 100%
of rows) — that is exactly the bug class a 246-pattern sweep of CondoEdge's catalog found 46
instances of (`docs/audit/96-condoedge-recommended-questions.md`, "Entity-config Cypher sweep").

**Law 1 — only properties listed in `graph.properties` exist on the node.**
`GraphPropertyMapper::extractProperties()` iterates exactly the declared property names
(`src/Services/GraphPropertyMapper.php:29-56`). Foreign-key columns are **not** auto-added:
if `user_id` is not in the list, `n.user_id` is `NULL` on every node, forever, and any
comparison against it is dead.

**Law 2 — only FK-declared relationships materialize as edges, in the outgoing direction.**
`RelationshipConfig::fromArray()` returns `null` for any relationship entry without a
`foreign_key` (`src/Domain/ValueObjects/RelationshipConfig.php:47-53`; nulls are filtered out in
`GraphConfig.php:60`), and `RelationshipSyncer` creates an edge only from source node →
`target_label` node identified by the FK value (`src/Services/RelationshipSyncer.php:50-127`).
The `inverse_type` and `direction` keys are **documentation for discovery and prompts — they
never create an edge**. There is no `HAS_RESERVATIONS` edge in the graph above; only
`(Reservation)-[:RESOURCE]->(Resource)` etc. exist (Cypher can traverse them backwards with
`<-[:RESOURCE]-`, so nothing is lost — but the *name* you reference must be the FK-declared one).

Corollary: relationships whose `target_label` is **`_polymorphic`** (morph columns like
`customer_type`/`customer_id`) never materialize at all — there is no single target label to
point at. The design to fix this exists (`docs/audit/94c-polymorphic-edge-sync-design.md`) but
is not implemented; until then, reach polymorphic targets with a property join over the synced
morph columns (see rule 6 below).

### 4.2 Scope-authoring rules

Scopes live under `metadata.scopes.{name}` with `specification_type`
(`parametric` | `property_filter` | `relationship_traversal` | `pattern`), a `concept`, a
`cypher_pattern`, optional `filter` keys, and `examples`. The `cypher_pattern` is injected into
the query-generation prompt with the instruction "USE THIS EXACT CYPHER PATTERN IN YOUR WHERE
CLAUSE" (`src/Services/PromptSections/DetectedScopesSection.php:206`) — the LLM pastes it into
a query *it* writes. That mechanism dictates every rule below. Each rule carries a wrong/right
pair taken from the 2026-06-10 46-bug sweep of CondoEdge's own catalog.

1. **Reference only synced properties** (Law 1).
   Wrong: `n.user_id = $userId` on Reservation — `user_id` is not in its `graph.properties`, matched zero rows.
   Right: `EXISTS { MATCH (n)-[:USER]->(:User {id: $userId}) }` (`condo.php:1300`, the `for_auth_user` fix).

2. **Match property TYPES to what the DB column syncs.** The node property has the column's
   type; Cypher comparisons are type-strict.
   Wrong: `n.code = 2410` — `accounts.code` syncs as a STRING; six scopes silently matched nothing.
   Right: `n.code = "2410"` (`finance.php:234`).
   Wrong: `n.enabled = true` — tinyint columns sync as integers; four scopes dead.
   Right: `n.enabled = 1` (`finance.php:372`).
   Also mind nullable tinyints: `(n.is_disabled = 0 OR n.is_disabled IS NULL)` when the Eloquent
   scope treats NULL as live (`finance.php:2249`, Fund `is_usable`).

3. **Traversal convention.** When the filter crosses an edge, use an existential subquery —
   it is a boolean expression, so it composes inside any WHERE clause the LLM writes:
   ```cypher
   EXISTS { MATCH (n)-[:EDGE]->(:Target {id: $param}) }
   ```
   (e.g. `EXISTS { MATCH (n)-[:UNIT]->(:Unit {id: $unitId}) }`, `condo.php:94`).

4. **Pure boolean expressions only in `cypher_pattern`.** No `MATCH … WHERE …` introducing
   variables, no aggregates, no prose — the fragment lands inside someone else's WHERE clause.
   Wrong: `n.year = MAX(year) per union` — prose plus an aggregate, not Cypher (`contact.php:96`, former board scope).
   Right: a property anti-join expressing the same "latest year" semantics as the Eloquent scope.
   Wrong: `date().plusMonths(1)` — Java temporal API, a hard SyntaxError (`calendar.php:120`, former).
   Right: `toString(date() - duration('P30D'))`-style Neo4j temporals (`condo.php:296`).
   Keep delimiters balanced — five sweep bugs were `( … ]` typos that made the whole fragment unparseable.

5. **Parametric scopes declare their filter keys.** A `specification_type: 'parametric'` scope
   names the parameters its pattern consumes in `filter`, so the scope-matching layer knows what
   to bind:
   ```php
   'for_auth_user' => [
       'specification_type' => 'parametric',
       'cypher_pattern' => 'EXISTS { MATCH (n)-[:USER]->(:User {id: $userId}) }',
       'filter' => ['user_id' => ''],
       'examples' => ['Show my reservations', /* … */],
   ],
   ```
   (`condo.php:1297-1308`.)

6. **Never reference an edge no FK declares** (Law 2).
   Wrong: `NOT EXISTS { (n)<-[:HAS_READ]- … }` — `HAS_READ` is an `inverse_type` name, no edge
   ever exists, so the anti-join reported **every** announcement unread (`condo.php:249`, former).
   Right: anti-join over the real FK-declared edges:
   `NOT EXISTS { MATCH (n)<-[:BELONGS_TO_ANNOUNCEMENT]-(:AnnouncementRead)-[:BELONGS_TO_USER]->(:User {id: $userId}) }` (`condo.php:249`).
   For polymorphic targets (which never materialize — see `docs/audit/94c-polymorphic-edge-sync-design.md`),
   use the endorsed property-join over the synced morph columns instead:
   `p.priceable_type = 'service' AND p.priceable_id = s.id` (`billing.php:381` area, Price hints).

After every edit: `php artisan ai:validate-config` (structural errors/warnings, non-zero exit
on errors) and a `php artisan ai:test-question "..." --debug` against a question the scope
should answer. Remember scope/alias/description edits also need re-indexing — section 11.

---

## 5. `user_entity_paths` — making "my X" questions work

`ai.user_entity_paths` (empty by default, `config/ai.php:771`) tells the query generator how to
traverse from the **authenticated user** to an entity type. Without an entry, "show my
reservations" has no anchored path and either fails or guesses. Each entry: a `description`
(prompt context), a `cypher` fragment ending with the target bound as `n`, plus the `entities`
and `relationships` it touches.

The CondoEdge Reservation entry is the canonical example because it encodes both sync laws
(`CondoEdge/config/ai.php:787-799`, FIX comment preserved in source):

```php
// FIX: user_id is NOT in Reservation's synced graph.properties (condo.php), so the previous
// `n.user_id = $userId` matched zero rows (the package only syncs listed properties; FKs are
// not auto-added). Traverse the materialized edges instead. NB: these edges only exist if the
// User/Contact nodes were already ingested when the Reservation synced — ingest Users first
// or run ai:sync-relationships after ingest.
'Reservation' => [
    'description' => 'User\'s amenity reservations. Reservation has a USER relationship (user_id column) to the User and/or a CONTACT relationship to the user\'s Contact.',
    'cypher' => 'MATCH (n:Reservation) WHERE EXISTS { MATCH (n)-[:USER]->(:User {id: $userId}) } OR EXISTS { MATCH (n)-[:CONTACT]->(:Contact {user_id: $userId}) }',
    'entities' => ['User', 'Contact', 'Reservation'],
    'relationships' => ['USER', 'CONTACT'],
],
```

CondoEdge declares paths for Unit, Contact, Invoice (a polymorphic property-join — worth reading),
Vehicle, Pet, Access, Extra, Announcement, Meeting, Reservation and BoardMember
(`CondoEdge/config/ai.php:698-799`). Authoring rules are the same as scopes: real edges, synced
properties, correct types.

---

## 6. Persona/role gating — `authorize` closures

Any config-registered action (sections 7a/7c) may carry an `authorize` closure with signature
`function ($user, array $input): bool`. It is the chat-side gate: an action whose closure returns
false is never advertised to that user, and for mutating actions it is re-run server-side before
execution. CondoEdge defines two reusable gates at the top of its `ai.php` and threads them
through every staff action (`CondoEdge/config/ai.php:6-47`):

```php
$staffOnly = function ($user, array $input = []): bool {
    if (!$user || !method_exists($user, 'hasAnyRole')) {
        return false; // null principal: fail closed
    }
    try {
        // Exactly App\Rbac\Role::team() — evaluated the same way RoleMiddleware does.
        return (bool) $user->hasAnyRole(['super-admin', 'lead', 'manager', 'assistant', 'accountant', 'concierge']);
    } catch (\Throwable $e) {
        return false; // missing Membership row — fail closed
    }
};
```

The semantics worth copying:

- **Mirror your route middleware exactly.** `$staffOnly` = CondoEdge's `Role::team()` tier;
  `$permanentOnly` = that set minus `concierge` (matches the finance routes that 403 a concierge).
  Residents *and board members* hold role `contact`, suppliers hold `supplier` — both excluded,
  so a chat never advertises a link that would 403 on click.
- **Fail closed, never throw.** The dispatcher invokes `authorize()` outside any try/catch (per
  the CondoEdge comment block at `ai.php:17-21`); an escaping `abort(403)` breaks the whole chat
  render. Guard for null users and wrap role checks in try/catch.
- **Sometimes the closure is the only gate.** Several CondoEdge routes (`contacts.form`,
  `announcements.form`) carry no role middleware at all — the `authorize` closure is the *sole*
  protection (`ai.php` comment at the Contact action, around `:1393`). Audit your routes before
  assuming defense-in-depth.

---

## 7. Actions

The action system (deep dive: `docs/audit/94-action-and-extensibility-abstraction.md`,
`docs/audit/layers/11-agentic-actions.md`) lets the assistant offer links, tables, exports and
approval-gated writes. Three registration styles:

**a) Config link actions — `ai.entity_actions` / `ai.generic_actions`.** Closures keyed by
entity type and action key; the LLM emits a markdown link `[text](entity://EntityType/id/action_key)`
that the response pipeline turns into a real Kompo element. Real example, abridged
(`CondoEdge/config/ai.php:1219-1248`):

```php
'entity_actions' => [
    'Unit' => [
        'view' => [
            'action' => fn ($id, $text = 'Open unit') => _Link($text)->href('unit.view', ['id' => $id]),
            'aliases' => ['open unit', 'view unit', 'unit page', 'go to unit'],
            'label' => 'Open unit',
        ],
        'add_vehicle' => [
            'action' => fn ($id, $text = 'Register a vehicle') =>
                _Link($text)->get('vehicles.form', ['unit_id' => $id])->inModal(),
            'aliases' => ['add vehicle', 'register my car'],
            'label' => 'Register a vehicle',
        ],
    ],
],
```

Note the CondoEdge habit of citing, in a comment, the exact route + the in-app call site whose
idiom the closure copies (`web.php:528`, `VehiclesUnitList.php:51`) — these closures break
silently when routes change, so leave the breadcrumbs.

**b) Class-string actions — `ai.actions`, the Plugin idiom.** A flat array of
`AiActionInterface` class-strings, ingested at boot exactly like `condoedge/utils` Plugins —
`new $class()`, no service-provider code (`src/Services/Actions/AiActionRegistry.php:96-118`;
binding rationale at `src/AiServiceProvider.php:364-377`):

```php
'actions' => [
    // \App\Ai\Actions\ExportRollCallAction::class,
    'token_ttl' => env('AI_ACTION_TOKEN_TTL', 900),          // reserved non-class keys coexist
    'native_tools' => (bool) env('AI_ACTION_NATIVE_TOOLS', false),
    'tool_rounds' => (int) env('AI_ACTION_TOOL_ROUNDS', 2),
    'max_tool_calls' => (int) env('AI_ACTION_MAX_TOOL_CALLS', 5),
],
```

Non-class entries are skipped, and a config action never clobbers a built-in under the same key.
Five built-ins ship: open-modal, render-table, link, export-excel, export-pdf
(`AiActionRegistry.php:124-132`).

**c) Approval-gated mutations — `ModelMethodMutationAction`.** The typed wrapper for exposing an
*existing* domain method (a write the app's own UI already performs) to the assistant. CondoEdge
wraps `Reservation::approve()/reject()`, `Task::close()` and `TaskDetail::complete()`
(`CondoEdge/config/ai.php:1306-1380`), each re-checking the same Policy ability against the
resolved row. The rule it encodes: **wrap the existing method; never issue a raw write from chat.**

### Result semantics: `ActionKind` and `mutates()`

An action's `handle()` returns data + intent (`AiActionResult`), never a Kompo element. The
`ActionKind` enum discriminates what the renderer builds (`src/Services/Actions/ActionKind.php:21-28`):
`Element` (link/redirect), `Table`, `Export` (download), `Pending` (approval card), `Error`
(graceful failure — the summary is shown, nothing actionable rendered).

`mutates(): bool` is the safety contract (`src/Contracts/AiActionInterface.php:66-70`): when
true, the dispatcher **never runs `handle()` inline**. It defers behind an approval gate and a
fresh server-side `authorize()` before the write ever happens.

### The approval flow, from the user's chair

1. The user asks ("approve reservation 42"); the assistant proposes the action. Nothing has run.
2. The chat renders an **ApprovalCard** — label, confirmation message, Confirm/Cancel buttons
   (`src/Kompo/Actions/ApprovalCard.php:47-90`).
3. The card carries a signed `PendingActionToken`: tamper-evident, expires after
   `ai.actions.token_ttl` (default 900 s), and single-use via a nonce consumed on confirm
   (`src/Services/Actions/PendingActionToken.php:10-31`).
4. On Confirm, the server decodes the token, re-runs `authorize()` against the live user and
   row, then calls the wrapped domain method. Cancel discards. An expired or replayed token is
   rejected at decode.

### The native tool channel

By default actions ride the markdown link grammar above. `ai.actions.native_tools`
(**default `false`**, `config/ai.php:1224`) switches to offering them as *native provider tools*
(OpenAI/Anthropic tool calling). The single decision point is `ActionToolGate`
(`src/Services/Actions/ActionToolGate.php:86-108`):

- terse response styles (`minimal`, `concise`) → **no** action channel at all;
- kill switch off → markdown (today's behavior, byte-identical);
- on, but the resolved `response_generation` provider doesn't implement
  `ToolCallingLlmInterface` → markdown, with a **loud per-turn warning** (capability drift must
  never be silent);
- on + capable provider + non-empty *relevance-scoped* tool set → native.

What actually flips when you enable it: the model receives tool specs instead of the link-grammar
prompt section (never both); a tool loop runs with hard caps — `tool_rounds` (default 2) LLM
rounds, `max_tool_calls` (default 5) dispatches, then one forced no-tools call closes the prose
(`src/Services/Actions/ToolLoopRunner.php`); telemetry (`channel`, `rounds`, `tool_calls`,
`degraded`) rides the response metadata. **Mutating actions keep the exact same signed-token
approval gate on both channels** — a tool call to a mutating action produces a Pending result
and an ApprovalCard, and the model is explicitly told the action has NOT run. Full design:
`docs/audit/94b-native-tool-calling-design.md`.

Staged rollout (the design's own posture — default-off kill switch, telemetry before tuning):

1. Leave `AI_ACTION_NATIVE_TOOLS=false` in production (nothing changes; the gate short-circuits
   before any spec is built).
2. Enable in staging with a tools-capable `response_generation` provider; both shipped providers
   qualify (`src/LlmProviders/OpenAiLlmProvider.php`, `AnthropicLlmProvider.php`).
3. Watch the `tool_calling` response metadata and the capability-drift log warning; tune
   `tool_rounds`/`max_tool_calls` on evidence.
4. Then flip production. The env var is the instant rollback.

---

## 8. Chat surfaces

`AiChatFloating` (`src/Kompo/AiChatFloating.php:19`) is the host-facing component: a floating
action button appended to a Kompo view's components array. Clicking it opens whatever
`ai.chat.surface` selects — `modal` (default) | `drawer` | `screen` (`config/ai.php:1034`;
dispatch at `AiChatFloating.php:43-47`), forwarding its `page_context` prop to the opened surface.

The CondoEdge mount pattern (`CondoEdge/app/View/Condo/UnionView.php:138-145`):

```php
new AiChatFloating(null, [
    'page_context' => [
        'entity_type' => 'Union',          // graph label — keys page_examples + prompt focus
        'entity_id'   => $this->model->id,
        'label'       => $this->model->name,
        'route'       => 'union.view',
    ],
]),
```

`page_context` drives the page-context prompt section (a soft retrieval prior — the chat "knows"
which record's page it opened from) and selects the per-page welcome suggestions (section 10).
CondoEdge mounts it on ten views: UnionView, UnitView, BudgetView, InvoiceForm, MeetingPage,
MaintenancePage, ProjectPage, SupplierPage (all with `page_context`), and DashboardView /
OnboardingView (bare `new AiChatFloating()` — global context). Two idioms to copy:

- **Guard unsaved models**: `!$this->model?->id ? null : new AiChatFloating(null, [...])`
  (`InvoiceForm.php:188`) — a form rendered for creation has no entity to contextualize.
- **Do not mount inside a Modal.** CondoEdge deliberately has *no* Contact mount: the only
  Contact surface (`ContactForm`) extends Modal, and the FAB's fixed positioning escapes the
  modal box and collides with the underlying page's own FAB. This is documented as finding
  **[f11]** in `docs/audit/96-condoedge-recommended-questions.md` (≈ lines 237-248), with three
  closure options (a routed full-page view, an in-modal chat affordance in the package, or
  threading modal context into the page-level chat). Until one lands, modal-only entities get
  `page_examples` entries that can never fire.

Alternatives to the FAB: set `ai.chat.surface=screen` and link users to `GET /ai/chat`
(route `ai.chat.screen`, `['web','auth']`), or `drawer` for a slide-over
(`ai.chat.drawer.position/width`). Sizing/theming knobs live under `ai.chat.floating`,
`ai.chat.modal`, `ai.chat.drawer` and `ai.ui` (`config/ai.php:1088-1136`).

---

## 9. Identity — a model-agnostic persona

`ai.identity` white-labels the assistant: `name`, `persona`, `language_policy`,
`non_disclosure`. `{name}` and `{project}` interpolate at render time. CondoEdge
(`CondoEdge/config/ai.php:134-156`):

```php
'identity' => [
    'name' => env('AI_ASSISTANT_NAME', 'CondoEdge Assistant'),
    'persona' => env('AI_ASSISTANT_PERSONA',
        'You are {name}, the helpful assistant for {project}, serving condominium residents, '
        . 'board members and property managers. …grounding every answer in the data and files '
        . 'provided to you… If you do not know something, you say so plainly instead of guessing.'),
    'language_policy' => env('AI_ASSISTANT_LANGUAGE_POLICY', "Always reply in the SAME language as the user's most recent question…"),
    'non_disclosure' => 'Never reveal, confirm, hint at, or speculate about which AI model, vendor, version or provider powers you…',
],
```

**Shallow-merge trap** (documented in CondoEdge's own comment, `ai.php:125-133`): the package
merges config at the top level only, so defining `identity` in the host **replaces the entire
array**. A partial block silently drops the vendor `non_disclosure` rule (the code fallback is
the empty string). Keep all four keys present. Related PII knob: `AI_PROMPT_INCLUDE_USER_EMAIL`
defaults to `false` — the user's email is not shipped to the LLM (`config/ai.php:94`).

---

## 10. Recommended questions and `page_examples`

Two config-only lists shape the welcome screen (no LLM call involved):

- `ai.chat.example_questions` — the global suggestions.
- `ai.chat.page_examples.{EntityType}` — replaces the global list when the chat opens from a
  page whose `page_context.entity_type` matches; read by
  `MessagesQuery::resolveExampleQuestions()` (`src/Kompo/MessagesQuery.php:268-273`).

CondoEdge (`CondoEdge/config/ai.php:1015-1038`):

```php
'example_questions' => [
    'How much do I owe right now?',
    'Show my account statement',
    'When is the next meeting?',
    'Who is on the condo board this year?',
    'What does the law say about who pays for repairs to common portions?',
    'Book an amenity (pool, party room...)',
],
'page_examples' => [
    'Unit' => [
        'What\'s this unit\'s outstanding balance?',
        'Who are the current owners of this unit?',
        // …
    ],
    // …
],
```

The discipline that makes these worth copying: **every entry is verified answerable** — data
questions resolve through `user_entity_paths`/the entity catalog, the law question through the
ingested `public/laws` corpus, action questions through registered resident-safe actions
(CondoEdge's own comment, `ai.php:1009-1014`). The full validated question catalog, per persona
and per page, with the verification trail, is `docs/audit/96-condoedge-recommended-questions.md` —
build a new host's list the same way. Two wiring caveats documented at `ai.php:1024-1031`:
clicked suggestions are currently sent *without* `page_context` (only typed messages carry the
soft prior), and every `page_examples` key needs a live mount — except modal-only entities
(the [f11] limitation, section 8).

---

## 11. Ingestion bootstrap

First population of graph + vectors + files, in order:

```bash
php artisan ai:discover                  # write config/entities.php from Nodeable models
                                         #   --enhance adds LLM-generated descriptions/aliases/scopes
                                         #   --dry-run to preview, --force to overwrite
php artisan ai:validate-config           # structural check; non-zero exit on errors
# … hand-review the catalog against section 4 before ingesting …

php artisan ai:ingest --fresh            # bulk: all Nodeable entities -> Neo4j nodes/edges + Qdrant embeddings
php artisan ai:sync-relationships        # reconcile edges skipped because targets weren't ingested yet
php artisan ai:index-context             # context_index collection (semantic context selection)
php artisan ai:index-semantic            # semantic_entities/_scopes/_templates (fuzzy detection)
php artisan ai:index-scopes              # scope_examples collection (semantic scope matching)
php artisan ai:process-files             # extract/chunk/embed existing DB files -> file_chunks
php artisan ai:ingest --docs             # physical doc corpus -> documentation_chunks
php artisan ai:diagnose                  # final smoke test
```

Notes:

- `ai:sync-relationships` is not optional on first run: bulk ingest silently skips any edge
  whose target node doesn't exist yet (ingestion-order problem,
  `src/Services/RelationshipSyncer.php:32-37,65-76`). CondoEdge's Reservation comment says it
  plainly: ingest Users first or sync after.
- `ai:ingest --docs` indexes `ai.file_context.physical_paths` globs under
  `ai.file_context.base_path`. CondoEdge indexes `'public/laws/**/*.md'` — the Civil Code of
  Québec co-ownership corpus (`CondoEdge/config/ai.php:889-891`).
- An orchestrator exists — `php artisan ai:setup [--discover] [--fresh] [--skip-ingest] [--dry-run]`
  (`src/Console/Commands/SetupCommand.php:26-30`) — **but** its ingest step calls
  `ai:ingest-entities` (`SetupCommand.php:101`) while the registered command signature is
  `ai:ingest` (`IngestEntitiesCommand.php:36`), and no alias exists in `src/`. Until that is
  reconciled, run the steps individually as above.
- `ai:enhance` (`--iterations=3`) is an optional LLM feedback loop that iteratively improves an
  existing catalog by generating test questions and grading the results — useful after the first
  end-to-end run, not before.

### What to re-run when

| Change | Re-run |
|---|---|
| Edited `metadata` (descriptions, aliases, scopes, examples) in the catalog | `ai:validate-config`, then `ai:index-context --force`, `ai:index-semantic --rebuild`, `ai:index-scopes --force`. No re-ingest — node data didn't change. |
| Edited `graph.properties` or `graph.relationships` | The above **plus** `ai:ingest --model=App\Models\X` and `ai:sync-relationships` — node shapes/edges in Neo4j must be rebuilt. |
| Added a new entity to the catalog | `ai:ingest --model=…`, `ai:sync-relationships`, then the three index commands. |
| Normal row creates/updates/deletes | Nothing — `ai.auto_sync` is on by default and syncs each save to Neo4j/Qdrant inline (`config/ai.php:191`). In production turn on `AI_AUTO_SYNC_QUEUE=true` and run a worker, otherwise every model save pays an embedding API call in-request. |
| Bulk data backfill / import | `ai:ingest --model=… [--from=…]` then `ai:sync-relationships`. |
| New file uploads | Nothing — `FileProcessingPlugin` is auto-attached to the host FileModel at boot (`src/AiServiceProvider.php:696-705`). `AI_FILE_QUEUE=true` to take it out of the request path. |
| Backfill of pre-existing files | `ai:process-files` (skips rows with `processed_at` set; `--force` to redo). |
| New/changed physical docs | `ai:ingest --docs`. |

Continuous QA once live: `ai:eval` (per-stage thresholds, `docs/audit/layers/15-eval-harness.md`)
and `ai:test-conversation` for multi-turn scripts.

---

## Open questions

- `ai:setup` invokes `ai:ingest-entities` (`SetupCommand.php:101`) but the registered command is
  `ai:ingest` (`IngestEntitiesCommand.php:36`); no alias was found in `src/`. Whether `ai:setup`
  currently completes past its ingest step could not be verified from source alone — run the
  pipeline steps individually until this is reconciled.
- The `ai-assets` tag publishes `ai-chat.css` to `public/vendor/condoedge/ai/css/`, but nothing
  in `src/` loads that stylesheet at runtime (the two JS files are inlined at render,
  `AbstractChatSurface.php:187-188`), and CondoEdge has not published the tag. Whether the CSS is
  required, and how a host is meant to include it, is unanswered by the source.
- How `composer require condoedge/ai` resolves for a *new* host is not derivable from the repos:
  CondoEdge's `composer.json` has no `repositories` entry, yet its lock pulls `v0.0.4` from the
  `condoedge/AI` GitHub repo — the resolution source (private Packagist? global config?) lives
  outside both codebases.
- `ai.file_context.access_scope` is documented in config but reportedly never read
  (`docs/audit/99-roadmap.md:40`); hosts needing per-file ACLs (CondoEdge does — see its
  `FileContextEnricherInterface` binding, `AppServiceProvider.php:123-126`) must override
  `scopeAccessibleBy` on their File model. Verify against current source before relying on the knob.
