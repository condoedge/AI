# 02 — Local Development

From zero to productive on **this package** (`condoedge/ai`). This is not a host-app guide:
the package is a Laravel library that boots itself through Orchestra Testbench for tests —
there is no `artisan` in the repo root and you never need a running Laravel app to work on it.

Architecture deep-dives live in `docs/audit/` (see [Where to go deeper](#7-where-to-go-deeper));
this document only covers getting a working dev loop.

---

## 1. Prerequisites

| Requirement | Why |
|---|---|
| **PHP 8.2+** | `composer.json:17` declares `"php": "^8.1"`, but the dev harness pins `orchestra/testbench ^9.15` (`composer.json:30`), and Testbench 9 targets Laravel 11, which requires PHP ≥ 8.2. So contributors effectively need 8.2+ even though the runtime constraint says ^8.1. Verified working on PHP 8.2.29. |
| **ext-curl** | Declared (`composer.json:18`) and genuinely exercised: every outbound HTTP call is raw cURL — Qdrant (`src/VectorStore/QdrantStore.php:304-328`), the LLM providers (`src/LlmProviders/AbstractHttpLlmProvider.php`), embeddings. There is no Guzzle dependency. |
| **ext-json** | Declared (`composer.json:19`). |
| **ext-pdo_sqlite** | Not declared in composer.json, but the test harness boots every test on an in-memory SQLite database (`tests/TestCase.php:46-52`). Without it, no test runs. |
| **Composer 2** | Standard install. |
| **Docker** (optional) | Only for the live Neo4j + Qdrant stack used by integration tests (section 5). |
| **Tesseract + Ghostscript** (optional, niche) | Only the scanned-PDF OCR fallback needs them (`src/Services/Extractors/OcrPdfParser.php:294-320`, rasterizer at `:477-495`). The test suite does not require them; native-text PDF extraction works without them via `smalot/pdfparser`. |

## 2. Clone and install

```bash
git clone <repo-url> kompo/ai
cd kompo/ai
composer install
```

Notes:

- **Everything resolves from Packagist.** `composer.json` has no `repositories` key; the
  CondoEdge glue (`condoedge/utils ^0.2.100`, which transitively brings `kompo/kompo`) and the
  Neo4j driver (`laudis/neo4j-php-client ^3.4`) are public packages, pinned in `composer.lock`.
- What `composer install` gives you beyond runtime deps: `orchestra/testbench ^9.15` (a
  throwaway Laravel skeleton that tests boot themselves), `phpunit/phpunit ^10`, `mockery`,
  `faker` (`composer.json:27-32`).
- Sanity check the install with the canonical test invocation (section 4) on one file:

```bash
php -d opcache.enable_cli=0 vendor/bin/phpunit tests/Unit/Services/Actions/PendingActionTokenTest.php --no-coverage
```

This passes on a fresh clone with **no services running and no API keys set** — see why next.

## 3. Running without any external services

The package is designed so that a bare checkout is workable. Three layers make that true,
and knowing them tells you exactly what breaks when you *do* need a service.

### 3.1 Test harness: Testbench, in-memory SQLite

Every test extends `Condoedge\Ai\Tests\TestCase` (`tests/TestCase.php:12`), which extends
`Orchestra\Testbench\TestCase`. It registers `KompoServiceProvider` + `AiServiceProvider`
(`tests/TestCase.php:30-36`) and points the database at SQLite `:memory:`
(`tests/TestCase.php:46-52`). Neo4j/Qdrant config is set to localhost defaults
(`tests/TestCase.php:59-73`) but nothing connects unless a test actually issues a query.

### 3.2 Runtime guards: everything external is lazy or fail-soft

| Missing thing | What happens | Guard (source) |
|---|---|---|
| `OPENAI_API_KEY` / `ANTHROPIC_API_KEY` | Boot succeeds; the **first container resolution** of the LLM/embedding provider throws `"... API key is required"`. The bindings are lazy singletons, so unit tests that mock the provider never hit this. | `src/AiServiceProvider.php:140-160` (closures), `src/LlmProviders/AbstractHttpLlmProvider.php:43-45`, `src/EmbeddingProviders/OpenAiEmbeddingProvider.php:85-87` |
| Neo4j not running | Store objects construct fine; the **first query** throws a connection exception. | `src/GraphStore/BoltNeo4jStore.php` (lazy client) |
| Qdrant not running | Same pattern: every call throws `RuntimeException` from the raw-cURL transport. | `src/VectorStore/QdrantStore.php:330-336` |
| `laudis/neo4j-php-client` class missing | Factory logs a warning and falls back to the HTTP API store instead of fataling. | `src/GraphStore/Neo4jStoreFactory.php:64-75` |
| `kompo/auth` not installed | `NullAuthAdapter` is bound instead of `KompoAuthAdapter` — **fail-closed**: zero teams granted but `checkIfPermissionsExist()` returns `true`, keeping the security service on the filtering path so secured queries are denied rather than unfiltered. | `src/AiServiceProvider.php:614-621`, `src/Auth/NullAuthAdapter.php:23-54` |
| `condoedge/utils` `FileModel` facade unresolvable (isolated package tests) | Plugin registration is wrapped in `try/catch` and silently skipped. | `src/AiServiceProvider.php:694-705` |

The takeaway: a missing key or service surfaces **on first use, not at boot** — which is why
the smoke-test command after any environment change is `ai:diagnose`
(`src/Console/Commands/DiagnoseCommand.php:25`), run inside a host app or via the Testbench
CLI (section 5.4).

### 3.3 Test-level guards: live tests skip themselves

Tests that genuinely need a key or a service detect absence in `setUp()` and call
`markTestSkipped` — they never fail on a bare machine:

- OpenAI key: `tests/Integration/LlmProviders/OpenAiLlmProviderTest.php:29-33`,
  `tests/Integration/EmbeddingProviders/OpenAiEmbeddingProviderTest.php:37`
- Anthropic key: `tests/Integration/LlmProviders/AnthropicLlmProviderTest.php:32`
- Neo4j up: `tests/Integration/GraphStore/Neo4jStoreTest.php:28`
- Qdrant up: `tests/Integration/VectorStore/QdrantStoreTest.php:28`
- All three at once: `tests/Feature/AiSystemFeatureTest.php:75-100`
- `FileModel` facade: `tests/Feature/FileContextIntegrationTest.php:118`

The independently verified sweep of all test files (193 at the time; the suite has since
grown) on a machine with **zero** services: 185 clean-OK, 8 skip-only, 0 failures
(`docs/audit/EXECUTION-SUMMARY.md:9-13`). The skips are
documented anti-masking guards — each passes when its dependency is present.

## 4. Running tests

### 4.1 The canonical command (per-file)

```bash
php -d opcache.enable_cli=0 vendor/bin/phpunit tests/Unit/Services/QueryExecutorSecurityTest.php --no-coverage
```

> **WARNING — do not run the full suite via `--testsuite` (or bare `vendor/bin/phpunit`).
> It segfaults.** Per-file execution is the repo convention and the *verified* invocation
> (`docs/audit/EXECUTION-SUMMARY.md:7`; the per-file rule is also the task-list convention in
> `docs/audit/94c-polymorphic-edge-sync-design.md:321`). The composer scripts
> `composer test` / `test-unit` / `test-integration` (`composer.json:40-45`) all delegate to
> suite-level phpunit and inherit the problem — avoid them. The root cause of the segfault is
> not documented in the repo (open question); one skip guard even exists specifically for a
> recursive-scope segfault (`docs/audit/EXECUTION-SUMMARY.md:13`).

To sweep everything the way the audit did, loop per file (suggested helper, not a repo script):

```powershell
# PowerShell
Get-ChildItem -Recurse tests -Filter *Test.php | ForEach-Object {
  php -d opcache.enable_cli=0 vendor/bin/phpunit $_.FullName --no-coverage
}
```

```bash
# bash
find tests -name '*Test.php' -print0 | xargs -0 -n1 -I{} \
  php -d opcache.enable_cli=0 vendor/bin/phpunit {} --no-coverage
```

### 4.2 Suite layout

`phpunit.xml:8-15` registers two suites — `Unit` (`tests/Unit`) and `Integration`
(`tests/Integration`). **`tests/Feature` is not registered in any suite** and only runs when
invoked per-file (which is the convention anyway); whether that omission is intentional is an
open question. `phpunit.xml:25-41` also bakes in localhost Neo4j/Qdrant env defaults that
match `.env.example`, so integration tests find the docker stack without extra setup.

### 4.3 Finding which test covers what

`tests/Unit/` mirrors `src/` directory-for-directory, so the test for a class is almost always
at the mirrored path:

| You changed | Look in |
|---|---|
| `src/Services/QueryExecutor.php` | `tests/Unit/Services/QueryExecutorTest.php`, `QueryExecutorSecurityTest.php`, `QueryExecutorRateLimitTest.php`, `QueryExecutorTimeoutTest.php` |
| `src/Services/Actions/*` | `tests/Unit/Services/Actions/*` (e.g. `PendingActionTokenTest.php`, `ToolLoopRunnerTest.php`, `ToolApprovalParityTest.php`) |
| `src/Kompo/*` (UI components) | `tests/Unit/Kompo/*` |
| `src/GraphStore/`, `src/VectorStore/`, `src/LlmProviders/`, `src/EmbeddingProviders/` | `tests/Unit/<same dir>/` for contract behavior; `tests/Integration/<same dir>/` for the live round-trip |
| Cross-component flows (approval round-trip, native tool calling, full pipeline) | `tests/Feature/` — e.g. `ActionApprovalRoundTripTest.php`, `NativeToolActionRoundTripTest.php`, `AiSystemFeatureTest.php` |

Shared fixtures sit in `tests/Fixtures/`, test-only migrations/factories in `tests/database/`.

## 5. Optional: the live stack (integration tests)

You only need this to un-skip `tests/Integration/*` and `tests/Feature/AiSystemFeatureTest.php`,
or to exercise the pipeline end-to-end.

### 5.1 What to stand up

- **Neo4j 5.x** — the Bolt store uses `elementId(n)` (`src/GraphStore/BoltNeo4jStore.php:78`,
  Neo4j ≥ 5.0) and generated Cypher uses `NOT EXISTS {(...)}` existential subqueries
  (`src/Services/Discovery/CypherPatternGenerator.php:220`, ≥ 4.4). **APOC is not required** —
  nothing in `src/` calls `apoc.*`.
- **Qdrant** — REST on port 6333. The transport is hardcoded plain `http://`
  (`src/VectorStore/QdrantStore.php:30`), so a local container is the expected setup.
- **Provider keys** — `OPENAI_API_KEY` for LLM + embeddings (the default provider for both),
  optionally `ANTHROPIC_API_KEY` for the Anthropic LLM tests. Note `AI_EMBEDDING_PROVIDER=anthropic`
  is a non-functional placeholder whose `embed()`/`embedBatch()` always throw
  (`src/EmbeddingProviders/AnthropicEmbeddingProvider.php:82-100`).

### 5.2 docker-compose (ships in the repo)

The repo root contains a real `docker-compose.yml` — `neo4j:5-community` (Bolt 7687, HTTP UI
7474) plus `qdrant/qdrant:latest` (REST 6333, gRPC 6334), with healthchecks and named volumes.
It preloads APOC (`docker-compose.yml:43`), which the package doesn't use — harmless.

The one alignment step is the Neo4j password. Compose substitutes
`NEO4J_AUTH: "neo4j/${NEO4J_PASSWORD:-neo4j_password}"` (`docker-compose.yml:41`) from your
environment or a `.env` file next to it, while the tests expect the password pinned in
`phpunit.xml:31` — which matches `.env.example:6`. So:

```bash
cp .env.example .env     # gives docker compose the NEO4J_PASSWORD the tests expect
docker compose up -d
```

### 5.3 Env vars and running the live tests

The provider keys are **not** in `phpunit.xml` — integration tests read the real process
environment (`getenv()` / `$_ENV`, see `tests/Integration/LlmProviders/OpenAiLlmProviderTest.php:29-33`):

```powershell
# PowerShell
$env:OPENAI_API_KEY = "sk-..."          # required for LLM + embedding integration tests
$env:ANTHROPIC_API_KEY = "sk-ant-..."   # only for the Anthropic LLM tests
php -d opcache.enable_cli=0 vendor/bin/phpunit tests/Integration/GraphStore/Neo4jStoreTest.php --no-coverage
php -d opcache.enable_cli=0 vendor/bin/phpunit tests/Integration/VectorStore/QdrantStoreTest.php --no-coverage
php -d opcache.enable_cli=0 vendor/bin/phpunit tests/Integration/LlmProviders/OpenAiLlmProviderTest.php --no-coverage
php -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/AiSystemFeatureTest.php --no-coverage
```

The LLM integration tests make **real API calls** (kept cheap via low `max_tokens`,
`tests/Integration/LlmProviders/OpenAiLlmProviderTest.php:39`) — expect small charges.

### 5.4 Running package artisan commands without a host app

`testbench.yaml` configures the Testbench workbench (providers including
`CondoedgeUtilsServiceProvider`; `testbench.yaml:3-6`), which makes the package's commands
runnable standalone:

```bash
vendor/bin/testbench ai:diagnose
```

Caveat: `testbench.yaml:15-21` points the workbench at a local MySQL (`testbench` database,
`root`/`testing_password`) which you must provide yourself; whether this workbench flow is
actively used by the team is an open question — the test suite does not depend on it.

## 6. Coding conventions for contributors

- **Style: match the surrounding file.** There is no Pint / php-cs-fixer config in the repo;
  the convention is local consistency, not a global formatter.
- **Comments carry constraints, not narration.** Write a comment only when the code cannot
  show *why* — invariants, security reasoning, ordering constraints. The house style is visible
  in `src/Auth/NullAuthAdapter.php:44-50` (why `checkIfPermissionsExist` returns `true`) and
  `src/Services/Actions/ActionDispatcher.php:220-221,243-245` (why the null-user rejection and
  nonce-claim ordering exist). Do not narrate what the next line does, and never write
  version-narrating comments ("previously X, now Y") — git history holds that.
- **Test-first for behavior changes.** Write or extend the mirrored test (section 4.3) so it
  fails, then change `src/`. The suite's green status was earned per-file under an explicit
  anti-masking rule — a skip must never hide a failure (`docs/audit/EXECUTION-SUMMARY.md:13`).
  Verify with the canonical per-file command before claiming done.
- **Keep prompts model- and domain-agnostic.** Hardcoded domain word lists and
  provider-specific phrasing were deliberately removed from the QU prompt
  (`docs/audit/EXECUTION-SUMMARY.md:22`); don't reintroduce them — semantic behavior belongs
  to the LLM and config, not literal string lists.

### 6.1 Trust-core files — extra care required

These files encode the package's security invariants. Changes here need a test that proves the
invariant survives, written *before* the change, and reviewer attention:

| File | Invariant it holds | Guarding tests |
|---|---|---|
| `src/Services/QueryExecutor.php` — `applySecurityFilter()` (`:652`) | Tenant isolation: team-based filters are injected into every secured Cypher query; fail-closed on label-free primaries and OR-precedence edge cases | `tests/Unit/Services/QueryExecutorSecurityTest.php` |
| `src/Services/Actions/ActionDispatcher.php` — `approve()` (`:208-253`) | Mutating actions never execute inline. Approval re-verifies everything server-side: token decode (`:210-213`), null-user rejection (`:222-224`), ownership (`:226-228`), **fresh `authorize()`** (`:239`), single-use nonce claimed only after authorization (`:246-248`) | `tests/Feature/ActionApprovalRoundTripTest.php`, `tests/Unit/Services/Actions/MutationActionLinkFlowTest.php` |
| `src/Services/Actions/PendingActionToken.php` | The deferred-action token is HMAC-signed over `APP_KEY`, TTL-bounded, and nonce-carrying — tamper-evidence + replay defense (docblock `:7-30` explains the threat model) | `tests/Unit/Services/Actions/PendingActionTokenTest.php` |
| `src/Kompo/Actions/ApprovalCard.php` | The approval UI that round-trips to `MessagesQuery::approveAiAction` → `ActionDispatcher::approve()`; it is the only path by which a proposed mutation becomes real | `tests/Feature/ActionApprovalRoundTripTest.php`, `tests/Unit/Services/Actions/ToolApprovalParityTest.php` (the native tool channel must keep the **same** approval gate as the markdown-link channel) |

If you touch the action system at all, read
`docs/audit/94-action-and-extensibility-abstraction.md` and
`docs/audit/94b-native-tool-calling-design.md` first — they document why the gate is shaped
this way (tokens minted at render, never persisted; both channels share one approval path).

## 7. Where to go deeper

| Doc | What it gives you |
|---|---|
| `docs/audit/00-overview.md` | C4-style architecture overview of the whole package |
| `docs/audit/layers/` | 17 layer deep-dives (`00-orchestration-entry.md` … `16-crosscutting.md`) — the per-subsystem reference |
| `docs/audit/91-contracts-and-abstraction.md` | Container contracts, interfaces, and swap points |
| `docs/audit/94-action-and-extensibility-abstraction.md` / `94b-native-tool-calling-design.md` | The action system and native tool-calling design |
| `docs/audit/96-condoedge-recommended-questions.md` | What the primary host (CondoEdge) actually asks of the system |
| `docs/audit/EXECUTION-SUMMARY.md` | Verified suite status, what was hardened, and the honest scorecard |

## Known oddities a newcomer will hit

- `.env.example` lists `AI_MAX_RESULTS`, which nothing in `src/` or `config/` reads (the real
  knobs are `AI_QUERY_DEFAULT_LIMIT` / `AI_EXEC_DEFAULT_LIMIT`, `config/ai.php:481,501`).
  `AI_DOCS_ENABLED` / `AI_DOCS_PREFIX` are real but only gate the optional LaRecipe docs site
  (`config/larecipe.php:3,21`).
- `NEO4J_ENABLED` and `QDRANT_ENABLED` exist in config (`config/ai.php:339,358`) but **no code
  reads them** — they disable nothing.
- The unauthenticated `GET /api/ai/health` route reports neo4j/qdrant/llm status with raw error
  strings (`routes/api.php:7-9`, `src/Http/Controllers/HealthController.php:14-49`) — useful
  locally, a known exposure concern for production.
