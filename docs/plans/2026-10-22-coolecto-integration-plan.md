# Coolecto (ActiviteDeFinancement) × condoedge/ai — integration plan (v2)

Date: 2026-10-22. Version 2 includes the owner's corrections:
- the package must be domain-abstract;
- buttons come from the actions setup;
- articles are product knowledge only;
- audience filtering happens in the harness;
- logged-in users only, guests last;
- orders reach teams through campaigns;
- evals and the feedback loop once set up.

Research reports (file:line evidence for every point) are in `C:\Users\jkend\Documents\Projects\kompo\coolecto-ai-research\`, outside every git repo because the inventory quotes real names from live data:
- `package.md`
- `survey.md`
- `kb/analysis.json`, `kb/articles.json`
- `routes.tsv`
- `decondo/design.md`, `decondo/inventory.tsv`
- `evalloop/design.md`

The paste-ready prompt is [2026-10-22-coolecto-new-session-prompt.md](2026-10-22-coolecto-new-session-prompt.md).

## 1. Principles (from the owner)

1. **The package is abstract.** Zero condo / CondoEdge / SISC domain content in `condoedge/ai`: prompts, defaults, vocabularies, comments and
   tests. Each host supplies its domain through **one** extension mechanism, and Coolecto fills the same slots CondoEdge fills.
2. **Zero regression.** CondoEdge's LLM requests must stay byte-identical through the whole refactor. This is proven by a record/replay guard, not by
   live evals, which are too noisy for that.
3. **Harness, not model, for access.** Which articles a user may read is a deterministic filter on the article's audience.
4. **Separation of knowledge and behaviour.** The articles explain the software (features, possibilities, steps) and are retrieved as content.
   They never instruct the assistant how to work. Behaviour lives in setup: role sections, actions and routing are config.
5. **Buttons are actions.** They come from an actions catalogue declared in setup, filtered by code, never derived from article text.
6. **Keep it simple, not dumb.** One resolver, one config block per concern. Anything added must earn its place in the eval.

## 2. Where we are

**The knowledge base** (458 French articles, 4 audiences, 896 FAQ questions, 9,750 quoted UI labels) is clean and fits section-level chunking.
Content fixes:
- quote 16 YAML values that contain `: `;
- `pages: [welcome]` → `onboarding.guest`;
- one source of truth for the fees;
- about 8 missing articles (§9).

**The package** is installed in Coolecto (vendor copy = current HEAD) but not discovered or configured.

**Package gaps for Coolecto:**
| # | Gap | Evidence |
|---|---|---|
| W1 | Frontmatter embedded as text, never parsed: no title, no audience, no `pages` | MarkdownExtractor.php:41-50 |
| W2 | No audience filter: project docs bypass every access check | FileAccessResolver.php:250-251, 293-297; FileSearchService.php:160 |
| W3 | Re-ingest: no hash; a skip counts as Failed (`ai:setup` stops); deleted files stay in Qdrant | FileProcessor.php:61-67; IngestEntitiesCommand.php:484-485 |
| W4 | No role concept; the "insider voice" rule applies to every user | ResponseCurrentUserContextSection |
| W5 | Domain content inside the package (§3) | decondo inventory |
| W6 | No docs-only switch: a knowledge turn with no article falls into Cypher generation | AiManager.php:3648-3674 |
| W7 | Page context wired by hand per view | AiManager.php:3235-3261 |
| W8 | English defaults, `llm_roles` empty (all stages on gpt-4o), stale Anthropic default | config/ai.php |
| W9 | `link` action accepts a raw URL; host action authorize defaults to allow | LinkAction.php:85-87; ConfigAiAction.php:105-117 |
| W10 | Eval harness: CondoEdge's `composer.json` lost the ai-eval require-dev + path repo (only the lock has it, so the next `composer update` drops `ai:eval`); Coolecto has none | evalloop design |

**Data scoping (corrected):**
- **Orders, order details and answers** reach their team through `campaign_id → campaigns.team_id`. That is the same multi-hop scoping CondoEdge
  already uses, so staff data questions are a normal configuration step.
- **Buyer and supplier data views** are cross-team by design (customers matched by email; supplier → client campaigns). That's why data
  questions start with staff only.
- **Never ingest:**
  - `transfers.interac_password`, `suppliers.shipstation_password`;
  - minors' fields on `recipients`;
  - free-text `answers.content`;
  - customer email, phone, IP and addresses.

## 3. Making the package abstract (de-condo) without regression

### What is there (inventory)
| Category | Rows | Weight |
|---|---|---|
| Runtime prompt text | 17 in 4 files | **Reaches CondoEdge's LLM:** 5 QU fragments on every turn (QueryUnderstandingService.php:375-386: rent / unit / parking / "tear down a wall… as a tenant"); 3 query-rules fragments on every data turn (QueryRulesSection.php:134,148,156) |
| Runtime logic / defaults | 43 in 5 files | `own_profile_ambiguous_entity_labels=['Contact']` (config:953, QU post-processing); dev-time `ai:discover` brief "condo management AI assistant" (AiConfigEnhancer.php:124-226); condo/Québec alias dictionary (AliasGenerator.php:59-93); SISC scouting role words (CypherScopeAdapter.php:507,542; TraversalScopeGenerator.php:218,242) |
| Legal-corpus logic | 13 | Generic numbered-provision handling, so it **stays**. Bug: `ArticleLocator.php:121` matches a year at the end of a sentence ("in 2024."). |
| Comments | 403 in 98 files | **20 name real people, organisations and buildings from live data** (e.g. ActionAttacher.php:584, DataAnswerPlan.php:338, ResponseFileEnricher.php:113): remove first |
| Tests | 4,319 in 414 files | rework on a neutral schema (last) |
| Docs in the package | 2,444 (1,903 in `docs/eval`) | move out or `export-ignore` |

The QU comment at :363-365 records that removing those example phrases once shifted unrelated outputs. They must **move verbatim** to CondoEdge, not be
dropped.

### Mechanism (chosen): one `ai.domain` config block, read through a single `DomainProfile` reader
- It matches how hosts already extend the package (`project.description`, `guardrails.domain_scope`, `corpus_register` are interpolated config
  strings). A new host writes no PHP, and CondoEdge's domain already lives in its `config/ai.php`.
- **Slots:**
  - `domain.understanding_examples` (QU fragments);
  - `domain.query_rule_examples`;
  - `domain.ambiguous_profile_labels`;
  - `domain.aliases` (a map, deep-merged);
  - `domain.discovery_brief`;
  - `domain.role_terms`.
- **Package defaults:** neutral examples for the prompt slots, empty for the vocabularies, and the discovery brief derived from `project.description`.
- Rejected:
  - a PHP contract per host: more code for every host, same result;
  - host-supplied whole prompts: QU, clarity and probe prompts are single blocks, so a host copy drifts on every package upgrade.

### The three-move pattern per slot (each move proven by the guard)
1. Add the slot, with the package default = **today's exact text**. Replay shows zero diff.
2. Copy the same text into CondoEdge's `config/ai.php`. Zero diff.
3. Switch the package default to neutral. CondoEdge still has zero diff, because it supplies its own text.

### Regression guard (build first)
- **Cassette record/replay in `kompo/ai-eval`** (~600 lines + tests, ~1.5 days). No package change: the LLM resolver, embedding provider and
  graph/vector stores are container bindings.
  - Record the CondoEdge gold suite once on today's code (`tests/eval/gold-conversations.json`: 16 conversations, 39 turns).
  - Replay after every step and require every LLM request to be byte-identical. The first mismatch prints the stage and a diff.
  - Graph/vector result hashes are compared separately, so data drift is reported apart from code regressions.
  - Replay costs nothing and takes seconds (no 429 / circuit-breaker noise).
- **Plus:**
  - prompt snapshot tests in CondoEdge;
  - for comment-only steps, a token-stream equality check (code with comments stripped is identical).
- **Pitfalls:**
  - `GenericContextSection.php:29` prints `date('Y-m-d H:i:s')`: mask it.
  - Record and replay with `--fresh` and an array cache; don't re-ingest in between.
  - The cassette contains real data: keep it out of git.

### Order
0. Snapshots + cassette recorded.
1. Guard proven (two replays of unchanged code = 0 diffs).
2. Remove the real-name comments.
3. Slots (a) QU → (b) query rules → (c) `Contact` default → (d) aliases → (e) discovery brief → (f) role terms, each with the three moves.
4. Optionally expose the legal grammar as config, and fix the year bug behind a test.
5. Rewrite the remaining comments.
6. Tests on a neutral schema.
7. Internal docs out of the package.
8. The scanner becomes a CI lint (zero domain hits).
9. Coolecto fills the same slots and records its own cassette.

## 4. Package improvements for Coolecto (generic, opt-in, no-op for CondoEdge)

| P | Item | What | Guard |
|---|---|---|---|
| P0 | Frontmatter | Parse YAML (`symfony/yaml`, already installed transitively; declare it), tolerant fallback. Strip it from stored and embedded text. Title = `titre`. Payload metadata: `audience`, `pages[]`, `chemin`, `keywords[]`, `related[]`, `slug`. `chemin` goes in the heading prefix. Keywords sit behind a flag until the eval shows they help. | CondoEdge docs (no frontmatter) → byte-identical chunks |
| P0 | Audiences | `ai.audiences = ['resolver' => …, 'map' => [key => ['documents'=>[…], 'insider_voice'=>bool, 'prompt'=>'…', 'example_questions'=>[…]]]]`. The resolver result filters physical-doc search **and** filename search on `metadata.audience` (docs without an audience = visible to all). One response section (+ conversational and digest composers) renders the asker's `prompt`. `insider_voice=false` replaces the global insider lines. **QU untouched.** | Unset → byte-identical prompts and filters |
| P0 | Incremental ingest | Content hash. Unchanged = skipped (exit 0); changed = replaced; `--prune` removes deleted files. `ai:setup` re-runnable. | CondoEdge re-run = 0 changes |
| P0 | Docs-only mode | `query_generation.enabled` (default true). Off → no turn reaches Cypher. A data-looking question gets "where to see it" from the articles. | Default unchanged |
| P1 | Navigation actions from setup | See §6 | Only active with a catalogue configured |
| P1 | Page awareness | Capture the route name when the chat mounts. `page_examples` by route. Optional soft score boost for chunks whose `pages` contain the route (retrieval metadata, never a filter, never a button source). | No route → no change |
| P1 | Trace stamps | Resolved audience and role on the trace and message (needed by the eval gates and the admin KPIs) | Additive |
| P1 | Cost | `llm_roles`: small model for QU, map, follow-ups, conversational; gpt-4o for composition. Fix the stale Anthropic default. | Host config |
| P2 | Staff data questions | Entities: Campaign, Order and OrderDetail (scope via campaign), Product, TaxReceipt, Transfer (no secret columns), Recipient (no PII columns). Supplier, buyer and participant data later. | Adversarial review of every scoping rewrite |
| P2 | Write actions | Resend receipt (checks `OrderPolicy::manage` itself), send tax receipt. Refund stays link-only. | Signed pending-action token + confirm |
| P2 | Streaming | Stream composition tokens | — |
| **Last** | Guest light surface | See §8 | Separate surface; authenticated path untouched |

## 5. Role sections (setup, not articles)

Resolver precedence (mirrors Coolecto's sidebar, DashboardSidebar.php:137-196):
1. `isScanner()` → organisateur (scanner);
2. `isInParticipantMode()` → participant;
3. `isInCustomerMode()` → acheteur;
4. `currentSupplierId()` → fournisseur;
5. else organisateur + `current_role` (owner / manager / editor / basic / agent) + `isAdmin()`.

| Key | Reads docs of | insider_voice | Prompt (≤ 8 lines; the answer follows the user's language) |
|---|---|---|---|
| organisateur | organisateur, acheteur | yes | Works for the organisation (organisme) running campaigns on Coolecto; role {role}. Sets up and runs campaigns (products, delivery dates, tickets, registrations, auctions, draws, donations, memberships, forms), participants, communications, orders, reports. Owners and managers also handle finances (deposits, statement, tax receipts, fees) and "Mon organisme". If their role can't do something, say so and say who can. Buyer-side articles explain what their donors see. |
| fournisseur | fournisseur | yes | A supplier selling to organisations' campaigns: catalogue, variants, prices and profit, storefront, templates, clients (organisations), deliveries, production, statement. Doesn't run campaigns: never send them to organiser pages. |
| participant | participant, acheteur | no | Sells or collects for a campaign, often a student or a parent: personal page, share link and QR, sales and leaderboard, P2P. Short and simple. Can't change campaign settings: the organisers can. |
| acheteur | acheteur | no | Buys, donates or registers: ordering, payment, promo and gift certificates, tickets, registrations, receipts and tax receipts, refunds. Doesn't manage the campaign. Refunds, changes, delivery dates and campaign rules are the organisation's decision (« Contacter l'organisme »): never promise them. |

## 6. Buttons = actions from setup

- **Catalogue in Coolecto's config.** Through the existing action registry (`ai.actions` / `generic_actions`), each entry has:
  - a key;
  - a translated label;
  - a route name;
  - the audiences allowed;
  - an `authorize` closure (required for navigation actions: no default-allow);
  - a one-line description.
- **Each turn:**
  1. Code keeps only the entries for the asker's audience that pass `authorize` and whose route exists and needs no parameter. Never
     `switch.campaign`, because `my-campaign/*` routes act on the current campaign.
  2. The composition picks at most 2 keys from that closed list (a schema field).
  3. Code re-validates the picks and renders them through `LinkAction` with `route()`, never a model-written URL.
- **Articles** are never parsed for buttons and never tell the assistant what to show.
- **Package work:** the closed-list pick for knowledge answers (today they only get follow-up question chips), and a required `authorize` for navigation
  entries. CondoEdge is unaffected without a catalogue.

## 7. Evals and the feedback loop (Coolecto)

**Harness:**
- Fix CondoEdge's `composer.json` drift (re-add `kompo/ai-eval` require-dev + path repo).
- Install it in Coolecto the same way.
- Evals write conversations flagged `eval`, so run them on a **local DB copy only**.
- Seed 5–6 local test users that pass `EnsureActivatedModeIntegrity`: owner, basic member, customer with linked customers, participant with
  recipients, supplier. The CLI skips middleware, so an inconsistent user silently gets the wrong audience.
- Turn feedback on (`AI_CHAT_ENABLE_FEEDBACK=true`; the default is false).

**Suite (~79 turns, 6 chunk files grouped by user):**
- 42 paraphrased FAQ questions. The paraphraser sees only the question and the title; any paraphrase sharing a 4-gram with the article or quoting a
  « label » is rejected; every turn is human-reviewed.
- Gold per turn: source slug, audience, 1–3 labels, `chemin`.
- Traps:
  - the same topic asked by another audience;
  - role limits;
  - buyer escalation;
  - English questions;
  - staff data questions;
  - small talk and out-of-scope;
  - KB gaps;
  - follow-ups.

**Mechanical gates:**
- Existing: `cites_document`, `max_latency_ms`, hard labels.
- New: `retrieves_doc`, `doc_audiences`, `quotes_grounded`, `no_placeholders`, `branch_in`, `no_cypher`, `max_tokens`, `audience_is`.
- Doc chunk ids `physical:<path>` are in the trace (AiManager.php:5750), so these are checkable without an LLM.
- Answer quality is graded by reading the traces.

**Weekly loop:**
1. Collect up to 30 items from the review page: 👎, regenerated (an implicit 👎), errors, slow answers.
2. Triage each into a cause, with its trace evidence:
   - retrieval miss;
   - wrong audience;
   - missing or outdated article;
   - wrong role framing;
   - composition error;
   - UI label changed;
   - action missing;
   - data or scoping.
3. Fix in exactly one place: article, setup or package.
4. Promote the question, anonymised, into the suite.
5. Re-run the affected chunk and compare.
6. Log the week per audience.

**Changes:**
| # | Change | Size |
|---|---|---|
| C1 | Composer fixes | S |
| C2 | Optional `user` per conversation + an `--only` flag | S |
| C3 | The new gates | M |
| C4 | Trace stamps (§4) | S |
| C5 | KB→suite generator | M |
| C6 | Optional comment after 👎, plus a timestamp | S–M |
| C7 | "Copy as eval case" in the transcript (clipboard, no write) | S |
| C8 | Admin filters (regenerated, no sources, slow) + per-audience KPIs | S–M |
| C10 | Seed users + enable feedback | S |

All generic except C5 (partly Coolecto) and C10 (setup).

## 8. Guests (final step, only after everything else)

A **light surface**: no side panel, no conversation switching, no answer settings. Conversations are managed the same way underneath, keyed by session
for guests (never `user_id IS NULL`, which would make every guest share one list), with a per-IP throttle. Docs-only, audience `acheteur` (or
`participant` on signed participant links). Mounted in `layouts/app.blade.php`.

## 9. Configuration checklist (Coolecto)

**Must:**
- [ ] `package:discover`; publish `ai-config`; migrations on the local DB
- [ ] `.env`: `OPENAI_EMBEDDING_MODEL`, `QDRANT_*`; Neo4j if docs-only still needs it (verify W6); feedback on
- [ ] KB copied to `resources/ai-help/aide/**`, with K1/K2 fixed by a script (original untouched; 0 YAML errors, 0 unknown routes)
- [ ] `file_context.physical_paths`, `base_path`, a Coolecto-specific `physical_collection`, `corpus_languages=['fr']`
- [ ] `ai.domain` slots (fundraising examples), `ai.audiences` + resolver, actions catalogue, `query_generation.enabled=false` (v1)
- [ ] `project.*` (Coolecto, fundraising, Québec, French-first), `identity.*` (name per owner), `language_policy`
- [ ] chat: French welcome, `example_questions` per audience, `page_examples` for `dashboard`, `campaign.page.settings`, `campaign.page.products`, `campaign.transactions`, `campaign.page.recipients`
- [ ] host `AiAuthAdapter` → `[currentTeamId()]`; gate `viewAiConversations` (owner's admin definition); `ai.admin.layout='layouts.dashboard'`
- [ ] chat mounted in `layouts/dashboard.blade.php`; `npm run dev`; visual check (Coolecto remaps `gray-*`, `bg-white`, `text-black` to team colours)
- [ ] `ai:ingest --docs` → `ai:documents:map --backfill` (sync queue: ~1.5 h, one time)

**Should:** `llm_roles`, `rate_limits`, theme, `guardrails.domain_scope`, French export labels.

## 10. Owner decisions still open

1. The assistant's name in Coolecto.
2. Admin gate: `isAdmin()` (email list or `admin` role) or team 1.
3. cms wiki (38 articles): replace, or ingest alongside.
4. Fees: one source of truth.
5. The ~8 missing articles:
   - support contact;
   - privacy and Loi 25;
   - testing a campaign before launch;
   - what Coolecto is, plus a glossary;
   - choosing a campaign type;
   - 2FA and password;
   - wallet passes;
   - closing an account.
6. Whether the 4,319 domain-flavoured test rows are reworked now (step 6 of §3) or after Coolecto v1 ships.
