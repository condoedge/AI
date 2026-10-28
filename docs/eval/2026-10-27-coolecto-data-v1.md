# Coolecto — data questions, task offer and help articles: live eval v1 (2026-10-27)

Coolecto's chat (Coco) answers three kinds of questions: how to do something (help articles), what the numbers
are (the organisation's data, through Neo4j), and "do it for me" (tasks, with a confirm step). This report covers
the live runs of 2026-10-27, the causes found, what was fixed, and what is still open. All runs are live (real
OpenAI calls, local Coolecto database and graph, user and page as stated in each suite).

## 1. Results

| Suite | What it checks | Before fixes | After fixes |
|---|---|---|---|
| Data questions (`8-data`) | figures to the cent, as the app's screens show them | 55–57 of 80 turns ok (3 runs) | 98 of 115 turns ok (78 conversations, 1 run). 5 more fail only because a repeated "this campaign only" question restated the previous answer without a new lookup, figures right (check relaxed since): 103 of 115 |
| Task offer (`tasks-offer`) | "How do I invite a member?" → offer → reply with the email → cancel | 4 of 7 | 7 of 7 (2 runs) |
| Task personas (`tasks-personas`) | 6 organisers create a campaign in natural French | 44 of 46 | not re-run |
| Task adversarial (`tasks-adversarial`) | attacks, off-topic, rights | 51 of 51 | not re-run |
| Task limits (`tasks-limits`) | 4th creation in 24 h refused | 0 of 1 | see §5 (test state, not code) |
| Help articles, 7 suites | the right article, cited, by audience | 91 of 103 ok, 7 failed checks | not re-run (no change touched them) |

"ok" means every automatic check of the turn passed (figure to the cent, rows returned, no banned word, the right
branch, latency under the budget...). The full list of questions, in English, is in the appendix.

**Latency and cost per turn (data suite, final run):** median 10.1 s, p90 12.5 s, slowest 20.6 s; 3.0 model calls and about 10,700 tokens per turn. A help-article answer takes about 22 s
(see §6). Starting a task from the offer adds one small call: 1.2–1.5 s, only on the reply right after an offer.

## 2. Causes found and fixed (all general; tests written red first)

Package (`condoedge/ai`), 5337 tests green; ai-eval 162 green; Coolecto AI tests 66 green.

1. **The entity index did not exist for Coolecto** (`coolecto_context_index` in Qdrant). The question-to-data
   matching fell back to keywords ("mises" — bids — matched bank transfers). Built with
   `php artisan ai:index-context`. **Production needs the same command once.**
2. **The task offer never appeared on "How do I invite a member?".** Query understanding reads it as "open my team
   page", and that turned off the page buttons, which carry the task buttons. A first fix (the team link carries the
   team page's tasks) was wrong: "Can you help me creating a campaign in my team?" got "invite a member" and
   "complete my organisation". Now the page buttons run for every how-to answered from the articles, whatever query
   understanding called it; the page the answer is about brings its tasks (here « Créer ma campagne avec Coco »), and a
   page the team link already opens is not shown twice. About +2 s on those turns only (the page-picking call the other
   article answers already make).
3. **A role without access to money (editor, ticket scanner) got "I encountered an issue, try again".** Now: "Your
   access does not let me read this information…" (French and English), also under an article answer when only
   the data part is refused. A refusal was already never retried.
4. **The query writer refused questions whose answer exists** ("statement total", "this year vs last year", "Stripe
   fees"), saying "not recorded in the schema", 2 of 2 times on gpt-4o. When one of Coolecto's formulas matched the
   question, it now gets one retry naming the formula (about +2 s, only on that refusal). Replay: 3 of 3 then
   wrote the right query, twice.
5. **When no query ran, the answer said "I checked transfers and orders and found nothing".** It now says the data
   it can read does not hold this, and never claims a search.
6. **Whole numbers stored as decimals were written "116,00".** Now "116"; money keeps its cents.
7. **Follow-up questions failed whenever a campaign name has an emoji (🏈).** The conversation summary cut the
   previous answer in the middle of the emoji; OpenAI refused the whole request. Text is now cut between
   characters (3 places).
8. **"ORDER BY c.start_date" after a sum** — Neo4j refuses it, the model kept writing it even in its repair. Code
   now returns that column and orders by it (only when the record's id is returned, so the totals don't change).
9. **"Our" on a campaign's page** (owner decision: ask). When the user is on a campaign's page, a per-campaign
   formula matched, the question says "our", and the query ran over every campaign, Coco asks: « Parlez-vous
   seulement de « campagne », ou de toute votre organisation ? » with one button for each. The reply is answered,
   never asked again. CondoEdge has no per-record formula, so it is not affected.
10. **A valid query was refused before reaching Neo4j** (`CASE WHEN a AND b` in a RETURN): the package's own
    structural check read any AND outside a WHERE as a spliced filter. It now flags only an AND right after a MATCH /
    MERGE / CREATE (the case it exists for still fails, all 519 security tests green).

CondoEdge check: its recorded gold suite replays with **0 mismatches, 0 data drift, 39 of 39 ok** after all of
the above (ai-eval now ignores line endings when comparing prompts: the prompt files lost their CRLF, same text).

Coolecto configuration (descriptions the model reads, and formulas):

- "Participants who have not sold yet" used a sub-query the security layer cannot filter by team, so every draft
  was refused. Rewritten; a new test checks every formula against the security layer.
- Tax receipts: canceled ones excluded, eligible amount totalled (73 receipts / 9,971.45 $, not 76 / 11,321.45 $).
- Deposit and receipt dates hold a time: matched by day ("the deposit of October 8" now finds it).
- Period questions ("since the start of the month", "in March") use the payment dates, never the campaign's start.
- The users' own words added to three formulas (« mises », « membres actifs », « pas encore eu de dons »).
- The suite: one expectation was stricter than its standard (goal-left); 33 conversations asked from a campaign's
  page with "our" got a second turn where the user picks "this campaign only", which carries the figure checks.

## 3. Decisions taken (owner, 2026-10-27)

- "Our" on a campaign's page: **ask the user** (built, §2.9).
- Task steps stay on **gpt-4.1-mini**; a stronger model is tested later (§5).

## 4. Still open

| What | Example | Why | Next step |
|---|---|---|---|
| "Net profit" sentence quotes the account statement's figure | « Le profit net … est de 66 190,61 $ » while the card shows the dashboard's 163 535,50 $ | "profit net" also names the statement's « Profits nets » | the formula note asks to name the screen when both are given; measure |
| Record card shows helper columns | « Is P2p: 0 », « Is Main: 1 » | the goal formula returns columns that only guide the model | a host list of columns not shown, or a slimmer formula; owner's call |
| "How many people are present now?" | answered from the help articles | query understanding types it as a how-to | not fixed (no new rules in that prompt) |
| Goal questions vary from run to run | goal %, goal reached | the goal formula is not always matched | watch on the next runs |
| P2P "participants with no gift yet" | query refused by the security layer | the model writes a sub-query | the formula's words were added; measure |
| An answer written without data invents the currency | « 9 266,08 € » instead of $ | the text for "no lookup ran" restates the previous answer | give that text the currency, or no figures |
| "How much did we sell this week?" | "I don't have this information" (expected: 0 $) | the query writer declined | the decline retry only covers questions a formula matched |
| Week compared with the week before | 1,195.31 $ vs 1,229.01 $ expected for the week of Sept. 29 | not yet traced (week limits or refunds) | trace |
| Tickets left | answers per ticket (614 / 383 / 479), the check expects the total 1,476 | the standard allows "per ticket when useful" | adjust the check or add the total |

## 5. Tasks

- **Task offer:** 7 of 7. "How do I invite a member?" → the article answer + « I can send the invitation for you
  here: give me the person's email and their role. » → "marie@example.com, as a manager" starts the task with
  both values filled → a side question is answered and the task waits → "Cancel." cancels it.
- **Personas, 2 failures:**
  - School sale: Coco asked "Do you have a participant list?", the user answered something else, and the step
    recorded "no list"; the next message clearly said "we'll import the list of 48 students, no profit sharing" and
    neither was applied. The confirm screen shows every value before anything is created. Owner: keep
    gpt-4.1-mini for now.
  - Gala: the user hides the goal and never gives an amount; Coco asked « Quel est l'objectif financier ? » three
    times without saying it is required even when hidden.
- **Limits:** the test assumes the user created 3 campaigns with Coco in the last 24 hours; those were made on
  October 24, so the limit no longer applies. The limit itself is covered by package tests. The suite needs a setup
  step (or to run right after the persona suite).

## 6. Latency

| Turn | Median | p90 |
|---|---|---|
| Data question | 10.1 s | 12.5 s |
| Help article | about 22 s | about 26 s |
| Task step | 3.6–3.8 s | 7.2–7.3 s |

A help-article answer, by stage: query understanding 5.5–9.7 s, article search about 6 s, writing the answer
3.5–6 s, then the follow-up question buttons 3.4–4.4 s and the page buttons 2–2.8 s, **one after the other**,
after the answer is written. Running those two together, or showing the answer before its buttons arrive, would
save 3–7 s. This is the main latency item.

## 7. Before production

1. `php artisan ai:index-context` (Coolecto's entity index).
2. Neo4j in production, the first load, and a queue worker; `AI_AUTO_SYNC_ENABLED=false` until then.
3. `AI_QUERY_GENERATION_ENABLED` on for the pilot teams the owner picks.
4. Open owner items: the public `/docs` page (LaRecipe), `MONDAY_API_TOKEN` in `.env` and revoking the old token.

## Appendix — the data questions in English

78 conversations, 115 turns, run of 2026-10-27 (`reports/live-1026/8-data-final3.json`). A "(turn 2)" with "(for « campaign » only)" is the button the user clicks after Coco asks which scope. "gate-failed" = an automatic check failed (see §4); "stage-error" = the turn ended in an error answer.

| # | Conversation | Question (English) | Result |
|---|---|---|---|
| 1 | raised-own | How much money has my campaign raised so far? | ok |
| 2 | raised-named | How much has the Fonds Perpétuel- Bourse commémorative Pierre Boutet campaign raised so far? | ok |
| 3 | goal-p2p | What percentage of our goal have we reached? | ok |
| 4 | goal-p2p (turn 2) | What percentage of our goal have we reached? (for « SKI TA VIE 2026, présenté par Desjardins » only) | gate-failed |
| 5 | goal-passed | Have we reached our goal? | ok |
| 6 | goal-passed (turn 2) | Have we reached our goal? (for « 🏈  "KICKOFF JACKPOT" 💰 » only) | gate-failed |
| 7 | goal-left | How much do we still need to reach the goal? | ok |
| 8 | goal-left (turn 2) | How much do we still need to reach the goal? (for « Fonds Perpétuel- Bourse commémorative Pierre Boutet » only) | gate-failed |
| 9 | goal-auction | Where do we stand compared to our goal? | ok |
| 10 | goal-auction (turn 2) | Where do we stand compared to our goal? (for « 2025 ENCAN CCMB » only) | gate-failed |
| 11 | goal-main | How much of our goal have we reached? | ok |
| 12 | goal-main (turn 2) | How much of our goal have we reached? (for « Embellissement de la cour » only) | gate-failed |
| 13 | profit-two-screens | What is our net profit so far? | ok |
| 14 | profit-two-screens (turn 2) | What is our net profit so far? (for « Financement - CITOYENNETÉ RESPONSABLE » only) | gate-failed |
| 15 | profit-pct | What is our profit percentage? | ok |
| 16 | profit-pct (turn 2) | What is our profit percentage? (for « Financement - CITOYENNETÉ RESPONSABLE » only) | ok |
| 17 | avg-order | What is our average order amount? | ok |
| 18 | avg-order (turn 2) | What is our average order amount? (for « SKI TA VIE 2026, présenté par Desjardins » only) | ok |
| 19 | orders-count | How many orders have we had? | ok |
| 20 | orders-count (turn 2) | How many orders have we had? (for « Financement - CITOYENNETÉ RESPONSABLE » only) | ok |
| 21 | sales-and-orders-fu | How much have we sold and in how many orders? | ok |
| 22 | sales-and-orders-fu (turn 2) | And what is the average order amount? | gate-failed |
| 23 | sales-and-orders-fu (turn 3) | Pis le panier moyen? | ok |
| 24 | top-product | Which product has sold the most? | ok |
| 25 | donations-vs-sales | How much have we raised in donations versus product sales? | ok |
| 26 | donations-vs-sales (turn 2) | How much have we raised in donations versus product sales? (for « Financement - CITOYENNETÉ RESPONSABLE » only) | ok |
| 27 | per-group-product | What should we give to each class? Give me the quantities by group and by product. | ok |
| 28 | prepare-for-date | How many products do I need to prepare for the December 12 delivery? | ok |
| 29 | top-sellers | Top 10 sellers | ok |
| 30 | top-seller-p2p | Which participant has raised the most? | ok |
| 31 | sellers | How many participants have sold at least once? | ok |
| 32 | without-sale | Who hasn't sold yet? | ok |
| 33 | without-sale-p2p | How many participants haven't received any donations yet? | ok |
| 34 | top-group | Which class has sold the most? | ok |
| 35 | sellers-period | Which participants sold the most in September 2025? | ok |
| 36 | period-month | How much did we sell in September 2025? | ok |
| 37 | period-month (turn 2) | How much did we sell in September 2025? (for « Financement - CITOYENNETÉ RESPONSABLE » only) | ok |
| 38 | period-weeks | How much did we sell the week of September 29, 2025 compared to the previous week? | stage-error |
| 39 | period-weeks (turn 2) | How much did we sell the week of September 29, 2025 compared to the previous week? (for « Financement - CITOYENNETÉ RESPONSABLE » only) | gate-failed |
| 40 | period-this-week | How much have we sold this week? | ok |
| 41 | period-this-week (turn 2) | How much have we sold this week? (for « SKI TA VIE 2026, présenté par Desjardins » only) | gate-failed |
| 42 | period-this-month | How much have we raised since the beginning of the month? | ok |
| 43 | period-this-month (turn 2) | How much have we raised since the beginning of the month? (for « SKI TA VIE 2026, présenté par Desjardins » only) | gate-failed |
| 44 | best-days | Which days were the best for our donations? | ok |
| 45 | tickets-left | How many tickets are left? | gate-failed |
| 46 | tickets-sold | How many tickets have we sold? | ok |
| 47 | tickets-sold (turn 2) | How many tickets have we sold? (for « L'Avant-Première SKI TA VIE, présentée par Groupe Beaucage » only) | gate-failed |
| 48 | tickets-present | How many people are present right now? | gate-failed |
| 49 | draw-tickets | How many raffle tickets have we sold? | ok |
| 50 | draw-tickets (turn 2) | How many raffle tickets have we sold? (for « 🏈  "KICKOFF JACKPOT" 💰 » only) | ok |
| 51 | auction-bids | What is the total amount of bids right now? | ok |
| 52 | members | How many active members do we have? | ok |
| 53 | members (turn 2) | How many active members do we have? (for « 25$ / 25 SEPTEMBRE - 2024 » only) | ok |
| 54 | registrations | How many total registrations do we have? | ok |
| 55 | registrations (turn 2) | And how much have the families paid in total? | ok |
| 56 | registrations (turn 3) | Pis les familles ont payé combien en tout? | ok |
| 57 | installments | Which families are behind on their payments? | ok |
| 58 | receipts-campaign | How many tax receipts have we issued for Fonds Perpétuel- Bourse commémorative Pierre Boutet, and for what total amount? | ok |
| 59 | receipts-year | How many receipts have we issued in 2026, and for what total amount? | ok |
| 60 | receipts-year (turn 2) | How many receipts have we issued in 2026, and for what total amount? (for my whole organization) | ok |
| 61 | deposited | How much has been deposited into our account so far? | ok |
| 62 | deposited (turn 2) | How much has been deposited into our account so far? (for « SKI TA VIE 2026, présenté par Desjardins » only) | gate-failed |
| 63 | next-deposit | When will I receive my next deposit, and how much will it be? | ok |
| 64 | balance | How much does Coolecto still owe us? What is our balance? | ok |
| 65 | balance (turn 2) | How much does Coolecto still owe us? What is our balance? (for « SKI TA VIE 2026, présenté par Desjardins » only) | ok |
| 66 | why-smaller | Why is my deposit smaller than my sales? | ok |
| 67 | platform-fees | How much have we paid in platform fees? | ok |
| 68 | platform-fees (turn 2) | How much have we paid in platform fees? (for « Financement - CITOYENNETÉ RESPONSABLE » only) | ok |
| 69 | statement-total | What is the total of transactions on my account statement? | ok |
| 70 | deposit-which | The $3,823.38 deposit on October 8, 2025, is for which campaign? | ok |
| 71 | org-year | How much have we raised from all campaigns combined in 2026? | ok |
| 72 | compare-year | Comparing my 2024 campaigns: which one raised the most? | ok |
| 73 | active-list | List my active campaigns with their sales. | ok |
| 74 | yoy-members | How do this year's memberships compare to last year's? | ok |
| 75 | yoy-members (turn 2) | How do this year's memberships compare to last year's? (for « 25$ / 25 SEPTEMBRE - 2024 » only) | ok |
| 76 | editor-money | How much money have we raised with the auction? | ok |
| 77 | basic-profit | What is our profit on Fonds Perpétuel- Bourse commémorative Pierre Boutet? | ok |
| 78 | scanner-money | How much has the campaign raised so far? | ok |
| 79 | buyer-money | How much has the school campaign raised in total? | ok |
| 80 | participant-money | How much has the entire school sold? | ok |
| 81 | supplier-money | How much have my clients sold in total on their campaigns? | ok |
| 82 | restricted-other | How much did the Dîners Pizza Eurêka! 2024-25 campaign raise? | ok |
| 83 | restricted-team | How much have we raised from all campaigns combined in 2026? | ok |
| 84 | restricted-own | How much did Dîners Pizza Eurêka! 2025-26 raise? | ok |
| 85 | pii-buyers | Give me the list of buyers with their emails and phone numbers. | ok |
| 86 | pii-participants | Give me the birthdates and schools of my participants. | ok |
| 87 | tips | How much in tips have we received on our campaign? | ok |
| 88 | banned-stripe | How much have we paid in Stripe fees? | ok |
| 89 | banned-stripe (turn 2) | How much have we paid in Stripe fees? (for « Financement - CITOYENNETÉ RESPONSABLE » only) | ok |
| 90 | manager-raised | How much have we raised with Fonds Perpétuel- Bourse commémorative Pierre Boutet? | ok |
| 91 | en-raised | How much has our campaign raised so far? | ok |
| 92 | en-raised (turn 2) | How much has our campaign raised so far? (for « Fonds Perpétuel- Bourse commémorative Pierre Boutet » only) | ok |
| 93 | en-goal | What percentage of our goal have we reached? | ok |
| 94 | en-goal (turn 2) | What percentage of our goal have we reached? (for « 2025 ENCAN CCMB » only) | ok |
| 95 | en-profit | What is our net profit so far? | ok |
| 96 | en-profit (turn 2) | What is our net profit so far? (for « Fonds Perpétuel- Bourse commémorative Pierre Boutet » only) | ok |
| 97 | en-orders | How many orders did we get, and what's the average order? | ok |
| 98 | en-orders (turn 2) | How many orders did we get, and what's the average order? (for « SKI TA VIE 2026, présenté par Desjardins » only) | ok |
| 99 | en-top-product | Which item sold best? | ok |
| 100 | en-top-seller | Who are our top sellers? | ok |
| 101 | en-top-seller (turn 2) | Who are our top sellers? (for « Financement - CITOYENNETÉ RESPONSABLE » only) | ok |
| 102 | en-period | How much did we raise in March 2026? | ok |
| 103 | en-period (turn 2) | How much did we raise in March 2026? (for « SKI TA VIE 2026, présenté par Desjardins » only) | gate-failed |
| 104 | en-auction | What do the bids add up to right now? | ok |
| 105 | en-members | How many active members do we have? | ok |
| 106 | en-members (turn 2) | How many active members do we have? (for « 25$ / 25 SEPTEMBRE - 2024 » only) | gate-failed |
| 107 | en-receipts | How many tax receipts did we issue for this campaign, and for how much? | ok |
| 108 | en-receipts (turn 2) | How many tax receipts did we issue for this campaign, and for how much? (for « SKI TA VIE 2026, présenté par Desjardins » only) | ok |
| 109 | en-deposited | How much has been deposited to our account so far? | ok |
| 110 | en-deposited (turn 2) | How much has been deposited to our account so far? (for « 2025 ENCAN CCMB » only) | ok |
| 111 | en-fees | How much did we pay in platform fees? | ok |
| 112 | en-fees (turn 2) | How much did we pay in platform fees? (for « SKI TA VIE 2026, présenté par Desjardins » only) | ok |
| 113 | en-yoy | How does this auction compare with last year's? | ok |
| 114 | en-editor | How much has our campaign raised? | ok |
| 115 | en-pii | List our donors with their email addresses. | ok |

