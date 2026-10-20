# CondoEdge Domain Synthesis — Decision-Ready Survey (Phase 0)

> Produced 2026-07-23 by the `condoedge-domain-survey` workflow (`wf_5629af55-aa8`, 10 agents, read-only over `../CondoEdge`). Seeds the eval gold set + config tuning. Effective config = `../CondoEdge/config/ai.php` + `config/ai-entities/*.php` (NOT the kompo/ai package template).

## 1. Domain map by role

| Role | Job-to-be-done | Data/document areas that matter |
|---|---|---|
| **Co-owner (resident/owner; `contact`)** | Manage their own unit and money, stay informed about building life, exercise their CCQ rights. Scoped to *their* unit(s) only. | (1) **My fees & account** — Invoice, InvoiceDetail, Transaction, unit_outstanding_balance. (2) **My unit's assets** — Unit, Extra (parking/storage), Vehicle, Pet, Access. (3) **Building life** — Announcement, Meeting/Motion, Reservation/Resource, Event. (4) **CCQ law & documents** — private-vs-common, votes, arrears-on-sale, contingency fund; stored File/Instruction. |
| **Board member (`contact` + `board_members` pivot)** | Everything a co-owner has, **plus** read-only oversight of union finances and governance; drive decisions & approvals. Gate on `$boardOrStaff`, not role. | (1) **Union finances (read-only)** — arrears list, Fund/reserve balance, Budget, cash position. (2) **Governance** — Meeting, Motion, Vote/Voter (quorum/tally), BillApproval/UnionApproval. (3) **CCQ decision law** — majorities (3/4, 3/4+90%), quorum, insurance duties, enforcement. (4) **Projects** — Project, QuoteRequest, WorkOrder pending approval. |
| **Property manager / staff (`lead`, `manager`, `accountant`, `assistant`, `concierge`)** | Run day-to-day operations of the union(s) they manage. Sees all units in current team. Finance tier excludes concierge (`$permanentOnly`). | (1) **Receivables/payables** — Invoice, Bill, BillApproval, Budget, Account, Transaction, Conciliation, team_total_owed / supplier_payables. (2) **People & contacts** — Contact, ContactUnit, BoardMember, Review, TeamRole. (3) **Ops & tasks** — Task, TaskDetail, Maintenance, MaintenanceContract, Visit/VisitDetail, Notification. (4) **Suppliers & projects** — Supplier, SupplierRating, WorkOrder, Project, QuoteRequest, Component. |
| **Supplier (external vendor)** | See only their own bills, work orders and interactions. 403 on every staff route — staff-gated AI actions never advertised. | (1) **My bills & payment status** — Bill (own), payment/approval state. (2) **My work** — WorkOrder, Task assigned, QuoteRequest, MaintenanceContract. |

## 2. Ranked high-value questions (North Star seed)

| # | Question | Role | Cluster | Why it adds value | Multi-turn? |
|---|---|---|---|---|---|
| 1 | "How much do I owe right now, and when is my next payment due?" | Co-owner | calculation | Top resident concern; `unit_outstanding_balance`. | — |
| 2 | "Show my past invoices and payment history for my unit." | Co-owner | data-query | Everyday account action. | → "now export that to PDF" |
| 3 | "What's the current arrears list — which units are behind and by how much?" | Manager/board | data-query + calculation | Core team-scoped op query; scope to `current_team_id`. | → "export that list to Excel" |
| 4 | "Give me all team members with their roles and contact info, as an Excel." | Manager/lead | export (data-query first) | Team roster; export-follow-up + insider-framing target. | Follow-up-shaped |
| 5 | "Show all open supplier bills awaiting approval and their due dates." | Manager/board | data-query | Payables/approval workflow. | → "which need board sign-off?" |
| 6 | "How much do we currently owe suppliers?" | Manager/accountant | calculation | `supplier_payables`; distinct from invoiced total. | — |
| 7 | "What's the reserve/contingency fund balance and recent transactions?" | Board/accountant | data-query + calc | Core CCQ fund concept. | — |
| 8 | "Can I tear down / move an interior wall in my unit, and whose approval do I need?" | Co-owner | document-law | Structural-modification — CCQ 1044/1045/1100. | — |
| 9 | "What majority is required to approve major work on common areas / a special assessment?" | Board | document-law | CCQ 1097 (3/4), 1072.1, 1096. | — |
| 10 | "If a co-owner hasn't paid, do they lose their vote or board seat?" | Board/manager | document-law | CCQ 1094 + 1086. | — |
| 11 | "When I buy a unit, am I liable for the previous owner's unpaid fees?" | Co-owner (buyer) | document-law | CCQ 1069 (yes, 15-day statement). | — |
| 12 | "What insurance must the syndicate carry, and what must I insure myself?" | Board/co-owner | document-law | CCQ 1073/1064.1/1074.3. | — |
| 13 | "Who is on the condo board this year, and who is president?" | Co-owner | data-query | BoardMember. | — |
| 14 | "When is the next meeting and what motions are on the agenda?" | All | data-query | Meeting + Motion. | → "summarize the last AGM minutes" |
| 15 | "How many voters were present at the last AGM — was quorum met?" | Board | data-query + law | Voter aggregate + CCQ 1089 quorum. | — |
| 16 | "What amenities can I reserve here, and what are the booking rules?" | Co-owner | data-query | Resource + Reservation. | → "book the party room Saturday" |
| 17 | "Which maintenance contracts expire in the next 60 days?" | Manager | data-query | MaintenanceContract. | — |
| 18 | "Which suppliers are best rated for roofing / plumbing?" | Manager | data-query | Supplier + SupplierRating. | — |
| 19 | "What tasks are assigned to me and still open / urgent?" | Concierge/staff | data-query | Task + TaskDetail. | → "which are overdue?" |
| 20 | "Compose a contact sheet for unit 310's owners and give me a PDF." | Manager/assistant | export (document mode) | Composed-document export (`mode=document`). | Follow-up-shaped |
| 21 | "What's the variance between budgeted and actual expenses this quarter?" | Accountant | calculation | Budget vs actual. | — |
| 22 | "Show me the balance." | Co-owner | clarification-required | **Ambiguous** — seeds clarify behavior missing from persona. | Clarify → then answer |

