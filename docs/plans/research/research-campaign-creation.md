# Coolecto: campaign creation, settings, authorization (research for the AI assistant design)

Read-only research. All paths are relative to `C:\Users\jkend\Documents\Projects\kompo\ActiviteDeFinancement` (shortened `app/...`). Line numbers are from the working tree on branch `feat/ai-integration` (2026-10-22). Anything not verified is marked **OPEN**.

## 0. Ten-line summary

1. A campaign is born **disabled** (`enabled = 0`), without communications, without dates (except Inscriptions and wizard dates) and without a bank account. It goes live **by itself** when every `critical`/`important` card of the setup band is done (`CampaignGoLiveService`), except Inscriptions which wait for a manual "Mettre en ligne" button. Bank/Stripe is **not** a go-live prerequisite.
2. There is **one central creation method**: `Onboarding::createCampaignFromResults()` (`app/Models/Onboarding/Onboarding.php:241`). The `Onboarding` row (JSON `answers`) is the "draft": each wizard question writes a key into `answers`, the method maps keys to columns. Copies go through `CampaignCopyTrait::createCampaignCopyWithRelations()` (`app/Models/Traits/CampaignCopyTrait.php:137`).
3. There are **two UI entry points** creating campaigns: the logged-in chain `route('new-campaign')` -> `Step1InternalForm` -> `Step2OnboardingForm` -> per-type "OuiNon" steps -> `StepFinalOnboardingForm`; and the guest/acquisition wizard `route('onboarding.wizard')` = `/create-campaign` -> `OnboardingWizard` (config driven by `config/onboarding-profiles.php`). Both end in `createCampaignFromResults()`.
4. No DB transaction wraps creation (no `DB::transaction` in `Onboarding`, `CampaignCopyTrait`, copy forms). Guards (`TeamLockGuard`) are **explicit calls**, not model events: any new entry point (the AI) must call them itself.
5. Authorization is thin: route `new-campaign` has only `auth` + `profile:team|supplier` middleware (`routes/web.php:275`). The "no Member / no Scanner" rule exists only in the UI (sidebar hides the button) and in the AI navigation gate `campaignCreator`. No plan/billing cap on number of campaigns found.
6. Money-risky settings are few and mostly in "Paramètres paiement" and "Autres paramètres": cover fees, forced cover fees, added donation, profit share, opening balance, receipts/tax receipts, goal, prices on products, P2P registration fees, terminal, order bump, max days before. The platform `rate` is **not** organiser-editable (admin form only).
7. Network (parent organisation) policies that bite at creation: `force_approval_for_child_campaigns` (adds a `critical` approval card = blocks go-live), `force_budget_number_for_child_campaigns` (adds a `suggested` card), `disable_cover_fees_for_child_teams` (silently forces `cover_fees` to 0 via accessor), `capture_child_transfers` (hides the bank card), single main campaign for travel-agency child teams (aborts a second main).
8. Supplier-reserved organisations ("unlocks"): `TeamLockGuard::assertCanCreateCampaign` aborts 403 for non-product campaign types / other suppliers until unlocked (`app/Services/Unlocks/TeamLockGuard.php:23`).
9. Existing AI: "Coco" help chatbot (`config/ai.php`, `app/Ai/*`) is docs-only (`query_generation.enabled=false`), reads `resources/ai-help/aide/**`; it can only suggest navigation buttons (`ai.navigation.pages`, including `new_campaign` gated by `NavigationGates::campaignCreator`). Nothing creates data.
10. Help coverage of creation is good for "how", weak for "which type fits my situation" and "consequences of settings" (see section 6).

---

## 1. Campaign types

Constants: `app/Models/Campaign.php:90-100`. Labels/icons: `app/Models/Traits/CampaignTypesTrait.php:18-50` (labels from `welcome.*`, FR in `resources/lang/fr/welcome.php`). Column: `campaigns.campaign_type_id` (migration declares `foreignId('campaign_type_id')->constrained()`, but **no `CampaignType` model class exists**; `Campaign::campaignType()` at `Campaign.php:410` points at a missing class. **OPEN**: does a `campaign_types` table still hold rows? Irrelevant for the logic, which uses the constants only).

| Const | Id | UI label (FR) | Picker offers it? | What it is for | Key type-specific parts |
|---|---|---|---|---|---|
| `TYPE_DELIVERIES` | 1 | Repas bénéfice | yes | Meals served on one or several dates; may be sold as tickets (meal type "single date with tickets" flips it to Tickets) | `multiple_dates`, `CampaignDate`s, delivery (`CampaignDeliveryTrait`), `max_days_before`, `max_quantity_per_date`, `multi_products`, `one_choice_all_dates`, `mandatory_all_dates` |
| `TYPE_DONATION` | 2 | Dons | yes (onboarding key 5) | Pure donations; always tax-receiptable (`isAlwaysTaxReceiptable`, `CampaignTypesTrait.php:217`) | Default products 5/10/20 $ (`generateDefaultProducts`), `display_donors`, recurring option, `profit_share` |
| `TYPE_PRODUCT` | 3 | Vente de produits | yes (onboarding key 2) | Sale of products, usually a supplier catalogue; one delivery date | `supplier_id`, `Deliverable`->`Product`, `preferred_date_of_delivery`, `max_days_before=1`, order bump, variants; `needsCampaignReason()` true |
| `TYPE_SURVEY` | 4 | Formulaire | yes (onboarding key 6) | A form people fill (can also be a sub-form of another campaign: "sub survey campaign") | `Condoedge\Surveys` `Survey` created lazily by `Survey::getOrCreateForCampaign` (`app/Kompo/Surveys/SurveryFormPage.php:42`); `survey_campaign_id` for sub-surveys. Always allowed by `TeamLockGuard` |
| `TYPE_TICKETS` | 5 | Vente de billets | yes (onboarding key 3) | Three sub-kinds via `ticketing_type`: `TICKETING_TIRAGE=1` (raffle, **admins/impersonators only** in picker), `TICKETING_QR=2` (general admission, QR scanned), `TICKETING_VENUE=3` (assigned seats on a venue plan) | `has_venue`, `has_seats`, `seat_selection`, `Venue`/`VenuePlan`/`Seat`/`Reservation`, scanners, `SETTING_SINGLE_QR_PER_TRANSACTION`, `hide_section_*`, `split_ticket_types`, `ticket_type_name_*`, draw: `Drawing`, `Prize`, `drawing_type_id` (`TIRAGE_PRIZE_POOL=1`, `TIRAGE_5050=2`), RACJ licence (`draw_licence_number`, `draw_licence_expires_at`), `locked_dates_and_prices`, `minimum_age` |
| `TYPE_CHEERUP` | 6 | Incitation à l'effort (code name "pledge") | yes (onboarding key 4) | Donors pledge per effort/result of participants; results are entered at the end | `Pledge`, `PledgeAmount`, `has_grades`, `results_type` (`RESULTS_PER_GROUP=1`/`PER_PARTICIPANT=2`), `only_with_invitation_code=1` default, pay phase (`hasPayPhase`) |
| `TYPE_ENCAN` | 7 | Encan | yes | Auction of lots | `app/Models/Encan/*`, `BidBot`, `allow_bids_automation`, `ask_credit_card`, `phone_needed`, `display_name_of_highest_bidder`, `show_product_cost`, end SMS reminder, sponsors |
| `TYPE_P2P` | 8 | Pairs à Pairs | yes | Each participant/team raises donations (peer-to-peer) with registration (pre-registration, registration, event date) | `P2PGroupType` (team types, fees), `Group`, `CampaignLevel`, `CampaignChallenge`, `min/max_recipients_allowed_per_group`, `registration fees`, goals (`force_group_goal`, ...), `min_age_to_p2p_inscription` |
| `TYPE_MEMBERSHIP` | 9 | Adhésion | **no** (picker line commented out, `Step2OnboardingForm.php:52`) | Recurring membership. Code still exists | Sets start=now, end=now+10 years at creation (`Onboarding.php:322-326`); default membership product |
| `TYPE_MAIN` | 10 | Groupe de campagnes / Campagne principale | yes, but not when already creating a sub-campaign | A parent campaign grouping sub-campaigns (`MainCampaign` link) | `main_type`: `MAIN_IS_EVENT=1`, `MAIN_IS_TRAVEL=2`, `MAIN_IS_WRAPPER=3`, `MAIN_IS_SYNCABLE=4` (UI "Année scolaire"); central participants; `participant_goal`; Event main auto-creates 2 ticket sub-campaigns |
| `TYPE_INSCRIPTIONS` | 11 | Inscriptions | yes | Registrations to activities/courses (parent registers a child, or the person registers) with groups, schedules, places, instalments | `InscriptionSetup` (4 steps: audience, activities, form, share), settings `registrant_is_self`, `ask_*`, `Establishment`, `InscriptionLocation`, `GroupDate`, `recipient/group` links; **never goes live on its own** |

