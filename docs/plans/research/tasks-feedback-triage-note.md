# Weekly triage: tasks (section to append to `coolecto-feedback-loop.md`)

Coco's tasks (today: campaign creation) are reviewed in the same weekly pass as the answers. All of it is on the AI admin
page, `/ai/admin/conversations` (Coolecto admins only); set the page's date range to the week.

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