## 3. Entity keep/prune list (16 categories)

| Category | Verdict | Specific entities to DROP | Why |
|---|---|---|---|
| **billing** | **PRUNE (all 6)** | PaymentMethod, Plan, PlanService, PlanUser, Price, Service | SaaS/Stripe subscription plumbing for the CondoEdge product, wrong audience; Price's "price/tarif/cost" aliases **hijack condo-fee queries** — top accuracy risk. |
| **finance** | **TRIM** | BillDetailTax, BillItemTax, InvoiceDetailTax, InvoiceItemTax, BillItem, InvoiceItem, AccountName, Rgcq, BudgetDetailDate, BudgetDetailQuote, FundDate, FundQuote | Keep Invoice, Bill, BillApproval, Budget, Fund, Account, AccountBalance, Bank, Conciliation, Tax, Transaction, UnionApproval (+Entry). Drop tax sub-lines, *Item templates, seed/reference tables, monthly/fraction mechanics. Detail lines = via-parent, not top-level. |
| **eft** | **TRIM → EftFile only** | EftLine | Keep EftFile for managers chasing failed collections; EftLine is raw CPA-005 bank plumbing. |
| **supplier** | **TRIM** | ComponentModel, MaintenanceModel, Servicing, ServiceElement, Element, Material, Constituent, Room, Blueprint | Keep Supplier, SupplierRating, WorkOrder, Project, QuoteRequest, Maintenance, MaintenanceContract, Component. Drop templates & classification taxonomies. |
| **condo** | **TRIM** | AccessType, ChargerType, AnnouncementRead (+Entrance unless access-code lookup is real) | Keep Union, Unit, Building, Extra, Resource, Reservation, Access, Announcement, Instruction, Pet, Vehicle, Visit, Event, UnionContract, Note. Drop lookup enums & read-pivot. |
| **contact** | **TRIM** | ContactType (+ContactDirectory unless direct phone/email queries needed) | Keep Contact, ContactUnit, BoardMember (high-value trio). ContactUnit carries load-bearing owner/renter/sold_at semantics — never prune. |
| **meetings** | **TRIM** | Schedule (+Vote/Voter unless quorum/tally is a use case) | Keep Meeting + Motion first-class. Vote/Voter pivot-like — keep only for aggregate/quorum questions. |
| **filemanager** | **PRUNE as-is** | FileFolder (pivot), Folder (near-useless without File) | Real deliverable `File` lives in **general**; prune whole category or keep only Folder+File and always drop FileFolder. |
| **calendar** | **KEEP** | — | Single meaningful entity VisitDetail (inspection outcomes users ask about). |
| **discussions** | **TRIM** | DiscussionBox, DiscussionRead, Member | Keep Channel + Discussion. Drop per-user box, read-receipt, access-pivot. |
| **messaging** | **TRIM** | Distribution, InboundEmail, MessageRead, ThreadBox | Keep Message, Thread, Attachment, MessageForward (+EmailAccount if mailbox-ownership matters). Drop join-rows, raw blobs, receipts, boxes. |
| **phone** | **TRIM → CallLog only** | PhoneConfiguration (+CallLog too if telephony unused → PRUNE whole category) | PhoneConfiguration is device/hardware plumbing. |
| **general** | **TRIM** | Newsletter, Relation, Signature, Template, UserSetting (+Address, Tag, TeamInvitation unless in scope) | Keep User, Team, TeamRole, File, Notification, Activity, Recurrence, Review. Drop marketing/cosmetic/join/preference plumbing. |
| **imports** | **PRUNE (both)** | Import, ImportRow | ETL staging; ImportRow is 70+ raw o1_/p1_ columns of PII duplicating canonical Unit/Contact — heavy redaction risk, hurts accuracy. |
| **settings** | **PRUNE (both)** | Question, QuestionUser | Account-security verification config; QuestionUser holds sensitive plain_email. Zero query demand. |
| **task** | **TRIM** | TaskLink, TaskRead | Keep Task + TaskDetail (highest-value). Drop polymorphic join & read-receipt. |

