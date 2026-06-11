# 97 — Multi-query Cypher (design, committed)

> Status: DESIGN — committed at the owner's request (2026-06-11: "we need to allow multiple
> cypher queries when the user asks for many information at once, like get info from my team
> and me"). Not yet implemented.

## What single-query already covers (ship first, costs nothing)

Connected asks ("my details AND my team's") fit ONE Cypher via chained
`MATCH`/`OPTIONAL MATCH` returning prefixed columns — a business rule teaching exactly this
landed in CondoEdge `config/ai.php` (project.business_rules) on 2026-06-11. Evaluate it live
before building more: it likely covers most "X and Y" phrasings.

## Where it genuinely breaks

- Disjoint result SHAPES (a person card + a 30-row invoice list) crammed into one row set.
- Comparisons over different time windows ("this month vs last month") — doc 96 §3 rejected
  these precisely for the single-query limit.
- Cartesian blowups when two unrelated lists are matched in one query.

## Recommended architecture: a read-only query TOOL in the native tool loop (94b)

The package already ships a complete native tool-calling loop (`ToolLoopRunner`,
`ActionToolGate/Channel`, provider tool turns; default-off behind `ai.actions.native_tools`).
The model calling a `run_query` tool N times in one turn IS multi-query — no second
orchestration layer, natural stop conditions, per-call validation.

Sketch:

1. **`GraphQueryTool`** (package): input `{purpose: string, cypher: string}`; READ-ONLY
   enforcement (reject regex on write clauses + executor's existing guards); each call runs
   through the SAME pipeline gates as today — tenant scoping injection + acceptance
   verification per query, row/time limits per call, max N query-calls per turn (config).
2. Results return as the tool result (sampled/truncated identically to ResultsDataSection),
   so the model composes the final answer over ALL named result sets.
3. The response/render side already understands the loop (ActionResultPresenter, invocation
   persistence from 94b/T1-T12).
4. Exports: `resultSet` for `mode=table` becomes the LAST query's rows; `mode=document`
   (the composed answer) is the natural multi-query export and already works.

Why not "generator emits a JSON array of queries" in the legacy pipeline: it doubles the
prompt contract surface (parse/validate/scope/merge N queries single-shot, no feedback
between queries), exactly what the tool loop gives for free with feedback.

## Preconditions (both already queued elsewhere)

- `ai.actions.native_tools` staged enablement (agreed plan: host staging w/ keys → prod).
- Tenant-scoping sign-off ([[project-tenant-scoping-architecture]]): per-query scoping rides
  the same injector — its known limits (UNION shapes) apply per call, which is SAFER than
  one mega-query but should be acknowledged in the sign-off.

## Task list (estimate: one focused session, test-first)

1. `GraphQueryTool` + read-only guard + per-turn call budget (unit tests incl. write-clause
   rejection, scoping invocation, truncation).
2. Gate wiring: include the query tool in the native tool set behind its own flag
   (`ai.actions.native_query_tool`, default off) so actions-without-queries stays possible.
3. Loop budget/stop tests (model loops queries then answers; runaway capped).
4. Feature test: "info about my team and me" → two tool calls → one composed answer.
5. Eval: re-run the doc-96 rejected multi-query questions + the 90c adversarial batch.
