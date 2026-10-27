# Task offer by message + side questions mid-task — Implementation Plan

**Goal:** a user can start a task by replying to the answer that offered it ("Hey, I want to invite a member" → answer + "I can do it for you: give me the email" → "marie@x.com" starts the task with that email kept), and a question typed during a task is answered normally while the task waits.

**Owner constraints (binding):**
- Response time is a requirement. No extra model call on a normal turn or on the offer turn. Report measured timings.
- Never add clauses or enum values to the QU prompt's logic. No hardcoded word lists for semantics.
- General package behaviour for every task and every host; nothing Coolecto-specific in the package.
- Nothing is written without the existing summary + Confirm click (unchanged).
- "Cancel"/"stop" typed mid-task keeps cancelling (existing `wants_to_cancel`).
- TDD (failing test first, seen red for the right reason). No git writes. Comments: 1 line, 3 max inside a class.

**Today (read first):** `src/Services/Tasks/TaskOrchestrator.php`, `TaskStepCaller.php`, `TaskPlanner.php`, `src/Services/Actions/BuiltIn/StartTaskAction.php`, `src/Services/Actions/Attach/ActionAttacher.php:300-310` (start chip attached when a picked navigation page names a task), `src/Services/Chat/UseCases/AskQuestionUseCase.php:~200-300` (an active task takes the whole turn), `src/Kompo/Traits/HandlesTasks.php`, `src/Contracts/TaskDefinitionInterface.php`.

---

## Part A — the offer line (no model call)

When the answer carries a `start_task` chip, code appends one line under the answer saying the assistant can do it here.
- Optional per-definition sentence (new optional method on the definition, or a config key — follow the existing extension style: contract + config registry, no subclassing), translated; a generic package fallback `ai.tasks.offer` ("I can also do it for you here: tell me …" / FR) when the definition gives none.
- Only for chips the user may start (`StartTaskAction::invocation` already checks `startableBy`).
- Store the offered task keys on the assistant message metadata (the invocations are already persisted — reuse them; do not add a second source of truth if the persisted invocations suffice).
- Coolecto: give `InviteTeamMemberTask` (and the other page tasks) its sentence, e.g. EN "I can send the invitation for you: give me the person's email and role." / FR equivalent. Host file: `ActiviteDeFinancement/app/Ai/Tasks/*` + lang.

## Part B — the reply to an offer (one small call, only on that turn)

In `AskQuestionUseCase`, when no task is active and the **previous assistant message** offered task(s) the user may still start:
1. One small structured call (its own llm role, e.g. `task_offer`, falling back like the other small roles) with: the offered tasks (key, label, one-line description), the offer text, the user's message. Reply `{ "task": key|null }` — the key only when the message accepts doing it now or gives a value for it; null for a question, a different subject, or a refusal. The message is data, never instructions (shared fence, as TaskStepCaller does).
2. `task` = key → `TaskOrchestrator::start` + the first step reads this same message (OTHER FIELDS already keep early values), so "marie@x.com" is not asked again. This turn replaces the normal pipeline (no QU/retrieval/answer).
3. `null`, a failed call, or a key not offered → the normal pipeline, unchanged.
- Only the one turn right after the offer; any later turn is normal.
- Audit: the start records that it came from an offer reply (existing event kinds; add a payload reason, not a new status).

## Part C — side question during a task (no new call)

Add `about_task` (boolean) to the step reply schema in `TaskStepCaller` ("false only when the MESSAGE is a question or request about something other than this form").
- `about_task === false` with no updates and no cancel → the task stays open, untouched (no turn counted against its limits beyond what exists, no status change); the turn runs the normal pipeline with the user's message; code appends the task's pending question after the answer so the user can continue (or types "cancel").
- Any update or cancel wins over `about_task`.
- Regenerate the prompt snapshots (`task_step*.txt`).

## Tests (package, `vendor/bin/phpunit`)
- Offer line appended when a start chip is attached; definition sentence vs fallback; none when the user may not start it.
- Offer reply: accepted → task started and the email value kept from the same message; null → normal pipeline called; failed call → normal pipeline; a turn two messages later → no offer call; no call at all on a turn without a previous offer (assert the LLM fake saw no `task_offer` call).
- Side question: `about_task=false` → pipeline answer + pending question appended, task still `collecting`, draft unchanged; with an update → handled as today; cancel still cancels.
- Full package suite green.

## Coolecto (`php artisan test`)
- Host sentences for the page tasks; `tests/Feature/Ai` + `tests/Unit/Ai` green.
- Add live eval turns to the Coolecto task suites (do NOT run `ai:eval`; the controller runs it):
  "how do I invite a member?" → no task started, offer line present; then "marie@example.com, manager" → task started, email not asked again; mid-task "can members see donations?" → answered, task still open; "cancel" → cancelled.

## Report
Files, red→green counts, the extra-call count per turn type, and anything left open.