Notes useful for choosing a type:
- Onboarding keys 2-6 collide with campaign constants: `Onboarding::campaignTypes()` maps key->type (`Onboarding.php:49-63`): `2->PRODUCT(3)`, `3->TICKETS(5)`, `4->CHEERUP(6)`, `5->DONATION(2)`, `6->SURVEY(4)`; every other key is already a campaign type id.
- Granular predicates (`CampaignTypesTrait.php:57-471`): `isDonations()` = Donation or Cheerup or P2P; `hasNoDates()`; `shouldCreateInitialCampaignDate()` (Product, Tirage, P2P, or Deliveries/Cheerup when not multi-date); `hasTerminalTtp()`; `isSportReceiptEligibleType()` (Inscriptions or non-raffle Tickets, team has tax-receipt info); `canCustomizeTaxAmounts()`; `offersAddedDonationAndCoverFees()` false for Inscriptions; `hasVouchersOption()`; `typesWithStock()` (Tickets, Product, Encan, Inscriptions); `recurringTypes()` (Donation, Membership); `needsCampaignReason()` only Product and Donation (`CampaignChecksTrait.php:239`).
- The type is not changeable after creation (help `billets-types-de-campagnes-de-billets` says so for tickets; no code path in Modifier la campagne edits `campaign_type_id`; **OPEN**: not exhaustively grepped for admin tools).
- `config/onboarding-profiles.php` defines "scenarios" (enum `app/Models/Onboarding/OnboardingType.php`): `donation`, `school_product`, `school_meal`, `school_venue`, `sport_product`, `scout_product`, `cadet_product`, `social_product`, `quick_product`, `registration`. Each = org kind x mechanic, with default answers. Picker shows 7 of them (`OnboardingType::pickerCases()`): school_product, sport_product, school_venue, school_meal, registration, donation, quick_product.

---

## 2. The creation flow today

### 2.1 Entry points

| Entry | File:line | Who |
|---|---|---|
| Sidebar "+ Campagne" button | `app/Kompo/Menus/DashboardSidebar.php:129-133` (`_Link('campaign.campaign')... ->selfGet('newOrCopyCampaignChoice')->inModal()`); shown unless `isMember()` (`:314-315`); a scanner gets a reduced sidebar without it (`:137-143`) | logged-in team user |
| Choice modal | `app/Kompo/Campaigns/NewOrCopyChoice.php:41-49`: card 1 "copier une campagne existante" -> `CopyCampaignForm` modal; card 2 "démarrer une nouvelle campagne avec de nouvelles données" -> `href('new-campaign', ['from_main_campaign_id'])` | |
| `new-campaign` | `routes/web.php:275-276` group `['auth', 'profile:team|supplier']` -> `App\Kompo\Onboarding\Step1InternalForm` | |
| Dashboard with no campaign | `app/Kompo/Dashboard/DashboardView.php:86-88` renders `Step1InternalForm` | user with zero campaigns |
| Guest wizard | `routes/web.php:694` `create-campaign` (layout `guest`, no auth) -> `OnboardingWizard`. Linked from home personas (`app/Kompo/Home/Personas/*`), supplier landing, `BehyPage`, `SchoolGuidePage` with `?onboarding_type=...&copy_campaign_id=...&org_type_id=...&quick=1&refc=...&invite=...` | new organisations |
| Supplier link | `app/Http/Controllers/RegisterFromSupplier.php`: logged in + `products_from_supplier` -> `new-campaign?products_from_supplier=`; logged in + campaign id -> `supplier.copy-campaign`; guest -> `register?refc=&copy_campaign_id=` | |
| Old guest route `welcome` | `routes/web.php:722` `Step1OnboardingForm` (legacy, guests) | **OPEN** whether still used |
| API | `app/Http/Controllers/Api/ApiTeamController.php:69-85` creates a TYPE_MAIN campaign for travel-agency child teams (calls `TeamLockGuard::assertCanCreateCampaign`) | agencies |
| Admin test generator | `routes/web.php:503` `AdminTestCampaignCreation`, `CampaignTestsTrait` | staff |

### 2.2 Logged-in chain (`new-campaign`)

`Step1InternalForm` (`app/Kompo/Onboarding/Step1InternalForm.php:39-62`) picks the first card:
- supplier-mode user -> `Step2OnBoardingSupplierForm`;
- `?products_from_supplier=ID` -> new `Onboarding` with `setValuesToCopiedFromSupplier` (answers `campaign_type=2`, `add_donation=1`, `max_days_before=1`, `show_goal=1`, column `copy_product_from_supplier_id`) -> `OuiNonRecipientsStep`;
- team fully locked (no CAMPAIGN_TYPES, OWN_PRODUCTS, OTHER_SUPPLIERS) -> `SupplierCampaignsModal` of its supplier;
- else `Step2OnboardingForm` ("Quel type de campagne voulez-vous créer?").

State is an `Onboarding` row (`BaseOnboardingForm.php:19-30` creates one on first render). Each card click does `selfGet('getCardAsString', ['key'])` -> `saveAnswer($key)` writes `answers[variableName]` (+ side answers) and returns the next step class (`BaseOnboardingForm.php:66-76`). "Retour" calls `resetFormAnswer` to un-set the answers of the step.

**Step 2 (type)** `Step2OnboardingForm.php:33-59` (labels FR):

| Key | Card | Side answers saved (`saveAnswer`, `:71-118`) | Next step |
|---|---|---|---|
| `TYPE_DELIVERIES` (1) | Repas bénéfice | `show_goal=1` | `MealOnboardingStep` |
| `ONBOARDING_TYPE_PRODUCT` (2) | Vente de produits | `add_donation=1`, `max_days_before=1`, `show_goal=1` | `CampaignProductsOriginStep` (or `CampaignPickSupplierStep` when own products/other suppliers hidden by lock) |
| `ONBOARDING_TYPE_TICKETS` (3) | Vente de billets | `add_donation=1` | `TicketOnboardingStep` |
| `ONBOARDING_TYPE_CHEERUP` (4) | Incitation à l'effort | `has_recipients=1`, `add_donation=1`, `show_goal=1`, `has_grades=1`, `only_with_invitation_code=1` | `OuiNonResultsTypeStep` |
| `ONBOARDING_TYPE_DONATION` (5) | Dons | `show_goal=1` | `OuiNonProfitShareStep` |
| `ONBOARDING_TYPE_SURVEY` (6) | Formulaire | `has_recipients=1` | `OuiNonClosedCampaignStep` |
| `TYPE_ENCAN` (7) | Encan | none ("TODO what are the defaults" `:106`) | `OuiNonRecipientsStep` |
| `TYPE_P2P` (8) | Pairs à Pairs | `show_goal=1` | `OuiNonShowGoalStep` |
| `TYPE_MAIN` (10), not when `from_main_campaign_id` | Groupe de campagnes | none | `MainCampaignOnboardingStep` |
| `TYPE_INSCRIPTIONS` (11) | Inscriptions | `has_recipients=2` (without list), `multiple_dates=1` | `InscriptionStartOnboardingForm` |
| `TYPE_MEMBERSHIP` (9) | commented out | | |

Under a "complete lock" only the product card is offered; in "visible lock" mode the other cards show a padlock opening `FeatureLockedModal`. Above the cards: copy from a supplier template (select -> `copySupplierCampaign` redirect to `supplier.copy-campaign`, `:154-189,223`) and copy from the parent organisation's templates (`:191-249`).

**Per-type questions** (each answer = oui/non choice; `1`=yes, `2`=no unless stated). Question texts FR from `resources/lang/fr/onboarding.php`:

