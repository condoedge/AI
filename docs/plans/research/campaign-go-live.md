# How a Coolecto campaign goes online, and what Coco could do after `CreateCampaignTask`

Read-only research for the next plan. This file states facts, not a plan. Paths are relative to
`C:\Users\jkend\Documents\Projects\kompo\ActiviteDeFinancement`. Line numbers come from the working tree on branch
`feat/ai-integration` (2026-10-25). Unverified points are marked **OPEN**. The per-card modal details (class file:line, fields, rules) are in
`tasks-catalogue.md` §6 L « Setup cards (dashboard band) » and rows L*.

## 1. Summary

1. A campaign is born offline (`enabled = 0`). `CampaignGoLiveService::goLive` (`app/Services/Campaigns/CampaignGoLiveService.php:26-36`)
   turns it on when `missingRequiredSteps()` is empty. That method keeps the setup cards whose priority is `critical` or
   `important` and that are not done (`app/Models/Traits/CampaignStepsTrait.php:273-278`). Both priorities show the red
   « Obligatoire » pill (`app/Kompo/Helpers/elements.php:161`).
2. Nobody presses a button. Going live is a side effect of loading a page:
   - `EnableCampaignIfReadyMiddleware` runs on every full-page GET of the web group (`app/Http/Kernel.php:54`,
     `app/Http/Middleware/EnableCampaignIfReadyMiddleware.php:19-38`).
   - `DashboardView::created` calls the same check (`app/Kompo/Dashboard/DashboardView.php:45`). So do the form pages
     (`SurveryFormPage.php:43`, `SurveryP2pFormPage.php:26`).

   Inscriptions are the exception. `enableIfReady` refuses them (`:18`), and they wait for the « Mettre en ligne » button
   (`NewOnboardingCard::goLive`, `app/Kompo/Dashboard/NewOnboardingCard.php:127-133`, `isEditor()` only; `SetupShareStep::goLive`
   `:177-179`).
3. Consequence for tasks: a task that completes the **last** required card publishes the campaign on the user's next page
   view. The confirm summary of such a task must say so.
4. "Online" is not "selling". Sales are accepted only between `start_date` and `end_date` when the campaign is not paused
   (`getCurrentPhase`, `app/Models/Traits/CampaignChecksTrait.php:37-65`; `Campaign::scopeActive`). The phase stays `setup`
   while `isMissingInfo()` is true (`CampaignChecksTrait.php:11-14`: infos, products, recipients, dates, communications).
5. Bank or Stripe never blocks going live. `bank_info` is `suggested`, or `pending` while the bank is being verified
   (`app/Models/Onboarding/Steps/BankInfoStep.php:69-72`).
6. Today, after creating a campaign, Coco shows only one sentence and one button (§6). It never reads the checklist.

## 2. The cards (`config/onboarding-steps.php`, `app/Models/Onboarding/Steps/*`)

Base class: `app/Models/Onboarding/CampaignOnboardingStep.php`. The list is built by `Campaign::getOnboardingSteps()`
(`CampaignStepsTrait.php:258-271`): classes from config → `appliesTo()` → keyed by `key()`. Inscriptions are reordered.
Campaigns from a wizard profile are filtered by `checklist_exclude` (`filterStepsByProfile`, `:304-317`). A Coco-created
campaign has an `Onboarding` without `onboarding_type`, so it is never filtered (`Onboarding::hasProfile`, `Onboarding.php:87`).
No card applies in supplier mode, except `campaign_info` and `products`. `campaign_info` is always done for a supplier
(`checkInfos`, `CampaignChecksTrait.php:103`).

