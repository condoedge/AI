# 2026-08-04 — OpenAI credits still exhausted; measurement-ready build day

## The blocker (verified, not assumed)

The user reported "the limit is already fixed". A one-call preflight (direct `curl` to
`api.openai.com/v1/embeddings` with the CondoEdge `.env` key) returned:

```
HTTP 429 — insufficient_quota — "You have no credits remaining. Add credits to continue
using the API at https://platform.openai.com/settings/organization/billing/."
(code: credit_balance_exhausted)
```

Most likely the *Claude* spend limit was what got fixed (it was also hit this campaign), or
credits went to a different OpenAI org than the key CondoEdge uses. **Every eval turn and every
embedding call dies on this**, so iteration 12 remains landed-but-unmeasured.

**NEW STANDING RULE — quota preflight:** before launching ANY eval run, make that one direct
embedding call. It costs a fraction of a cent and saves a void 13-turn measurement (this is the
second void run this campaign; the first burned ~15 minutes and produced 13 identical French
canned errors).

## Built while blocked (all OpenAI-free, all measurement-ready)

1. **Page-context forwarding in the eval harness** (the ~2-line queue item, done properly):
   - `EvalSuiteLoader` parses an optional per-turn `page_context` object (fail-fast on
     lists/scalars; `{}` normalizes to null) — `src/Services/Eval/EvalSuiteLoader.php`.
   - `ConversationEvalRunner` threads it into `PipelineEvalDriver` (previously hardcoded
     `null`) and records it in `conversationContextMeta` for grader audit.
   - 3 new pin tests; the eval harness directory is 101/101 green.

2. **20 page probes authored with live-verified seeds** —
   `CondoEdge/tests/eval/page-probes-partA.json` (11) + `page-probes-partB.json` (9), one
   conversation per staged `page_examples` question, each carrying the `page_context` a real
   surface would send. Seeds probed against MySQL (team 3) today: union 8 (board 2021 ×4,
   meeting 70 with 11 motions, 45 open tasks), unit 474 "312" @ 825 de Bruxelles (10 owners,
   honest-zero balance), unit 177 "506" (6 open unit tasks), meeting 58 AGA Laval-sur-le-Lac P4
   (24 motions / 552 votes / 29 voters), invoice 10934 (5 lines), supplier 785 Serrurier
   Lafleur (real email + telephone), project 59 (2 quote requests), maintenance 244, budget 33
   (55 details; **2,304 generated invoices**).
   - **Two staged questions exposed as bad seeds and flagged DO-NOT-SHIP**:
     `supplier-maintenance` and `maintenance-supplier` — **zero maintenances in the entire DB
     have a supplier_id**, so both can only ever answer an honest "none". Honest ≠ valuable:
     they stay commented regardless of probe outcome. CondoEdge config comment updated.

3. **Context-degradation study** (user directive: "bigger conversations — find after how many
   messages we lose context, set that limit"): gold suite grew to 16 conversations / 39 turns
   with `context-degradation-study` — 12 turns / 24 messages over two subject arcs (members →
   unions/units → docs turn → tasks) with **depth markers**:
   - msg 17 (t8): return-after-switch export ("back to the very first thing — export that
     members list") — the one-deep snapshot now holds tasks; silent wrong-data export is the
     failure this hunts;
   - msg 19 (t9): verbatim recall of the first question;
   - msg 21 (t10): numeric recall of the roster total;
   - msg 23 (t11): global conversation summary (the behavior the clipboard resume approximates).
   Runs ONLY via `CondoEdge/storage/eval/suite-long.json` (~7 min throttled — its own command;
   partA/partB untouched at 27 turns). Graders must report the FIRST turn where context
   degrades; that knee replaces the provisional banner default.

   Why these markers (measured mechanics): production threads only the last **5 messages**
   (`buildPromptContext` default at `AskQuestionUseCase.php:116`), answers truncated at 300
   chars; the context snapshot is **one-deep** (every row-producing turn overwrites it);
   `mentioned_entities` accumulates unboundedly. Config note: `ai.chat.max_history_messages`
   (10) appears to be dead config on this path — flagged, deliberately NOT wired silently (a
   prompt-size change mid-campaign must be its own pre-registered lever).

4. **Conversation-limit banner + clipboard mini-resume feature** (workflow `wf_3b135a29-664`,
   implement → 3-lens review → fix; design: scratchpad `conversation-limit-design.md`):
   `ai.chat.conversation_limit` (soft limit, provisional 30 messages), deterministic
   `ConversationResumeBuilder` (serializes snapshot + recent user questions; no LLM call, no
   row values, EN/FR), banner above the composer with one button = copy resume to clipboard
   (SuggestionSubmit-style hex-encoded embedding) + start new conversation.

## Waiting on credits (single queue, all files ready)

iter-12a/12b (gold splits) → grading fleet vs the 6 pre-registered criteria → 
recommended-probes re-validation → suite-long degradation study → page-probes A+B → 
ship decisions (page_examples un-staging + banner threshold calibration).