| Step class | Variable saved | Question / choices | Next |
|---|---|---|---|
| `MealOnboardingStep` | `meal_type` | 1 "Une seule date avec liste" (`has_recipients=1`); 2 "Une seule date avec billets" (**`campaign_type` switched to Tickets(3)**, `has_recipients=0`, `has_venue=1`; note `ticketing_type` is **not** set); 3 "Plusieurs dates avec liste" (`has_recipients=1`, `multiple_dates=1`) | `OuiNonAddDonationStep` |
| `CampaignProductsOriginStep` | none | "Fournisseur Coolecto" -> pick supplier; "Mon fournisseur" (own products) | `CampaignPickSupplierStep` / `OuiNonRecipientsStep` |
| `CampaignPickSupplierStep` -> `SuppliersSelectionQuery` -> `SupplierCampaignsModal` | | pick a supplier then a turnkey campaign "Utiliser cette campagne" -> redirect `supplier.register-from-supplier` -> copy flow (2.5) | |
| `TicketOnboardingStep` | `ticketing_type` | 1 Tirage (only `isImpersonated()||isSuperAdmin()`, `:15-18`): `has_recipients=1` -> `DrawOnboardingStep`; 2 Admission générale: `has_recipients=0`, `has_venue=1`; 3 Places assignées: `has_recipients=1`, `has_venue=1`, `has_seats=1`, `seat_selection=1` | `OuiNonRecipientsStep` |
| `DrawOnboardingStep` | `drawing_type_id` | 1 Lot de prix; 2 50/50 | `OuiNonRecipientsStep` |
| `OuiNonResultsTypeStep` | `results_type` | "résultats par participant ou par groupe" | `OuiNonMultipleDatesStep` |
| `OuiNonMultipleDatesStep` | `multiple_dates` | "plusieurs dates importantes?" | `OuiNonShowGoalStep` |
| `OuiNonClosedCampaignStep` | `closed-campaign` | "groupe fermé de participants?" (answer **never read** by `createCampaignFromResults`) | `OuiNonAddDonationStep` |
| `OuiNonAddDonationStep` | `add_donation` | "demander un don supplémentaire?" | `OuiNonShowGoalStep` |
| `OuiNonRecipientsStep` | `has_recipients` | 2 "lien d'inscription pour que mes participants s'inscrivent" / 1 "j'ajoute ou importe ma liste" / 3 "pas de liste, ouverte à tous" (3 -> stored NULL). In a main campaign only "participants are in the group" (1) | `OuiNonProfitShareStep` (or ShowGoal if in a main campaign) |
| `OuiNonProfitShareStep` | `profit_share` | "partager les profits avec les participants?" (yes -> 100, no -> NULL) | `OuiNonShowGoalStep` |
| `OuiNonShowGoalStep` | `show_goal` | "afficher l'objectif financier?" | `StepFinalOnboardingForm` (or `FeesCalculatedInBalanceStep` if from a main campaign) |
| `FeesCalculatedInBalanceStep` | `fees_paid_by_recipients` | only for sub-campaigns of a main campaign | final |
| `MainCampaignOnboardingStep` | `main_type` | Event(1) / Travel(2) / "Année scolaire" syncable(4) -> `has_recipients=2` then `StepInputParticipantGoal` (`participant_goal`, $); Wrapper(3) -> final | |
| `InscriptionStartOnboardingForm` | `campaign_name`, `start_date`, `end_date`, `registrant_is_self`, `only_with_invitation_code` | name, opens/closes, who fills (parent vs person), public link vs invitation. **Creates the campaign** and redirects to `campaign.inscription-setup` | |
| `StepFinalOnboardingForm` | `campaign_name` | "Dernière étape : nommez votre campagne" -> `handle()` creates the campaign (logged in) or redirects to `register?onboarding_id=` (guest). Rule: `campaign_name` required, no max | |

Unused-answer steps (exist, not reachable from `Step2`, answers not mapped to columns): `OuiNonGradesStep`, `OuiNonGroupGoalStep`, `OuiNonInventoryStep`, `OuiNonMailingListStep`, `OuiNonManageDeliveryStep`, `OuiNonSocialShareStep`, `OuiNonStopOrdersStep`, `StepInputStopOrders`. (`social_share` and `has_grades` **are** mapped; the steps for them are just not wired in `Step2`.)

### 2.3 Answers -> columns (`Onboarding::createCampaignFromResults`, `Onboarding.php:241-333`)

Helper semantics: `ouiNonToCol(key, valueIfYes, valueIfAbsent)` -> answer `1` => `valueIfYes ?: 1`; answer `2` => `NULL`; absent => `valueIfAbsent` (`:385-400`). `manyOptionsAndNonToCol('has_recipients', 3)` -> answer 3 => NULL, else the value (`:402-415`).

| Column | Source | Notes |
|---|---|---|
| `user_id`, `team_id` | `User::newBaseCampaign()` (`app/Models/User.php:505-524`) from `current_team_id` | |
| `language` | team's language | |
| `rate` | `setCampaignRate` (`Campaign.php:2315`): `team->plan->campaign_fee` else `RATE_DEFAULT = 0.03` | not editable by organiser |
| `enabled` | 0 | `reinitEnabled()` |
| `profit_share` | 100 default in `newBaseCampaign`; then `ouiNonToCol('profit_share', 100)` overrides with 100 or NULL | |
| `supplier_id`, `owner_team_id`, `pay_fixed_fees` | team/user supplier; `currentTeamRole->from_team_id`; supplier's `pay_fixed_fees` | |
| `onboarding_id`, `name` | the Onboarding row, `answers.campaign_name` | |
| `campaign_type_id` | `campaignTypeFor(answers.campaign_type)` | |
| `has_recipients` | see above | 0 none, 1 list, 2 self-registration link (`HasRecipientTypeEnum`) |
| `multiple_dates`, `add_donation`, `show_goal`, `has_grades`, `has_seats`, `seat_selection`, `has_venue` | `ouiNonToCol` | |
| `donation_text` | `__('campaign.ex-donation-message')` if `add_donation` | |
| `donation_options` | `$campaign->donation_options ? [['key'=>5]] : null` evaluated on a brand-new model, so **always NULL** (`:260`). Probable latent bug, **OPEN** (the later settings card defaults the list to 5) | |
| `max_days_before` | `ouiNonToCol('max_days_before', getMaxDaysBefore())` => 1 for product sales | |
| `results_type`, `ticketing_type`, `drawing_type_id`, `only_with_invitation_code`, `main_type`, `participant_goal`, `fees_paid_by_recipients` | straight from answers | |
| `has_qr_code`, `has_qr_code_campaign`, `has_qr_code_recipients` | hard-coded 1 ("TODO delete these columns") | |
| `referal_email`, `referal_fb` | `ouiNonToCol('social_share', 1, 1)` => 1 unless explicitly "no" | |
| `qr_code` | random 16 chars in `Campaign::save()` override (`Campaign.php:236`, `HasQrCodeTrait`) | this is the public identifier (no slug column found) |
| `supplier_id` | `copy_product_from_supplier_id` if set | |

### 2.4 The guest wizard (`/create-campaign`, `OnboardingWizard`)

- Creates/resumes an `Onboarding` kept in session `wizard_onboarding_id` (`OnboardingWizard.php:31-84`); `request('onboarding_type')` selects a profile in `config/onboarding-profiles.php`; `mode=express|guided`; `copy_campaign_id`, `refc`, `invite`.
- Steps are config-driven (`stepsFor`, `OnboardingWizard.php:242-297`): optional `welcome` (only if supplier has video and not embedded) -> `type` (scenario picker, or organisation picker "Autre") -> `account` (individual vs organisation, team name, account questions) -> `supplier` (only supplier copies) -> profile `wizard_steps` (`campaign_name`, `registration_audience`, `participants`, `bank`, `dates` are registered but profiles today use `['campaign_name']`, registration adds `registration_audience`; sport adds `welcome`) -> `auth` for guests (name, email, password, terms).
- Account questions (`config/onboarding-profiles.php:322-414`): `org_type_id` (column `teams.org_type_id`), `role_in_org`, `sport`, `school_service_center` (`teams.school_board`), `school_establishment` (`teams.establishment_id`), saved to `teams.onboarding_data` JSON or columns (`BaseWizardStep` / `WizardStepAuth::saveAccountQuestionsToTeam`).
- Creation: `WizardStepAuth::handle()` creates the `User`, `User::registerBaseHandle` (logs in, creates personal team), `Supplier::fromSignup(...)->reserveNewTeam($team)`, `handleReferral`, saves account questions, then `BaseWizardStep::createCampaignSetupFromWizard` (`BaseWizardStep.php`): supplier copy (`copyCampaignFromSupplier`: reinit enabled, `createCampaignCopyWithRelations(includeAiRewriting: true)`, dates, participants, sub-campaigns copied) **or** `createCampaignFromResults($user)` + `applyDatesToCampaign` + `createBankAccount` (only if bank answers present; `Bank` status MISSING, frequency 1) + `switchCampaign` + `User::dispatchAllReminders` (3 delayed onboarding emails: +2h, +3d, +14d, `User.php:784`).
- Profile defaults pre-fill answers (`applyProfileDefaults`): e.g. `school_product` = `add_donation=1, show_goal=1, has_recipients=1, max_days_before=1`; `sport_product` adds `profit_share=1`; `donation` = `show_goal=1, has_recipients=3`; `cadet_product` = `has_recipients=0` (no participants, CJCR rule noted in config); `school_venue` = `ticketing_type=QR, has_venue=1`; `school_meal` = `meal_type=3, multiple_dates=1, has_recipients=1`; `registration` = `has_recipients=2, multiple_dates=1`.
- Profile `checklist_exclude` (default: `approval`, `lock_dates`, `prizes`) removes setup cards (`CampaignStepsTrait::filterStepsByProfile`, `:240-259`).

### 2.5 Copy flows

