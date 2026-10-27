# Coolecto: the weekly feedback loop

Once a week, someone reads what users told us about Coco's answers, fixes each problem in one place, and turns the question
into an eval case so it stays fixed. Everything below is on the AI admin page, `/ai/admin/conversations` (Coolecto admins
only). Set the page's range to the week (the **7 days** preset, or the From / To dates on the Conversations tab).

## 1. Collect (up to 30 items)

| Look at | Where |
|---|---|
| 👎 answers, with the user's comment if they left one | **Feedback** tab, filter *👎 Negative*. The Comment column shows what they wrote; the time under the badge is when they rated. |
| Regenerated answers (an implicit 👎: the user asked again) | **Conversations** tab, Answers = *Regenerated*. |
| Answers that cite no document | **Conversations** tab, Answers = *No sources*. Most of these are fine (small talk, task turns, data answers); keep the ones that should have quoted an article. |
| Slow answers | **Conversations** tab, Answers = *Slow*. The threshold is `ai.admin.slow_answer_ms` (default 20 000 ms). |
| Failed answers | The **Failed answers** card counts them. A failed answer cites nothing, so it is in *No sources*; the transcript marks it in red with the stage that failed. |

The **By audience** cards give the week's numbers per audience (organisateur, acheteur, participant, fournisseur, and
*Unresolved* for users the resolver could not place): conversations, answers, 👍 / 👎, share of answers without sources,
average time. Copy them into the log (step 6) before you start fixing.

If there are more than 30 items, take every 👎 with a comment first, then the other 👎, then regenerated, then the rest.

## 2. Triage: one cause per item

Open the row: the transcript shows the question, the answer, the rating with its time and comment, the route, the cited
files and the time taken. Read the user's
message before the answer, then pick **one** cause and write down the evidence (a file name, a label, a stage).

| Cause | What you see | Fix in |
|---|---|---|
| **Article content** | The right article was cited, but it is missing the step, is outdated, or names a button that changed. Or no article covers the topic. | The article in `resources/ai-help/`, then re-ingest it. |
| **Retrieval** | An article answers the question, but it is not in the cited files (or only a weaker one is), or the passage came from another audience's article. | The article's frontmatter (title, `public`), or the retrieval settings in `config/ai.php`. |
| **Prompt** | The right passages were cited, but the answer ignores them, invents a fee, delay or promise, or speaks to the wrong role. | The role section or the prompt text in the setup (`config/ai.php`). |
| **Config** | The user got the wrong audience (the copied eval case's `audience_is`, step 4, shows the one they got), a navigation button is missing or wrong, or a page is not offered. | Coolecto's `config/ai.php` or `app/Ai/AudienceResolver.php`. |
| **Package bug** | An error stage, a crash, a wrong route for a plain question, or the same failure in CondoEdge's shape too. | The package, with a failing test first. |
| **Out of scope** | A question Coco is not meant to answer (another product, a data question we do not support, small talk). | Nothing. Note it; if it comes back every week, it is a candidate for an article. |

## 3. Fix in exactly one place

One cause, one fix. If an item seems to need two fixes, it is two items. Do not patch the prompt to cover a missing
article, and do not add words to the prompt's logic for one question. A package fix needs a CondoEdge replay with
0 mismatches before it lands.

## 4. Promote the question into the suite (anonymised)

1. In the transcript, click **Copy as eval case**. The clipboard gets one conversation in the suite format: the user's
   questions as turns, and an `expect` skeleton from the trace (`branch_in`, `retrieves_doc` with every retrieved document,
   `audience_is`). It has no user id, name or email.
2. Paste it into the chunk file of that audience in `storage/app/eval/coolecto/` (`1-organisateur-owner.json` ... `5-fournisseur.json`,
   `6-transversal.json` for the rest).
3. Edit it:
   - give it a readable `id` (`own-fb-2026-10-27-refund`, for example);
   - add `"user"`: the local test user of that audience (owner 53, manager 5285, basic member 4980, buyer 655, participant 247059,
     supplier 5058);
   - cut `retrieves_doc` down to the article that should answer; add the gates the other turns of the file carry
     (`no_placeholders`, `no_cypher`, `max_latency_ms`, `cites_document`, `quotes_grounded`, `doc_audiences`);
   - write the `standard`: the gold article, the path and labels the answer must give;
   - reread the question: remove any name, email, order number or other personal detail the user typed.
4. Add its row to `REVIEW.md`.

## 5. Re-run the affected chunk

Run only the chunk you changed, on the local database, and never while another eval run is going:

```
php artisan ai:eval --suite=storage/app/eval/coolecto/<chunk>.json --report=storage/app/eval/coolecto/reports/<chunk>-<date>.json < /dev/null
```

Add `--only=<id>` to run just the promoted case first. Compare the report with the chunk's previous one: the new case must
pass, and no other turn may lose a gate. A turn that flips once and passes on a second run is noise; note it.

## 6. Log the week per audience

Add one row per audience to the log below: the numbers from the **By audience** cards, how many items you triaged and
their causes, what you fixed, and how many cases you promoted.

| Week | Audience | Answers | 👍 / 👎 | No sources | Avg time | Items | Causes | Fixed | Promoted |
|---|---|---|---|---|---|---|---|---|---|

## Tasks (Coco's campaign creation)

Coco's tasks (today: campaign creation) are reviewed in the same weekly pass as the answers.

| Look at | Where | What to do with it |
|---|---|---|
| 👎 on task turns (questions, summary, outcome) | **Conversations** tab: Feedback = *👎 Negative*, Tasks = *With a task*. Open a row: the turn rated down carries a red badge. The **Feedback** tab also lists them, mixed with normal answers. | Read the turn and the user's answer before it. Wrong value kept → task definition rule. Bad wording → task prompt. Wrong type recommended → article. |
| `confirm_refused` and its reason | Open the conversation: the transcript's task timeline shows `confirm_refused (reason)`. | `unacknowledged`: the money/legal ticks are unclear. `stale` / `not_waiting`: a double click or an old summary, fine unless frequent. `invalid` / `foreign`: a forged or cross-conversation token; look at it the same day. |
| `limit_hit` | Task timeline (`limit_hit`). A limit hit at start leaves no task row: it is only in the app log (`AI task start refused: limit hit`). | Same organiser hitting the daily limit: find out why they started over. Turn limit hit: the conversation looped; read it. |
| `failed` outcomes | Task timeline (`failed`); the task row has `status = failed`; the event's payload holds the exception class or the failed outcome. | A bug in the write path. Reproduce it and open a ticket. |
| `verifyChain` breaks | Task timeline header: "Audit trail broken: an event was changed or removed" in red instead of "Audit trail intact". | Someone or something edited `ai_task_events`. Not a quality issue: escalate it right away. |

The admin has no filter for a task's events, so for the counts use this query (dev copy or read replica):

```sql
SELECT
    e.kind
    , JSON_UNQUOTE(JSON_EXTRACT(e.payload, '$.reason')) AS reason
    , COUNT(*) AS events
    , COUNT(DISTINCT e.task_id) AS tasks
FROM ai_task_events e
WHERE e.at >= NOW() - INTERVAL 7 DAY
    AND e.kind IN ('confirm_refused', 'limit_hit', 'failed')
GROUP BY
    e.kind
    , reason
ORDER BY
    e.kind
    , events DESC
```

For context, the admin dashboard's **Campaigns created with Coco** card gives the year-to-date count and how many of those
campaigns are now live.
