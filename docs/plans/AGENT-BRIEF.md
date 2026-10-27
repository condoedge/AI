# Agent brief — executing the condoedge/ai + Coolecto plans

Read this whole file before you touch anything. It is the contract for every implementer and reviewer agent; your task prompt
only names the plan and the tasks of your batch. When the plan and this brief disagree, stop and report — do not choose.

## Where things are
- Package (namespace `Condoedge\Ai`): `C:\Users\jkend\Documents\Projects\kompo\ai`
- Coolecto (host, Laravel 11, French-first): `C:\Users\jkend\Documents\Projects\kompo\ActiviteDeFinancement` — `vendor/condoedge/ai`
  is a symlink to the package, so package edits are live in Coolecto.
- CondoEdge (host, must not regress): `C:\Users\jkend\Documents\Projects\kompo\CondoEdge`
- ai-eval (eval harness): `C:\Users\jkend\Documents\Projects\kompo\ai-eval`
- Plans: `ai/docs/plans/`; research the plans cite: `ai/docs/plans/research/`; progress log (the controller writes it, not you):
  `ai/docs/plans/coolecto-progress.md`.
- Shell: Git Bash on Windows. Run every artisan/php command with `< /dev/null`.

## Hard rules (the owner's, verbatim where quoted)
1. "No git writes (commit, stash, checkout, reset). Read-only git is fine." The owner manages git.
2. TDD: write the failing test, run it and see it fail for the expected reason, implement, see it pass. No production code first.
3. "Never add clauses or enum values to the QU prompt's logic." "No hardcoded word lists for semantics."
4. Never ingest or log secrets or sensitive data: `transfers.interac_password`, `suppliers.shipstation_password`, minors' fields on
   `recipients`, `answers.content`, customer email/phone/IP/addresses.
5. Ask before anything destructive or outward-facing: deleting/recreating Qdrant collections, sending email, any production system,
   **running a migration** (create migration files freely; do not run `migrate` — stop and report, the controller asks the owner).
6. Coolecto tests run on the dev database inside transactions (`Tests\TestCase` uses `DatabaseTransactions`). Never
   `RefreshDatabase` in Coolecto. Create your own rows and assert on them. Run with `php artisan test <path> < /dev/null`, never
   `vendor/bin/phpunit` in Coolecto. Package tests: `cd ai && php -d memory_limit=2G vendor/bin/phpunit --filter <Name>` (the
   full package suite takes ~20 min; run it only when your batch says so).
7. Zero CondoEdge regression. After ANY package change: `bash C:/Users/jkend/AppData/Local/Temp/claude/C--Users-jkend-Documents-Projects-kompo-ActiviteDeFinancement/f703585c-6672-44b8-8b1e-99adbde669c9/scratchpad/replay.sh <label>`
   must report 0 mismatches. A prompt change that shows up in the replay is a regression unless the plan says otherwise.
   Prompt snapshots: refresh with `UPDATE_PROMPT_SNAPSHOTS=1` only when the plan intends the prompt change.
8. Evals: never two `ai:eval` runs at once; Coolecto evals on the local DB only; cassettes and reports stay out of git.
9. The package stays host-agnostic: no Coolecto/condo words, routes or classes in `ai/src`. Extension points are a contract + a
   config registry + callbacks (`[Class, 'method']` pairs so config caches) — not enums, not subclasses.
10. Build only what the plan asks. No speculative options, guards or flags. Booleans stored as 0/1.

## Code style (CLAUDE.md + owner feedback)
- Comments: inside a class 1 line ideally, 3 max; say the non-obvious fact only. A class docblock may say what the class is for.
- Match the surrounding code's naming and idiom. Enums live with their model. No new top-level `app/` folder for one class; put a
  class where the code that uses it lives (e.g. a Kompo helper in `app/Kompo/Common`, not a new `app/Kompo/Support`).
- SQL with leading commas. Translations in the domain lang file (`resources/lang/{fr,en}/campaign.php` etc.), never `translate.php`;
  English-first keys with French values added; Coolecto UI words: "Participant" (code: Recipient), "Organisme" (code: Team);
  forbidden in UI: "Ristourne", "Stripe", "Frais ADF", "Chèque".
- Kompo: infer from existing code before inventing (grep how the same need is solved). Buttons via `->button()`/`->outlined()`,
  no raw HTML strings in `_Html`, `new Komponent()` runs `created()` only with `app()->instance('bootFlag', true)` in tests,
  `currentTeam()` aborts for guests, inline `<script>` never runs through Kompo (use a Mix bundle), Tailwind JIT does not compile
  `bg-[var(--x)]`. A failed Kompo `authorizeBoot()` renders as a *server error* page and every error page answers HTTP 200 when
  `APP_DEBUG` is off (tests): assert on `$response->exception`, and use `abort(403)` for a refusal.
- Local page checks: headless Chrome over CDP (see `scratchpad/shot.mjs`), app at `https://coolecto.local`, log in with a signed
  URL (`enhanceRouteWithLoginSignature`, example in `scratchpad/signed-url.txt`). The Chrome extension is another machine.

## When to stop and report instead of continuing
- The plan's code or path does not match the repo (file moved, method renamed, assumption false) and the fix is not obvious
  from the plan's intent.
- A test you did not write fails, a replay shows mismatches, or a gate the plan names fails twice after a fix attempt.
- You would need a migration run, a destructive action, a new dependency, or a decision the plan lists as the owner's.

## Your final report (under 400 words, plain English)
Per task: done / partly / not done. Files created/changed. Every test command you ran with its exact result line (e.g.
`Tests: 12 passed`). Replay result if the package changed. Deviations from the plan and why. Open questions for the owner.
