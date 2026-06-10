# CondoEdge Closed Eval — 2026-06-10 (90c)

Closed, agent-as-judge eval of the CondoEdge-configured assistant after tonight's sweep
(46 dead Cypher patterns fixed, 3 suspects resolved) and the native tool-calling work.
**70 questions, 4 personas** (Resident 13, Board member 11, Staff/manager 38,
Adversarial-realistic 8), each graded 0-10 by tracing the full pipeline in source —
no live stack (no LLM keys, Neo4j, Qdrant), so each grade is "what the code makes
likely," not "what was observed." Every `file:line` carried into this document was
**re-verified against the working tree on 2026-06-10** while writing it; per-question
evidence not re-cited here lives in the batch records.

---

## 1. Scorecard

| Persona | n | Avg /10 | ≥7 (works) | 5–6 (coin-flip) | ≤4 (fails) | Expected confident-WRONG |
|---|---|---|---|---|---|---|
| Resident | 13 | **7.7** | 11 | 2 | 0 | 0 |
| Board member | 11 | **5.3** | 3 | 5 | 3 | 3 |
| Staff / manager | 38 | **4.1** | 9 | 2 | 27 | 26 |
| Adversarial-realistic | 8 | **4.9** | 3 | 3 | 2 | 2 |
| **All** | **70** | **5.0** | **24 (34%)** | **12 (17%)** | **34 (49%)** | **31 (44%)** |

Grade distribution: 9×2, 8×15, 7×7, 6×6, 5×6, 4×3, 3×24, 2×7. The mass at 3 is not
noise — it is one defect repeated (see §2).

The single most important number: **31 of 70 questions (44%) project to a confidently
WRONG answer**, not an honest empty. Tonight's sweep eliminated most silent-empty
failures (dead scope patterns); the dominant failure mode is now **unbound referents
narrated with confidence** — which is worse, and concentrated in two fixes (§3 W1/W2).

### Stage-attribution histogram (the "which stage is weakest" answer)

| Weakest stage | Count | Share |
|---|---|---|
| query-generation | 45 | 64% |
| response-generation | 6 | 9% |
| data-sync | 5 | 7% |
| tenant-scoping | 5 | 7% |
| retrieval | 3 | 4% |
| action-gating | 3 | 4% |
| scope-detection | 1 | 1% |
| persona-gating | 1 | 1% |
| none (clean) | 1 | 1% |

**Honest reading: the histogram overstates the LLM.** Of the 45 query-generation
attributions, **32 are one root cause that is not the model's fault**: the record id
from the page the chat was opened on never reaches the Cypher generator (28 staff
page:X questions + 4 board page:X questions). Add the 2 resident page:Union questions
attributed to retrieval (clicked suggestions drop page context at the source) and
**34/70 (49%) of the eval is a single context-binding break** with two one-line-class
fixes. Only 13 questions are genuine "LLM must compose hard Cypher" cases (multi-hop
polymorphic joins, 3-way UNIONs, string-typed aggregates).

So the per-stage answer to the maintainer's question is:

1. **Context binding into query generation** — dominant, 34 questions, fix is W1+W2.
2. **Query generation proper** (composition over polymorphic property-joins and
   string-typed decimals) — 13 questions, fix is W3+W5.
3. Everything else (data-sync ingestion prerequisites, tenant grain, action gating)
   is single-digit counts.

---

## 2. Top systemic findings (fewest real work items, by value)

