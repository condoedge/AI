# Config & prompt noise audit — 2026-10-06

Owner question: "are we in the best version, or do we still have noise in the CondoEdge and ai configs?
Remove what adds zero improvement and only noise — carefully."

Answer: **not yet the leanest version.** Three read-only audits (reports in this folder):
[package config](audit-package-config.md) · [CondoEdge entity configs](audit-entity-configs.md) ·
[CondoEdge ai.php + prompts](audit-host-config-and-prompts.md).

Evidence caveat (applies to every "never used"): the eval is ONE persona (team lead) and `ai_query_logs` is
dev/eval traffic (120 distinct questions), the graph a dev graph. Code-level proof ("no reader") is strong;
"never fired" is weak and needs a production check or an A/B eval.

## Tier 1 — provably inert (no code reads it / cannot change a prompt, ingest or scope) ≈ 2,200 of ~15,000 lines
| Where | Lines | What |
|---|---|---|
| CondoEdge `ai-entities/*` | 1,633 | `common_properties` entries the card builder always drops (549); discovery-only relationship keys `inverse_type`/`inverse`/`inferred`/`discriminator_fields`/`pivot_conditions` (405); `security.team_resolution`/`team_query_scope`/`multiple_teams`/`has_owner_bypass` (292 — scoping reads only `team_path` + `graph.relationships`); scope `filter` on non-`property_filter` scopes (291); 3 stub files (48); misc scope/security keys (48). `ai:discover` must stop emitting them or they come back. |
| CondoEdge `ai.php` | ~340 | 281 comment lines narrating past fixes; `chat.page_examples` = 8 empty arrays; + ~49 lines of action `aliases` nothing reads (needs the interface decision) |
| Package `config/ai.php` + lang + env | ~200 | 10 dead keys, 11 no-op keys, ~46 stale comment lines, 23 unreferenced lang keys ×2, dead env names |
| Host mistakes | few | `ai.entities` loads all 16 entity files a second time (nothing reads it; the eval fingerprint hashes it — switch the fingerprint to `config('entities')` first); host tunes `scope_matching.threshold` = 0.75, a key nobody reads (the live one is `semantic_matching.scope_threshold` = 0.7) |

## Tier 2 — changes behaviour: one A/B eval run each
- QU entity catalogue: 159–199 alias variants of the label push real synonyms out of QU's first-5 window (~550 tokens/turn).
- Scopes: 22 of 202 ever injected; 94 generic tenancy scopes (1,241 lines) never appear in any cypher; 10 scopes with no `cypher_pattern` took 2 of 3 scope slots on 5 turns.
- 13 generic actions with no placement (unreachable, ~96 lines) still listed in the generation prompt every turn; 4 write actions have no producer since the tool loop was deleted (owner decision).
- Prompt sections: persona + vendor-secrecy in the Cypher-generation prompt (458 tok/call); ACTION REQUESTS (525); field-projection rules duplicated by REQUESTED ATTRIBUTES (~400); contradicting rules (scope pattern vs canonical formula; always-LIMIT vs formulas without; four different instructions about closing offers). Upper bound ≈ 21% of input tokens.
- 14 never-selected entities (1,675 lines; removal must be paired with node deletion); per-entity `vector` blocks (1,395 lines, 302k Qdrant points nobody searches — needs a package change first: a missing block rolls back the graph node on single-entity ingest).

## Worse than noise — wrong or broken (found by the audit)
- **FIXED 2026-10-06:** QU stopped emitting `corpus_translations` / `register_expansions` on every knowledge turn from p10 on (26/26) — both gpt-4o-mini and gpt-4o skip the keys when their docs sit at the end of the key list (even with p9's original prompt, replayed live). Moved right after `search_tags`; verified live (legal questions get "copropriété divise" / "portions privées" probes again). Explains the legal-retrieval plateau since p10.
- `Union.view` action ignores `$id` → 27 record chips named after different unions all open the same page (CondoEdge ai.php ~796).
- Three false join hints in every Invoice / Unit schema card (Invoice→`EftLine` label no longer exists; Invoice `team_id` set on 0/6,006; Unit `represented_union_id` 0/1,877).
- `needs_clarification` true on 0 of 148 turns incl. the 6 designed for it — the clarify feature never fires (264 tokens/turn of instructions).
- `context_index` has no relationship points (selector reads a key the host never sets); VisitDetail `team_path` goes through an edge that does not exist; `union_manager` gated staff-only on a permanent-role route; `Thread` user path uses `EXISTS {}` the package's own shape rule forbids.
- Wrong comments: null file `access_scope` "allows all files" (code falls back to the user/team filter); theme defaults; digest rate-limit cost.
- Docs: 52 documented env vars exist nowhere; onboarding still describes the deleted pattern library.

## Owner direction 2026-10-06 → done the same day (full suite 3390 OK, context index rebuilt 2324 points)
"Unused actions could be USED; reduce entities nobody could ask about; synonyms matter — keep them."
- **Synonyms** — `src/Support/AliasNormalizer` (case, accents, separators, one trailing s/x per token; no stemming,
  no word lists). Every alias consumer goes through it (QU catalog + entity-type validation, EntityLabelIndex
  `labelForTerm`, semantic index, hybrid lexical, schema cards). Host aliases 751 → 311 (440 were spellings of the
  label or of an earlier alias; verifier: identical normalized key set for all 73 entities). QU now sees
  **296 of 311 real synonyms (was 115)** with the cap at 12, catalog −4% tokens. Open: `ai:discover --enhance`
  generators still emit the variants (runtime unaffected); 10 synonyms are shared by two entities and never resolve.
- **Actions** — 5 of the 13 unreachable actions placed (calendar → Event/Reservation list; files manager → File list;
  chart of accounts → Account list; P&L → Account report; financial statements → Fund report); balance sheet placed
  but dormant (AccountBalance has no money_properties); 7 need a package "open page X" path or are not real pages
  (docs = "coming soon"; my_statement 403s for residents). `Union.view` took no id (always the CURRENT union; switching
  is a form POST) → per-union chips removed pending an app route `union/{id}/view` (owner decision). Fixed:
  union_manager gate (permanent, not staff) + link (was the blank new-union form); support link (drawer).
- **Entities** — [classification](entity-classification.md): 69 of 73 askable; EmailAccount structural-only;
  MessageForward / Folder / AccountBalance recommended dead (AccountBalance only attracts "show me the balance"
  mis-routes); the real mass is **51 orphan graph labels, 406,399 nodes (55% of the graph)** left by the July trim —
  49 recommended for node deletion; `Address` and `Distribution` look wrongly trimmed. Owner decisions pending.

## Not bloat (keep)
Descriptions (181 chars avg, rendered in full), enum legends, `money_properties`, `graph.computed/with/casts`,
`security.team_path`, `sensible_columns`, the 22 injected scopes, all calculations / attention sources /
`user_entity_paths`, every authorize gate. `graph.properties` + `graph.relationships` (~3,000 lines) are the ingest
mapping and feed tenant scoping — **machine-derivable, not deletable**: the real path to "near-zero config" is
deriving them at discovery/boot instead of keeping them in files.