**Summary:** prune/trim from 16 categories to the core set: **finance** (Invoice/Bill/Budget/Fund/Account/Transaction/BillApproval), **supplier** (Supplier/WorkOrder/Project/Maintenance/QuoteRequest/Component), **condo** (Union/Unit/Building/Extra/Resource/Reservation), **contact** (Contact/ContactUnit/BoardMember), **meetings** (Meeting/Motion), **general+task** (User/Team/TeamRole/File/Task/Notification/Activity), **calendar** (VisitDetail) — cutting the ~56-entity money side and ~24-entity infra side roughly in half.

## 4. Config → prompt-stage routing map

| Config key | Reaches QUERY-gen (A)? | Reaches RESPONSE-gen (B)? | File:line evidence |
|---|---|---|---|
| `project.name` / `description` / `domain` | **YES** | **YES** | A: `ProjectContextSection.php:62,66,70`; B: `ResponseProjectContextSection.php:42,46,50` |
| **`project.business_rules`** | **YES** | **NO** ⚠️ | A: `ProjectContextSection.php:74-79`; **absent** in B `ResponseProjectContextSection.php:40-54`. `config/ai.php:1451` vs `:1478` |
| `identity.persona` | **YES** | **YES** | A: `SemanticPromptBuilder.php:255,257,274` via `AssistantIdentity.php:45-48`; B: `IdentitySection.php:35` (`config/ai.php:1474`) |
| `guardrails.domain_scope` | **NO** | **YES** (response-exclusive) | B only: `ResponseGuardrailsSection.php:57-59` (`config/ai.php:1477`) |
| `calculations` | **YES** | **NO** (query-exclusive) | A only: `CalculationsSection.php:12-14,37-46` (`config/ai.php:1466`) |
| entity metadata / per-entity `business_rules` | **YES** | **NO** | `DetectedScopesSection.php:80-82` — query-gen list only |

### Verdict — the team-info bug (CONFIRMED)
The rule *"When you are in a team it is considered your team, so you shouldn't reference it as external…"* is `project.business_rules` #7 (`config/ai.php:136-144`). `business_rules` render **only** into query-generation (`ProjectContextSection.php:74-79`); the response twin `ResponseProjectContextSection::format()` renders name/description/domain and stops (`:40-54`). **The answer LLM never sees the rule** → "contacts your own team like an outsider." The rule is misfiled (a voice rule in a query-building block) and grammatically broken.

**Single most-general fix:** move the insider-framing into **`identity.persona`** (the one key already in BOTH prompts). Add: *"the user is a member/insider of their own team/building — speak in first-person plural, never refer to their team as an external party."* No code change. Add a **clarify-when-ambiguous** clause at the same time (fixes seed Q22).

### Other mis-routed / gap flags
- **`calculations` is query-exclusive** — canonical money formulas never enter the response prompt, so the model can't correct arithmetic while *narrating*. "Totals in the answer text are wrong" is NOT fixable via `calculations`.
- **`domain_scope` is response-exclusive** — refusal/on-topic behavior lives only in B; `project.description` leans on unset env fallbacks (latent weak spot).
- **business_rule #6** ("export a document OF info is still a DATA question") — its export-at-response-time half never reaches B.
- **Persona gaps** (`identity.persona`): export behavior heavily covered, but **insider voice missing** and **no clarify-when-ambiguous** instruction.
- **Export column bug (Bug #2):** there is **no `ai.export` block** in the effective config; `redact_columns`/`auto_identity_fields` do not exist there. "All team members exports only the email" is **upstream of the exporter** — most likely the team-members query's narrow RETURN projection (business_rule #3) or the LLM PII-minimization pass (`access_control.identifying_fields`, which lists `email`) stripping name/phone. Needs the trace to pin — do not patch blindly.
