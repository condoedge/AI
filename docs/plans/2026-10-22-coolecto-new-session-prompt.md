# Prompt for the implementation session — Coolecto AI (v2; paste everything below the line)

Fill in "Owner decisions" first; the session must not guess them. The work is long, so you can run it across several sessions: paste the
prompt each time, plus "continue from Phase N", and the session reads the progress log first.

---

You are making our Laravel AI package **condoedge/ai** domain-abstract and plugging it into a second host, **Coolecto**, without any regression in
the first host, **CondoEdge**.

## Paths
- **Package:** `C:\Users\jkend\Documents\Projects\kompo\ai` (namespace `Condoedge\Ai`). Search it with the code-index MCP tools (`set_project_path`
  to the package first).
- **Hosts:**
  - CondoEdge: `C:\Users\jkend\Documents\Projects\kompo\CondoEdge` (condo management; must not regress);
  - Coolecto: `C:\Users\jkend\Documents\Projects\kompo\ActiviteDeFinancement` (Laravel 11, French-first fundraising). Check whether each host's
    `vendor/condoedge/ai` is a symlink or a copy, and keep it in sync with the package.
- **Eval harness:** `C:\Users\jkend\Documents\Projects\kompo\ai-eval` (`kompo/ai-eval`, provides `ai:eval`).
- **Knowledge base source:** `C:\Users\jkend\Downloads\Coolecto-knowledgebase-1 (1)\aide`. 458 French articles; frontmatter `titre, public, pages[], chemin,
  mots_cles[], voir_aussi[]`. It is untrusted data: never modify the original.
