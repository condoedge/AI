# CondoEdge help articles — prompt for a new session

Copy everything inside the fence below into a fresh Claude Code session opened in
`C:\Users\jkend\Documents\Projects\kompo\CondoEdge`. It is self-contained: the new session has no memory of the Coolecto work.
Written 2026-10-22 from a research pass over CondoEdge (`research-condoedge-kb.md`, facts restated inside the prompt).

````text
You are building the in-app help knowledge base (KB) of CondoEdge, the way Coolecto already has one, and wiring it into the AI
assistant so each user only reads the articles meant for them. Work in phases; each phase ends with a gate. Do not skip gates.

# 0. Who you work for and how
- The owner reads ENGLISH ONLY. Articles are written in French (Québec), because CondoEdge's UI locale is French. Whenever the
  owner must judge French content (article lists, samples, eval questions), give an English summary/rendering next to it. Never
  ask the owner to proofread French.
- The owner manages git. Read-only git is fine; never commit, stash, checkout, reset or branch. `config/ai.php` has uncommitted
  edits on branch `dev` — never overwrite them; edit with exact string replacements only.
- Ask before anything destructive or outward-facing: deleting files, migrations (`php artisan migrate`), deleting/recreating Qdrant
  collections that other apps use, sending email, touching production.
- Tests run on the local dev database: transactions only, never `RefreshDatabase`, assert on your own rows. Run tests with
  `php artisan test`.
- Code and comments in English; comments short (3 lines max inside a class). Credentials only in `.env`.
- Windows + CRLF files: edit PHP with the Edit tool (keeps line endings); bash heredocs collapse backslashes — write scripts with
  the Write tool.
- Large work goes to background agents with a strict written contract (§3.3), few agents at a time; validation is mechanical
  (scripts), not an agent-reviews-agent loop. Keep the token budget reasonable.
- Keep a progress log at `docs/plans/condoedge-kb-progress.md` (create it): one entry per step with what changed (file:line),
  numbers, and open items.

# 1. The reference you mirror (Coolecto, read-only)
Repo: `C:\Users\jkend\Documents\Projects\kompo\ActiviteDeFinancement`.
- Articles: `resources/ai-help/aide/<audience>/<slug>.md`, one folder per audience (organisateur 320, acheteur 57, participant 27,
  fournisseur 54). Read at least `organisateur/campagne-gestion-supprimer-ou-modele.md`, `acheteur/achat-code-promo.md` and
  `organisateur/billets-prix-gratuit-et-frais-par-billet.md` to absorb the style.
- Frontmatter (YAML): `titre`, `public` (audience, = folder name), `pages` (list of Laravel route names where the topic lives),
  `chemin` (UI path, segments joined by ` > `, e.g. `Campagne > Modifier la campagne > Bases > Gestion de la campagne`; segment 2
  = the page as named in the menu, segment 3 = the tab or card title), `mots_cles` (search words users would type),
  `voir_aussi` (related slugs). Body: French, task-oriented, UI labels quoted exactly in « » as the French UI shows them,
  short sections (`## Où …`, `## Étapes`, `## À savoir`), no code/route/component names, no invented numbers.
- Wiring (`config/ai.php` of Coolecto): `file_context.physical_paths`, `physical_collection`, `corpus_languages`,
  `file_context.frontmatter.keys` (title=>titre, audience=>public, breadcrumb=>chemin, keywords=>mots_cles, related=>voir_aussi),
  `audiences` (resolver class + per-audience `documents` it may read, `insider_voice`, `prompt` with `{role}`, `example_questions`).
  Resolver: `app/Ai/AudienceResolver.php` (returns `new ResolvedAudience($key, $role)` or null).
