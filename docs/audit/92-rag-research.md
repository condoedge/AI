# Latest RAG Practices, Mapped to This Codebase

This document distills 2024–2026 RAG best practice and maps it onto `condoedge/ai`'s
actual gaps, cross-referenced to the audit findings (`layers/NN-*.md`) and the sequenced
roadmap (`99-roadmap.md`). It is deliberately decision-oriented and skeptical: this package
is **injected into many host Laravel apps**, so every recommendation is weighed against the
operational surface it forces on those hosts. The default verdict for trendy techniques that
add a second index, a planner, or a gold-label harness is **defer** — see "What NOT to adopt".

The throughline: the package already owns most of the good primitives (a model-agnostic
`LlmReranker`, a typed `PipelineContext`, an existing `generateEmptyResponse` abstention
method, a JSON-schema-shaped action contract). The shortfalls are almost all **wiring gaps**,
not missing capability — the cheap fixes reuse assets that already exist and stay config-gated
and fail-open.

## Summary table

| technique | current consensus (2024–2026) | where this package falls short | recommended minimal change | roadmap item |
|---|---|---|---|---|
| **Hybrid retrieval (dense + sparse/BM25)** | Fuse dense + BM25 so lexical/ID/acronym queries the embedding misses still surface; equal-weight (α≈0.5) is near-optimal. | Dense-only on both the graph and file paths. No lexical channel for exact names/aliases/IDs. | **Defer.** A second index is real ops surface for an injectable package. Gate behind eval evidence that dense-only misses lexical/ID queries; ride Qdrant native sparse/hybrid before standing up a separate BM25 index. | P1-D2 (conditional) |
| **Reciprocal Rank Fusion (RRF)** | Fuse by *rank* (`Σ 1/(k+rank)`, k≈60), not by score — sidesteps incompatible BM25/cosine scales. Default in Elastic/Weaviate/Qdrant. | Cross-collection **min-max score normalization** manufactures fake `1.0` relevance, then a threshold cut runs on that fabricated score (P0-9). | Stop surfacing normalized scores as relevance: threshold on **raw** scores; if/when merging collections, fuse by RRF and cut by rank/top-N, never by a normalized float. | P0-9, P1-D2 |
| **Cross-encoder / LLM reranking** | Over-fetch wide (50–100), rerank that pool, cut to top-N. Reranking is ~useless at ~20 candidates, improves sharply at 50–100. | The well-built `LlmReranker` is wired **only** into file search. The graph/similar-query path passes `top_k` (default 10) straight through with a threshold cut — no over-fetch, no rerank. | Wire the **existing** reranker into `searchSimilarQueries`: fetch `top_k×4` (cap ~40) → `rerank()` → cut. Contract already falls back to input order, so default behavior is unchanged. Delete the dead `resolveReranker()`/`NullReranker`. | P1-D1 |
| **Grounding / abstention on sufficient context** | Gate generation on *evidence sufficiency*, not self-reported confidence. Adding insufficient context makes models more confident and more likely to hallucinate. | `knowledge_query` branch hardcodes `confidence: 1.0` even when zero files were retrieved; `$hasFileContext` is computed one line above but never consulted. `generateEmptyResponse()` exists but is called only from tests. | When `!$hasFileContext`, set low confidence + `metadata.insufficient_context=true` and route to the already-built `generateEmptyResponse()`. ~10 lines; activates dead code, kills a false-confidence bug. | P1-D3 (L3-5/L9-1) |
| **Native tool / function calling** | Model returns a schema-validated `tool_calls` object; the app validates args and executes. Auditable, testable; markdown-regex round-trips are brittle. | Invocation is 100% markdown-regex (`entity://`/`action://`); one stray space/encoded char silently drops the action. The query-gen prompt is never told built-ins (`export_excel`, …) exist (L11-2). | Add `toToolSchemas()` mapping the existing `key/description/inputSchema` contract to provider tool defs; adapt a returned `tool_call` into the same `(action, params, ctx)` triple. **Keep markdown as fallback.** One seam, no new subsystem. | P1-C1 |
| **Human-in-the-loop write gate** | Side-effecting calls interrupt before execution; approve/edit/reject; approval state is single-use and expiring, keyed to a thread. | The approval/write gate has **zero real producers** (only a test `SpyAction`); the pending token has no TTL, no single-use, and `belongsTo(null)===true`. | Ship **one** real mutating reference action (`requiresApproval()===true` + `authorize` closure) + a booted-app round-trip test. Harden the token: TTL in `decode()`, reject `userId===null` in authed contexts, single-use nonce before `handle()`. | P1-C2, P1-C3 |
| **Contextual chunking (chunk augmentation)** | Prepend a short LLM blurb situating each chunk in its parent doc before embedding; cuts retrieval failures ~49% (≈67% with rerank). | Chunker mixes byte/char ops and **fabricates** start/end offsets under overlap (L6-1). No contextual augmentation. | **Do not build augmentation yet** — it's a real per-chunk ingest cost. Fix the honesty bug: compute real `mb_strpos` offsets from a moving cursor, **or delete** the offset fields until a citation/highlight consumer exists. Reserve augmentation as an opt-in flag gated behind hybrid/RRF work. | P2-C/D (L6-1), P1-D2 |
| **Reference-free eval (RAGAS-style)** | Faithfulness + answer-relevancy (generation) and context-precision + context-recall (retrieval), no gold answers needed. | Eval judge can resolve to the **same provider/family** as generation (L15-7), inflating scores via self-preference bias. | Config-recommend a **distinct `eval_judge` model from a different family**; `warn()` when the resolved judge provider equals the generation provider. No ensemble, no gold-label harness. | P2-E (L15-7) |