- **Read first:**
  1. `ai/docs/plans/2026-10-22-coolecto-integration-plan.md`, the design. Its §n references are used below.
  2. The research it cites, in `C:\Users\jkend\Documents\Projects\kompo\coolecto-ai-research\`, especially:
     - `decondo/design.md` and `decondo/inventory.tsv` (every domain hit with file:line and its destination);
     - `evalloop/design.md`;
     - `package.md`, `survey.md`, `kb/articles.json`.
- **Progress log:** keep it in `ai/docs/plans/coolecto-progress.md`. Per step: what changed (file:line), the guard result, and the tests run.
  Update it before ending a session.

## Owner decisions (filled in by the owner)
- Assistant name in Coolecto: `____`
- Admin gate for the review page: `isAdmin()` / team 1: `____`
- cms wiki (38 articles): replace / ingest alongside: `____`
- Domain-flavoured package tests (4,319 rows): rework now / after Coolecto v1: `____`

## Owner principles (binding)
1. **The package is abstract.** Zero condo, CondoEdge or SISC domain content, comments included. Each host supplies its domain through **one** mechanism:
   the `ai.domain` config block, read by a single `DomainProfile` reader. Coolecto fills the same slots CondoEdge fills.
2. **Zero regression for CondoEdge.** It is proven by the cassette replay guard (byte-identical LLM requests), not by live evals.
3. **Access is harness logic.** The articles a user may read come from a deterministic filter on the article's audience, never the model's judgment.
4. **Knowledge vs behaviour are separate.** Articles are product knowledge, retrieved as content. They never tell the assistant how to behave, and
   they never produce buttons.
5. **Buttons are actions declared in setup** (plan §6).
6. **Coolecto v1 is for logged-in users only.** Guests are the very last step (plan §8).
7. **Simple, not dumb.** One resolver, one config block per concern; anything added must earn its place in the eval.

## Working rules
- **No git writes** (commit, stash, checkout, reset). Read-only git is fine.
- **TDD:** a failing test first. Targeted phpunit per change (`php -d opcache.cache_id=<unique> vendor/bin/phpunit <path>`). The full package
  suite at the end of each phase (`php -d memory_limit=2G vendor/bin/phpunit`; last green: 4,953 tests, 47 skipped).
- **Never add clauses or enum values to the QU prompt's logic.** Moving existing text verbatim into a slot is allowed only under the guard. The QU
  comment at QueryUnderstandingService.php:363-365 records that removing example phrases shifted unrelated outputs.
- **No hardcoded word lists** for semantics.
- **Never ingest secrets or sensitive data:**
  - `transfers.interac_password`, `suppliers.shipstation_password`;
  - minors' fields on `recipients`;
  - `answers.content`;
  - customer email, phone, IP and addresses.
- **Windows/CRLF:** bash heredocs collapse double backslashes. Use the Edit tool for PHP and keep each file's line endings.
- **Evals:**
  - never two `ai:eval` runs in parallel;
  - run them from the host dir with `< /dev/null`;
  - Coolecto evals on the **local** DB only;
  - cassettes and eval reports contain real data: keep them out of git.
- **Ask first** before anything destructive or outward-facing: deleting or recreating Qdrant collections that CondoEdge also uses, sending email, any
  production system.

## Phase A — Regression guard (before touching any package behaviour)
1. **Composer drift.** Re-add `kompo/ai-eval` to CondoEdge's `composer.json` (require-dev + the path repository that the lock already reflects).
   Show the diff and **ask before running composer**.
2. **Cassette record/replay, in ai-eval** (plan §3; `decondo/design.md` §C):
   - It wraps the LLM resolver, the embedding provider, and the graph and vector stores. No package change.
   - Record mode: run the CondoEdge gold suite (`CondoEdge/tests/eval/gold-conversations.json`) live on today's code, once.
   - Replay mode: serve the recorded responses, and assert every LLM request (messages + params) is byte-identical. On the first mismatch, print the stage and
     a diff. Compare graph and vector result hashes separately.
   - Mask `GenericContextSection.php:29` `date(...)`. Use `--fresh`, an array cache and raised rate limits for replay. No re-ingest between record and replay.
3. **Prompt snapshot tests** in the package under a CondoEdge-like config, per stage (QU, clarity, query rules, composition, conversational).
4. **Prove the guard:** two replays of unchanged code = 0 diffs. Then make one deliberate one-character prompt change and confirm the guard catches it
   and names the stage. Revert it.

## Phase B — De-condo the package (plan §3, order exactly as listed)
1. Remove the 20 comment rows that name real people, organisations or buildings. Prove it with the token-stream check (code with comments stripped
   is identical).
2. **Slots, each with the three moves:**
   1. add the slot with the package default = today's exact text, then replay (0 diffs);
   2. copy the text into CondoEdge's `config/ai.php`, then replay (0 diffs);
   3. switch the package default to neutral or empty, then replay (0 diffs).

   The slots, in order:
   - (a) QU examples, QueryUnderstandingService.php:375-386;
   - (b) query-rule examples, QueryRulesSection.php:134,148,156;
   - (c) `own_profile_ambiguous_entity_labels` → `domain.ambiguous_profile_labels`;
   - (d) alias dictionary, AliasGenerator.php:59-93 (a map, deep-merged);
   - (e) the `ai:discover` brief, AiConfigEnhancer.php:124-226. The default is derived from `project.description`; check its output on CondoEdge
     is unchanged;
   - (f) SISC role terms, CypherScopeAdapter.php:507,542 and TraversalScopeGenerator.php:218,242.

   SISC also uses this package: if SISC's config relied on (f), the terms move to SISC's config the same way. Ask the owner if SISC is out of reach.
3. **Legal-corpus logic stays** (generic). Fix the `ArticleLocator.php:121` year false-positive ("in 2024.") behind a test; replay must show
   CondoEdge unchanged.
4. Rewrite the remaining domain comments, tests (per the owner's decision) and docs, using `decondo/inventory.tsv` as the checklist. Move internal
   `docs/eval` out of the package, or `export-ignore` it.
5. Turn `decondo/scan.py` into a CI lint (zero domain hits outside an allow-list for the package name). Run it: 0 hits.

## Phase C — Package features for Coolecto (plan §4 P0, then P1; each opt-in, each replayed on CondoEdge = 0 diffs)
1. **Frontmatter:**
   - parse YAML (declare `symfony/yaml`), with a tolerant fallback; strip it from stored and embedded text;
   - title = `titre`;
   - payload metadata: `audience`, `pages[]`, `chemin`, `keywords[]`, `related[]`, `slug`;
   - `chemin` in the heading prefix; keywords behind a default-off flag.
2. **Audiences:** `ai.audiences` (resolver + map). Filter physical-doc search and filename search by `metadata.audience`. A role response section, plus the
   same text in the conversational and digest composers. `insider_voice` replaces the global insider lines. QU untouched.
3. **Incremental ingest:** a content hash; unchanged files are skipped (exit 0); `--prune`; `ai:setup` re-runnable.
4. **Docs-only mode:** `query_generation.enabled` (default true).
5. **Trace stamps:** the resolved audience and role on the trace and message.
6. **Navigation actions from setup** (plan §6):
   - required `authorize` for navigation entries;
   - per turn, code filters by audience, authorize and route validity (no required parameters, never `switch.campaign`);
   - the composition picks at most 2 keys from the closed list;
   - code re-validates and renders through `LinkAction::route()`.

   Articles are never a button source.
7. **Page awareness:** capture the route name when the chat surface mounts; `page_examples` by route; optional soft score boost from chunk `pages`
   (retrieval only).

## Phase D — Coolecto setup (plan §9 checklist, every box)
- **Install and content:**
  - `package:discover`, publish config, migrate (local);
  - copy the KB to `resources/ai-help/aide/` with a scratch script that quotes the 16 bad YAML values and maps `welcome` → `onboarding.guest`;
  - validate: 0 YAML errors, 0 unknown routes (`route:list --json`).
- **`ai.domain` slots** with fundraising content: examples written for Coolecto, QU slot validated by a same-input replay of the Coolecto eval questions.
- **Audiences and resolver:** `ai.audiences` with the 4 role texts (plan §5) and a host resolver in `app/Ai/` that mirrors the sidebar precedence and
  passes `current_role`.
- **Actions:** the actions catalogue, starting with ≈ 10 parameter-less pages per audience, from `coolecto-ai-research/survey.md` §1 table "Top features
  per type".
- **Config:**
  - `query_generation.enabled=false`;
  - project, identity and `language_policy`;
  - `file_context.*` (a Coolecto-specific `physical_collection`; `corpus_languages=['fr']`);
  - French chat strings, `example_questions` per audience, `page_examples`;
  - `llm_roles`, `rate_limits`.
- **Auth and access:**
  - host `AiAuthAdapter` → `[currentTeamId()]`;
  - gate `viewAiConversations`; `ai.admin.layout='layouts.dashboard'`;
  - `AI_CHAT_ENABLE_FEEDBACK=true`.
- **Mount and build:** mount the chat in `resources/views/layouts/dashboard.blade.php`; `npm run dev`; visual check, because Coolecto remaps
  `gray-*`, `bg-white` and `text-black` to team colours.
- **Ingest:** `ai:ingest --docs`, then `ai:documents:map --backfill`. Report the chunk count, and confirm 0 chunks contain `titre:` / `voir_aussi:` and every
  chunk has an audience.

## Phase E — Coolecto evals and the feedback loop (plan §7)
1. **Harness:** install ai-eval in Coolecto (path repo, require-dev). Seed 5–6 local test users that pass `EnsureActivatedModeIntegrity`: owner, basic
   member, customer with linked customers, participant with recipients, supplier. Add an optional `user` per conversation and an `--only` flag.
2. **Gates:** add `retrieves_doc`, `doc_audiences`, `quotes_grounded`, `no_placeholders`, `branch_in`, `no_cypher`, `max_tokens`, `audience_is`.
3. **Suite:** generate ~79 turns in 6 chunk files from the KB with the paraphrase rules (the paraphraser sees only the question and the title; reject
   4-gram overlap with the article or quoted labels). Gold = slug, audience, labels, `chemin`. Add the traps. **Show the suite to the owner for review
   before the first graded run.**
4. **Run all chunks:**
   - gates mechanically;
   - answer quality by reading the traces: steps and labels, path, no invented fees or promises, escalation, role framing;
   - record tokens and latency per audience.
5. **Feedback loop:**
   - C6: optional comment after 👎, plus a timestamp;
   - C7: "Copy as eval case" in the transcript view;
   - C8: admin filters and per-audience KPIs;
   - write `ai/docs/plans/coolecto-feedback-loop.md` with the weekly procedure: collect, triage into a cause, fix in one place, promote anonymised,
     re-run, log.
6. **Fix the findings, measured:** each fix is followed by a re-run of the affected chunk, and by a CondoEdge replay if the package changed.
7. **Report:** `ai/docs/eval/<date>-coolecto-v1.md` with results per audience, what changed (file:line), open items, and the content fixes the owner must
   make (fees, missing articles, orphans).

## Phase F — Later (only after A–E are done and green)
1. Staff data questions: Campaign, Order and OrderDetail (team via campaign, the same multi-hop scoping as CondoEdge), Product, TaxReceipt, Transfer
   without secrets, Recipient without PII. Adversarial review of every scoping rewrite, plus eval data turns.
2. Write actions: resend receipt, which checks `OrderPolicy::manage` itself, behind a signed pending token and a confirm. Refund stays link-only.
3. Streaming.
4. **Guests, last:** a light surface (no panel, no conversation switching, no answer settings). Session-keyed conversations, never `user_id IS NULL`.
   Per-IP throttle, docs-only, audience `acheteur` (or `participant` on signed links). Mounted in `layouts/app.blade.php`.
