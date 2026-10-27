# Fact sheet: Phase 4 creation articles (batch 4a)

Internal, never ingested. One section per article in `ActiviteDeFinancement/resources/ai-help/aide/organisateur/`.
Paths are relative to `ActiviteDeFinancement` unless they start with `ai/`. Line numbers: working tree of
`feat/ai-integration`, 2026-10-24. "KB:" = an existing help article the fact is repeated from (itself code-sourced).
`TYPES` = `app/Models/Traits/CampaignTypesTrait.php`, `CHECKS` = `app/Models/Traits/CampaignChecksTrait.php`,
`STEPS` = `app/Models/Traits/CampaignStepsTrait.php`, `ONB` = `app/Models/Onboarding/Onboarding.php`,
`S2` = `app/Kompo/Onboarding/Step2OnboardingForm.php`, `CCT` = `app/Ai/Tasks/CreateCampaignTask.php`,
`ORCH` = `ai/src/Services/Tasks/TaskOrchestrator.php`. Lang keys are `file.key` under `resources/lang/fr/`; `PKG ai.*`
is `ai/resources/lang/fr.json`.

No fee rate, percentage or price is stated in any article. The default suggested amounts (Dons/P2P, Incitation, raffle
packs) are described as "trois montants" without the figures (`Campaign.php:2603-2612`).

---

## 1. `choisir-le-type-de-campagne`

| Fact | Source |
|---|---|
| Type chosen at "Quel type de campagne voulez-vous créer?" | `S2:43-59`; `onboarding.what-type-of-campaign` |
| Type cannot be changed in Modifier la campagne | research §1 (no card writes `campaign_type_id`); KB `billets-types-de-campagnes-de-billets` |
| A copy keeps the source's type | `CampaignCopyTrait.php:31-38` (`replicate()`) |
| Products from a Coolecto supplier or own products → Vente de produits | `CampaignProductsOriginStep`; `campaign.from-coolecto-suppliers`, `campaign.from-my-own-suppliers` |
| Meal at one or several dates → Repas bénéfice (example "dîners à l'école") | `onboarding.meal-resp1..3-sub1`; KB `produits-repas-a-plusieurs-dates` (mots_cles "dîners à l'école") |
| Entry to "spectacle, souper, soirée" → Vente de billets | `website.guide-type-billets-admission-generale-hero-sub` |
| Pure donations → Dons | research §1 table (`TYPE_DONATION`) |
| P2P: each participant raises from "son entourage, avec sa propre page et son propre total" | `website.guide-type-pair-a-pair-hero-sub` |
| Incitation: donors sponsor a performance, results entered afterwards | `website.guide-type-incitation-a-l-effort-hero-sub`; `has_grades` side answer `S2:89-95` |
| Formulaire: questions, paid choices | KB `formulaires-creer-un-formulaire` |
| Inscriptions: courses/activities with groups and schedules | KB `inscriptions-creer-une-inscription`; research §1 |
| Encan: lots, winners pay at the end | `website.guide-type-encan-hero-sub`; KB `encan-demander-le-paiement-aux-gagnants` |
| Groupe de campagnes: several campaigns, one goal or same participants | `onboarding.main-is-other-sub1`, `campaign.main-has-central-participants-sub1` |
| Tirage not offered (staff/impersonator only) | `app/Kompo/Onboarding/TicketOnboardingStep.php:13`; `CCT:229` (never offered) |
| Adhésion not offered | `S2:52` (commented out); KB `adhesions-types-d-adhesion` |
| Supplier-reserved team: product only (complete lock) or padlocks (visible lock) | `S2:37-41`; research §2.2 (FeatureLockedModal) |
| Groupe de campagnes absent when creating a sub-campaign | `S2:54-56` |
| Dons/P2P/Incitation tax-receiptable by default (team must issue receipts) | `TYPES:217-221`; KB `recus-envoi-automatique-des-recus` |
| Each sub-campaign keeps its own type | KB `demarrage-groupe-de-campagnes` |
| Coco recommends from the goal and the organisation | `CCT:100-104`, `CCT:453-468` |