1. **Copy my own campaign** ("copier une campagne existante"): `CopyCampaignForm` (`app/Kompo/Campaigns/CopyCampaignForm.php`): user selects any campaign from `getCopiableCampaigns()` (team + agent campaigns, main campaigns excluded when under a main/single-main-team; main campaigns go to `CopyMainCampaignModal`), then checkboxes `elements[]`: `polls`, `recipients` (hidden when participants come from a main campaign), `communications`, `products` (+ `venue` option for seat venues; "Copier les produits des campagnes du fournisseur" multiselect). `handle()`: `reinitEnabled()`, `setCopyName()` (" (copie)" suffix), `createCampaignCopy()`, `copySetup()` (settings in `config/campaign.php` `copied_settings`, P2P group types, levels), then chosen elements; `switchCampaign`; redirect dashboard. If the source campaign's supplier no longer sells (`Supplier::blocksNewCampaigns`, `Supplier.php:760`) a handoff block replaces products.
2. **Copy from a supplier turnkey template**: `supplier.copy-campaign/{id}` -> `CopySupplierCampaignPage` (`app/Kompo/Market/CopySupplierCampaignPage.php`): `Campaign::findSupplierTemplateOrFail` (404 unless `team_id == supplier's team`, `Campaign.php:1394-1407`); shows card; supplier "delivered_by_email" skips terms, else `AcceptCopySupplierCampaignModal` requires `terms_accepted` (rule `required|accepted`) with delivery conditions + availability dates; `handle()`: `reinitEnabled`, `createCampaignCopyWithRelations(includeAiRewriting: true)` (copy setup, polls, **communications (AI-reworked)**, products), attach to main campaign if any, optionally copy recipients from a handed-over campaign, `switchCampaign`; redirect `supplier.copied-extra-settings` (-> `CopiedSupplierCampaignSteps` asks `has_recipients` and then `dashboard`, aborts 403 if the campaign is not supplier-based or already enabled).
3. **Copy parent organisation templates** (`Step2OnboardingForm::copyParentTemplateCampaign`, `:231-249`): only campaigns of the main parent team with `is_template = 1`; copies campaign + all sub-campaigns with relations, AI rewriting on; redirect dashboard.
4. **Copy a team's own campaign by link** `campaign.copy/{id}` -> `CopyMyTeamCampaignPage` (403 unless `team_id == currentTeamId()`).
5. What a copy does (`CampaignCopyTrait::createCampaignCopy`, `:31-82`): `replicate()`, `from_campaign_id`, user/team/owner, **resets** `start_date`, `end_date`, registration/preregistration dates, `locked_dates_and_prices`, welcome/onboarding flags, `communications_reviewed_at`, AI-plan flags, `scanner_attach_token`, `reminder_end_job_id`; calls `TeamLockGuard::assertCanCreateCampaign`; `setCampaignRate` (new rate of current team); new `qr_code`; duplicates logo/photo files; `applySupplierDesignScheme()`. `copyProducts` skips hidden products and products of suppliers that left, calls `TeamLockGuard::assertCanSaveProduct` per product, copies product files and categories. `copyCommunications` rewrites text with `WriteCommunicationContentJob` (queue, `rework: true`) **only when the target team differs from the source team** (`:304-322`). Venue seat campaigns do **not** copy products (`:327-329`).
6. Copy gives `enabled=0`; `start_date`/`end_date` NULL, so `checkInfos()` is false and the "Compléter ma campagne" card is `critical` until dates are entered.

### 2.6 What is left for after creation: the setup band

Rendered by `app/Kompo/Dashboard/NewOnboardingCard.php` ("Phase 1 · Configuration", band at top of dashboard while the campaign is not live). Cards come from `config/onboarding-steps.php`, class `App\Models\Onboarding\Steps\*`, base `app/Models/Onboarding/CampaignOnboardingStep.php` (key, title, description, `priority()`, `check()`, `appliesTo()`, click action). Pill labels (`app/Kompo/Helpers/elements.php:157-167`): `critical`+`important` => **"Obligatoire"** (red), `pending` => "En attente", `before_end` => "Avant la fin", `suggested` => "Facultatif". **Only `critical` and `important` block going live** (`CampaignStepsTrait::missingRequiredSteps`, `:273-278`).

| Key | Shown when (`appliesTo`) | Priority | Done when (`check`) |
|---|---|---|---|
| `welcome` | not supplier mode; campaign needs reason & welcome asks it, or no reason needed | critical only if it asks the reason | `checkCampaignReason()` or `welcome_completed_at` |
| `delivery_choice` | not supplier; campaign from a supplier; (done or no orders) | critical when a choice exists | setting `delivery_choice_made` |
| `team_info` | not supplier | critical | `team->name` non-empty (`Team::teamInfoComplete`, `Team.php:735`) |
| `campaign_info` ("Compléter ma campagne"; for Inscriptions "dates" setup card) | always | critical | `start_date` set; Tirage needs `venue_address`+`minimum_age`+draw_time; P2P needs group type(s) and min/max team size (`CampaignChecksTrait::checkInfos`, `:101`) |
| `campaign_reason` | not supplier; Product or Donation; welcome not asking it | critical | reason text in any language |
| `campaign_communications` | not supplier and `needsCampaignReason()` (Product/Donation) = AI writes emails | critical | `no_communications` or `communications_reviewed_at` |
| `travel_agency` | main travel campaign | important | settings `travel_agency_name` and `travel_funds_go_to` |
| `products` | survey or `hasProducts()` (everything except Main) | critical | survey has polls; Inscriptions: activities+groups; others: at least one product with a non-null price |
| `registration_form` | Inscriptions | suggested | setup step FORM saved |
| `delivery_info` | not supplier; supplier-based; not delivered by email; delivers to organisation | before_end | campaign delivery address/contact |
| `participants` | not supplier, not Inscriptions, and `has_recipients` = 1 or 2 | critical | list: recipients and donors exist; without-list: groups exist or `onboarding_without_groups` |
| `registration_link` | `has_recipients = 2` and not supplier | critical (Inscriptions: suggested) | `recipient_registration_sent_at` |
| `dates` | multi-date campaign (not Inscriptions) | critical | at least one `CampaignDate` |
| `prizes` | raffle with prize pool | important | at least one prize |
| `communications` | communications not written by AI (all other types) | important (Inscriptions: suggested) | `no_communications` or at least one communication |
| `draw_licence` | raffle | critical | valid licence |
| `lock_dates` | raffle | critical | `locked_dates_and_prices` and dates and draw date |
| `budget_number` | parent forces budget number and not main campaign | suggested | `account_code` set |
| `approval` | parent forces approval | **critical** | an approved `ApprovalRequest` |
| `bank_info` | not super admin and no parent capturing transfers | `pending` if bank pending verification else suggested | `team->payoutInfoCompleted()` (Stripe account onboarded OR accepted bank) |
| `added_donation` | `add_donation` and not donation-type, not tips-only | suggested | donation text + more than 1 option, or donation-without-purchase configured |
| `show_goal` | `show_goal` or main wrapper | suggested | `goal` set when `show_goal` |

- Profile `checklist_exclude` hides some of the above for onboarding-profile campaigns (default `approval`, `lock_dates`, `prizes`; express mode also `bank_info`).
- **Go-live**: `DashboardView::created()` calls `CampaignGoLiveService::enableIfReady` (`app/Kompo/Dashboard/DashboardView.php:40`, `app/Services/Campaigns/CampaignGoLiveService.php:16-36`): not Inscriptions, not already enabled, `missingRequiredSteps()` empty => `setEnabled()` + `save()`, then (campaigns created after 2026-04-30) opens `CampaignCreatedCongratsModal`. Survey pages (`SurveryFormPage`, `SurveryP2pFormPage`) also call it. Inscriptions: `NewOnboardingCard::goLive` / `SetupShareStep::goLive` call `goLive()` (editor+, `abort 422` otherwise).
- "Live" is not "open": transactions are accepted only between `start_date` and `end_date`, not paused (`getCurrentPhase`, `CampaignChecksTrait.php:37-69`; phases `setup, paused, scheduled, active, cutoff, fulfillment, archived`). `Campaign::scopeActive` = enabled and within dates (`Campaign.php:2265`).
- **Communications are not created at campaign creation** (not in `createCampaignFromResults`). They appear when the organiser opens the emails card: `CommunicationOnboardingModal::generateCommunications` -> `Campaign::generateDefaultCommunications(includeAiRewriting: true)` (`Campaign.php:2574-2587`) creating one communication per `Template` matching org type x campaign type (`defaultCommunicationTemplates`, `:2431-2456`), AI wording by `WriteCommunicationContentJob`; or the AI plan generator (`CommunicationGenerationPage`, `ExecuteCommunicationPlanJob`, claim lock `claimCommunicationGeneration`). Copies carry communications with them.

---

## 3. The code that actually creates the campaign