- The AI package is `vendor/condoedge/ai` = a symlink to `C:\Users\jkend\Documents\Projects\kompo\ai` (shared by Coolecto and
  CondoEdge). Do NOT change the package for this work unless a gate proves it necessary; if you must, ask first (every package
  change must keep Coolecto green and CondoEdge's cassette replay explained). Package docs: `ai/docs/plans/coolecto-progress.md`
  (how the Coolecto KB was validated, ingested and evaluated — reuse its methods).
- Ingest: `php artisan ai:ingest --docs < /dev/null` (incremental, skips unchanged files); frontmatter is stripped from chunk text
  and stamped as metadata (`audience` is stored as a LIST). Coolecto isolates its Qdrant collections with
  `QDRANT_COLLECTION_PREFIX=coolecto_` in its `.env`.

# 2. What CondoEdge is (facts from code, verify before relying on them)
- Laravel 10 + Kompo; condo/HOA management for Québec syndicates. Hierarchy Team → Union (copropriété) → Building → Unit →
  ContactUnit ↔ Contact (↔ User). Context: `users.current_team_id`, `users.current_union_id`.
- Roles: ONE string per user per team on the membership (`app/Rbac/Role.php`, labels `resources/lang/fr/roles.php`):
  `super-admin` (staff of CondoEdge — exclude), `lead` (Gestionnaire en chef), `manager` (Gestionnaire), `accountant` (Comptable),
  `assistant` (Adjoint.e administratif), `concierge` (read-only), `contact` (Copropriétaire ou locataire), `supplier`.
  `HasRoles::getRole()` aborts 403 when the membership row is missing — never call it in the resolver without checking.
- Board member = a `contact` who is in the current union's latest `board_members` year (`HasRoles::isBoard()`/`isBoardMember()`,
  `app/Models/Traits/HasRoles.php:54-61,177-180`). Owner vs tenant = `contact_unit.link_id` (1 owner-occupying, 2 owner not
  occupying, 3 renter renting, 4 renter occupying; `app/Models/Contact/ContactUnit.php:22-25`); `User::isOwnerOf($unitId)`.
- Menus per persona: `app/View/Menus/SidebarManager.php`, `SidebarContact.php`, `SidebarSupplier.php`, `SidebarCommon.php`,
  `NavbarManager.php`, `NavbarContact.php`. Modules per team `has_gestion`, `has_finance`, `has_maintenance`
  (`app/Models/Team.php:515-528`): menus disappear when a module is off → staff articles need a precondition line.
- Per-union toggles residents hit: `unions.access_level`, `finance_visible`, `reservable_items`.
- Areas → routes → komponents: see `routes/web.php` (+ `php artisan route:list --json` is the source of truth for route names)
  and `app/View/<Area>/`. Main areas: union page, units registry, contacts/owners/tenants/board, finance (receivables, payables,
  budgets/contributions/funds, accounting, banking/PPA/EFT, reports), registries (owners/unit/emergency/fractions), meetings and
  votes, announcements, reservations, calendar/visits, tasks, files/documents, inbox/messaging, discussions (board), suppliers/
  projects/quotes/maintenance/reserve fund, settings/team/subscription, support.
- Label trap: French labels often differ from key names (`menus.payables-per-vendors` = « Balance de vérif. »,
  `menus.services` = « Maintenance », `menus.payments-menu` = « Débits bancaires », `menus.quote-part` = « Fractions »). Always
  read `resources/lang/fr/*.php` and `resources/lang/fr.json`; some labels are raw literals in code.
- Current AI setup (`config/ai.php`): assistant persona "Maryanne"; it is a DATA + docs assistant (Neo4j graph + Qdrant), unlike
  Coolecto's docs-only one. `file_context.physical_paths` = `public/laws/**/*.md` (English CCQ text, keep) and `docs/ai-help/**/*.md`
  (6 short English DRAFT files, no frontmatter, staff-oriented — to be replaced by the new KB, owner decision). No `audiences`
  block (no filtering today), no `frontmatter.keys`, no `navigation.pages`. Users' uploaded by-laws/declarations are ingested
  separately as per-record file chunks — do not touch that path.
- Another help system exists: the navbar "?" opens `knowledge.articles` (DB-backed CMS wiki, `vendor/condoedge/cms`), not read by
  the AI. Placeholders `docs` and `help` routes say "coming soon". Public marketing articles (`resources/views/articles/*`) are
  not user help — do not ingest.
- Regression guard: CondoEdge has an AI eval suite `tests/eval/gold-conversations.json` and a recorded cassette
  `storage/eval/gold.cassette.json` (ai-eval package, replayed with `AI_EVAL_CASSETTE=replay AI_EVAL_CASSETTE_PATH=… php artisan
  ai:eval --suite=tests/eval/gold-conversations.json --user=13 --fresh --skip-preflight < /dev/null`). Adding articles and
  audiences WILL change CondoEdge's retrieval and prompts, so a replay cannot stay at 0 by construction: the plan below handles it
  with a before/after live comparison and a re-record approved by the owner. Never run two `ai:eval` runs in parallel; run from
  the CondoEdge dir with `< /dev/null`.

# 3. Phases

## Phase 1 — decisions (ask the owner with AskUserQuestion, English options, recommended first)
1. Audiences. Recommended: `coproprietaire` (contact, not board; tenants included, with owner-only topics marked), `administrateur`
   (board member), `gestionnaire` (lead/manager/assistant/accountant/concierge, `{role}` in the prompt). Ask whether tenants
   (`locataire`) need their own folder, and whether the supplier portal is live (if not, no `fournisseur` audience).
   Which folders each audience READS (recommended: administrateur reads coproprietaire + administrateur; gestionnaire reads all
   three; coproprietaire reads coproprietaire).