The last column says how each card is reached today. A dashboard card opens a modal in place. No URL opens a given card's
modal (no query parameter, no section id on the band; the band's DOM id is `onboarding-card-id`, `NewOnboardingCard.php:27`).

| Key | Shown when (`appliesTo`) | Priority | Done when (`check`) | Card opens |
|---|---|---|---|---|
| `welcome` | not supplier; type needs a reason and welcome not completed, or type needs no reason (`WelcomeStep.php:52-56`) | `critical` only while it asks the reason, else none (`:70-73`) | reason written (any language) or `welcome_completed_at` (`:75-80`) | `WelcomeModal` (`NewOnboardingCard.php:228`) |
| `delivery_choice` | not supplier, campaign from a supplier, done or no orders yet (`DeliveryChoiceStep.php:66-71`) | `critical` when the supplier offers a choice (`:83-86`) | setting `delivery_choice_made` (`:93-96`) | `DeliveryChoiceModal` (`:218`) |
| `team_info` | not supplier (`TeamInfoStep.php:43-46`) | `critical` | `team.name && primary_contact && business_type` (`Team.php:736-739`) | `TeamInfoModal` → `TeamInfoForm` (`:188`) |
| `campaign_info` « Compléter ma campagne » | always (`CampaignInfoStep.php:24-27`) | `critical` | `start_date`; raffle also needs `venue_address` + `minimum_age` + draw time; P2P also needs team types and min/max team size (`checkInfos`, `CampaignChecksTrait.php:101-108`, `missingP2PInfo` `:26-35`) | `StepCampaignDates` modal; Inscriptions: link `campaign.inscription-setup?step=audience` |
| `campaign_reason` | not supplier, Product or Donation, welcome not asking it (`CampaignReasonStep.php:25-30`) | `critical` | reason in any language (`CampaignChecksTrait.php:213-220`) | `OnboardingCampaignReasonForm` (`:233`) |
| `campaign_communications` | `aiWritesCommunications()` = not supplier and Product or Donation (`CampaignChecksTrait.php:149-152`) | `critical` | `no_communications` or `communications_reviewed_at` (`:227-230`) | AI e-mail generation modal (`:240`) |
| `travel_agency` | main campaign of type Travel | `important` | settings `travel_agency_name` and `travel_funds_go_to` (`:191-194`) | `StepCampaignTravelAgency` (`:291`) |
| `products` | form, or anything but a main campaign (`ProductsStep.php:24-27`; `hasProducts` `CampaignTypesTrait.php:401`) | `critical` | form: at least one question (`polls`); Inscriptions: activities each with a group; others: at least one product with a price (`CampaignChecksTrait.php:110-121`) | products modal; form: `campaign.survey` page; Inscriptions: setup « activities » step |
| `registration_form` | Inscriptions | `suggested` | setup FORM step saved | setup « form » step |
| `delivery_info` | not supplier, from a supplier, not delivered by e-mail, delivers to the organisation | `before_end` | delivery address and contact | `DeliveryInfoModal` (`:223`) |
| `participants` | not supplier, not Inscriptions, `has_recipients` 1 (list) or 2 (without list) (`ParticipantsStep.php:35-40`) | `critical` | list: at least one recipient **and** one donor; without list: groups exist, or `mute_groups`/`onboarding_without_groups` (`:62-67`, `CampaignChecksTrait.php:123-131`) | `ParticipantsModal` (list) or `OnboardingGroupsModal` (`:250`, `:259`) |
| `registration_link` | not supplier, `has_recipients = 2` (`RegistrationLinkStep.php:25-29`) | `critical` (Inscriptions: `suggested`) | `recipient_registration_sent_at` (Inscriptions: link shared) | `RecipientRegistrationLinkModal` (`:266`); it e-mails the link to the address typed, by default the organiser's own (`RecipientRegistrationLinkModal.php:42-46`, `:59`) |
| `dates` | multi-date, not Inscriptions (`DatesStep.php:14-18`) | `critical` | at least one `CampaignDate` (`CampaignChecksTrait.php:133-136`) | dates list modal (`:314`) |
| `prizes` | raffle with a prize pool | `important` | at least one prize | prizes modal |
| `communications` « Créer les communications » | not `aiWritesCommunications()` | `important` (Inscriptions: `suggested`) (`CommunicationsStep.php:31-34`) | `no_communications` or at least one communication (`CampaignChecksTrait.php:143-146`) | `CommunicationOnboardingModal` (`:303`): "no e-mails" or "use the templates" |
| `draw_licence` | raffle | `critical` | valid RACJ licence | `StepCampaignDrawLicence` |
| `lock_dates` | raffle | `critical` | `locked_dates_and_prices` + dates + draw date (`:160-163`) | `LockDatesOnboardingModal` (irreversible) |
| `budget_number` | parent forces a budget number, not a main campaign | `suggested` | `account_code` | `CampaignBudgetNumberModal` (`:281`) |
| `approval` | parent forces approval (`Campaign::requiresApproval`, `Campaign.php:1368`) | `critical` | an **approved** `ApprovalRequest` (`CampaignChecksTrait.php:165-168`) | `CampaignApprovalRequestModal` (`:274`): creates a pending request and e-mails the parent's approver |
| `bank_info` | not super admin, no parent that captures transfers | `pending` (bank under verification) or `suggested` | `team->payoutInfoCompleted()` | bank form; « owner only » is enforced in the UI only (`BankInfoStep.php:85-93`) |
| `added_donation` | `add_donation`, not a donation-type campaign, not tips-only | `suggested` | text + more than one amount, or donation without purchase configured (`CampaignChecksTrait.php:180-184`) | `AddedDonationModal` (`:286`) |
| `show_goal` | `show_goal` or main wrapper | `suggested` | `goal` set when `show_goal` (`:186-189`) | `ShowGoalModal` (`:298`) |

## 3. « Obligatoire » cards per type, for a campaign created by Coco

Assumptions: team user (not supplier mode, not staff), no network, `team_info` not yet complete. Every type gets `team_info`
(until the organisation's name, primary contact and business type are set) and `campaign_info`. Network add-on: `approval`
(critical) when the parent forces approval. The column "CreateCampaignTask already fills" lists what the optional groups of
`CreateCampaignTask` / `CampaignSettingsWriter` can complete today.

| Type (picker label) | Extra « Obligatoire » cards after creation | What creation sets that already ticks a card | CreateCampaignTask already fills |
|---|---|---|---|
| Repas bénéfice (1), one date with list | `products` (no default product), `participants` (list), `communications` | — | `campaign_info` (dates group) |
| Repas bénéfice, several dates (meal type 3) | as above + `dates` | — | `campaign_info` |
| Vente de produits (3), own products | `welcome` (asks the reason) or `campaign_reason`, `campaign_communications`, `products`; `participants` (+ `registration_link` when without a list) if the organiser chose participants | — | `campaign_info`, the reason (`welcome`/`campaign_reason`) |
| Vente de produits, supplier turnkey copy | `delivery_choice` (when the supplier offers one), reason/`welcome`, `campaign_communications` (copied e-mails must be reviewed), `participants` per choice; `delivery_info` is « Avant la fin » | products copied with prices (`products` done) | `campaign_info`, reason |
| Vente de billets (5), general admission or assigned seats | `products` (priced ticket types; seats: venue plan), `communications`; `participants`/`registration_link` if a list was chosen | — | `campaign_info` |
| Incitation à l'effort (6) | `participants` (the wizard forces a list), `communications`; `dates` if several important dates | default pledge products 1/2/5 $ (`Campaign::generateDefaultProducts`) | `campaign_info` |
| Dons (2) | `welcome`/`campaign_reason`, `campaign_communications`; `participants` only if a list was chosen | default donation products 5/10/20 $ | `campaign_info`, reason |
| Formulaire (4) | `products` = the form has at least one question (page `campaign.survey`), `participants` (the wizard sets `has_recipients = 1`), `communications` | — | `campaign_info` |
| Encan (7) | `products` (priced lots), `communications`; participants per choice | — | `campaign_info` |
| Pairs à Pairs (8) | `campaign_info` stays undone until team types (`P2PGroupType`) and min/max team size exist, even with dates; `communications` | default donation products | dates only (team types and sizes are not in the task) |
| Groupe de campagnes (10) | `communications`; Travel: `travel_agency`; Event/School year: `participants` (without list) + `registration_link` | Event: two ticket sub-campaigns created | `campaign_info` |
| Inscriptions (11) | `products` (activities, each with a group); the rest is `suggested`; then the « Mettre en ligne » button (editor+) | dates and audience are written at creation (`campaign_info` done) | (dates are type fields of the task) |
| Raffle (staff only, not offered by Coco) | `draw_licence`, `lock_dates`, `prizes` (important), `campaign_info` needs venue, minimum age and draw time | default ticket packs | — |

Raffle caution: `CampaignSettingsWriter::dates()` cannot write a raffle's dates. `StepCampaignDates::datesRules` requires the
draw fields, which the writer never supplies. The writer also skips the card's `beforeSave` checks: locked information, and
draw date after the end date. See `tasks-catalogue.md` §2.6 and rows S11, L24.

## 4. Per card: can a task fill it, or does it need a page?

The detail is in `tasks-catalogue.md` §6 L « Setup cards (dashboard band) ». In short:

| Card | Task-fillable? | Why / what the task would write | Where to send the user otherwise |
|---|---|---|---|
| `campaign_info` | **yes**, except raffle and P2P team types | dates through `CampaignSettingsWriter::dates()` (already used) | `campaign.page.settings?section=sec-campaign-campaign-activity-dates` |
| `welcome` / `campaign_reason` | **yes** for the reason | `reason_description` through the writer (already in CreateCampaignTask). The welcome's own « I have read » acknowledgement is not a task: it explains things the user should read | `campaign.page.settings?section=sec-campaign-campaign-reason` |
| `team_info` | **partly** | `TeamInfoForm` (`app/Kompo/Teams/TeamInfoForm.php`, rules `:201`): name, primary contact e-mail, business type are plain fields; logo (upload) and Places address are not. It saves `Change::addWithMessage` (team audit). Manager-only page in navigation (`org.manage`), but the dashboard modal has no role check | `org.manage?section=sec-register-organization` (nav gate: manager) or the dashboard |
| `communications` (non-AI types) | **yes**, as a choice: "no e-mails" (`no_communications = 1`) or "use the templates" (creates default communications with an AI rewrite job, `CommunicationOnboardingModal.php:42`, `:50`; needs `start_date`, `:84`) | **outward later**: generated e-mails get scheduled envois to buyers and participants. The confirm card needs an explicit row | `campaign.page.communications` |
| `campaign_communications` (Product/Donation) | **partly** | a task could start the AI generation (mood, address form). The card closes only when the organiser has reviewed the e-mails (`communications_reviewed_at`, `CommunicationGenerationByAiModal.php:216`). Reviewing is iterative | dashboard card (modal); afterwards `campaign.page.communications` |
| `products` | **partly**: one product per write, or a list field | `CampaignProductForm` holds all rules (see catalogue P1–P7). The product types differ per campaign type. A form (Formulaire) needs the editor (iterative) | `campaign.page.products`; form: `campaign.survey` |
| `participants` (list) | **no** for the list itself | many records, often MINORS (names, school, level); Excel import is an upload | `campaign.page.recipients?section=sec-campaign-recipients` |
| `participants` (without list) | **yes** for "no groups" (`onboarding_without_groups`) or a few group names | groups are plain rows (`OnboardingGroupForm.php:49`: name required) | `campaign.page.recipients?section=sec-campaign-groups` |
| `registration_link` | **yes** | one e-mail address (default: the organiser's own) → `RecipientRegistrationLinkMail` + `recipient_registration_sent_at`. Outward if the address is someone else's | dashboard card |
| `dates` (multi-date) | **partly**: a list of dates | `CampaignDate` rows through `CampaignDateForm` (other area; per-row Kompo form, no service) | `campaign.page.dates` (no section id) |
| `delivery_choice` | **yes** | enum `delivery_type` + setting `delivery_choice_made`. Needs a rule (the card has none) | dashboard card |
| `delivery_info` (« Avant la fin ») | **partly** | date, contact, notes yes; the address is a structured Places value | `campaign.page.settings?section=sec-campaign-delivery-information` |
| `travel_agency` | **yes** | two settings (`StepCampaignTravelAgency.php:107` rules) | dashboard card |
| `approval` | **yes**, outward | one message → `ApprovalRequest` pending + e-mail to the parent's approver. The card then waits for a human decision | dashboard card |
| `budget_number` (suggested) | **yes** | `account_code`, required text (`CampaignBudgetNumberModal.php:25`) | dashboard card |
| `added_donation`, `show_goal` (suggested) | **yes** | already in the writer (donation, goal) | settings sections `sec-campaign-ask-for-donation-page`, `sec-campaign-campaign-goal` |
| `draw_licence`, `lock_dates`, `prizes` | **no** for Coco (raffles are staff-only; licence and locking are legal and irreversible) | — | dashboard |
| `bank_info` | **no** (provider/bank verification + cheque image upload; not blocking) | — | `finance.onboarding` (nav `bank_account`, manager gate) |

## 5. What a "help me get it online" follow-up could use (facts only)

- **Checklist from code.** `$campaign->getOnboardingSteps()` gives each applicable card with `key()`, `titleKey()` (wording via
  `title()`, team-reworded), `priority()` and `isDone()`. `$campaign->missingRequiredSteps()` is exactly what keeps it offline.
  Both are pure reads. Callers today: the dashboard band, the go-live service, the supplier client modals
  (`SupplierClientCampaignInfoModal`, `SupplierNotStartedCampaignModal`), admin views. Note: `team_info`, `delivery_choice`,
  `campaign_reason`, `participants` and `registration_link` read `currentSupplierId()`. The list must be computed in the
  organiser's request (logged in, team mode), not in a queue.
- **Chips to pages.** `config/ai.php:128-191` `navigation.pages` declares organiser pages with gates (`app/Ai/NavigationGates.php`):
  - `campaign_dashboard` (anyone);
  - `campaign_settings`, `campaign_products`, `campaign_communications` (`currentCampaign`);
  - `campaign_participants` (`editorOnCampaign`);
  - `bank_account`, `my_organisation` (`manager`);
  - `new_campaign` (`campaignCreator`; it also carries `'task' => CreateCampaignTask::KEY`).

  A chip may carry a section of its page as `?section=<id>`. A global script scrolls to it and opens its tab
  (plan `2026-10-22-page-section-anchors.md`). Registry: `resources/ai-help/sections.php`, generated by `ai:sections:plan`.
  Sections useful for go-live:
  - `campaign.page.settings`: `sec-campaign-campaign-activity-dates`, `sec-campaign-campaign-reason`,
    `sec-campaign-campaign-goal`, `sec-campaign-ask-for-donation-page`, `sec-campaign-delivery-information`,
    `sec-campaign-groups-settings`, `sec-campaign-p2p-pricing`, `sec-onboarding-recipients-quest1`;
  - `campaign.page.recipients`: `sec-campaign-recipients`, `sec-campaign-groups`, `sec-campaign-p2p-groups`;
  - `campaign.page.products`: `sec-campaign-tickets`, `sec-campaign-event-dates`;
  - `org.manage`: `sec-register-organization` (team info), `sec-register-organization-tax-receipt`.
- **Gaps for chips.**
  - There is no navigation page for `campaign.page.dates`, `campaign.survey` (form editor), `campaign.inscription-setup` or
    `campaign.page.inscriptions`.
  - No URL opens a single setup-card modal: welcome, delivery choice, approval, registration link, travel agency, budget number.
    A chip can only land on the dashboard, where the band is the first card.
  - The `my_organisation` gate is `manager`, but the `team_info` card's modal is open to any team role. An editor would get
    no chip.
- **Steps that could be tasks** (see the catalogue shortlist):
  - dates, the reason, the goal and the suggested donation amounts (writer exists);
  - team info without the logo;
  - the communications choice ("no e-mails" vs "templates": outward-later, needs an explicit row);
  - the registration link to oneself;
  - delivery choice, travel agency, budget number;
  - the approval request (outward to the network);
  - a groups list, or "no groups";
  - one or a few products (a product writer would be new).

  Everything else should be links: participants list import, form editor, AI e-mail review, bank, raffle cards.
- **Order of a follow-up**: the band's own order is the config order (`config/onboarding-steps.php:8-31`); Inscriptions reorder
  (`CampaignStepsTrait.php:280-302`). The `Obligatoire` subset is `missingRequiredSteps()`.
- **Live-on-next-page-view**: when a follow-up task completes the last missing card, the campaign goes live on the next GET
  (§1.2) and `DashboardView` opens `CampaignCreatedCongratsModal` (`DashboardView.php:48-52`; campaigns created after
  2026-04-30). The confirm card of that task is the last moment to say so.
- **Authorization reality for follow-up writes**: no setup-card modal checks a role. Only Inscriptions go-live checks
  `isEditor()`. A follow-up task must apply its own gate, e.g. the `campaignCreator` gate the creation task uses, or editor+.
  That gate would be stricter than the UI, which today lets a Member write through every card.

## 6. What `CreateCampaignTask` shows after creation today

- `execute()` (`app/Ai/Tasks/CreateCampaignTask.php:218-236`):
  - creates the campaign (wizard path or supplier template copy);
  - calls `switchCampaign`;
  - applies the optional groups through `CampaignSettingsWriter`;
  - records `campaign_created_by_ai` on `tracked_events`;
  - returns `TaskOutcome::success(['campaign_id' => …], __('campaign.coco-campaign-created', ['name' => …]))->withLink(route('switch.campaign', [... 'redirect_to' => route('dashboard')]), 'campaign.coco-open-campaign-dashboard')`.
- Text shown (EN, `resources/lang/en/campaign.php:717`): "Your campaign “:name” is created. It is not live yet: finish
  setting it up from the dashboard." (FR `:717` « Votre campagne « :name » est créée. Elle n'est pas encore en ligne : terminez
  sa mise en place depuis le tableau de bord. »)
- One chip: "Go to the campaign dashboard" (`:732`). It is rendered as the outcome link by `MessageBubbleRenderer`
  (`ai/src/Kompo/Support/MessageBubbleRenderer.php:454-455`), from `TaskOrchestrator::outcomeResponse`
  (`ai/src/Services/Tasks/TaskOrchestrator.php:790-795`).
- The `TaskOutcome` has one link only (`TaskOutcome::withLink`, `ai/src/DTOs/Tasks/TaskOutcome.php`). There is no list of
  remaining cards, no count of « Obligatoire » cards, no per-card chip and no follow-up task.
- The help article `creer-sa-campagne-avec-coco` repeats this. It says the campaign "goes live by itself when the
  « Obligatoire » cards of the setup band are done (dates, products, participants, depending on the type)".
- The local dev DB has 33 `ai_tasks` rows and 7 `campaign_created_by_ai` tracked events, all local test runs.
