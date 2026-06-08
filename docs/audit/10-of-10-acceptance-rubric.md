# The 10/10 Acceptance Rubric — the skeptic's gate

Nothing here is "done" until it clears this bar. Each criterion is **falsifiable**: it names the
evidence required and the red flags that fail it. The hourly quality heartbeat re-checks this list;
the execution roadmap is not complete while any P0 row is open.

> Default stance: **assume it is NOT 10/10 and try to prove it.** A claim with no file:line evidence
> is treated as false. "It probably works" fails. Trusting prior memory/docs over the live code fails.

| # | Dimension | Passes only if… | Fails if (red flags) |
|---|-----------|-----------------|----------------------|
| 1 | **Model-agnostic identity** | Persona + non-disclosure + the priority security policy are rendered in the **system** role of every LLM call; swapping OpenAI↔Anthropic changes nothing user-facing; the assistant never names/▶hints the model. | Any `complete($prompt, null)`; persona in the user message; a prompt that would let "are you ChatGPT/Claude?" leak the vendor. |
| 2 | **Single path, no dead code** | Exactly one code path per job. Every public method/class/config-key is reachable from a real caller (proven by repo search). | The 4 overlapping `AiManager` pipelines; `filterSensitiveResults()` no-op; `provides()` on a non-deferrable provider; orphaned JS/staging; unused commands/sections/providers. |
| 3 | **Right-sized abstraction** | Each seam is justified by ≥2 real implementations or a concrete near-term need; contracts in `src/Contracts` are clean, single-responsibility, and consumed. | Interfaces with one impl and no extension intent; god-classes (`ContextRetriever` 1686L, `AiManager` 1118L) left whole; theme/trait sprawl that config could do. |
| 4 | **Grounded, correct answers** | Eval shows answers are grounded in retrieved data/files with an **abstention** branch that actually fires when context is thin; QU's clarified query feeds **both** file and graph grounding. | Hallucinated answers when retrieval is empty; `generateEmptyResponse` defined but never called; QU outputs computed then dropped. |
| 5 | **Retrieval quality** | Reranking + hybrid (dense+sparse/RRF) are **wired and on a real path**; file retrieval is reliable (correct collection, no exception that wipes all file context); precision measured, not assumed. | `NullReranker` always in effect; recall-only single-vector search; filename search depending on an uncreated payload index. |
| 6 | **Fail-closed security** | Team/file filters key off the **threaded** user (not `auth()`), default deny on missing permission/team-path, real per-user file authz (public/role/union), verified by tests. | Any `auth()->user()`/`auth()->id()` in the data path; fail-open bypass as default; the SEC-1 file leak reproducible. |
| 7 | **Extensible actions (any project)** | A project registers an action (export, open-file, **create-event** in Sisc, **create-campaign** in ActiviteDeFinancement) with config-only effort; mutating actions pass an **approval gate**; invocation is robust (not brittle markdown-regex only). | Action system ships empty/half-done; no approval gate; write-actions executable without confirmation; adding one needs core edits. |
| 8 | **Multi-format output** | Excel/PDF/table/markdown export works end-to-end from a real query result and is reachable from chat. | Exporter classes exist but unwired; PDF/Xlsx throw on real data. |
| 9 | **Prompt/token economy** | Every prompt section earns its tokens; measured reduction of fixed overhead; no always-empty or contradictory sections. | 18 sections always emitted regardless of relevance; schema/Cypher leaking into the response prompt; verbose boilerplate. |
| 10 | **UX — prefer AI over the app** | Assistant knows **which page** it was opened from (page-context injected); multiple surfaces (floating, modal, drawer, **dedicated screen**); components render-only; chaining matches house Kompo idioms (checked vs siblings). | No page-awareness; only a floating button; fat components; bespoke syntax diverging from `Sisc`/`utils`. |
| 11 | **File processing (CondoEdge focus)** | Ingestion + extraction (incl. OCR) + chunking are robust; one embedding system (no leftover chatbot duplicate); errors surfaced, not silently swallowed. | Two parallel embedders; `fail_silently` hiding broken ingestion; unretrievable physical docs. |
| 12 | **Clean contracts & layering** | The C4 layer map is real in the code: each layer depends only on contracts of its neighbors; the pipeline `DTO` is the single seam; a newcomer can point at the box to improve. | Cross-layer reach-around; logic in Kompo components; coupling that makes "which box to fix" unanswerable. |
| 13 | **Comments & realness** | Comments explain *why*, never the obvious; **no** version-referencing ("before we did X, now Y") since v1 isn't shipped; no speculative "future" placeholders. | AI-ish narration of trivial code; changelog-in-comments; `// pending FooGate` dead stubs. |
| 14 | **Verified, not asserted** | Tests pass (run per-file given the suite segfault); every P0 fix has an acceptance check; claims carry file:line. | "Should work"; green claimed without running; findings unverified by the adversarial pass. |

## Skeptic's standing questions (asked every iteration)
1. What here is **half-done** and pretending to be done?
2. What is **complexity without value** — delete it, does anything break?
3. Where are there **two paths**? Which one dies?
4. What would a hostile reviewer at a top shop call **embarrassing**?
5. Is the abstraction earning its keep, or is it **architecture cosplay**?
6. Does a real user actually **prefer this over clicking the app**? If not, why not — concretely?

_Status: OPEN. The package is not 10/10 until every P0 row above is closed with evidence._