---

## Hybrid retrieval + reranking

**Consensus.** The production RAG retrieval pattern is a three-stage funnel: **over-fetch →
fuse → rerank → cut**.

- **Hybrid (dense + sparse/BM25)** lifts *recall*. Dense captures paraphrase/semantic
  similarity; BM25/sparse captures exact lexical matches — IDs, names, codes, rare tokens,
  acronyms — where embeddings are weak. They fail on *different* queries, so combining them
  catches both head and tail. This is the standard fix for "the right doc exists but the
  embedding missed it."
- **Reciprocal Rank Fusion (RRF)** fuses by *rank* (`score = Σ 1/(k+rank_i)`, k≈60) because
  BM25 scores and cosine similarities live on incompatible scales and cannot be linearly
  added without per-query calibration. RRF is ~3 lines and is the default fusion in
  Elasticsearch/OpenSearch, Weaviate, and Qdrant. Equal-weight fusion (α≈0.5) is consistently
  near-optimal in recent benchmarks.
- **Second-stage reranker** lifts *precision@N*. A cross-encoder (bge-reranker, Cohere Rerank)
  or an LLM reranker jointly reads (query, candidate) and scores relevance directly — far more
  precise than bi-encoder cosine, but too expensive over the whole corpus. So you over-fetch a
  wide pool cheaply, rerank only that pool, and cut to top-N. Benchmarks show reranking is
  **ineffective at ~20 candidates but improves sharply at 50–100** (Recall@5 0.826→0.888);
  hybrid+RRF+cross-encoder dominates single-stage methods (MRR@5 ~76% vs ~62% BM25-only).

**Failure modes.** A reranker over a too-small pool is pure cost — if retrieval already missed
the answer, reranking can't recover it; over-fetch (limit×3–×5, pool 50–100) is the
precondition. Cross-encoders add a model pass; LLM rerankers add a full LLM call (slow, $$,
need strict JSON + fallback) so for an embedded package they must be opt-in and fail-open.
RRF discards calibrated scores, so a downstream *threshold* cut on fused output is meaningless
— cut by rank/top-N, not by score. (This is exactly the bug behind **P0-9**: per-collection
min-max normalization manufacturing fake "1.0" relevance.)

**Recommendation (do P1-D1 first; treat P1-D2 as conditional).**

The mapped gap: `ContextRetriever::searchSimilarQueries` fetches exactly `top_k` (default 10)
from the vector store, applies a score threshold, and returns — **no over-fetch, no rerank** —
while file search already has a good, model-agnostic `LlmReranker` (`RerankerInterface`:
never-throws, order-preserving fallback, a `$textOf` extractor that works on any candidate
shape).

1. **Wire the existing reranker into the graph path (highest ROI, ~no new surface).** In
   `searchSimilarQueries`, fetch `top_k × 4` (cap ~40), then
   `app(RerankerInterface::class)->rerank($retrievalQuery, $candidates, fn($c) => $c['question'], $top_k)`.
   The contract already guarantees safe fallback to input order, so when
   `rag.rerank_enabled=false` (default) behavior is unchanged. Inject `RerankerInterface` into
   `FileContextProvider` and delete the hand-rolled `resolveReranker()` (the bound
   `NullReranker` is dead-by-construction today).
