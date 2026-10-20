# 2026-10-08 — uploaded documents: duplication root cause, linking to buildings, baseline

Owner rule: UPLOADED files are dynamic and must be LINKED to the record they belong to; PROJECT ("physical")
files are GENERIC / GLOBAL. Owner question: should uploaded files get the document map / reasoning step?
Plan approved ("do all the steps"): dedupe → link + filter by building → fence names / headings → eval suite +
baseline → map and measure → switch `document_map.uploads` on only if it gains without wrong-building answers.
Reports: scratchpad `files/files-investigation.md`, `uploads/decl-baseline-{1,2}.analysis.txt`.

## What the uploaded files are
`file_chunks` = 3,964 chunks = 38 PDFs = ONE document: a scanned 1990 declaration of co-ownership (French, OCR
noise, 110 chunks, article numbers, no headings) uploaded 36 times + 2 copies of a 2-page excerpt.

## Root cause of the 36 copies (traced) — NOT a project file
Manual test uploads through the app's upload dialog on 2026-03-20 / 21 (file rows 14649–14686, the last 38 rows of
the table), the same PDF picked from the developer's Downloads folder (same sha1; written 11 s before the first
row). 32 are filed on one unit of union 14 (team 1) as its "insurance proof", 6 on two units of union 66 (team 35);
38 separate stored objects. No seeder / test / command / eval step creates File rows; the PDF exists nowhere in the
project trees. Project files and uploads are cleanly separated today (`documentation_chunks` holds only
`physical:` ids, `file_chunks` only integer ids). There is NO dedupe anywhere: the 35 nearest neighbours of a chunk
are its own copies at score 1.0. Nothing deleted (they are the only uploaded documents readable on this machine).

## Linkage facts
- `files.union_id` is set on 7,958 of 8,286 live rows and matches the parent's union with 0 mismatches; morph
  parent on 3,698 rows (unit 1,221, union 1,006, meeting 326, bill 290, …).
- The ACL is team-wide for staff (user 13: 2,472 files across 20 unions — none with bytes locally, so the eval has
  NEVER exercised uploaded files).
- The chunk payload carries no owner; search filters only by accessible file ids; page context reaches document
  search as embedded text only.
- Probable bug: `FileProcessingPlugin` queues a closure testing `wasRecentlyCreated` — false after a database-queue
  round trip → new uploads never indexed. The host's `CondoEdgeFileContextEnricher` tiers files by a filename WORD
  LIST (forbidden) and reads a relation that does not exist.

## Step done: file names and headings fenced
`Support/UntrustedLabel` (structural sanitiser) + names / heading paths moved inside the `<retrieved_document>`
fence in the composer, listing, previous-turn, query-side, map-reader, map-builder and follow-up prompts; user
citation cards keep the real names. 17 new tests with hostile names / headings. Live: tear-down (gate open,
three-part rule) and how-to unchanged; composition +0.7–1.1% tokens.

## Baseline on the uploaded declaration — `CondoEdge/storage/eval/suite-declaration.json`
12 single-turn questions (4 direct, 5 inference, 2 declaration + Civil Code, 1 not answered; FR + EN), user 433
(management, team 35), two runs: **mean 0.33 / 0.34, 0 of 24 turns pass** (direct 0.45, inference 0.29, both
sources 0.23, absent 0.35). ≈ 18–19k tokens and 18.8 s per document turn.
- 44% of passage slots hold a passage already shown from another copy (≈ 40% of composition input); the copies
  crowd the Civil Code out: 0 Code passages on both "declaration + Code" questions; duplicate source cards.
- The Civil Code presented as "your declaration" (insurance): the map reader read only the LAW's map (the
  declaration is unmapped), opened its gate on a Code article and the analysis block steered the composer onto it.
- "As an accountant, you are allowed to see clients in your unit" — the declaration forbids any profession: on a
  map turn the probes are dropped and the English question reached the French text through one angle.
- One inference question is routed as a data question (no document search at all).

## Built: linking + dedupe (unit suite 4,806 green; 8-turn smoke unchanged)
- Every uploaded chunk carries `owner` (e.g. `unit:873`), `ancestors` (`["building:71","union:66"]`) and a
  `dedupe` key — derived with no host code from the File's morph parent and the File entity's declared belongs-to
  relationships; `ai:files:link` backfilled all 3,964 points payload-only (vectors and ids identical). Project files
  carry no keys (global); `ai:ingest --docs` refuses a physical collection equal to the uploads collection.