### W1 — [HOST CONFIG] The two section-list overrides are stale vs package defaults — re-sync them
The single highest-value change in this report, and it is a config diff.
`CondoEdge/config/ai.php:1680-1697` (`query_generator_sections`) **omits
`PageContextSection`** — present in package defaults at `ai/config/ai.php:1321` and the
ONLY generation-prompt consumer of `PipelineContext->pageContext`
(`src/Services/PromptSections/PageContextSection.php:35-38` gates on it). Laravel's
`mergeConfigFrom` is top-level-shallow (`src/AiServiceProvider.php:112-115`), so the
host list wins wholesale. Result: every verified `AiChatFloating` mount (Unit, Invoice,
Supplier, Project, Maintenance, Budget, Meeting pages) passes the record id, and it is
discarded before the generator — "this unit / this invoice / this supplier" binds to
nothing, and the model narrates over an unbound `MATCH`. The same host list also omits
`NameResolutionSection` (package `:1323`) and `TenantScopingRulesSection` (package
`:1335` — without it the generator never self-scopes and complex aggregations ride
entirely on the post-hoc injector; board Q5 "motions and votes" degrades to silent
empty when the injector can't splice).

The response-side override `CondoEdge/config/ai.php:1699-1711` omits **IdentitySection**
(package `:1341` — the only home of the "answer in the user's language" policy; the
French question grades 7 instead of 9 because of it) and **ResponseGuardrailsSection**
(package `:1344` — anti-over-claim + out-of-domain scoping; its absence is why
"what's the weather?" gets "no results found, try rephrasing" framing and why the
compound legal question can over-claim).

**Impact: directly attributed in 30+ of the 34 confident-wrong/context questions.**
Fix: re-sync both lists with package defaults (or delete the overrides). Batch evidence
projects the staff average from 4.1 to ≈7 once the 28 page-scoped questions bind.

### W2 — [PACKAGE] `askSuggestion` drops page context — 1 line
`src/Kompo/MessagesQuery.php:343-360`: `askSuggestion` calls
`ChatPresenter::handleSend($conversation, $question, $responseStyle, $user)` — four
args, no `pageContext` — while the typed-question path forwards it. **Clicked
suggestions are the primary invocation path for every `page_examples.*` entry**, so
even after W1, clicked page suggestions stay context-less. Fix: pass
`$this->pageContext` as the 5th argument. W1 and W2 are a pair; ship together.

### W3 — [PACKAGE] DECIMAL columns sync to Neo4j as strings — all money aggregates are coin-flips
`calc_total_amount`/`calc_due_amount` are `decimal(14,2)`
(`CondoEdge/database/migrations/Migrated/2021_01_21_000055_add_total_due_amounts_to_charges_table.php:20-21`)
with no model cast (`CondoEdge/app/Models/Finance/Invoice.php:21-24` casts only the two
dates), so PDO yields PHP strings and `GraphPropertyMapper::extractProperties`
(`src/Services/GraphPropertyMapper.php:29-56`) passes them through as strings.
`sum(n.calc_due_amount)` is then a Neo4j type error and `> 0` a null-returning
cross-type comparison. Attributed in 6 financial questions across board + adversarial
batches (total owed, overdue list, units behind, supplier payables ×2, pets+owing).
Fix package-side (coerce numeric-string decimals at sync) or host-side (float casts),
plus a host business_rule mandating `toFloat()` until re-ingestion.

### W4 — [HOST CONFIG + PACKAGE] Tenant scoping fails open, and its grain is wrong for residents
Two related defects. (a) **Fail-open:** with no per-entity permission records,
`checkIfPermissionsExist()===false` + `on_permission_unexistent='bypass'`
(`CondoEdge/config/ai.php:305`, consumed at
`src/Services/Security/AiSecurityService.php:57-67`) returns **no team filter at all**
— every staff-batch global aggregate ("units we manage", "who owes money") spans every
team in the graph. (b) **Grain:** `permission_level='teams'` (`ai.php:288`) is
team-level only; a resident is a member of the union team, so the one-worder
"payments?" (grade 2) confidently lists **neighbours' payment transactions** — the
privacy-adjacent worst answer in the eval. Fixes: seed `{Entity}.AiRetrieving`
records (or adapter change) to close the bypass; treat `Membership.role='contact'` as
implicit row-level possessive scope for financial entities; make the Supplier team rule
NULL-tolerant (`team_id IS NULL` = shared central list, currently silently excluded
from payables breakdowns).

### W5 — [HOST CONFIG] Residual scope/action gaps the sweep missed
- **3 leftover full-query patterns** in the exact defect class tonight's sweep fixed
  elsewhere: `Resource.for_union` / `booked_during` / `never_reserved`
  (`CondoEdge/config/ai-entities/condo.php:1425, 1440, 1455`) are standalone
  `MATCH...RETURN` statements injected under "USE THIS EXACT CYPHER PATTERN IN YOUR
  WHERE CLAUSE" — a compliant model pastes invalid Cypher. One-line WHERE-fragment
  rewrites each.
- **Missing scopes that would de-risk composition:** Invoice `overdue` and
  `for_auth_user`, Reservation `pending` (currently inferred from a property-description
  enum), Meeting string-date "next" business rule.
- **Polymorphic Invoice↔Unit/Contact join is only taught on the possessive path**
  (`user_entity_paths.Invoice`, `CondoEdge/config/ai.php:715-723`); third-person
  questions ("which units are behind?") never see it. Promote to a business_rule.
- **No cancel_reservation action** — "cancel my reservation" must bridge to
  reject_reservation with no alias support.

### W6 — [PACKAGE] Actions are advertised unfiltered, then stripped — dead links in answers
`EntityAutoDiscovery::getGenericActions` (`src/Services/Discovery/EntityAutoDiscovery.php:794-806`)
returns every action with no authorize filtering; the response prompt and the
empty-path hint (`src/Services/ResponseGenerator.php:342-352, 391-416`) advertise them
all; the renderer then strips links the user fails authorize on — so a board member
gets a phantom "Owners registry" offer rendered as dead text (board Q7). Fix: thread
the user into the advertisement and filter by `authorize()` before the prompt.

### W7 — [HOST OPS] Ingestion prerequisites have no health checks
All 5 data-sync attributions are operational, not code: `ai:ingest --docs` must have
populated `documentation_chunks` (all 4 law questions degrade to honest-empty
otherwise), BoardMember needs current-year rows per union, and File `parent_type`
morph values need post-ingest verification. Fix: deploy checklist + post-ingest count
assertions before these questions enter any suggestion list.

### W8 — [HOST] Contact has no mountable page
`page_examples.Contact` is dead config — ContactForm is a modal the FAB can't live in,
so all 4 page:Contact questions grade 2-3 regardless of W1. Needs a routed Contact
page, in-modal chat affordance, or modal-context threading; until then rephrase the
suggestions name-based.

---

## 3. Would they prefer the AI over clicking through CondoEdge?

**Resident — YES, today.** Avg 7.7, nothing below 6, zero confident-wrong outcomes.
Action/navigation questions are 9s (double-surfaced links survive even empty-data
turns), knowledge/law 8s, possessive money/registry 7-8s on tonight's fixed paths.
Honest caveats: a QU outage on "do I owe" phrasing can produce a building-wide sum
(fallback regex gap), a private board meeting can surface ("meeting_public" never
forced), and a 3-family registry answer can silently drop one family. None of these is
the common path. The resident welcome list is shippable.

**Board member — SPLIT, leaning no for the job they actually have.** Avg 5.3. Legal/
knowledge questions are 8s and listings are fine, but the board's core duties —
financial oversight — sit exactly on the weak spots: every money aggregate is a
coin-flip on string-typed decimals (W3), supplier payables silently exclude
central-list suppliers (W4), and both page:Supplier questions (3s) return confident
wrong answers. A board member would use it for "what does the law say" and stop
trusting it the first time a receivables total disagrees with the report page.

**Staff / manager — NO, today; plausibly YES after W1+W2.** Avg 4.1 — the worst
persona, and the most damning because staff live on record pages: 28 of their 38
realistic questions are page-scoped and all currently produce confident answers about
the wrong records (every one attributed to the same missing `PageContextSection` +
clicked-suggestion drop). Their global action/navigation set (open unit, create
invoice, post announcement, reports — 8s) genuinely beats clicking, but it can't
carry the persona. This verdict is one config re-sync and one 5th-argument away from
flipping — the cheapest persona-level win available.

**Adversarial-realistic — NO.** Avg 4.9. The honesty machinery partially holds: typos
and French normalize fine through the QU LLM, "what's the weather?" degrades to an
honest (if database-flavored) abstention. But "payments?" leaking neighbours'
transactions (grade 2, W4) is disqualifying for messy real-world input, the compound
legal+personal question produces a grounded-sounding, citation-bearing, materially
incomplete answer (worse than empty), and there is no clarifying-question path for
vague one-worders — the pipeline always executes the broadest match.

---

## 4. Before / after: what tonight's sweep actually bought

Tonight 46 dead Cypher patterns were rewritten to valid WHERE-fragment/edge forms and
3 suspects resolved. Of the 70 evaluated questions, **12 load-bear directly on
patterns fixed tonight** — yesterday all 12 would have been silent failures (dead
pattern → 0 rows → honest abstention, or an injected full-query pattern → unrunnable
Cypher):

- Resident: "how much do I owe" (`paths.Invoice` polymorphic property-join,
  `CondoEdge/config/ai.php:715-723`), vehicles/pets/parking (Pet/Extra paths + sweep),
  next-meeting possessive variant (`paths.Meeting` Unit→`[:UNION]`→Union hop,
  `:772-779`), board-this-year ×2 (`current_board_members` property anti-join,
  `contact.php:93-104`), announcements (`for_union` EXISTS + `recent` string-date,
  `condo.php:271, 296`) — 6 questions, now grading 7-8.
- Board: both page:Supplier questions ride the fixed `Bill.for_supplier` EXISTS form
  (`finance.php:1015`) — the scope now works, but they still grade 3 because the
  **id-binding break (W1/W2), not the scope, is now their weakest link**.
- Adversarial: "owe rn feez" (paths.Invoice), French AGM (Unit→Union hop), pets+owing
  (Pet.for_unit post-sweep), cancel-reservation data half (`paths.Reservation` edge
  form, `:786-791`) — 4 questions.

Plainly: **yesterday ~17% of this eval would have been silent-empty; today those
execute valid graph forms and 10 of the 12 grade 5-8.** The sweep converted the
failure profile from "silently returns nothing" to "answers, with the remaining risk
moved up-stack to binding and composition" — which is what made the W1/W2 cluster
visible as the new dominant defect. The eval also caught the sweep's only known
stragglers: the 3 Resource scope patterns (W5), same defect class, missed because
Resource wasn't in tonight's suspect list.

---

## 5. Next capability: Polymorphic edge sync (94c) — verdict: **implement-with-fixes**

Design: `docs/audit/94c-polymorphic-edge-sync-design.md`. The adversarial design review
confirms the root-cause chain end-to-end (all spot-checked citations accurate):
discovery already captures the morph column (`'morph' => $relation->getMorphType()`,
`src/Services/Discovery/RelationshipDiscoverer.php:418`) alongside the `_polymorphic`
sentinel (`:416`); `RelationshipConfig::fromArray` drops it
(`src/Domain/ValueObjects/RelationshipConfig.php:47-61`); and all three sync paths pass
`_polymorphic` verbatim into `nodeExists`/`MATCH`
(`src/Services/RelationshipSyncer.php:60-63`;
`src/Services/BatchDataIngestionService.php:171, 323-325`) — guaranteed miss on every
row. All 12 host `_polymorphic` edges follow the standard `<base>_id` convention, so
the FK-derived fallback is necessary and sufficient. No double-edge risk from tonight's
property-join guidance strings, no phantom nodes (batch endpoint is MATCH not MERGE),
order-independent resolution, BC-safe constructor change.

**But the review found one genuinely new failure mode the design misses:** the fix
*activates a dormant prompt-rendering bug*. `RelationshipsSection`'s live-type filter
(`src/Services/PromptSections/RelationshipsSection.php:44-49`) currently hides all 12
polymorphic relationships from the LLM because their types don't exist in Neo4j;
post-backfill they will exist, and the section will emit
`Cypher: MATCH (x:Invoice)-[:CUSTOMER]->(y:_polymorphic)` (`:80-84`) under a
"use ONLY these" header — queries copying that label return zero rows. The design's
test plan (sync-layer only) ships this regression green.

**mustFix before implementing:**

1. Handle `_polymorphic` in `RelationshipsSection.php` **in the same change set**: render
   an unlabeled target (`(File)-[:PARENT]->(x) // polymorphic`) and never emit
   `y:_polymorphic` in the Cypher template; unit-test the prompt output. Audit the other
   raw `target_label` consumers (`EntityAutoDiscovery.php:484/620/638`,
   `TeamPathResolver.php:159/217`) for the same leak.
2. Correct the §2.5 wiring: `DataIngestionService` takes `?RelationshipSyncer`
   (`src/Services/DataIngestionService.php:41-52`), not a resolver — the provider must
   build `new RelationshipSyncer($graphStore, $resolver)` and pass it (and share the
   resolver with `BatchDataIngestionService`), or drop the shared-memo-cache claim.
3. Initialize `relationships_unresolved_morphs` in `BatchDataIngestionService`'s summary
   array (`:142-149`) as well as `RelationshipSyncer`'s before incrementing; assert it
   in the batch test.
4. Add the stale-edge case to the failure-mode table: a morph re-point after edge
   creation leaves the old edge (update path syncs no relationships,
   `DataIngestionService.php:400-402`; `deleteRelationship` is never invoked) —
   reconcile adds-beside; §6.2 spot-check counts are upper bounds. Parity with
   non-polymorphic FK changes, but say so.
5. Fix the §2.1 overstatement: `ai:discover`'s `mergeWithExisting` preserves existing
   relationship arrays, so current host configs will rely on the convention fallback
   indefinitely — the fallback is load-bearing, not a convenience.

---

## 6. Recommended order of work

| # | Item | Side | Size | Flips |
|---|---|---|---|---|
| 1 | W1 section-list re-sync (both lists) | host config | config diff | ~30 questions wrong→good class |
| 2 | W2 `askSuggestion` pageContext forward | package | 1 line | all clicked page suggestions |
| 3 | W3 decimal-as-string sync coercion | package (+host rule) | small | 6 money questions coin-flip→reliable |
| 4 | W5 Resource scope rewrites + missing scopes/rules | host config | 1-line each | 4-6 questions |
| 5 | W4 tenant-scoping close-the-bypass + resident grain | host + package | medium | staff aggregates, "payments?" leak |
| 6 | 94c polymorphic edge sync (with the 5 mustFixes) | package | medium | unlocks Price/File/Task/Note graphs |
| 7 | W6 authorize-filtered action ads; W7 ingest health checks; W8 Contact mount | mixed | small each | polish + 4 Contact questions |

After items 1-4 land, re-run this 70-question eval: batch evidence projects overall
≈5.0 → ≈7, staff 4.1 → ≈7, board 5.3 → ≈6.5, with the confident-wrong count dropping
from 31 to single digits. Items 1-2 alone are the morning's best hour.
