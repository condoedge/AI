# Document Map

Lets the assistant reason from a user's concrete situation to the rules that govern it, when those rules share no word with the question.

---

## Overview

A passage is retrieved by the words it shares with the question. A rule is often worded nothing like the situation it settles: "Can I tear down the wall between my kitchen and living room?" shares no word with the article that says a co-owner disposes freely of a private portion, the one that presumes walls between portions common, or the one that sets the majority for works on common portions. With the whole chapter in the prompt the answer still missed them: the gap is reasoning, not text.

The document map closes it in two steps:

| When | What | Cost |
|------|------|------|
| Index time | Each stored chunk gets a plain-language **gist**; a document with parallel top-level parts gets a **scope** (which parts govern this application's users) | One small-model call per chunk, once per text |
| Question time | ONE small call (the **reader**) reads the question against the map and names the passages a complete answer needs, each with its role. A **gate** decides whether that changes the answer | One call per document question, replacing two |

No host configuration is required. No table, no migration: the map lives in the vector payload of the chunks it describes (key `map`).

> {info} The map is built for the host's **own documents** (the physical collection, `ai:ingest --docs`). Files uploaded by users are not mapped unless you switch that on — see [Security](#security).

---

## What happens at question time

For a document question that is not a follow-up, over admitted documents that carry a usable map:

1. **The plain search runs** on the question's own angles (no probe call).
2. **The reader** is shown the map of the admitted documents — their headings and one gist line per chunk, never their text — and names up to 8 passages with a role: the general rule, the classification, a condition or exception, who approves or decides, the consequence, the steps.
3. **The gate.**
   - **Closed** — every governing rule the reader named (general rule, classification, approval) is already among the search's passages. The turn is then *exactly* the plain-search turn: same passages, same prompt.
   - **Open** — the reader named a governing rule the search did not find. Its picks lead the passages, ordered by role, and the composer gets a `QUESTION ANALYSIS` block listing the governing rules with their article numbers and passage numbers.

An open gate never drops a passage of an article the user named by number (it stays first), nor the passage the question's own wording ranks first.

Measured on 53 turns: gated turns 0.45 → 0.62 answer quality with fewer ungrounded claims; ungated turns unchanged; the turn is about 2 s faster at +16 % tokens.

### Which turns keep the probe + passage-selection path

| Turn | Path |
|------|------|
| A follow-up: Query Understanding judged it one (`follow_up_reference`), or the passage the question's own wording ranks first is one the previous answer cited ("tell me more about …") | Probes + selection (the reader does not name what a deepening answer adds — measured) |
| No admitted document carries a usable map | Probes + selection |
| The reader call failed | Probes + selection |
| An ask the application answers (a how-to, `navigation_target`), a file listing, a data question | Unchanged: no reader |

The decision is made **per turn**. A new question asked later in a conversation is read like a first one, even when some of its passages overlap what an earlier answer cited.

When a turn planned for the reader falls back, the plain angles are not searched again: the second pass pays for the probe call and the probe angles only. A mapped document admitted beside an unmapped one is still read; the unmapped one keeps its plain-search passages.

### Scope

A code that states similar rules for different regimes ("undivided co-ownership", "divided co-ownership") answers from the wrong chapter when nothing says which one governs your users. For a document with three or more heading levels and at least two top-level parts, ONE call on the accurate model reads a summary of each part against `ai.project.description` and says `in`, `out` or `unclear` per part.

- Chunks of a part judged `out` are not returned by the document search and are not stitched into a neighbouring passage. A question that names an article by number still gets that article.
- It fails open: one `unclear` part, a reply that would hide every part, or no project description filters nothing.
- A verdict is stored with a fingerprint of the description it was judged against, and the model's one-line reason. Change the description and the next build judges again; `--rescope` does it on demand.
- A failed scope call never erases a stored verdict. A first build whose scope call failed leaves the document *scope-pending*: the next job or backfill retries it.
- Every `out` verdict is logged (`Document map scope: part judged out of the application scope`) with the file, the part, the chunk count and the reason.

---

## Building the map

### After an ingest

With a non-`sync` queue, `FileProcessor` dispatches `BuildDocumentMapJob` after each successful ingest of a physical document. The job:

- works in **slices of 40 seconds** and re-dispatches itself until the file is done, so it fits a queue `retry_after` of 90 seconds;
- holds a **cache lock per file**: two workers never build the same file at once;
- **retries what failed** (a provider outage) up to 3 times, 30 / 60 / 120 seconds apart, then logs `Document map left incomplete` with what is left.

With a `sync` queue nothing is dispatched (about 2.4 s per chunk would block the upload): use the command.

### The command

```bash
php artisan ai:documents:map [options]
```

| Option | Description |
|--------|-------------|
| `--backfill` | Every file with something left to do: chunks never attempted, a scope verdict owed |
| `--file=<id>` | One file (`physical:docs/guide.md`, or a numeric id) |
| `--collection=<name>` | Another chunk collection than the default (the physical collection; plus the uploads collection when uploads are switched on) |
| `--retry-failed` | Attempt again the chunks recorded as failed |
| `--rescope` | Judge the parts of divided documents again; no gist is rebuilt |
| `--force` | Rebuild every gist and judge the scope again |
| `--dry-run` | List the files and their state; no model call, nothing written |

```bash
# What would a backfill do?
php artisan ai:documents:map --backfill --dry-run

# Build what is missing
php artisan ai:documents:map --backfill

# The project description changed: judge one document's parts again
php artisan ai:documents:map --file=physical:public/laws/code.md --rescope
```

The command exits with a failure when a file it built reported an error, ended without a usable map, or still owes a scope verdict.

### What a run pays for

Every gist is verified by code before it is stored: the document is searched with it, and it is kept only when its own chunk comes back first. A gist that fails is written again once.

A chunk is attempted **once per text**. Its payload then holds either a gist or the record of the failed attempt:

| Record | Meaning | Next backfill |
|--------|---------|---------------|
| `{v, hash, gist}` | A verified gist | Skipped |
| `{v, hash, failed: "rejected"}` | Its gist did not retrieve its own chunk, twice | Skipped (`--retry-failed` asks again) |
| `{v, hash, failed: "error"}` | A model call or the verification search failed | Skipped by the command; retried by the queue job, up to 3 attempts |
| none, or a `hash` of another text | Never attempted for this text | Built |

A document's map is **usable** when every content chunk was attempted and at least nine in ten carry a gist. A chunk without a gist is simply absent from what the reader is shown.

A re-ingest of unchanged text (`ai:ingest --docs --fresh`) **keeps** the records: they are read before the old points are deleted and stored with the new ones. Only the chunks whose text or place in the document changed are mapped again. The ingest logs `Document map carried over a re-ingest` with the carried and dropped counts.

### Cost

Per 1,000 chunks, measured on `gpt-4o-mini`:

| | |
|---|---|
| Gist calls | 1,000 to 2,000 (one retry when verification rejects a gist) |
| Tokens | about 700k input + 65k output (measured on 134 chunks with no retry: 93k + 8.6k) |
| Verification | one embedding and one vector search per gist call |
| Scope | per divided document: one summary call per part, one call on the accurate model |
| Time | about 46 minutes sequential (2.8 s per chunk) |

At question time the reader costs one small call: about 650 tokens of task plus the map — roughly 7k tokens for a 90-chunk law — and at most 400 output tokens. It replaces the probe call and the passage-selection call.

The build calls go through a circuit breaker of their own (`llm_index`): a backfill that runs into the provider's rate limit cannot open the breaker the live chat goes through.

---

## Configuration

```php
// config/ai.php — file_context
'document_map' => [
    'enabled' => env('AI_DOCUMENT_MAP', true),
    'uploads' => env('AI_DOCUMENT_MAP_UPLOADS', false),
    'reader' => [
        'enabled' => env('AI_DOCUMENT_MAP_READER', true),
        'max_chunks' => env('AI_DOCUMENT_MAP_READER_MAX_CHUNKS', 150),
    ],
],
```

| Key | Default | Meaning |
|-----|---------|---------|
| `enabled` | `true` | `false`: **nothing** of the feature runs or is read. No map is built or carried, the scope filter is off (every chunk is searchable again, whatever a stored map says), no reader: every turn is the probe + passage-selection path |
| `reader.enabled` | `true` | `false`: the scope filter stays; every turn is the probe + passage-selection path |
| `reader.max_chunks` | `150` | A **hard cap** on the gist lines one reader call is shown. Above it the map is cut to the sections of the search's hits and, when even those do not fit (a file without headings), to the chunks nearest to the hits. A rule far from every hit is then out of the reader's sight |
| `uploads` | `false` | Also map files uploaded by users |

There is no switch for the analysis block alone: the reader used as retrieval only, without the block, measured worse than the probe + selection path. "No block" is "no reader".

Two optional roles in `ai.llm_roles`:

| Role | Used for | Falls back to |
|------|----------|---------------|
| `document_scope` | The one scope call per divided document | `query_generation` (the small model answered "applies" for every part) |
| `document_map_reader` | The reader call | `query_understanding` |

---

## Security

A gist is written from a document's text and read by a model. Document text is never trusted; the feature keeps data and instructions apart by construction.

- Every prompt of the feature shows document-derived text inside a fence its content cannot close, and says that what stands inside is data, never instructions.
- The reader can only answer with **ids of the map it was shown** (a strict schema, checked again by code) and **roles of a closed list**.
- The `QUESTION ANALYSIS` block is rendered by code from those role names, **article numbers** and **passage numbers**. No sentence a model wrote and no text of a document is printed in it — not the reader's own sentence about the question, not a heading. The passages stay inside the same untrusted-data fence as on every other turn.
- The map cannot widen file access: the reader sees the maps of the files the search admitted for this user, and every passage it adds passes the access gate again.

**What remains possible.** A document the user is allowed to read can word a chunk so that its gist gets it picked, shown as a passage and announced by its role ("the general rule … in passage [1]"). It cannot say more than its own text, inside the fence, and it cannot reach a file the user has no access to. That is the reach any retrieved document has on an answer, plus the announcement of a role.

For documents the host wrote, that reach is the host's. For documents anyone can upload, it is the uploader's — which is why `uploads` is off by default. Before switching it on, consider who can upload a file that other users' questions will retrieve.

> {warning} Unchanged by this feature: a passage header prints the file name and the heading path of the passage outside the fence.

---

## Troubleshooting

| Symptom | Check |
|---------|-------|
| The reader never runs | `php artisan ai:documents:map --backfill --dry-run`: is the document `complete`? Does the turn trace say `document_map.fallback` and why (`unmapped_file`, `no_report`)? |
| A file stays `not usable` | More than one chunk in ten has no gist. `--retry-failed` attempts them again; a scanned or tabular document may never pass verification and keeps the plain search |
| A chapter that should answer is never retrieved | Its part was judged `out`: look for the `Document map scope` log line and its reason. Correct `ai.project.description`, then `--rescope` |
| A backfill reports `another build of this file is running` | A queue worker holds the file's lock: run again when it is done |

In the turn trace, the `file_context` stage carries `document_map`: the reader's map size, picks, tokens and time; the gate's state and what opened it; the final passages — or `fallback` and its reason.