### 3.1 New campaign (`Onboarding::createCampaignFromResults`, `Onboarding.php:241-333`)

Order of operations (no transaction):
1. `$user->newBaseCampaign()` (`User.php:505`): unsaved model with team, language, rate, `enabled=0`, `profit_share=100`, supplier, owner team, `pay_fixed_fees`.
2. Map answers to columns (2.3).
3. `TeamLockGuard::assertCanCreateCampaign($campaign)` (`:294`): (a) 403 if TYPE_MAIN and the team `hasSingleMainCampaign()` (created by API under a travel agency) and already has one; (b) if the team is "reserved" to a supplier and not staff: 403 unless type is PRODUCT or SURVEY, or CAMPAIGN_TYPES unlocked; 403 if `supplier_id` differs from reserved supplier and OTHER_SUPPLIERS locked.
4. `enforcedMainCampaign()` (`Campaign.php:2654`): for a single-main-campaign team, any non-main non-survey campaign is forced under that main campaign. Else `answers.from_main_campaign_id`. Then `setMainCampaignAndSave($main)` (`:2698`) or plain `$campaign->save()`. `save()` override (`:236`) assigns `qr_code`. `MainCampaign::createLinkBetween`; if the main has own participants (`isMainCampaignWithOwnParticipants`), groups/leaders/recipients of the main are replicated into the sub-campaign.
5. `applySupplierDesignScheme()` (`:2679`): if the supplier "picks the design scheme", **deletes the team's design system and replaces it with the supplier team's palette**.
6. Tickets main (`MAIN_IS_EVENT`): creates two sub-ticket campaigns ("Premier paiement - X", "Paiement final - X" with `sub_pays_balance=1`, `only_with_invitation_code=1`, `has_venue=1`, `send_qr_automatically` 1 on the second) through `bootNewTicketCampaign` (also guarded).
7. `generateDefaultProducts($copy_product_from_supplier_id)` (`Campaign.php:2589-2610`): supplier => `Deliverable::addAllProductsOfSupplier`; Donation or P2P => donation products 5/10/20 $; Cheerup => 1/2/5 $; raffle => (5$,1 ticket), (15$,5), (40$,10); Membership => one membership product with null price. Product sales with no supplier: no products.
8. `LandingInvite::attributeTo($campaign)` (cookie-based attribution, optional Odoo job).
9. Membership: start now, end +10 years. Inscriptions: dates from answers, `handleAfterStartEndDatesAreSet()`, settings `registrant_is_self`, `ask_level`, `ask_payer_phone`, `InscriptionSetup::markSaved(AUDIENCE)`.
10. Caller: `auth()->user()->switchCampaign($campaign)` (returns false silently if the user's campaign restrictions exclude the new id, `User.php:419`, `currentTeamCampaigns` `utilities.php:673`; **OPEN**) and redirects to `dashboard`.

Events/observers on `Campaign` (`app/Observers/CampaignObserver.php`, registered `AppServiceProvider.php:196`):
- `created`: `triggerWebhook(WebhookType::CAMPAIGN_CREATED, ...)` and `EventRecorder::record('campaign_created')` (table `tracked_events`; skipped when no actor, e.g. queue).
- `updated`: when `enabled` flips 0->1: `EventRecorder::record('campaign_enabled')`; for raffles, `DrawLog::createLog(EVENT_CREATING_DRAW)`. The "new campaign" mail to listeners is commented out.
- No model events set `enabled`, create communications, forms, tickets or Stripe objects.

Not done at creation: no Stripe call, no bank requirement, no slug (the public key is `qr_code`), no tax-receipt config, no emails to the organiser about the campaign (only the account-level reminders in the wizard path), no queue job (except `WriteCommunicationContentJob` in copies).

### 3.2 Date side effects (`CampaignSetAndHandleDatesTrait`, `handleAfterStartEndDatesAreSet`, `:90`)
Called after dates are saved (`StepCampaignDates::afterSave`): creates/updates the single `CampaignDate` for types that need one (`shouldCreateInitialCampaignDate`: Product, raffle, P2P, or single-date Deliveries/Cheerup) at `end_date` (or event/draw datetime); re-syncs `Envoi` schedules of start/end/dates communications; extends the single date of a product campaign when `end_date` changes; `setEndReminderJobIfNeeded` (reminder job id in `reminder_end_job_id`); `deleteHandleEndCampaignNotifications`.

### 3.3 Settings write path
Every settings card is a Kompo form extending `app/Kompo/Dashboard/BaseCampaignForm.php`; the framework saves the posted `name()`d fields to the model (columns) and the form's `beforeSave/afterSave/handle` do the extras. `CampaignSetting` rows (key/value, strings) via `setSetting/getSetting` (`app/Models/Traits/CampaignSettingsTrait.php`).

---

## 4. Settings after creation ("Modifier la campagne")

Page: `route('campaign.page.settings')` = `/my-campaign/settings`, `app/Kompo/Settings/CampaignPageSettings.php`. Tabs are `Campaign::getTabs()` (`CampaignStepsTrait.php:11-22`, labels FR `campaign.php`): **Bases** (`campaign.basics`), **Image et description**, **Paramètres paiement** (`campaign.payment-behavior`), **Partage**, **Paramètres de l'événement** (`campaign.event-settings`, P2P), **Livraison** (`campaign.delivery-settings`), **Informations additionnelles** (Inscriptions), **Formulaire** (`campaign.campaign-form`), **Formulaire participant** (`campaign.p2p-survey`), **Autres paramètres**; plus **Créer une expérience** only when `isAdvanced()` (team flag `teams.is_advanced`, `AdvancedSettingsForm`). A tab is hidden when it has no card. The cards per tab come from `Campaign::getSettingsSteps()` (`CampaignStepsTrait.php:83-229`), each class declares `public static $tabGroup` (default `TAB_BASICS` from `BaseCampaignForm`). Inscriptions replace name/dates/invitation-code cards by `SetupAudienceStep` (+ `SetupFormStep`) (`:233-243`).

Storage legend: **C** = column on `campaigns`; **S** = `campaign_settings` key; **T** = team/product table. Money/risk marks: **$** money, **F** fees, **R** receipts/tax, **D** dates, **V** public visibility/behaviour, **P** payment flow.

### Bases (`TAB_BASICS`)
| Card class | Fields (storage) | Validation | Risk |
|---|---|---|---|
| `StepCampaignName` | `name` (C, translatable) | `required` | V |
| `StepCampaignReason` | `reason_description` (C, translatable) | `nullable`, `MaxTranslatable(2000)` | V (shown to buyers; also feeds AI emails) |
| `StepCampaignContacts` | `contact_email`, `contact_info` (C) | `contact_email: required|email` | |
| `StepCampaignDates` (supplier mode: `StepCampaignDatesSupplier`) | `start_date`, `end_date` (C datetime); `preferred_date_of_delivery`; raffle: `drawing_datetime` (stored on the CampaignDate), `venue_address`, `minimum_age`, `max_prize_amount`, `allow_draw_date_to_be_manually`, `rules_text`; P2P: `preregistration_start/end_date`, `registration_start/end_date`, `event_datetime` | `start_date: required|date`; `end_date: required|date|after_or_equal:start_date`; raffle `drawing_datetime/venue_address/minimum_age: required`, draw date must be >= end date; P2P `registration_*: required` with `after/before_or_equal` chain; `preferred_date_of_delivery` rule from `getPreferredDeliveryDateRule()`; supplier seasons `min/max_start_date`, `max_end_date` as `dd-mm` regex (stored on the template, used by `validateSeasonalCampaign`) | **D, P**. Locked (`locked_dates_and_prices`) disables the inputs; 403 if a delivery already sent to the supplier (`beforeSave`); end date cannot exceed supplier delivery buffer (`validatePreferredDeliveryDate`, bypassed when impersonating) |
| `StepCampaignVenue` (tickets, not raffle) | `venue_id`, `venue_address`, `max_total`, `max_per_recipient`, `reserve_many_dates`, `send_qr_automatically`, `instructions_text` (C); hide sections and split ticket types (S) | custom: not every section hidden | P |
| `StepCampaignVenueDates` / `StepCampaignICS` (advanced) | event dates (CampaignDate), `send_ics` | | D |
| `StepCampaignResultsRegistrationType` (if `has_grades`) | `results_type` (C) | | |
| `StepCampaignEncanSettings` (Encan) | `allow_bids_automation`, `ask_credit_card`, `display_name_of_highest_bidder`, `instructions_text`, `phone_needed`, `rules_text`, `show_product_cost` (C) | | P |
| `StepCampaignMembershipInfo` (Membership) | `membership_ask_info`, `membership_ask_renewal`, `membership_text` | | |
| `StepCampaignLeaderboard` (when `has_recipients != 0`) | `leaderboard_display` (S: `named|anonymous|off`) | `required|in:named,anonymous,off` | V (names shown publicly) |
| `StepCampaignShowGoal` | `goal` (C decimal 15,2), `goal_type` (`GOAL_TYPE_TOTAL=1`/`PROFIT=2`), `show_goal`, `show_total_donated_only`; advanced: `add_participant_and_group_goals`, `group_goal`, `participant_goal`, `allow_personal_goal` | `goal: required_if:show_goal,1` | V |
| `DeleteCampaignCard` | delete | `CampaignPolicy::delete` same team; `Campaign::delete()` aborts 403 with paid orders (`abortIfPaidOrders`) and for the last admin-team campaign of a type | irreversible |

### Image et description
`StepCampaignDescription`: `description` (translatable, each `min:50` chars), `main_color`/`secondary_color`/`tertiary_color` (`nullable|size:7`), `custom_logo`, `custom_photo` (JSON), `language`, `tags`. `StepCampaignButtonsLabel`: `button_label_page`, `button_label_order` (translatable). Risk V.

### Paramètres paiement (`TAB_PAYMENT_BEHAVIOR`)
| Card | Fields | Validation | Risk |
|---|---|---|---|
| `StepCampaignDonation` (non-donation types, not tips-only, not Inscriptions) | `add_donation`, `donation_text` (translatable), `donation_options` (JSON list of `{key: amount}`), `round_up_ad`, `round_up_value_ad` (1/5/10), `donation_without_purchase` (+ `_btn_text`, `_modal_text`) | `donation_text: required_if:add_donation,1`, `MaxTranslatable(500)`; `donation_options.*.key: required|numeric`; `round_up_value_ad: required_if:round_up_ad,1` | **$, P**. `hasDonationStepConfigured` (more than one option) is what completes the optional card |
| `StepCampaignDonationSettings` (Donation/Cheerup/P2P) | `display_donors` | | V |
| `StepCampaignCoverFees` | `cover_fees` (const `COVER_FEES=1`, `COVER_TIPS=2` = tips only, no platform fee), `cover_fees_default_toggle_on`, advanced `forced_cover_fees` | none; **disabled card + forced 0** if parent has `disable_cover_fees_for_child_teams` (accessors `Campaign.php:528-544`) | **F, $** (changes what the buyer pays; `getEffectiveRate` = 0 when tips only, `Campaign.php:1321`) |
| `StepReceiptsAndTaxReceipts` | `receipt_copy_email`, `send_receipt_automatically` (default 1), `send_tax_receipt_automatically` (default 1; only if team `hasTaxReceiptInfo`), `show_message_tax_sending_later`, `emit_sport_receipts` (only if `isSportReceiptEligibleType`) | `receipt_copy_email: nullable|email` | **R** (legal documents). Team-level prerequisites: `has_tax_receipt`, `tax_number`, `address` (`Team::hasTaxReceiptInfo`, `Team.php:893`); product-level `tax_receipt_amount`, `taxable_amount` (`lte:price`), `do_not_emit_tax_receipt` |
| `StepCampaignOrderBump` (Product) | `enable_order_bump`, `upsell_minimum_cart_amount` (decimal 8,2) | | $ |
| `StepCampaignTerminalTP` (types with `hasTerminalTtp`) | `enable_terminal`, `accelerate_ttp_transaction`, `dont_open_keyboard_in_select` | | P |
| `StepCampaignShowOrderModalDirectly` (advanced) | `open_ordering`, `accept_zero_qty` | | |

### Partage
`StepCampaignRecipients`: `has_recipients` (C: 1 list, 2 link) through `SaveRecipientSetting` (`app/Kompo/Market/Actions/SaveRecipientSetting.php`): if existing communications are invalid for the new mode a confirm modal appears; confirming **deletes prepared envois of start-communications** and rewires `sending` (start <-> after-register). Cannot set "no participants" here (option rejected). `StepCampaignSocialShare`: `referal_email`, `referal_fb`.

### Livraison (`TAB_PRODUCT_SETTINGS`, supplier-based / product sales)
`StepDeliveryPreferences` (`delivery_address`, `delivery_contact_name`, `delivery_contact_phone`, `delivery_more_info`, `preferred_date_of_delivery`; date rule `getPreferredDeliveryDateValidations`), `StepCampaignProductDeliveryInfo` (`delivery_type`: `DELIVER_ONLY_TO_ORGANIZATION=1`, `..._AND_DONOR=2`, `ONLY_TO_DONOR=3`), `StepCampaignMaxDaysBefore` (`max_days_before`, Deliveries extras `multi_products`, `one_choice_all_dates`, `mandatory_all_dates`, `max_quantity_per_date`; hidden for supplier-based campaigns). Risk P, D (orders blocked N days before date).

### Paramètres de l'événement (`TAB_EVENT_SETTINGS`, P2P only)
`StepP2PPricing` (group types via `P2PGroupType`: names and registration/pre-registration fees per participant and per team), `StepGroupsSettings` (`max_groups_allowed nullable|integer|min:0`, `min_recipients_allowed_per_group required|integer|min:0`, `max_recipients_allowed_per_group required|integer|gte:min`, `do_not_allow_closed_groups`), `StepP2PGoalsSettings` (`force_group_goal`, `force_recipient_goal` required boolean; `default_*_goal required_if ... numeric|min:0`; `allow_personal_goal`), `StepCampaignP2PSettings` (`min_age_to_p2p_inscription required|numeric|min:0`, `count_inscriptions_amount_in_totals`), `StepPaymentBehaviour` (`force_donation_to_groups_or_recipients`), `StepCampaignP2PDirectionDetails` / `CalendarDetails` / `DescriptionDetails` (`event_address`, `direction_details`, `calendar_details`, `description_details`), `StepCampaignP2pSurveyForm` (participant form, settings `p2p_ask_birth_date|gender|phone|address`, `participants_collect_in_person`), `StepCampaignLevels`, `StepCampaignChallenges`. Risk: fees ($), pre-registration/registration dates (D).

### Informations additionnelles / Formulaire
`StepCampaignCampaignSurveyForm` (questions added to a campaign, `Survey`), Inscriptions `SetupFormStep` (settings `ask_birth_date|level|school|file_number|ask_payer_phone|ask_payer_address`, saved with `setSetting(key, (int))`, marks `inscription_setup_saved_step`).

### Autres paramètres (`TAB_OTHER_SETTINGS`)
`StepCampaignNeedsAddress` (`address_needed`, `accept_donations_from_someone_else`, `ask_donation_is_in_the_name_of_company`, `ask_donor_amount_anonymous`, `ask_donor_be_anonymous`, `ask_donor_phone`, `ask_donor_want_to_subscribe_to_newsletter`, `ask_for_additional_message`, `ask_in_honors_of`), `StepCampaignOnlyWithInvitationCode` (`only_with_invitation_code`, `ask_link_to_recipient`; hidden for Cheerup; visibility V: restricts the public page to personal links), `StepCampaignProfitShare` (`profit_share` string/decimal; `required_if:profit_share,1|numeric|min:0|max:100`; `single_outcome_per_recipient` for Cheerup) **$**, `StepCampaignParticipantsMessage` (`participants_message`, `participants_metric` = `PARTICIPANTS_SEE_PROFITS=1`/`SEE_BALANCE=2`), `StepCampaignDrawLicence` (`draw_licence_number` nullable + `Campaign::drawLicenceRule()` regex from `config('draws.licence_pattern')`, refuses class "LA-"; `draw_licence_expires_at required_with:number|date`), `CampaignEndSmsReminderCard` (Encan), `StepCampaignLinks` (advanced, links between campaigns), advanced-only: `StepCampaignInvoiceComment` (`invoice_comment`), `StepOpeningBalance` (`opening_balance required|numeric|min:0`, **$ accounting**), `StepCampaignAddTagsToCustomers` (`customerTags`).

### Fields set by other pages (not a card)
`paused` / `paused_message` (pause), `is_visible`, `locked_dates_and_prices` (raffle lock), `is_template` (make template), `account_code` (budget number modal), `approval_requests`, `no_communications`, products (`CampaignProductForm`: `name required` (translatable, max 255), `price required|numeric|min:0`, `taxable_amount nullable|numeric|lte:price`, `fee_type` + `fee_amount`, `equivalent_units required|integer` for raffles, `introduction.*` max 1000, files max 3000 KB, stock fields `initial_stock`, `can_be_out_of_stock`, `max_product_per_recipient`, `unit_cost`, `tax_receipt_amount`, `do_not_generate_qr`, etc.), banks, team info.

### Defaults worth knowing
`cover_fees` NULL (donors do not cover fees by default), `send_receipt_automatically` 1, `send_tax_receipt_automatically` 1, `is_visible` 1, `paused` NULL, `rate` plan fee or 0.03, `delivery_type` 1 (organisation only), `goal_type` 1 (total), `show_latest_donations` 1 (migration defaults, **OPEN** whether later migrations changed them).

### Not organiser-editable
`rate` (`AdminCampaignRateForm`, admin only), `pay_fixed_fees` (from supplier), Stripe/bank verification status (admin/bank flows).

---

## 5. Authorization, limits, blockers

### 5.1 Roles
`app/Models/Teams/TeamRole.php:20-26`: `owner`, `manager`, `editor`, `basic` (UI "Membre"), `scanner`, `admin` (staff), `agent`. Groups: `managerRoles()` = owner+manager; `editorRoles()` = owner+manager+editor (`TeamRole.php:76-91`). Helpers `isManager/isEditor/isMember/isScanner/isAgent` in `app/Models/Teams/HasTeamsTrait.php:61-90`.
- Create campaign button: hidden for `isMember()` (`DashboardSidebar.php:314-315`); scanners see a reduced sidebar (`:137`). AI gate `NavigationGates::campaignCreator` = `!isMember() && !isScanner()` (`app/Ai/NavigationGates.php:40-43`), i.e. editor, manager, owner, agent, admin may create.
- **The route does not enforce it**: `new-campaign` is under `['auth', 'profile:team|supplier']` only (`routes/web.php:275`); `Step1InternalForm`/`BaseOnboardingForm`/`StepFinalOnboardingForm` have no `authorize()`; `CampaignPolicy::create/update` are empty stubs, only `delete` checks `current_team_id == team_id` (`app/Policies/CampaignPolicy.php`). **OPEN/RISK**: a Member (or Scanner) who knows the URL appears able to create a campaign. Not tested.
- Bank account management: `User::canManageBankAccount` = team owner or role owner, or manager when `let_managers_manage_bank_account` (`User.php:344`).
- Go-live of Inscriptions: `isEditor()`.
- Settings pages are under the `my-campaign/*` routes with `role:` middleware in places (`RoleMiddleware`; `TeamRole::mwManager()`); per-route role map not exhaustively verified. **OPEN**.
- Multi-tenancy: campaign gets `team_id = user.current_team_id`; `currentTeamCampaigns()` scopes lists; `switchCampaign` refuses campaigns outside the list and honors per-user campaign restrictions (`getRestrictedCampaignIdsForTeam`). `currentTeam()` helpers abort (419) for guests.

### 5.2 Middleware/profile
`ProfileMiddleware` (`app/Http/Middleware/ProfileMiddleware.php`): `profile:team|supplier` requires `activated_mode_id` in (TEAM, SUPPLIER) (users without a mode are treated as team). Suspended users blocked by `EnsureUserIsNotSuspended` (web group, `Kernel.php:52`). No `throttle` on `new-campaign` (throttle only on `api` and a couple of routes).

### 5.3 Parent / network policies (flags on the parent `Team`; UI `app/Kompo/Teams/TeamChildSettingsPage.php`)
| Flag | Effect on a child's campaign |
|---|---|
| `force_approval_for_child_campaigns` | `Campaign::requiresApproval()` (`Campaign.php:1360`) => `approval` card, **critical** (blocks go-live). Organiser sends `CampaignApprovalRequestModal` (creates `ApprovalRequest` status pending=1/approved=2/rejected=3, notifies parent's `approver_email`); parent approves from `approval-request/{id}` / pending list (`Team::pendingChildCampaigns`). Done only when an **approved** request exists (`checkApproval`) |
| `force_budget_number_for_child_campaigns` | `requiresBudgetNumber()` (not for main campaigns) => card `budget_number`, `suggested` (does **not** block go-live), done when `account_code` set |
| `disable_cover_fees_for_child_teams` | `cover_fees` and `forced_cover_fees` read as 0 and the card is disabled with an explanation |
| `capture_child_transfers` | `Team::parentWhoCapturesTransfers()` (parent or grand-parent): `bank_info` card hidden for children; payout info judged on the capturing parent |
| `force_children_centralized_list` | appears once in code; **OPEN** meaning |
| `hasSingleMainCampaign()` (child created by API under a travel agency) | one main campaign only; every other new campaign is forced under it (`enforcedMainCampaign`), copy of main campaigns blocked (`CopyCampaignForm`) |
| Parent templates | children can copy parent's `is_template` campaigns (`Step2OnboardingForm::parentTemplateCampaigns`) |

### 5.4 Supplier reservation ("unlocks")
`app/Services/Unlocks/TeamAccess.php`, `TeamLockGuard.php`, `TeamUnlockService.php`, `config/team-unlocks.php`, enum `app/Models/Teams/TeamFeature.php` (`campaign_types`, `other_suppliers`, `own_products`). A team that signed up through a supplier link is "reserved": the picker shows only the supplier's catalogue (or padlocks); `TeamLockGuard::assertCanCreateCampaign` (campaign) and `assertCanSaveProduct` (product of a product-sale campaign) abort 403 server side; staff (`isSuperAdmin`) bypass. Plan `two-campaigns` unlocks all three after 2 completed supplier campaigns (`CompletedSupplierCampaigns`).

### 5.5 Plan/billing
- `Plan` (`app/Models/Finance/Plan.php`; `plans.campaign_fee` decimal(5,2)) only sets the per-campaign `rate` at creation/copy. **No cap on number of campaigns, no subscription gate** was found (greps for limits returned nothing). Public pricing in help: 5.9 % + 0.30 $ per online transaction; supplier-based campaigns can have the supplier absorb fees (`pay_fixed_fees`, `supplierPaysSomeFees`). **OPEN**: how `rate` 0.03 relates to the 5.9 % displayed (processor fee + commission, per `frais-frais-de-plateforme`).

### 5.6 What blocks going live / taking money
- Blocks go-live (automatic): any `critical`/`important` card undone (2.6). Approval for networks. Raffle: licence + locked dates. Product campaigns: at least one **priced** product. Participants cards when `has_recipients` 1 or 2.
- Does **not** block: bank account / Stripe onboarding (`bank_info` is `suggested`/`pending`), tax-receipt setup, goal, donation card, delivery info (`before_end`).
- Bank: team `payoutInfoCompleted()` = `stripe_account && onboarded` OR an accepted bank (`Team.php:859-866`). Missing payout info does not stop sales; it holds transfers (`Transfer.php:415`) and triggers admin/team reminders when paid orders exist (`CheckBankStatusAdminCommand`, `CheckBankStatusTeamCommand`). Bank verification states `BankStatusEnum` (ACCEPTED=1, PENDING=2, MISSING=3, REFUSED=4). Wizard-collected bank numbers become a MISSING `Bank`.
- Dates: transactions only between `start_date`/`end_date`, not paused; multi-date campaigns need at least one remaining date.
- Suspended team (`is_suspended`) removes registrations from catalog listing.

### 5.7 Existing audit/observability hooks relevant to an AI creator
`CampaignObserver` webhook + `tracked_events` (`EventRecorder`, actor from `Actors::present()`), `DrawLog` (raffle), `TracePlugin` (component tracing), `Communication`/`Envoi` logs, `CampaignNoticeLog`. There is **no generic "who created/changed what" audit table for campaign settings** found (**OPEN**: `vendor/condoedge/ai` and `ai-eval` (installed in `vendor/condoedge/`) may add conversation logs; not inspected). `campaigns.user_id` and `owner_team_id` record the creator.

---

## 6. Help articles (`resources/ai-help/aide/organisateur/`, 320 articles, frontmatter `titre/public/pages/chemin/mots_cles/voir_aussi`)

Counts by prefix: demarrage 19, campagne 25, billets 19, inscriptions 31, participants 26, produits 26, rapports 25, communications 13, formulaires 12, tirage 11, encan 9, catalogue 8, livraison 8, compte 16, commandes 15, recus 10, dons 6, paiements 6, banque 4, frais 4, groupes 4, partage 4, salle 5, transferts 5, adhesions 3, contacts 5, factures 1.

### Directly about creating a campaign (`demarrage-*`)
| Slug | Covers |
|---|---|
| `demarrage-assistant-de-creation` | The guest wizard (first campaign): steps, supplier-branded variant, FR/EN toggle |
| `demarrage-creer-une-nouvelle-campagne` | "+ Campagne": copy vs new, step by step, Member role hidden, "not live right away", locked types |
| `demarrage-types-de-campagnes` | Types offered and the starting questions per type; FAQ (raffle under tickets, single type = locked account) |
| `demarrage-copier-une-campagne` | Copy an existing campaign (what can be copied) |
| `demarrage-copier-un-groupe-de-campagnes` | Copy a main campaign with sub-campaigns |
| `demarrage-groupe-de-campagnes` | Main campaign and sub-campaigns |
| `demarrage-bandeau-de-mise-en-place` | The setup band, priority pills (Obligatoire, Facultatif, Avant la fin) |
| `demarrage-cartes-de-mise-en-place` | List of setup cards |
| `demarrage-carte-completer-ma-campagne` | Card "Compléter ma campagne" (dates) |
| `demarrage-carte-infos-de-mon-organisme` | Card team info |
| `demarrage-carte-vos-courriels` | Cards "Vos courriels" / "Créer les communications" |
| `demarrage-carte-informations-bancaires` | Bank info card |
| `demarrage-carte-approbation-et-poste-budgetaire` | Approval and budget-number cards (network) |
| `demarrage-cartes-de-livraison` | Delivery mode and delivery info cards |
| `demarrage-cartes-du-tirage` | Raffle cards: licence, prizes, date lock |
| `demarrage-cartes-facultatives` | Optional cards: extra donation, goal, travel agency, important dates |
| `demarrage-fenetre-de-bienvenue` | Welcome modal of a new campaign |
| `demarrage-mise-en-ligne` | Automatic go-live, Inscriptions button, "live is not started", what visitors see |
| `demarrage-selecteur-de-campagne` | Switching campaigns / finding old ones |

### Settings and per-type articles (non-exhaustive, most relevant)
`campagne-modifier-la-campagne` (tabs and cards index), `campagne-nom`, `campagne-raison`, `campagne-contact`, `campagne-dates`, `campagne-objectif`, `campagne-image-et-description`, `campagne-libelles-des-boutons`, `campagne-liste-de-participants`, `campagne-resultats-par-participant-ou-groupe`, `campagne-options-avancees-de-la-campagne`, `campagne-creer-une-experience`, `campagne-mettre-en-pause`, `campagne-fin-de-campagne`, `campagne-gestion-supprimer-ou-modele`, `campagne-questions-additionnelles-au-donateur`, `campagne-livraison-et-produits`, `campagne-voyagiste`, `campagne-p2p-tarifs-equipes-et-objectifs`, `campagne-p2p-lieu-calendrier-et-details`, dashboard articles (`campagne-tableau-de-bord*`, `campagne-prochaines-etapes`), `billets-types-de-campagnes-de-billets` (QR vs seats vs raffle, type not changeable), `billets-parametres-de-l-evenement`, `billets-dates-de-l-evenement`, `billets-prix-gratuit-et-frais-par-billet`, `tirage-fonctionnement-et-mise-en-place` and 10 other `tirage-*`, `encan-parametres-de-l-encan` and 8 other `encan-*`, `dons-montants-suggeres`, `dons-don-supplementaire-et-don-sans-achat`, `dons-montant-personnalise`, `dons-dons-mensuels-recurrents`, `dons-afficher-la-liste-des-donateurs`, `dons-en-honneur-anonyme-et-questions-au-donateur`, `frais-frais-de-plateforme`, `frais-couverture-des-frais-par-les-acheteurs`, `frais-calcul-du-profit`, `frais-frais-payes-par-le-fournisseur`, `recus-*` (10, tax receipts), `adhesions-types-d-adhesion`, `adhesions-parametres-lors-de-l-adhesion`, `inscriptions-creer-une-inscription`, `inscriptions-configuration-guidee`, `inscriptions-pour-qui-parent-ou-personne`, `inscriptions-sur-invitation`, `inscriptions-mettre-en-ligne-et-partager`, `inscriptions-periode-d-inscription` (+ 24 other `inscriptions-*`), `groupes-equipes-et-capitaines-en-pair-a-pair`, `groupes-parametres-des-equipes-en-pair-a-pair`, `participants-parametres-pair-a-pair`, `participants-frais-d-inscription-en-pair-a-pair`, `participants-paliers-et-defis-en-pair-a-pair`, `participants-statuts-en-pair-a-pair`, `formulaires-creer-un-formulaire` (+ 11 `formulaires-*`), `catalogue-choisir-un-fournisseur-a-la-creation`, `catalogue-copier-une-campagne-de-fournisseur`, `catalogue-conditions-du-fournisseur`, `produits-*` (26: create product, price/cost/profit, variants, stock, taxes TPS/TVQ, tax-receipt amount, vouchers, gift certificates), `livraison-*` (8), `banque-*` (4), `paiements-terminal-tap-to-pay`.

### Gaps relevant to an assistant that must choose a type and settings
1. **No "which type fits my situation" guide.** `demarrage-types-de-campagnes` lists the types and the questions, but gives no comparison (Dons vs Pairs à Pairs vs Incitation à l'effort; Repas bénéfice vs Billets; Formulaire vs Inscriptions; Encan vs Tirage), no typical example per organisation kind, no revenue/tax-receipt consequences per type.
2. **Type consequences are scattered**: which types issue tax receipts automatically (`isAlwaysTaxReceiptable`: Dons, Incitation, P2P, Adhésion, valid-for-taxes Formulaire), which allow added donation / cover fees (all except Inscriptions), which allow the terminal, which need dates, which support participants/profit share. This lives in code (`CampaignTypesTrait`), not in articles.
3. **Defaults per type are undocumented**: values set silently at creation (e.g. product sale = `max_days_before=1`, added-donation text, goal shown; Cheerup = invitation-code only, grades on; Donation = default amounts 5/10/20; raffle = 3 default ticket packs) and the fact that `donation_options` is empty at creation.
4. **Tirage/raffle**: help says it is "set up with the Coolecto team"; the code hides it from non-admins, and requires a RACJ licence; the assistant must not offer it directly.
5. **Adhésion (Membership)** has help articles (`adhesions-*`) but is not offered at creation (commented out); help may mislead.
6. **Fees**: the articles state 5.9 % + 0.30 $; `Campaign::RATE_DEFAULT = 0.03` and plan fees exist; the relation between the configured `rate` and the displayed price is not explained (OPEN).
7. **Network rules** (approval, budget number, forced fee settings, templates) are documented per card but not as "what to expect if your organisation is under a network".
8. **Wizard vs "+ Campagne" differences** (two flows with different questions/defaults) are described in two articles with no side-by-side; the AI will need one canonical question set.
9. No article on **P2P vs the others at creation** beyond settings (the picker asks only "show goal").
10. The `Coco` chatbot is docs only; no article describes it or its limits.

---

## 7. Implications for the design (facts only, derived)

- Natural integration point: build an `Onboarding` (answers array) then call `createCampaignFromResults($user)`, or call `createCampaignCopyWithRelations()` for templates. Everything the human wizard can set at creation is a key of `answers` listed in 2.3. More settings than the wizard asks (cover fees, profit share %, goal amount, donation options, dates, terminal, order bump, P2P fees...) must be written after creation through the same model attributes the Kompo cards write; there is **no service layer** behind the cards (validation lives in each form's `rules()`/`beforeSave`), so a server-side assistant would have to replicate those rules (or reuse the form classes' `rules()`). Validation rules are collected in section 4.
- Anything the model can do that bypasses the cards (e.g. `forceFill`) skips: `MaxTranslatable`, `required_if`, date ordering, supplier season limits (`validateSeasonalCampaign`), delivery-date buffer (`validatePreferredDeliveryDate`), `locked_dates_and_prices`, parent-team overrides on accessors, and the `handleAfterStartEndDatesAreSet()` side effects (campaign date row, envois resync, end reminder job).
- Guard calls to replicate: `TeamLockGuard::assertCanCreateCampaign`, `assertCanSaveProduct`, `enforcedMainCampaign`, `Campaign::abortIfTakesParticipantsFromMainCampaign`, `Supplier::blocksNewCampaigns`.
- Server-side authorization has to be added explicitly (role not Member/Scanner; owner/manager/editor semantics); do not rely on the route.
- Recommended safe boundaries observed in code (facts, not decisions): the campaign stays `enabled=0` until the human completes `critical`/`important` cards, so creation alone cannot take money; dates/product prices/communications/participants/team info remain human or later steps.

## 8. Open questions

1. Can a Member/Scanner really create a campaign by URL (route has no role middleware)? Needs a test.
2. Meaning and use of `force_children_centralized_list` (one hit).
3. `donation_options` always NULL at creation (`Onboarding.php:260`): intended?
4. Is `Step1OnboardingForm` (route `welcome`) still in use, and `Step2OnBoardingSupplierForm` (supplier mode) flow content (not read).
5. `switchCampaign` silently failing for users with campaign restrictions after creating one.
6. Does anything else (admin tools) change `campaign_type_id` after creation?
7. Real column defaults after all migrations (`cover_fees`, `send_*_receipt`, `is_visible`) vs the first migrations quoted here.
8. How `rate` (0.03 default) maps to the 5.9 % + 0.30 $ shown to customers.
9. What `vendor/condoedge/ai` and `ai-eval` provide for conversation audit, tools/actions and rate limits (not inspected; tools unavailable in this research).
10. `RoleMiddleware` usage per `my-campaign/*` route (which settings pages require manager vs editor).