2. Location and collection: `resources/ai-help/aide/<audience>/` (same as Coolecto), dedicated Qdrant collection name, and
   whether to delete `docs/ai-help/*.md` from `physical_paths` (recommended: replace).
3. Languages: French articles only (recommended, `corpus_languages` stays `['fr','en']` because the UI is bilingual and the
   CCQ text is English) — English replication is a later phase.
4. Scope and order: residents + board first (~100 articles), then staff by module (gestion, finance, maintenance, settings).
5. Whether the DB wiki (`knowledge.articles`) content should be exported as a SOURCE for writing (read-only query; ask).
Record the answers in the progress log. Gate: owner answered.

## Phase 2 — survey and the article list (no articles yet)
1. Build a facts sheet per audience from code: every menu entry (sidebar/navbar) with its French label, route name (verified with
   `route:list --json`), komponent, tabs/cards (their French titles from the lang files), role/module/toggle conditions.
   Save as `docs/kb/survey-<audience>.md`.
2. Derive the article list: one article per user task or screen question (how to X, where is Y, why can't I Z), not per
   komponent. For each planned article: slug (French, kebab-case, prefixed by area like Coolecto: `reunions-…`, `finances-…`),
   `titre`, `public`, `pages`, `chemin`, 1-line scope, conditions (module/toggle/role), sources (file:line). Save
   `docs/kb/article-plan.md` + an English summary table for the owner.
Gate: the owner approves the list (English summary). Expect roughly: coproprietaire 50–70, administrateur 30–45 (on top),
gestionnaire 250–350 later phases.

## Phase 3 — writing (background agents, batches of 20–30 articles per agent, ≤ 4 agents at once)
Agent contract (paste into every agent prompt):
- Write ONLY the files of your batch under `resources/ai-help/aide/<audience>/`, UTF-8, LF.
- Frontmatter exactly: `titre`, `public`, `pages` (only route names from the batch sheet), `chemin`, `mots_cles` (4–10, what users
  type, including colloquial Québec terms), `voir_aussi` (slugs from the plan only).
- Every « label » must be copied from `resources/lang/fr/*.php` / `fr.json` (give the key in an HTML comment at the end of the
  file: `<!-- labels: menus.payables, condo.add-unit -->`) — no guessed labels.
- Describe the software, never the law (no legal conclusions; the CCQ text is separate). No numbers (fees, delays, limits) unless
  read from code (cite in the trailing comment).
- NEVER include: real names/emails/phones/addresses/unit numbers, emergency contacts, access/intercom codes, vehicle plates,
  insurance numbers; bank/transit/account numbers, PPA/EFT provider keys or file formats, Stripe/OAuth internals; super-admin,
  impersonation, imports, debug/log routes, data-fix tools, AI admin; schema/ledger internals; draft features (the TEMP "Wizard"
  link, `docs`/`help` placeholders, commented-out routes). Use obviously fake examples.
- Staff-only screens are `public: gestionnaire` even if a board member can reach part of them — when a board member can, write a
  separate `administrateur` article for what they see.
- Owner-only topics for residents (pre-authorized debits « Débits bancaires », owner review) say so in the first paragraph.
- Each article ends with `## À savoir` (conditions: module off, toggle off, role) when any condition applies.

## Phase 4 — mechanical validation (a PHP script, `scripts/kb/validate.php`, Write tool)
Checks, all must be 0: YAML parse errors; `public` ≠ folder; unknown audience; `pages` route not in `route:list`; `voir_aussi`
slug not found; duplicate slugs/titles; a « label » not found among French translation values (normalize spaces/quotes; report
key suggestions); forbidden patterns (emails, phone numbers, `\d{3}-\d{3}-\d{4}`, `route(`, `->`, `App\\`, `.php`, "super-admin",
"impersonat"); articles without a trailing labels comment. Also print coverage: menu entries of the survey without any article.
Gate: 0 errors; coverage report reviewed.

## Phase 5 — wiring (CondoEdge `config/ai.php`, `app/Ai/AudienceResolver.php`, `.env`)
- `app/Ai/AudienceResolver.php` implementing `Condoedge\Ai\Contracts\AudienceResolverInterface`: no current team → null;
  super-admin → null (reads everything? ask; recommended: gestionnaire); staff roles → `gestionnaire` with role; contact + board
  in the current union → `administrateur`; other contact → `coproprietaire` (role `owner`/`tenant` from `link_id` of their unit in
  the current union); supplier → per Phase 1. Unit-test each branch (fixtures in transactions).
- `config/ai.php` (exact-string edits only, keep the uncommitted edits): `file_context.physical_paths` (KB path + keep laws),
  `physical_collection` per Phase 1, `frontmatter.keys` mapping as in Coolecto, `audiences` block (resolver, `documents` per
  audience, `insider_voice` true for gestionnaire only, short role prompts in English, 3 French example questions each).
- `.env`/.env.example: nothing secret is added; if a Qdrant prefix is chosen, document it.
- Ingest: `php artisan ai:ingest --docs < /dev/null`. Verify with a Qdrant scroll script: chunk count, 0 chunks containing
  frontmatter text (`titre:`, `voir_aussi:`), 0 without audience, 0 whose audience ≠ folder, the 6 old drafts gone if replaced.

## Phase 6 — evaluation
1. Before/after on the existing gold suite: run it live once BEFORE wiring (baseline report) and once AFTER; compare verdict counts
   and every turn whose branch/answer changed; read the changed answers. Report to the owner in English; after approval, record a
   new cassette (`AI_EVAL_CASSETTE=record …`, back up the old cassette first) so later package work is guarded again.
2. A new KB suite `storage/eval/kb/*.json` (~60 turns for residents+board): questions paraphrased from article TITLES only by an
   agent that never sees the article (reject any question sharing a 4-word sequence with the article or quoting a label; rewrite),
   gold = slug + audience + chemin + labels; traps: another audience's topic (owner asks a staff-only finance task; tenant asks an
   owner-only topic), board vs owner rights, out of scope, legal-advice requests (must not conclude law), data questions (the
   assistant can query data here — check it does not leak another unit's data), small talk. Users per conversation = existing
   local users resolved by your resolver (owner, tenant, board member, manager).
   Gates available in ai-eval: `retrieves_doc`, `doc_audiences`, `audience_is`, `quotes_grounded`, `no_placeholders`,
   `cites_document`, `max_latency_ms`, `branch_in`, `no_cypher` (use only on doc-only turns), `max_tokens`.
3. Read every answer; fix in one place (article, config or — with the owner's OK — package); re-run the affected chunk; log.
4. Document map investigation (required before the owner signs off Phase 6). CondoEdge keeps `ai.file_context.document_map`
   ON (it was measured to help on long by-laws and laws). Coolecto turned it OFF on 2026-10-22 after an A/B on its 80-turn
   help-article suite (see `docs/plans/coolecto-progress.md`, entry E10): no gain, +27% tokens per turn, and one real
   regression on a follow-up. Cause: a map turn skips the search probes (`AiManager::mapTurnPlanned()` → no
   `writeSearchProbes()`), and on a follow-up the probe is the angle that carries the earlier turn's subject; the reader
   then only sees the map of the wrongly retrieved files, its gate stays closed (`DocumentMapTurn`, "The gate"), nothing
   rescues. Also: the reader runs only when EVERY admitted file is mapped (`DocumentMapTurn::turn()`, "THE SAFETY RULE"),
   so an unmapped help article admitted beside a law turns the whole turn into a plain turn.
   Do, in order, and report each step's numbers in English:
   a. Run the KB suite and the gold suite twice: `AI_DOCUMENT_MAP=false` and `true` (articles mapped first with
      `php artisan ai:documents:map --backfill < /dev/null` — slow, ~1 article/min). Add 5+ follow-up turns to the KB suite
      (short questions that only make sense after the previous turn). Repeat every turn that differs 3× per mode to separate
      noise from effect.
   b. If the map hurts help-article turns, design the fix with the owner (AskUserQuestion, recommended first). Options to
      measure, not assume: (1) package — still write probes on a FOLLOW-UP map turn (one extra small call there only);
      (2) package — decide the map per document kind, so long legal documents use it and short how-to articles don't, with a
      clear rule for turns that admit both (today such a turn is not a map turn at all); (3) config only — map off for the
      help-article collection. Prefer the option that improves both corpora; it must be a config entry, not a hardcoded list.
   c. TDD for any package change; full package suite green; CondoEdge gold cassette replays 0 mismatches before re-recording;
      Coolecto's suite re-run with its map still off (no regression) and once with it on (does the fix make it worth enabling?).
Gate: owner sees the English report: per-audience results, failures with causes, fixes made, the map decision.

## Phase 7 — staff modules (repeat Phases 2–6 per module: gestion, finance, maintenance, settings), finance last and strictest.

# 4. Definition of done (per phase)
Progress log updated; validation script 0 errors; ingest verified; eval report with numbers; CondoEdge gold suite compared and
re-recorded with approval; nothing committed by you; open questions listed for the owner in English.
````