Labels: Quel type de campagne voulez-vous créer? `onboarding.what-type-of-campaign` · Modifier la campagne
`campaign.edit-campaign` · type names `welcome.campaign-type1/2/3/4/5/7`, `welcome.campaign-bidding`,
`welcome.peer-to-peer`, `welcome.campaign-group`, `welcome.campaign-inscriptions` · Admission générale / Places
assignées / Tirage `onboarding.ticket-resp2/3/1` · Adhésion `campaign.membership`.

## 2. `comparer-dons-pair-a-pair-incitation`

| Fact | Source |
|---|---|
| "Si rien n'est mesuré … une campagne de dons ira plus vite" (Coolecto's own advice) | `website.guide-type-incitation-a-l-effort-step1-text` |
| Dons: immediate payment (no pay phase) | `TYPES:166-169` (`hasPayPhase` = pledge or encan only) |
| Dons and P2P: three suggested amounts created | `Campaign.php:2603-2605` |
| Incitation: three suggested amounts created | `Campaign.php:2607-2609` |
| Dons created without a participant list (no recipients question, column NULL) | `S2:48` (next step ProfitShare), `ONB:301` + `manyOptionsAndNonToCol` `ONB:411-424` |
| Participants can be set later in Partage | `STEPS:154-157` (`StepCampaignRecipients`, tab `TAB_SHARING`) |
| Reason card ("Raison de la campagne") for Product and Donation; text shown to donors and used to write emails | `CHECKS:239-242`; `app/Models/Onboarding/Steps/CampaignReasonStep.php:32-35`; research §2.6 (`campaign_communications`); `CCT:399-400` |
| Recurring monthly donations possible for Donation | `TYPES:406-411` (`recurringTypes`); KB `dons-dons-mensuels-recurrents` |
| P2P: team types with fees, pre-registration/registration/event date, tab Paramètres de l'événement | `STEPS:104-112`; research §4 (`StepP2PPricing`, `StepCampaignDates` P2P rules); KB `campagne-p2p-tarifs-equipes-et-objectifs` |
| Compléter ma campagne for P2P needs group types and team size besides start date | `CHECKS:27-35`, `CHECKS:101-108`; `app/Models/Onboarding/Steps/CampaignInfoStep.php:29-32,45-48` |
| Incitation: pledge, paid later | `TYPES:166-169`; KB `rapports-engagements` |
| Incitation asks results per participant/group and multiple dates | `CCT:290-297`; `app/Kompo/Onboarding/OuiNonResultsTypeStep`, `OuiNonMultipleDatesStep` |
| Incitation born with participant list, results entry, invitation-code only | `S2:89-95` (`has_recipients=1`, `has_grades=1`, `only_with_invitation_code=1`) |
| Invitation-code card hidden for Incitation, so the rule stays | `STEPS:138-140` |
| Generic link refused when personal link required | KB `demarrage-mise-en-ligne` |
| Menu Résultats | KB `campagne-resultats-par-participant-ou-groupe` |
| Montants suggérés menu for the three types | KB `dons-montants-suggeres` |
| No added-donation card; Afficher les donateurs instead | `STEPS:142-148` |
| Cover fees card offered (every type but Inscriptions) | `STEPS:163-165`, `TYPES:336-339` |
| Profit share asked at creation for Dons; elsewhere in Autres paramètres | `S2:48`; `STEPS:167-169`; KB `participants-partage-des-profits` |

Labels: Montants suggérés `campaign.suggested-amounts` · Partage `campaign.sharing` · Raison de la campagne
`campaign.campaign-reason` · À quoi serviront les profits `campaign.campaign-reason-description` · Vos courriels
`campaign.onboarding-communications` · Paramètres de l'événement `campaign.event-settings` · Compléter ma campagne
`campaign.finish-my-campaign` · Est-ce que vos résultats… `campaign.are-your-campaign-results-per-participant-or-group`
· Est-ce que votre campagne comporte plusieurs dates importantes? `onboarding.multipledates-quest1` · Résultats
`campaign.results` · Demande de don supplémentaire `campaign.ask-for-donation-page` · Afficher les donateurs
`campaign.donation-settings` · Couverture des frais de la plateforme `campaign.donors-cover-the-platform-fees` ·
Voulez-vous partager les profits… `onboarding.profitshare-quest1` · Autres paramètres `campaign.other-settings`.

## 3. `comparer-repas-benefice-et-billets`

| Fact | Source |
|---|---|
| Meal type question and three choices with sub-texts | `app/Kompo/Onboarding/MealOnboardingStep`; `CCT:270`; `onboarding.what-type-of-meals`, `onboarding.meal-resp1..3`, `-sub1` |
| "Une seule date avec billets" switches the type to Vente de billets | `CCT:568`; research §2.2 (MealOnboardingStep) |
| Both "avec liste" choices give a participant list | `CCT:567,569` (`has_recipients=1`) |
| Single-date meal dated at the end date | `TYPES:223-227` (`shouldCreateInitialCampaignDate`); research §3.2 (`handleAfterStartEndDatesAreSet`) |
| Multi-date: card "Ajouter des dates importantes" | `app/Models/Onboarding/Steps/DatesStep.php:20-23`; research §2.6 (critical) |
| Products per date, order lock N days before (card Paramètres de produits) | KB `produits-repas-a-plusieurs-dates`, `produits-parametres-de-produits-par-date` |
| Ticket question, two choices offered to organisers, sub-texts | `CCT:284-287`; `onboarding.what-type-of-tickets`, `onboarding.ticket-resp2/3`, `-sub1` |
| Menus Scanner les billets, Gestion des billets | KB `billets-types-de-campagnes-de-billets`; `menu.scan-tickets`, `menu.ticket-management` |
| Tickets: added donation set to yes at creation | `S2:85-87`; `CCT:558` |
| Terminal Tap-to-Pay: meals yes, tickets yes except seat selection | `TYPES:176-180` |
| Sport receipts: tickets not raffle, team with tax-receipt info | `TYPES:182-185` |
| Tax amount per item for meals and tickets | `TYPES:212-215` |
| Parents choose per date | KB `produits-repas-a-plusieurs-dates` |

## 4. `comparer-formulaire-et-inscriptions`

| Fact | Source |
|---|---|
| Form editor in menu Formulaire; no Produits page; paid choices | KB `formulaires-creer-un-formulaire` |
| "Les contributions dans ce formulaire sont déductibles des impôts" makes it receiptable | `TYPES:217-221` (`survey->is_valid_for_taxes`); `campaign.orders-are-valid-for-tax-receipts` |
| Creation asks added donation and goal (Coco); wizard also asks the closed-group question | `CCT:299`; `app/Kompo/Onboarding/OuiNonClosedCampaignStep.php:7-15` |
| Formulaire born with a participant list | `S2:101-103`; `CCT:560` |
| Participants card applies and is critical | `app/Models/Onboarding/Steps/ParticipantsStep.php:35-41,55-58`; `CHECKS:123-126` |
| Add a form to an existing campaign instead | KB `formulaires-creer-un-formulaire` |
| Inscriptions creation questions | `app/Kompo/Inscriptions/Setup/InscriptionStartOnboardingForm`; `CCT:317-328`; `campaign.setup-*` keys |
| Created at once, guided setup opens | KB `inscriptions-creer-une-inscription`; research §2.2 |
| Registrants declared by the family; import only by invitation | KB `inscriptions-creer-une-inscription` |
| Instalments | KB `inscriptions-versements-plans` |
| Never goes live alone, "Mettre en ligne" | `app/Services/Campaigns/CampaignGoLiveService.php:16-23`; `campaign.setup-go-live` |
| No added donation / cover fees for Inscriptions | `TYPES:336-339`; `STEPS:144-147,163-165` |
| Sport receipts for Inscriptions | `TYPES:182-185` |
| Tax amount per activity | `TYPES:212-215` |

## 5. `comparer-encan-et-autres`

| Fact | Source |
|---|---|
| Winners pay at the end: payment request, or card charged | KB `encan-demander-le-paiement-aux-gagnants`, `encan-parametres-de-l-encan` |
| Creation: participants, profit share only with participants, goal | `S2:50`; `app/Kompo/Onboarding/OuiNonRecipientsStep.php:22-24`; `CCT:300` |
| Card Paramètres de l'encan (bids automation, card, highest bidder) | `STEPS:120-123`; research §4 (`StepCampaignEncanSettings`); `campaign.auction-settings` |
| Added-donation card offered; per-lot tax amount | `STEPS:144-147`; `TYPES:212-215` |
| Raffle: numbers drawn at random | KB `tirage-fonctionnement-et-mise-en-place` |
| Raffle staff-only; Lot de prix / 50/50; RACJ licence | `TicketOnboardingStep.php:13`; `onboarding.drawing-resp1/2`; KB `tirage-licence-racj`; research §2.6 (`draw_licence` critical) |
| Membership not offered; price and validity | `S2:52`; KB `adhesions-types-d-adhesion` |
| Main campaign types and sub-texts | `CCT:302-313`; `onboarding.what-type-of-main-campaign`, `campaign.main-is-ticket/travel`, `campaign.main-has-central-participants`, `campaign.main-is-other`, `-sub1` keys |
| Event creates two ticket sub-campaigns "Premier versement - X", "Paiement final - X" | `ONB:254-267`; `TYPES:300-303`; `campaign.first-payment`, `campaign.final-payment` |
| Participant goal and link registration for Event/Travel/School year | `CCT:314-315`, `CCT:579-580`; `MainCampaignOnboardingStep.php:32`; `campaign.input-participant-goal-quest1` |
| Coco offers only creatable types | `CCT:229-261` |

## 6. `consequences-par-type-recus-d-impot` — dropped

Covered by `recus-envoi-automatique-des-recus` ("Ce qui donne droit au reçu d'impôt": `TYPES:217-221`, `TYPES:212-215`,
`TYPES:182-185` all match) and `recus-activer-les-recus-d-impot` (team prerequisites, `Team::hasTaxReceiptInfo`).

## 7. `consequences-par-type-frais-et-don-supplementaire` — merged into 8

## 8. `valeurs-par-defaut-a-la-creation` (with 7)

| Fact | Source |
|---|---|
| Born offline | `app/Models/User.php:515` (`reinitEnabled`); go-live `CampaignGoLiveService.php:16-36` |
| No dates except Inscriptions (answers) | `ONB:288-341` (no date set); `ONB:344-361` |
| No communications until the emails card is opened | `Campaign.php:2582` (`generateDefaultCommunications`, called from `CommunicationOnboardingModal`); research §2.6, §3.1 |
| Platform rate from the team's plan, not organiser-editable | `User.php:513`; `Campaign.php:2323-2335`; research §4 "Not organiser-editable" |
| Cover fees off | `cover_fees` nullable, no default: `database/migrations/migrated/2022_10_27_113164_add_cover_fees_to_campaigns.php:14`; not set in `ONB:288-341` |
| Automatic receipts and tax receipts on | `…/2024_04_18_161030_add_send_receipt_automatically_to_campaigns_table.php:14`, `…/2024_03_14_112041_add_send_tax_receipt_automatically_to_campaigns_table.php:14` (`default(1)`) |
| Profit share off unless answered yes | `ONB:309` + `ONB:394-409` (absent → NULL, overrides `User.php:516`) |
| Product (own): added donation on, orders blocked 1 day before | `S2:79-83`; `ONB:311` (`0 ?: 1`) with `Campaign.php:405-408` |
| Product (supplier): products, prices, emails copied | `CampaignCopyTrait.php:154`; research §2.5 |
| Tickets: added donation on | `S2:85-87` |
| Meal "avec liste": participant list | `CCT:567,569` |
| Dons/P2P/Incitation: three suggested amounts | `Campaign.php:2603-2609` |
| Incitation: list, grades, invitation code | `S2:89-95` |
| Formulaire: participant list | `S2:101-103` |
| Inscriptions: payer phone asked; school level asked when a parent fills | `ONB:356-359` |
| Main Event/Travel/School year: registration link; Event: two sub-campaigns | `CCT:579-580`; `ONB:254-267` |
| Encan: nothing more | `S2:105-107` |
| Added-donation card: not Dons/P2P/Incitation/Inscriptions | `STEPS:142-148`; `TYPES:145-148`, `TYPES:336-339` |
| Default donation message, no amounts; card "Dons supplémentaires" Facultatif | `ONB:306-307` (`donation_options` always NULL, research open question 3); `app/Models/Onboarding/Steps/AddedDonationStep.php:38-56`; `CHECKS:180-184`; `campaign.ex-donation-message` |
| Cover-fees card: all but Inscriptions; not tips-only; disabled by parent | `STEPS:163-165`; `Campaign.php:1324-1327`; tips set by admins only `app/Kompo/Admin/AdminCampaignsTable.php:61`; `Campaign.php:529-544` |
| Supplier may pay some fees | `User.php:521` (`pay_fixed_fees`); KB `frais-frais-payes-par-le-fournisseur` |

## 9. `regles-de-reseau-a-la-creation`

| Fact | Source |
|---|---|
| Approval: card "Demander l'approbation", critical, offline until approved | `app/Models/Onboarding/Steps/ApprovalStep.php:14-32`; `Campaign.php:1368-1373`; research §5.3 |
| Budget number: card suggested; not for a main campaign | `BudgetNumberStep.php:14-32`; `Campaign.php:1375-1380` |
| Cover fees disabled by parent, card disabled with parent name | `Campaign.php:529-552`; `campaign.this-feature-was-disabled-by-parent-team` |
| Deposits to parent or grand-parent; bank card hidden | `app/Models/Teams/Team.php:819-831`; `BankInfoStep.php:49-52`; `campaign.all-deposits-go-to-my-bank-account` |
| Rules set on the parent's Politiques du réseau page | KB `compte-politiques-du-reseau` |
| Parent templates copied with sub-campaigns | `S2:192-249`; `campaign.copy-from-parent-templates` |
| Coco does not copy parent templates | `CCT:94-109` (only supplier templates, `CCT:518-531`) |
| Travel-agency team: one main campaign; others forced under it except surveys; second main refused | `Team.php:620-623`; `Campaign.php:2662-2669`; `TeamLockGuard::assertCanCreateCampaign` research §3.1; `CCT:251-254` |
| Coco knows network name and rules | `CCT:453-468` |
| Network-forced cover fees not asked, row "Imposé par votre réseau" | `CCT:383`, `CCT:189-197`, `CCT:683-693`; `app/Services/Campaigns/CampaignSettingsWriter.php:102-105` |

## 10. `creer-sa-campagne-avec-coco`

| Fact | Source |
|---|---|
| Feature switched on per environment | `config/ai.php:193-197` (`AI_TASKS`, default false); `ORCH:59-62` |
| Chip "Créer ma campagne avec Coco" in Coco's suggestions | `ai/src/Kompo/Support/WelcomeSuggestions.php:64-78`; `ai/src/Kompo/MessagesQuery.php:319-334`; `CCT:78-81` |
| Not for Member / Scanner; not suspended; not supplier mode | `CCT:84-92`; `app/Ai/NavigationGates.php:40-43` |
| Fundraiser goal asked, not saved on the campaign | `CCT:100-102`; excluded from summary `CCT:173` |
| Type recommended from goal, organisation, network | `CCT:103-104`, `CCT:465-467` |
| Type questions = the wizard's | `CCT:30-37` docblock, `CCT:263-331`; golden test `tests/Feature/Ai/CreateCampaignTaskGoldenTest.php` |
| Name asked except supplier template (keeps template name) | `CCT:106`, `CCT:538-542` |
| Optional groups offered together, "Rien d'autre pour l'instant" | `ORCH:619-634`; `ai/src/Services/Tasks/TaskPlanner.php:65-80`; `PKG ai.tasks.groups`, `ai.tasks.groups-none` |
| Supplier template, conditions (unless delivered by email), participants | `CCT:337-352` |
| Dates group (P2P adds pre-registration, registration, event date/time); past end date refused | `CCT:362-370`, `CCT:139-142` |
| Goal + goal type | `CCT:372-376` |
| Donation amounts only with added donation, non-donation types | `CCT:378-381` |
| Cover fees + default toggle (not Inscriptions, not tips-only, not forced) | `CCT:359`, `CCT:383-386` |
| Receipts only with tax-receipt info; sport receipts for eligible types | `CCT:388-393` |
| Contact email | `CCT:395-396` |
| Reason only Product/Donation, shown on the campaign page | `CCT:398-400`; `CHECKS:239-242` |
| Leaderboard only with participants; school → "Sans les noms" | `CCT:402-407`, `CCT:500-503`; `app/Models/Traits/CampaignLeaderboardTrait.php:36-41` |
| Never sets rate, supplier fees, enabled, bank, emails, participant import, prices, raffle, membership, copies, network rules | field whitelist `CCT:94-109` (no such field); `ORCH` drops undeclared fields (plan §4.2, Phase 1 log); `CCT:229`; plan §4.3 |
| Summary rows with source labels | `CCT:168-200`; `campaign.coco-source-*` |
| "Modifier" per row reopens the field | `ai/src/Kompo/Actions/TaskSummaryCard.php:91`; `ORCH:278-315`; `PKG ai.tasks.change` |
| Money/legal badges, per-row tick, refusal message | `TaskSummaryCard.php:90-96`; `ai/src/DTOs/Tasks/TaskSummary.php:21-24`; `ORCH:219-228`; `PKG ai.tasks.risk-money/legal`, `ai.tasks.acknowledge`, `ai.tasks.acknowledge-first` |
| Money rows: goal, goal type, profit share, added donation, donation amounts, cover fees, participant goal; legal rows: receipts, supplier conditions | `CCT:315,372-393,346,423,428`; network row `CCT:194` |
| Confirm / Cancel buttons; cancel creates nothing | `TaskSummaryCard.php:65-69`; `PKG ai.actions.confirm/cancel`, `ai.tasks.cancelled`; `ORCH:256-270` |
| Re-checks at confirm: role, validation, daily limits | `ORCH:229-243` |
| Outcome message and dashboard button | `CCT:219-220`; `campaign.coco-campaign-created`, `campaign.coco-open-campaign-dashboard` |
| Born offline; setup band decides go-live; Inscriptions button | `CCT:37`; `CampaignGoLiveService.php:16-36` |
| 3 per user / 10 per team per 24 h (executed tasks) | `ai/src/DTOs/Tasks/TaskLimits.php:14-18`; `ORCH:646-661` (checked at start `ORCH:107` and confirm `ORCH:239`); `CCT:223-226` (defaults) |
| 24 h TTL, 40 turns (and 60 model calls) → stopped, nothing created | `TaskLimits.php:14-16`; `ORCH:345-356`, `ORCH:576-591`; `PKG ai.tasks.expired`, `ai.tasks.limit-reached` |
| Limit message points to the usual form | `PKG ai.tasks.daily-limit-reached`, `ai.tasks.limit-reached` |
| Every step audited | `ai_task_events`, `ai/src/Services/Tasks/TaskStore.php` (hash chain), plan §4.2 |
| Coco cannot set fees to zero | no rate field in `CCT`; E2E run b in `coolecto-progress.md` ("`rate`/`pay_fixed_fees` untouched after 'mets les frais à 0 %'") |

## Label check (all 8 files)

Script `scratchpad/labelcheck/keys.py`: 172 distinct « » quotes, 0 not found in `resources/lang/fr` + `ai/resources/lang/fr.json`.
Not exact UI strings, on purpose:
- « + Campagne »: the sidebar button (label `campaign.campaign` with a + icon), written this way in the existing KB.
- « avec liste », « sur invitation »: fragments naming two choices (`onboarding.meal-resp1/3`, `campaign.setup-by-invitation`).
- « Cette fonctionnalité a été désactivée par »: prefix of `campaign.this-feature-was-disabled-by-parent-team` (`:team_name`).
- « Elle n'est pas encore en ligne : … »: second sentence of `campaign.coco-campaign-created` (first has `:name`).
- Package-only (shown by the chat UI, not in Coolecto's lang): « Réglages facultatifs », « Rien d'autre pour l'instant »,
  « Financier », « Juridique », « Je confirme cette valeur », « Cochez d'abord … », « Confirmer », « Annuler », « Modifier ».
No user-speech quotes. Forbidden terms (Ristourne, Stripe, Frais ADF, chèque, virement, bénéficiaire): none.