- The question's scope (`Retrieval/DocumentScope*`): page record → conversation record → previously cited files →
  the host's current-scope attribute map (`ai.file_context.scope.current`, CondoEdge: `['union' =>
  'current_union_id']`). Uploads are filtered to: the record's own files, its building's files, files of records
  under it — never a sibling's, never silently widened (live: a sibling unit's page gets no declaration passage).
  NOT built: resolving a record only NAMED in the question; "which building?" reply chips.
- Dedupe before any limit (Qdrant `group_by` on the dedupe key): identical text under one owner is one candidate;
  identical text under different owners is shown once, naming every owner.
- Headers say whose document a passage is ("GENERAL document, applies to everyone" / "document of unité "A-101"
  (copropriété "…")"); citation cards show it too.
- Safety rule: the map reader runs only when every admitted file is mapped.
- Bug fixed (reproduced first): uploads were never indexed on a database queue (`wasRecentlyCreated` lost on
  re-fetch). The host's filename-word-list enricher is removed (backup: ai-backups/…/round5/).

**Measured (blind graders, same rubric): baseline 0.325 / 0.317 → 0.379 / 0.321.** Duplicate slots 44% → 0;
Code passages back on 3 of 4 "both sources" runs; the reader no longer reads only the law's map. The MEAN is
within noise: the deciding clause is often not retrieved at all, and the Code is still sometimes presented as the
declaration — a retrieval / reasoning gap that dedupe and linking cannot close. Cost: +2–3 s per document turn
without payload indexes (the build was told to write payload only; being fixed).

## Measured: mapping the declaration — VERDICT: `document_map.uploads` stays OFF
Blind-graded (two graders, five runs in one batch, neutral names; linked runs differ by 0.10 between themselves):

| | linked (no map) | mapped |
|---|---|---|
| all 12 | 0.28 | **0.37** (+0.09 — kill criterion was ≥ +0.15) |
| direct (4) | 0.33 | **0.61** |
| inference (5) | 0.25 | 0.22 |
| declaration + Code (2) | 0.31 | 0.38 |
| Code presented as the declaration, per run | 3–4 | **0–2** |
| seconds / tokens per turn | 21 s / 19–20k | **16 s** / 29–30k |

Criteria: mean +0.15 with inference gains — NOT met; no more wrong-document claims — met; no new invented-claim
pattern — not clean (the block announced the administrators' powers article as a governing rule on the partition
question: a wrong-subject gist drew the pick); latency — met; main-suite smoke with and without the switch — met.
**Limiting factor: gist quality on a scanned document** (hand check: ≈ 11 of 20 faithful): continuation chunks of a
long article lose who the clause binds; two deciding list chunks (exclusive-use portions; what the co-owner
insures) were rejected by the nearest-own-chunk check and have no gist; 71 of 104 gists came out in English for a
French document. The reader, the gate and the two-document block work (balcony vote 0.44 → 0.65: declaration
art. 77 then Code art. 1097, each labelled).
Built on the way (active with the switch off): payload indexes + one redundant search removed (search stage
2.4–4.2 s → 0.8–1.75 s); one map for identical text (118 gist calls for all 38 files, ≈ $0.02); per-document
reader budget; scope labels in the map and the analysis block; own-document rules listed before the general
document's; `Retrieval/ReferenceSlots` (one passage slot reserved per document kind — active for everyone,
verified by p27). Traced, not built: the "away three weeks" question is routed as a data question; a document
fallback on a clean empty data result would touch every empty data turn.
## Second measurement after a gist-builder round — VERDICT UNCHANGED: uploads stay OFF
Built: a continuation chunk is shown its article's opening as context (the administrators' articles now say
"Les administrateurs…"; the reader no longer picks art. 32 on the partition question); verification tolerance
0.03 among the 5 nearest and sibling pieces not counted (rejected 5 → 3 of 109; art. 11 and 55-B now mapped;
wrong-chunk gists still rejected); language + length checked by code with one rewrite (gists in the passage's
language 32% → 99%); the analysis block announces at most 2 rules of one role (thin evidence, provisional).
Law corpus untouched (0 maps changed; 20-chunk side-by-side neutral). Unit suite 4,870 green; 8-turn smoke
unchanged with the switch off and on.

Blind-graded, same protocol, six runs in one batch:

| | switch off (same code) | mapped, new gists | mapped, old gists |
|---|---|---|---|
| all 12 | 0.32 | **0.41** (+0.10; bar +0.15) | 0.39 |
| direct (4) | 0.36 | **0.65** | 0.61 |
| inference (5) | 0.39 | 0.23 | 0.24 |
| declaration + Code (2) | 0.13 | **0.51** | 0.42 |
| wrong-document claims per run | 5 | **0–1** | 0–3 |
| seconds / tokens per turn | 18.3 s / 20k | **15.1 s** / 29k | 16 s / 29k |

A strict hand check of 23 deciding gists: faithful 1 → 2, lossy 14 → 15, wrong 6 → 6, absent 2 → 0 — completeness
did not improve: 70 words cannot hold a 2,000-character chunk with 8–15 sub-clauses (structural, not wording).
What limits the inference questions is mostly NOT the gists: one question is routed as data in every arm; on the
map path the probes are dropped, so an English question can miss the French text entirely (the switch-off path's
probes supply the French angle); the composer turns a room-rental clause into a flat "you cannot" in every arm;
the reader picks the three-quarters article for an interior partition. Index cost on a non-English scan:
1.76 calls and 4.3 s per chunk (≈ $0.03 for this document).

**Engineering conclusion for the owner's question:** mapping uploaded documents is cheap, makes answers faster,
nearly doubles direct questions and removes most "Code presented as the declaration" errors — and does NOT make the
assistant reason better on a scanned, flat document. Not worth turning on by default yet. What would change it:
born-digital documents with headings (untested), keeping the cross-lingual probe on map turns, the data-routing
fallback, and a gist budget proportional to a chunk's sub-clauses.

Earlier list (superseded by the round above) — give a continuation chunk its article's lead-in; do not lose list / table
chunks to rejection; enforce the passage's language; cap how many rules of one role the block announces — then
re-measure. Untested: two DIFFERENT declarations on two buildings (wrong-building test — fixtures pending).