2. **Stop threshold-cutting fused/reranked output.** After rerank, cut by top-N, not by raw
   score — also closes P0-9's normalization-as-relevance bug.
3. **Defer BM25/RRF.** A sparse index is real operational surface for a package injected into
   many apps. The cheap win — exact-name/alias matching — can ride Qdrant's native
   sparse-vector/hybrid support (or a payload keyword filter) fused via RRF **only if eval
   shows dense-only misses lexical/ID queries**. Don't ship a second index speculatively; gate
   it behind eval evidence.

Net: reuse the asset you already have, change ~10 lines on the graph path, keep it
config-gated and fail-open. That captures most of the precision lift before taking on any BM25
infrastructure.

**Sources**
- [Hybrid Search: BM25, Vector & Reranking (2026) — Digital Applied](https://www.digitalapplied.com/blog/hybrid-search-bm25-vector-reranking-reference-2026)
- [Hybrid Search in RAG: Dense + Sparse (BM25/SPLADE), RRF, and When to Use Which — GoPenAI](https://blog.gopenai.com/hybrid-search-in-rag-dense-sparse-bm25-splade-reciprocal-rank-fusion-and-when-to-use-which-fafe4fd6156e)
- [From BM25 to Corrective RAG: Benchmarking Retrieval Strategies (arXiv 2604.01733)](https://arxiv.org/html/2604.01733v1) — over-fetch depth vs rerank gains (50→0.826, 100→0.888)
- [Building Hybrid Search That Actually Works: BM25 + Dense + Cross-Encoders — Ranjan Kumar](https://ranjankumar.in/building-a-full-stack-hybrid-search-system-bm25-vectors-cross-encoders-with-docker)

---

## Grounding + abstention

**Consensus.** The 2024–2026 consensus is **selective generation gated on "sufficient
context," not on retrieval scores alone.** Google Research's *Sufficient Context* work shows
the failure mode directly relevant here: adding context makes models *more* confident and
*more* likely to hallucinate rather than abstain — Gemma's error rate rose from 10.2% (no
context) to 66.1% (insufficient context). Their fix combines two cheap signals: an LLM-based
"is this context sufficient to answer?" autorater (no ground truth needed) plus self-rated
confidence, improving selective accuracy ~10% over confidence-only baselines.

Industry practice layers three controls, cheapest first:

1. **Retrieval confidence gating** — if top-k is empty or below a similarity threshold, route
   to a dedicated abstention path *before* generation.
2. **Citation-grounded generation** — instruct the model to answer *only* from provided chunks
   and attach `[chunk_id]` citations, then validate each citation points to a real retrieved
   chunk and drop/flag uncited claims.
3. **LLM abstention** — an explicit "if the context does not contain the answer, say you don't
   know" instruction, optionally a separate groundedness/faithfulness judge.

These help most exactly in this package's scenario: **`knowledge_query` with zero retrieved
files.** Layer 1 alone closes the mapped gap.

**Failure modes.** Threshold tuning is the whole game — too high over-abstains (refuses
answerable questions), too low hallucinates, and there is no universal cutoff; it must be tuned
per corpus on a labeled query set. For a package injected into many apps a hardcoded similarity
threshold will be wrong everywhere, so prefer the **empty/near-empty signal** (unambiguous)
over a magic float, and make any threshold config with a conservative default. Self-reported
confidence is unreliable — models assert high confidence on insufficient context (the
`confidence: 1.0` bug is the human-coded version of this); gate on retrieval *evidence*, not a
model's own number. Citation validation adds latency and a second failure surface (models cite
plausible-but-wrong IDs) — full citation validation + a faithfulness judge is overkill for a
package this size; reserve it for a later phase.

**Recommendation (minimal viable, no new infra).**

The bug is concrete: `AiManager::generateAndExecute()` hardcodes `confidence: 1.0` for every
`knowledge_query` and never consults `$hasFileContext` (computed one line above).
`ResponseGenerator::generateEmptyResponse()` already exists and is only called from tests
(L9-1). Wire the existing abstention path to the existing empty signal:

1. In the `knowledge_query` branch, if `!$hasFileContext`, set `confidence` to a low value
   (e.g. `0.0`) instead of `1.0`, and mark `metadata['insufficient_context'] => true`.
2. When `insufficient_context` (or the knowledge-path execution result is empty), call the
   already-built `generateEmptyResponse($question, null, ...)` instead of normal generation.
3. Keep the existing "answer only from provided context" system instruction and verify it
   renders in the **system** role (per the model-agnostic rubric).

That is ~10 lines plus the routing call — it activates dead code (`generateEmptyResponse`) and
kills a known false-confidence bug. **Defer** retrieval-threshold config, citation validation,
and a faithfulness judge to a later roadmap item; they over-engineer a multi-tenant package.
Add one feature test: empty file context → response is the abstention answer,
`metadata.empty_result === true`.

**Sources**
- [Deeper insights into RAG: the role of sufficient context — Google Research](https://research.google/blog/deeper-insights-into-retrieval-augmented-generation-the-role-of-sufficient-context/) — sufficient-context autorater + selective generation; the confidence-inflation paradox
- [Confidence-Aware RAG: Teaching Your AI Pipeline to Acknowledge Uncertainty — Microsoft Community Hub](https://techcommunity.microsoft.com/blog/azuredevcommunityblog/confidence-aware-rag-teaching-your-ai-pipeline-to-acknowledge-uncertainty/4515061) — three-layer architecture; threshold/precision-recall tuning
- [Sufficient Context: A New Lens on RAG (arXiv 2411.06037)](https://arxiv.org/pdf/2411.06037) — the paper behind the Google blog
- [RAG Evaluation Metrics: Faithfulness & More — Confident AI](https://www.confident-ai.com/blog/rag-evaluation-metrics-answer-relevancy-faithfulness-and-more) — faithfulness/groundedness as per-claim source-traceability; negative rejection

---

## Agentic tool-calling

**Consensus.** The settled best practice is **provider-native tool/function calling**: the
model returns a structured `tool_calls` object (validated against a JSON Schema you supply)
rather than emitting prose the app must parse. Anthropic, OpenAI, Google, Mistral, and Cohere
all expose this natively, and MCP has de-facto standardized how harnesses present tools. The
model never *executes* anything — it emits a typed intent; your code validates args against the
schema, runs the logic, and feeds the result back as new context. That separation is what makes
actions auditable and testable.

Two adjacent practices matter for mutating actions:

- **Human-in-the-loop (HITL) gating.** Side-effecting calls (write file, non-SELECT SQL,
  "charge $X") are *interrupted* before execution; a reviewer gets **approve / edit-args /
  reject**, and state is checkpointed against a thread id so the turn can pause and resume.
  LangChain/LangGraph treats this as middleware keyed on a `when(args)` predicate — the gate is
  **per-call, decided from the arguments**, not the tool's mere identity.
- **Single-use, expiring approval state.** The paused call is the only thing resumable; once
  consumed it cannot replay. (This is the property the pending token must enforce.)

**Failure modes.** Over-constrained or escaped-JSON schemas measurably hurt accuracy (>20% on
some models) — keep schemas flat and favor LLM-natural arg shapes (absolute ids over relative,
enums over free text), the "poka-yoke" principle. Not all models tool-call well, so you keep a
text fallback (two paths). Native tool use **widens the prompt-injection blast radius**:
retrieved/untrusted content can induce a mutating call — HITL + server-side re-authorization is
the mitigation, not the model's judgment. Gating *everything* drives users to revolt; gate by
per-arg risk predicate. The current markdown-regex approach has its own failure modes: one
stray space / URL-encoded char silently drops the action; the model is told only
`key/label/aliases`, never `description`/schema, so it can't form correct calls; and "export to
excel" mis-routes to Cypher because the query-gen prompt can't even see the built-ins (L11-2).

**Recommendation (minimal viable; no MCP, no planner, no LangGraph).**

This package is injected into many hosts, so do not adopt MCP, a planner, or LangGraph. The
contract (`key/description/inputSchema`) *is already a JSON-Schema tool spec* — exploit that.

1. **Emit, don't redesign (P1-C1).** Add one `AiActionRegistry::toToolSchemas()` that maps
   registered actions to the provider tool-schema array, and an adapter that turns a returned
   `tool_call` into the same `(AiActionInterface, rawParams, ctx)` triple `ActionLinkHandler`
   already produces. **Keep the `entity://`/`action://` markdown path as the fallback** for
   non-tool models. One new seam, no new subsystem.
2. **Give the gate a real producer (P1-C2).** Ship **one** mutating reference action with
   `requiresApproval()===true` + an `authorize` closure, plus a booted-app test of
   ApprovalCard → `approveAiAction` → `handle`. Without this the centerpiece stays theoretical.
3. **Harden the token to match HITL semantics (P1-C3):** TTL check in `decode()`, reject
   `userId===null` in authed contexts, and **single-use consumption** (cache/message-metadata
   nonce) before `handle()` — this is the "approved call can't replay" guarantee, not
   gold-plating.
4. **Single metadata source (P1-C4):** route `EntityActionAwarenessSection` through the
   registry so both prompt sides — and the tool schemas — advertise the same
   `description`/`inputSchema`/`requires_approval`.

Defer typed `AiActionResult.kind` (P1-C5) only far enough to stop array-sniffing the `pending`
shape; the full kind-enum is optional now.

**Sources**
- [Anthropic — Building Effective Agents](https://www.anthropic.com/engineering/building-effective-agents) — tool-design rigor, poka-yoke, human checkpoints
- [LangChain — Human-in-the-Loop](https://docs.langchain.com/oss/python/langchain/human-in-the-loop) — interrupt/approve-edit-reject, per-arg gating, checkpoint-resume
- [Zylos Research — Tool Use & Function Calling: Standards & Benchmarks](https://zylos.ai/research/2026-04-07-tool-use-function-calling-standards-benchmarks) — native `tool_calls`, MCP standardization, structured-format accuracy tax
- [Christian Schneider — From LLM to Agentic AI: Prompt Injection Got Worse](https://christian-schneider.net/blog/prompt-injection-agentic-amplification/) — mutating-tool risk; why HITL + re-auth gate writes

---

## Contextual chunking + reference-free eval

*Scope: maps onto this package's chunking (overlap + fabricated offsets, L6-1) and eval judge
(judge can equal generation provider, L15-7 / roadmap P2-E).*

**Consensus.**

- **Contextual retrieval (chunk augmentation).** The dominant 2024+ technique is Anthropic's
  *Contextual Retrieval*: before embedding, prepend a short (~50–100 token) LLM-generated blurb
  situating each chunk in its parent document ("This section of the 2024 budget covers
  reserve-fund contributions…"). The same augmented text feeds both the dense embedding *and* a
  BM25 lexical index; results are fused (RRF) and reranked. Anthropic reports contextual
  embeddings + contextual BM25 cut retrieval failures by ~49% (with reranking, ~67%). It helps
  most when chunks lose meaning out of context (pronouns, "this section", tables,
  legal/financial docs split mid-topic) — exactly the condo-document corpus here. It helps
  least on short, self-contained records (entity rows), where the dense vector already carries
  full meaning.
- **Chunking quality.** Respect semantic/structural boundaries (headings, sentences), use
  modest overlap to preserve cross-boundary context, and keep faithful character offsets so
  citations/highlights map back to source. Offsets are load-bearing **only if** a consumer
  highlights spans.
- **Reference-free evaluation.** RAGAS-style metrics are the de-facto standard: **faithfulness**
  (answer claims entailed by retrieved context — hallucination detector), **answer relevancy**
  (answer addresses the question), **context precision** (signal/noise of retrieved chunks),
  **context recall** (was the needed evidence retrieved). The first two grade generation, the
  latter two grade retrieval — none need gold answers, which suits this package's
  config-generated question set. The known blind spot (already flagged in L15 open questions): a
  confidently-wrong answer over *plausible-but-incomplete* retrieval scores high —
  reference-free can't see what was never retrieved.

**Failure modes.** Contextual augmentation costs one LLM call per chunk at ingest — mitigated
by prompt-caching the parent doc, but still real cost/latency for large corpora, and over-long
blurbs dilute the chunk's own signal. Hybrid/RRF adds a BM25 index and a fusion step
(operational weight for a many-host package). **LLM-as-judge self-preference bias:** judges
systematically favor their own outputs ("narcissistic evaluation"), traced to lower-perplexity
= more-familiar text; when judge == generator, scores skew optimistic and regressions hide —
precisely L15-7. Mitigation: **cross-family judging**, position-swap averaging (pairwise), and
calibrated multi-dimensional rubrics.

**Recommendation (minimal viable; config + small surface over new infrastructure).**

- **L6-1 (fabricated offsets):** Do **not** build contextual augmentation yet — it's a real
  ingest-cost/latency commitment. Per the audit verdict this is *latent* (no
  citation/highlight consumer exists). The minimal fix is honesty: compute real offsets with a
  moving `mb_strpos` cursor, **or delete the start/end fields** until a highlight consumer
  exists. Reserve contextual augmentation as an **opt-in config flag**
  (`chunking.contextual => false`) for the document corpus only, gated behind the hybrid/RRF
  work already scoped at P1-D2, so it is not a standalone new system.
- **L15-7 (judge == generator):** The minimal, high-value fix is exactly what the roadmap
  states (P2-E) — ship config recommending a **distinct `eval_judge` model from a different
  family**, and `warn()` when the resolved judge provider equals the generation provider. No
  ensemble, no gold-label harness (rightly deferred in the "out of scope" list). Optionally add
  a position-swap or a small rubric to the judge prompt — but the cross-family default is the
  load-bearing 80%.

**Sources**
- [Anthropic — Contextual Retrieval](https://www.anthropic.com/news/contextual-retrieval)
- [RAGAS: Automated Evaluation of Retrieval Augmented Generation (arXiv 2309.15217)](https://arxiv.org/html/2309.15217v1)
- [Self-Preference Bias in LLM-as-a-Judge (arXiv 2410.21819)](https://arxiv.org/html/2410.21819v2)
- [Quantifying and Mitigating Self-Preference Bias of LLM Judges (arXiv 2604.22891)](https://arxiv.org/html/2604.22891v2)

---

## What NOT to adopt

This package is **dropped into many host apps** as a Composer dependency. Every new index,
service, or always-on LLM call is operational surface those hosts inherit. The following are
genuinely trendy in 2024–2026 RAG literature but are **overkill or net-new surface** for this
package's scope, and the audit's "out of scope" list already rules most of them out. Be
opinionated: do not build these.

- **A standalone BM25/SPLADE index, shipped speculatively.** Hybrid retrieval is real and
  helps lexical/ID queries — but standing up a second index (and its sync/lifecycle) inside an
  injectable package is a maintenance liability for every host. **Gate it behind eval evidence**
  that dense-only actually misses lexical queries, and prefer Qdrant-native sparse/hybrid over a
  separate engine. Do not ship the index "because hybrid is best practice."

- **MCP, an agentic multi-step planner, or LangGraph.** The action contract is *already* a
  JSON-schema tool spec; emit it as native tool definitions and keep the markdown fallback. A
  planner / agent loop / MCP server is net-new surface the roadmap explicitly defers ("no
  agentic multi-step planner, no streaming/async turn engine").

- **Contextual chunk augmentation as a default.** The ~49% retrieval-failure reduction is real
  for prose/financial docs, but it costs an LLM call per chunk at ingest. With **no
  citation/highlight consumer even live today** (offsets are latent, L6-1), augmentation is
  premature. Make it an opt-in flag for the document corpus, gated behind hybrid/RRF — never the
  default ingest path.

- **Full citation validation + a separate faithfulness/groundedness judge on the hot path.**
  Per-claim citation checking adds latency and a second failure surface (models cite
  plausible-but-wrong IDs). The mapped grounding gap is closed by the unambiguous empty-context
  signal plus the existing `generateEmptyResponse` — a citation-validation subsystem is a later
  phase, not the fix for P1-D3.

- **A tuned similarity threshold as the abstention mechanism.** A hardcoded float will be wrong
  in every host's corpus and needs a labeled query set to tune. Gate on the
  **empty/near-empty retrieval signal** (unambiguous, corpus-independent); expose any threshold
  as config with a conservative default, not as the primary gate.

- **A gold-label / ensemble-judge eval harness.** The reference-free RAGAS-style metrics the
  package already approximates are the right tool for a config-generated question set. The
  cross-family-judge default + a warning closes the self-preference-bias gap (L15-7); a
  multi-judge ensemble or human-labeled gold set is net-new surface the roadmap defers.

- **A second vector-store adapter or a `PipelineContext` DTO-hierarchy rewrite.** Both are in
  the audit's explicit "NOT building" list. Keep `PipelineContext` a typed bag with the few
  sub-DTOs needed to stop stage-stomping; do not abstract a second store nobody has asked to
  swap in.

- **"Harmonizing" the duplicated retrieval/fan-out/indexer copies instead of collapsing them.**
  Best-practice fusion/rerank wiring should land in **one** path (`FileContextRetriever`,
  P1-B1), not be re-tuned in each of the three divergent copies. Harmonizing preserves the very
  drift surface the audit is eliminating.
